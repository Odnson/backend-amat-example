<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QualityAssessmentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller untuk menangani identifikasi komunitas
 * Versi public yang sudah di-refactor dengan clean architecture
 */
class IdentificationController extends Controller
{
    protected QualityAssessmentService $qualityService;

    public function __construct(QualityAssessmentService $qualityService)
    {
        $this->qualityService = $qualityService;
    }

    /**
     * Get daftar identifikasi untuk observasi
     *
     * @param int $checklistId
     * @return JsonResponse
     */
    public function index(int $checklistId): JsonResponse
    {
        try {
            $identifications = DB::table('community_identifications as ci')
                ->join('fobi_users as u', 'ci.user_id', '=', 'u.id')
                ->join('taxa as t', 'ci.taxa_id', '=', 't.id')
                ->select([
                    'ci.id',
                    'ci.checklist_id',
                    'ci.user_id',
                    'ci.taxa_id',
                    'ci.body',
                    'ci.is_current',
                    'ci.is_withdrawn',
                    'ci.agrees_with_observation',
                    'ci.created_at',
                    'u.uname as username',
                    'u.fname',
                    'u.lname',
                    'u.profile_picture',
                    't.scientific_name',
                    't.cname_species as common_name',
                    't.rank as taxon_rank'
                ])
                ->where('ci.checklist_id', $checklistId)
                ->whereNull('ci.deleted_at')
                ->orderByDesc('ci.created_at')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $identifications
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching identifications:', [
                'error' => $e->getMessage(),
                'checklist_id' => $checklistId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data identifikasi'
            ], 500);
        }
    }

    /**
     * Tambah identifikasi baru
     *
     * @param Request $request
     * @param int $checklistId
     * @return JsonResponse
     */
    public function store(Request $request, int $checklistId): JsonResponse
    {
        try {
            $request->validate([
                'taxa_id' => 'required|exists:taxa,id',
                'body' => 'nullable|string|max:2000',
            ]);

            $userId = auth()->id();

            // Cek apakah observasi ada
            $checklist = DB::table('fobi_checklists')
                ->where('id', $checklistId)
                ->whereNull('deleted_at')
                ->first();

            if (!$checklist) {
                return response()->json([
                    'success' => false,
                    'message' => 'Observasi tidak ditemukan'
                ], 404);
            }

            // Withdraw identifikasi sebelumnya dari user yang sama
            DB::table('community_identifications')
                ->where('checklist_id', $checklistId)
                ->where('user_id', $userId)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'updated_at' => now()
                ]);

            // Insert identifikasi baru
            $identificationId = DB::table('community_identifications')->insertGetId([
                'checklist_id' => $checklistId,
                'user_id' => $userId,
                'taxa_id' => $request->taxa_id,
                'body' => $request->body,
                'is_current' => true,
                'is_withdrawn' => false,
                'agrees_with_observation' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update quality assessment
            $this->qualityService->updateAssessment($checklistId);

            return response()->json([
                'success' => true,
                'message' => 'Identifikasi berhasil ditambahkan',
                'data' => [
                    'id' => $identificationId
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating identification:', [
                'error' => $e->getMessage(),
                'checklist_id' => $checklistId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan identifikasi'
            ], 500);
        }
    }

    /**
     * Withdraw identifikasi
     *
     * @param int $checklistId
     * @param int $identificationId
     * @return JsonResponse
     */
    public function withdraw(int $checklistId, int $identificationId): JsonResponse
    {
        try {
            $userId = auth()->id();

            $identification = DB::table('community_identifications')
                ->where('id', $identificationId)
                ->where('checklist_id', $checklistId)
                ->first();

            if (!$identification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifikasi tidak ditemukan'
                ], 404);
            }

            if ($identification->user_id !== $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak memiliki akses untuk menarik identifikasi ini'
                ], 403);
            }

            DB::table('community_identifications')
                ->where('id', $identificationId)
                ->update([
                    'is_withdrawn' => true,
                    'is_current' => false,
                    'updated_at' => now()
                ]);

            // Update quality assessment
            $this->qualityService->updateAssessment($checklistId);

            return response()->json([
                'success' => true,
                'message' => 'Identifikasi berhasil ditarik'
            ]);

        } catch (\Exception $e) {
            Log::error('Error withdrawing identification:', [
                'error' => $e->getMessage(),
                'identification_id' => $identificationId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menarik identifikasi'
            ], 500);
        }
    }

    /**
     * Setuju dengan identifikasi
     *
     * @param Request $request
     * @param int $checklistId
     * @param int $identificationId
     * @return JsonResponse
     */
    public function agree(Request $request, int $checklistId, int $identificationId): JsonResponse
    {
        try {
            $userId = auth()->id();

            $identification = DB::table('community_identifications')
                ->where('id', $identificationId)
                ->where('checklist_id', $checklistId)
                ->where('is_current', true)
                ->where('is_withdrawn', false)
                ->first();

            if (!$identification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifikasi tidak ditemukan atau sudah ditarik'
                ], 404);
            }

            // Cek apakah user sudah pernah agree
            $existingAgreement = DB::table('community_identifications')
                ->where('checklist_id', $checklistId)
                ->where('user_id', $userId)
                ->where('taxa_id', $identification->taxa_id)
                ->where('is_current', true)
                ->first();

            if ($existingAgreement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda sudah menyetujui identifikasi ini'
                ], 422);
            }

            // Withdraw identifikasi sebelumnya dari user
            DB::table('community_identifications')
                ->where('checklist_id', $checklistId)
                ->where('user_id', $userId)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'updated_at' => now()
                ]);

            // Insert agreement sebagai identifikasi baru
            $agreementId = DB::table('community_identifications')->insertGetId([
                'checklist_id' => $checklistId,
                'user_id' => $userId,
                'taxa_id' => $identification->taxa_id,
                'body' => 'Setuju dengan identifikasi ini',
                'is_current' => true,
                'is_withdrawn' => false,
                'agrees_with_observation' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update quality assessment
            $this->qualityService->updateAssessment($checklistId);

            return response()->json([
                'success' => true,
                'message' => 'Persetujuan berhasil ditambahkan',
                'data' => [
                    'id' => $agreementId
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error agreeing with identification:', [
                'error' => $e->getMessage(),
                'identification_id' => $identificationId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyetujui identifikasi'
            ], 500);
        }
    }
}
