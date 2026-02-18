<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service untuk menangani operasi observasi/checklist
 */
class ObservationService
{
    protected LocationService $locationService;
    protected MediaService $mediaService;

    public function __construct(LocationService $locationService, MediaService $mediaService)
    {
        $this->locationService = $locationService;
        $this->mediaService = $mediaService;
    }

    /**
     * Membuat observasi baru
     *
     * @param array $data
     * @param int $userId
     * @return array
     */
    public function createObservation(array $data, int $userId): array
    {
        try {
            DB::beginTransaction();

            // Get location name dari koordinat
            $locationName = $this->locationService->getLocationName(
                $data['latitude'] ?? null,
                $data['longitude'] ?? null
            );

            // Insert checklist
            $checklistId = DB::table('fobi_checklists')->insertGetId([
                'user_id' => $userId,
                'taxa_id' => $data['taxa_id'] ?? null,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'location_name' => $locationName,
                'observation_date' => $data['observation_date'],
                'observation_time' => $data['observation_time'] ?? null,
                'notes' => $data['notes'] ?? null,
                'count' => $data['count'] ?? 1,
                'quality_grade' => 'needs id',
                'is_wild' => $data['is_wild'] ?? true,
                'is_public' => $data['is_public'] ?? true,
                'source' => 'fobi',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return [
                'success' => true,
                'checklist_id' => $checklistId,
                'location_name' => $locationName
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating observation:', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update observasi
     *
     * @param int $checklistId
     * @param array $data
     * @param int $userId
     * @return array
     */
    public function updateObservation(int $checklistId, array $data, int $userId): array
    {
        try {
            $checklist = DB::table('fobi_checklists')
                ->where('id', $checklistId)
                ->first();

            if (!$checklist) {
                return [
                    'success' => false,
                    'error' => 'Observasi tidak ditemukan'
                ];
            }

            if ($checklist->user_id !== $userId) {
                return [
                    'success' => false,
                    'error' => 'Tidak memiliki akses untuk mengubah observasi ini'
                ];
            }

            $updateData = [
                'updated_at' => now()
            ];

            // Update fields yang diberikan
            $allowedFields = ['taxa_id', 'latitude', 'longitude', 'observation_date', 
                              'observation_time', 'notes', 'count', 'is_wild', 'is_public'];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }

            // Update location name jika koordinat berubah
            if (isset($data['latitude']) || isset($data['longitude'])) {
                $updateData['location_name'] = $this->locationService->getLocationName(
                    $data['latitude'] ?? $checklist->latitude,
                    $data['longitude'] ?? $checklist->longitude
                );
            }

            DB::table('fobi_checklists')
                ->where('id', $checklistId)
                ->update($updateData);

            return [
                'success' => true,
                'checklist_id' => $checklistId
            ];

        } catch (\Exception $e) {
            Log::error('Error updating observation:', [
                'error' => $e->getMessage(),
                'checklist_id' => $checklistId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Hapus observasi
     *
     * @param int $checklistId
     * @param int $userId
     * @return array
     */
    public function deleteObservation(int $checklistId, int $userId): array
    {
        try {
            $checklist = DB::table('fobi_checklists')
                ->where('id', $checklistId)
                ->first();

            if (!$checklist) {
                return [
                    'success' => false,
                    'error' => 'Observasi tidak ditemukan'
                ];
            }

            if ($checklist->user_id !== $userId) {
                return [
                    'success' => false,
                    'error' => 'Tidak memiliki akses untuk menghapus observasi ini'
                ];
            }

            // Soft delete
            DB::table('fobi_checklists')
                ->where('id', $checklistId)
                ->update([
                    'deleted_at' => now()
                ]);

            return [
                'success' => true
            ];

        } catch (\Exception $e) {
            Log::error('Error deleting observation:', [
                'error' => $e->getMessage(),
                'checklist_id' => $checklistId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get observasi dengan detail
     *
     * @param int $checklistId
     * @return object|null
     */
    public function getObservation(int $checklistId): ?object
    {
        return DB::table('fobi_checklists as c')
            ->leftJoin('fobi_users as u', 'c.user_id', '=', 'u.id')
            ->leftJoin('taxa as t', 'c.taxa_id', '=', 't.id')
            ->select([
                'c.*',
                'u.uname as username',
                'u.fname',
                'u.lname',
                'u.profile_picture',
                't.scientific_name',
                't.cname_species as common_name',
                't.family',
                't.order',
                't.class'
            ])
            ->where('c.id', $checklistId)
            ->whereNull('c.deleted_at')
            ->first();
    }

    /**
     * Get daftar observasi dengan pagination
     *
     * @param array $filters
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function getObservations(array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $query = DB::table('fobi_checklists as c')
            ->leftJoin('fobi_users as u', 'c.user_id', '=', 'u.id')
            ->leftJoin('taxa as t', 'c.taxa_id', '=', 't.id')
            ->select([
                'c.id',
                'c.latitude',
                'c.longitude',
                'c.location_name',
                'c.observation_date',
                'c.quality_grade',
                'c.created_at',
                'u.uname as username',
                'u.profile_picture',
                't.scientific_name',
                't.cname_species as common_name'
            ])
            ->whereNull('c.deleted_at')
            ->where('c.is_public', true);

        // Apply filters
        if (!empty($filters['user_id'])) {
            $query->where('c.user_id', $filters['user_id']);
        }

        if (!empty($filters['taxa_id'])) {
            $query->where('c.taxa_id', $filters['taxa_id']);
        }

        if (!empty($filters['quality_grade'])) {
            $query->where('c.quality_grade', $filters['quality_grade']);
        }

        if (!empty($filters['start_date'])) {
            $query->where('c.observation_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('c.observation_date', '<=', $filters['end_date']);
        }

        // Get total count
        $total = $query->count();

        // Get paginated results
        $observations = $query
            ->orderBy('c.observation_date', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'data' => $observations,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => ceil($total / $perPage)
        ];
    }
}
