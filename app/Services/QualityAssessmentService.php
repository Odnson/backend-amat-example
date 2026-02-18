<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service untuk menangani penilaian kualitas data observasi
 * dan manajemen identifikasi taksa
 */
class QualityAssessmentService
{
    /**
     * Konstanta untuk quality grades
     */
    const GRADE_RESEARCH = 'research grade';
    const GRADE_CONFIRMED = 'confirmed id';
    const GRADE_NEEDS_ID = 'needs id';
    const GRADE_LOW_QUALITY = 'low quality id';
    const GRADE_CASUAL = 'casual';

    /**
     * Menentukan quality grade berdasarkan kriteria
     *
     * @param int $checklistId
     * @param string $source
     * @return string
     */
    public function determineGrade(int $checklistId, string $source = 'fobi'): string
    {
        $checklist = $this->getChecklistData($checklistId, $source);
        
        if (!$checklist) {
            return self::GRADE_CASUAL;
        }

        // Cek kriteria dasar
        $hasMedia = $this->checkHasMedia($checklistId, $source);
        $hasLocation = $checklist->latitude && $checklist->longitude;
        $hasDate = $checklist->observation_date !== null;

        // Jika tidak memenuhi kriteria dasar
        if (!$hasMedia || !$hasLocation || !$hasDate) {
            return self::GRADE_CASUAL;
        }

        // Hitung identifikasi
        $identificationStats = $this->getIdentificationStats($checklistId, $source);
        
        if ($identificationStats['total'] === 0) {
            return self::GRADE_NEEDS_ID;
        }

        // Cek konsensus (2/3 majority)
        $quorumThreshold = ceil($identificationStats['total'] * 2 / 3);
        
        if ($identificationStats['max_agreement'] >= $quorumThreshold) {
            // Cek apakah identifikasi level species
            if ($identificationStats['agreed_rank'] === 'species' || 
                $identificationStats['agreed_rank'] === 'subspecies') {
                return self::GRADE_RESEARCH;
            }
            return self::GRADE_CONFIRMED;
        }

        return self::GRADE_NEEDS_ID;
    }

    /**
     * Update quality assessment untuk checklist
     *
     * @param int $checklistId
     * @param string $source
     * @return array
     */
    public function updateAssessment(int $checklistId, string $source = 'fobi'): array
    {
        try {
            $grade = $this->determineGrade($checklistId, $source);
            
            $table = $this->getChecklistTable($source);
            
            DB::table($table)
                ->where('id', $checklistId)
                ->update([
                    'quality_grade' => $grade,
                    'updated_at' => now()
                ]);

            return [
                'success' => true,
                'grade' => $grade
            ];

        } catch (\Exception $e) {
            Log::error('Error updating quality assessment:', [
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
     * Get statistik identifikasi untuk checklist
     *
     * @param int $checklistId
     * @param string $source
     * @return array
     */
    public function getIdentificationStats(int $checklistId, string $source = 'fobi'): array
    {
        $identifications = DB::table('community_identifications as ci')
            ->join('taxa as t', 'ci.taxa_id', '=', 't.id')
            ->select([
                'ci.taxa_id',
                't.rank as taxon_rank',
                DB::raw('COUNT(*) as count')
            ])
            ->where('ci.checklist_id', $checklistId)
            ->where('ci.is_current', true)
            ->where('ci.is_withdrawn', false)
            ->whereNull('ci.deleted_at')
            ->groupBy('ci.taxa_id', 't.rank')
            ->orderByDesc('count')
            ->get();

        $total = $identifications->sum('count');
        $maxAgreement = $identifications->first()?->count ?? 0;
        $agreedRank = $identifications->first()?->taxon_rank ?? null;

        return [
            'total' => $total,
            'max_agreement' => $maxAgreement,
            'agreed_rank' => $agreedRank,
            'identifications' => $identifications
        ];
    }

    /**
     * Cek apakah checklist memiliki media
     *
     * @param int $checklistId
     * @param string $source
     * @return bool
     */
    private function checkHasMedia(int $checklistId, string $source): bool
    {
        return DB::table('fobi_checklist_media')
            ->where('checklist_id', $checklistId)
            ->exists();
    }

    /**
     * Get data checklist
     *
     * @param int $checklistId
     * @param string $source
     * @return object|null
     */
    private function getChecklistData(int $checklistId, string $source): ?object
    {
        $table = $this->getChecklistTable($source);
        
        return DB::table($table)
            ->where('id', $checklistId)
            ->first();
    }

    /**
     * Get nama tabel checklist berdasarkan source
     *
     * @param string $source
     * @return string
     */
    private function getChecklistTable(string $source): string
    {
        return match($source) {
            'burungnesia' => 'fobi_checklists',
            'kupunesia' => 'fobi_checklists',
            default => 'fobi_checklists'
        };
    }

    /**
     * Hitung persentase keyakinan identifikasi
     *
     * @param int $checklistId
     * @return array
     */
    public function calculateConfidence(int $checklistId): array
    {
        $stats = $this->getIdentificationStats($checklistId);
        
        if ($stats['total'] === 0) {
            return [
                'percentage' => 0,
                'grade' => self::GRADE_NEEDS_ID,
                'agreed_taxa' => null
            ];
        }

        $percentage = round(($stats['max_agreement'] / $stats['total']) * 100);
        $grade = $this->determineGrade($checklistId);

        // Cap at 100% only for research grade
        if ($grade !== self::GRADE_RESEARCH && $percentage >= 100) {
            $percentage = 99;
        }

        return [
            'percentage' => $percentage,
            'grade' => $grade,
            'agreed_taxa' => $stats['identifications']->first()?->taxa_id ?? null
        ];
    }
}
