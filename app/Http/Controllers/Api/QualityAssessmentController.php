<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QualityAssessmentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller untuk menangani penilaian kualitas data
 * Versi public yang sudah di-refactor dengan clean architecture
 */
class QualityAssessmentController extends Controller
{
    protected QualityAssessmentService $qualityService;

    public function __construct(QualityAssessmentService $qualityService)
    {
        $this->qualityService = $qualityService;
    }

    /**
     * Get quality assessment untuk observasi
     *
     * @param int $checklistId
     * @return JsonResponse
     */
    public function show(int $checklistId): JsonResponse
    {
        try {
            $confidence = $this->qualityService->calculateConfidence($checklistId);
            $stats = $this->qualityService->getIdentificationStats($checklistId);

            return response()->json([
                'success' => true,
                'data' => [
                    'checklist_id' => $checklistId,
                    'quality_grade' => $confidence['grade'],
                    'confidence_percentage' => $confidence['percentage'],
                    'agreed_taxa_id' => $confidence['agreed_taxa'],
                    'total_identifications' => $stats['total'],
                    'identifications' => $stats['identifications'],
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching quality assessment:', [
                'error' => $e->getMessage(),
                'checklist_id' => $checklistId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data penilaian kualitas'
            ], 500);
        }
    }

    /**
     * Update quality assessment untuk observasi
     *
     * @param int $checklistId
     * @return JsonResponse
     */
    public function update(int $checklistId): JsonResponse
    {
        try {
            $result = $this->qualityService->updateAssessment($checklistId);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error']
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Penilaian kualitas berhasil diupdate',
                'data' => [
                    'quality_grade' => $result['grade']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating quality assessment:', [
                'error' => $e->getMessage(),
                'checklist_id' => $checklistId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengupdate penilaian kualitas'
            ], 500);
        }
    }

    /**
     * Get confidence data untuk observasi
     *
     * @param int $checklistId
     * @return JsonResponse
     */
    public function getConfidence(int $checklistId): JsonResponse
    {
        try {
            $confidence = $this->qualityService->calculateConfidence($checklistId);

            return response()->json([
                'success' => true,
                'data' => $confidence
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching confidence data:', [
                'error' => $e->getMessage(),
                'checklist_id' => $checklistId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data keyakinan'
            ], 500);
        }
    }
}
