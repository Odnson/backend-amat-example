<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreObservationRequest;
use App\Http\Requests\UpdateObservationRequest;
use App\Http\Resources\ObservationResource;
use App\Http\Resources\ObservationCollection;
use App\Services\ObservationService;
use App\Services\MediaService;
use App\Services\QualityAssessmentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller untuk menangani operasi observasi
 * Versi public yang sudah di-refactor dengan clean architecture
 */
class ObservationController extends Controller
{
    protected ObservationService $observationService;
    protected MediaService $mediaService;
    protected QualityAssessmentService $qualityService;

    public function __construct(
        ObservationService $observationService,
        MediaService $mediaService,
        QualityAssessmentService $qualityService
    ) {
        $this->observationService = $observationService;
        $this->mediaService = $mediaService;
        $this->qualityService = $qualityService;
    }

    /**
     * Get daftar observasi dengan pagination dan filter
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'user_id' => $request->input('user_id'),
                'taxa_id' => $request->input('taxa_id'),
                'quality_grade' => $request->input('quality_grade'),
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
            ];

            $page = $request->input('page', 1);
            $perPage = min($request->input('per_page', 30), 100);

            $result = $this->observationService->getObservations($filters, $page, $perPage);

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'meta' => [
                    'current_page' => $result['page'],
                    'last_page' => $result['last_page'],
                    'per_page' => $result['per_page'],
                    'total' => $result['total'],
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching observations:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data observasi'
            ], 500);
        }
    }

    /**
     * Get detail observasi
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $observation = $this->observationService->getObservation($id);

            if (!$observation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Observasi tidak ditemukan'
                ], 404);
            }

            // Get media
            $media = DB::table('fobi_checklist_media')
                ->where('checklist_id', $id)
                ->orderBy('sort_order')
                ->get();

            // Get identifications
            $identifications = DB::table('community_identifications as ci')
                ->join('fobi_users as u', 'ci.user_id', '=', 'u.id')
                ->join('taxa as t', 'ci.taxa_id', '=', 't.id')
                ->select([
                    'ci.*',
                    'u.uname as username',
                    'u.profile_picture',
                    't.scientific_name',
                    't.cname_species as common_name'
                ])
                ->where('ci.checklist_id', $id)
                ->where('ci.is_current', true)
                ->whereNull('ci.deleted_at')
                ->orderByDesc('ci.created_at')
                ->get();

            // Get comments
            $comments = DB::table('fobi_comments as c')
                ->join('fobi_users as u', 'c.user_id', '=', 'u.id')
                ->select([
                    'c.*',
                    'u.uname as username',
                    'u.profile_picture'
                ])
                ->where('c.checklist_id', $id)
                ->whereNull('c.deleted_at')
                ->orderBy('c.created_at')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'observation' => $observation,
                    'media' => $media,
                    'identifications' => $identifications,
                    'comments' => $comments,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching observation detail:', [
                'error' => $e->getMessage(),
                'id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil detail observasi'
            ], 500);
        }
    }

    /**
     * Membuat observasi baru
     *
     * @param StoreObservationRequest $request
     * @return JsonResponse
     */
    public function store(StoreObservationRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $userId = auth()->id();
            $data = $request->validated();

            // Cari taxa berdasarkan scientific_name
            $taxa = DB::table('taxa')
                ->where('scientific_name', $data['scientific_name'])
                ->first();

            if ($taxa) {
                $data['taxa_id'] = $taxa->id;
            }

            // Buat observasi
            $result = $this->observationService->createObservation($data, $userId);

            if (!$result['success']) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => $result['error']
                ], 422);
            }

            $checklistId = $result['checklist_id'];

            // Process media jika ada
            if ($request->hasFile('media')) {
                foreach ($request->file('media') as $index => $file) {
                    $mediaResult = $this->processMediaFile($file, $checklistId, $index);
                    
                    if (!$mediaResult['success']) {
                        Log::warning('Failed to process media:', [
                            'error' => $mediaResult['error'],
                            'index' => $index
                        ]);
                    }
                }
            }

            // Update quality assessment
            $this->qualityService->updateAssessment($checklistId);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Observasi berhasil dibuat',
                'data' => [
                    'id' => $checklistId,
                    'location_name' => $result['location_name']
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating observation:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat membuat observasi'
            ], 500);
        }
    }

    /**
     * Update observasi
     *
     * @param UpdateObservationRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateObservationRequest $request, int $id): JsonResponse
    {
        try {
            $userId = auth()->id();
            $data = $request->validated();

            $result = $this->observationService->updateObservation($id, $data, $userId);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error']
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Observasi berhasil diupdate'
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating observation:', [
                'error' => $e->getMessage(),
                'id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengupdate observasi'
            ], 500);
        }
    }

    /**
     * Hapus observasi
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $userId = auth()->id();

            $result = $this->observationService->deleteObservation($id, $userId);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error']
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Observasi berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting observation:', [
                'error' => $e->getMessage(),
                'id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus observasi'
            ], 500);
        }
    }

    /**
     * Process dan simpan media file
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param int $checklistId
     * @param int $sortOrder
     * @return array
     */
    private function processMediaFile($file, int $checklistId, int $sortOrder): array
    {
        try {
            $isImage = $this->mediaService->isValidImage($file);
            $isAudio = $this->mediaService->isValidAudio($file);

            if (!$isImage && !$isAudio) {
                return [
                    'success' => false,
                    'error' => 'Format file tidak didukung'
                ];
            }

            if ($isImage) {
                $result = $this->mediaService->processImage($file);
                $mediaType = 'image';
            } else {
                $result = $this->mediaService->processAudio($file);
                $mediaType = 'audio';
            }

            if (!$result['success']) {
                return $result;
            }

            // Simpan ke database
            DB::table('fobi_checklist_media')->insert([
                'checklist_id' => $checklistId,
                'file_path' => $result['path'],
                'file_name' => $file->getClientOriginalName(),
                'media_type' => $mediaType,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'storage_type' => $result['storage_type'],
                'sort_order' => $sortOrder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'success' => true,
                'path' => $result['path']
            ];

        } catch (\Exception $e) {
            Log::error('Error processing media file:', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
