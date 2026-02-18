<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\TaxaIdentificationHistory;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\TaxaSimilarIdentification;

/**
 * ChecklistQualityAssessmentController menangani penilaian kualitas data pengamatan
 * dan manajemen identifikasi taksa.
 * 
 * PENGGUNAAN FITUR PERSETUJUAN IMPLISIT:
 * -------------------------------------
 * Ketika sebuah identifikasi baru ditambahkan, panggil metode createImplicitAgreements()
 * setelah menyimpan identifikasi untuk memeriksa apakah ada identifikasi sebelumnya
 * dengan taksa yang sama. Jika ada, identifikasi baru akan otomatis dikonversi menjadi
 * persetujuan terhadap identifikasi taksa yang sama yang lebih awal.
 * 
 * Contoh penggunaan dalam IdentificationController:
 * 
 * ```php
 * // Setelah menyimpan identifikasi baru
 * $newIdentification = TaxaIdentification::create([...]);
 * 
 * // Buat persetujuan implisit jika diperlukan
 * app(ChecklistQualityAssessmentController::class)->createImplicitAgreements(
 *     $request->checklist_id,
 *     $newIdentification->id,
 *     $newIdentification->taxon_id,
 *     auth()->user()->id
 * );
 * ```
 * 
 * Fitur ini membantu meningkatkan konsensus identifikasi tanpa perlu persetujuan eksplisit
 * dari pengguna ketika beberapa pengamat independen mengidentifikasi taksa yang sama.
 * 
 * PENARIKAN OTOMATIS IDENTIFIKASI TINGKAT LEBIH TINGGI:
 * --------------------------------------------------
 * Ketika identifikasi spesies tertentu (mis. Prinia familiaris) telah mencapai konsensus
 * dan menjadi research grade, identifikasi tingkat lebih tinggi (genus, family, order, dll)
 * yang berada dalam garis taksonomi yang sama akan ditarik secara otomatis.
 * 
 * Contoh: Jika spesies Prinia familiaris telah mencapai research grade, maka identifikasi
 * untuk genus Prinia atau order Passeriformes yang sebelumnya diusulkan (tetapi merupakan 
 * taksonomi yang sama) akan ditarik otomatis. Ini membantu menjaga dataset yang konsisten
 * dan mencegah identifikasi yang redundan.
 * 
 * Fitur ini dipanggil secara otomatis ketika:
 * 1. UpdateQualityAssessment dijalankan dan menentukan status research grade
 * 2. Persetujuan baru menyebabkan spesies mencapai kuorum (2/3 dari total pengusul)
 * 
 * Contoh penggunaan manual:
 * 
 * ```php
 * // Dapatkan data takson yang disepakati
 * $agreedTaxon = DB::table('taxas')
 *     ->where('id', $taxaId)
 *     ->select('id', 'taxon_rank', 'kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species')
 *     ->first();
 * 
 * // Jalankan penarikan otomatis
 * app(ChecklistQualityAssessmentController::class)->autoWithdrawHigherRankIdentifications(
 *     $checklistId,
 *     $agreedTaxon
 * );
 * ```
 */
class ChecklistQualityAssessmentController extends Controller
{
    private function getChecklistTable($source)
    {
        return match($source) {
            'burungnesia' => 'fobi_checklists',
            'kupunesia' => 'fobi_checklists_kupnes',
            default => 'fobi_checklist_taxas'
        };
    }

    private function getAssessmentTable($source)
    {
        return match($source) {
            'burungnesia' => 'data_quality_assessments',
            'kupunesia' => 'data_quality_assessments_kupnes',
            default => 'taxa_quality_assessments'
        };
    }

    private function checkHasMedia($id, $source)
    {
        if ($source === 'burungnesia') {
            return DB::table('fobi_checklist_fauna_imgs')
                ->where('checklist_id', $id)
                ->exists() ||
                DB::table('fobi_checklist_sounds')
                    ->where('checklist_id', $id)
                    ->exists();
        } elseif ($source === 'kupunesia') {
            return DB::table('fobi_checklist_fauna_imgs_kupnes')
                ->where('checklist_id', $id)
                ->exists();
        } else {
            return DB::table('fobi_checklist_media')
                ->where('checklist_id', $id)
                ->exists();
        }
    }

    private function getActualId($id, $source)
    {
        if ($source === 'burungnesia' && str_starts_with($id, 'BN')) {
            return substr($id, 2);
        }
        if ($source === 'kupunesia' && str_starts_with($id, 'KP')) {
            return substr($id, 2);
        }
        return $id;
    }

    public function getAssessmentConfig($source)
    {
        return match($source) {
            'burungnesia' => [
                'table' => 'data_quality_assessments',
                'id_column' => 'observation_id',
                'fauna_column' => 'fauna_id'
            ],
            'kupunesia' => [
                'table' => 'data_quality_assessments_kupnes',
                'id_column' => 'observation_id',
                'fauna_column' => 'fauna_id'
            ],
            default => [
                'table' => 'taxa_quality_assessments',
                'id_column' => 'taxa_id',
                'fauna_column' => 'taxon_id'
            ]
        };
    }

    private function determineGrade($totalIdentifications, $agreementCount, $hasMedia, $hasLocation, $hasDate, $actualId, $source = 'fobi')
    {
        // Hitung identifikasi aktif dan persetujuannya
        $identificationStats = DB::table('taxa_identifications as ti1')
            ->select([
                'ti1.taxon_id',
                'ti1.id as identification_id',
                'ti1.user_id',
                't.taxon_rank',
                'ti1.excluded_from_quorum',
                DB::raw('COUNT(DISTINCT ti1.user_id) as identifier_count'),
                DB::raw('CASE WHEN COUNT(DISTINCT CASE
                    WHEN ti2.agrees_with_id = ti1.id AND (ti2.is_withdrawn = false OR ti2.is_withdrawn IS NULL)
                    AND ti2.deleted_at IS NULL
                    THEN ti2.user_id END) = 0
                    THEN NULL
                    ELSE COUNT(DISTINCT CASE
                        WHEN ti2.agrees_with_id = ti1.id AND (ti2.is_withdrawn = false OR ti2.is_withdrawn IS NULL)
                        AND ti2.deleted_at IS NULL
                        THEN ti2.user_id END)
                    END as agreement_count')
            ])
            ->join('taxas as t', 'ti1.taxon_id', '=', 't.id')
            ->leftJoin('taxa_identifications as ti2', 'ti1.id', '=', 'ti2.agrees_with_id')
            ->where(function($query) use ($actualId) {
                $query->where('ti1.checklist_id', $actualId)
                      ->orWhere('ti1.burnes_checklist_id', $actualId)
                      ->orWhere('ti1.kupnes_checklist_id', $actualId);
            })
            ->where(function($query) {
                $query->where('ti1.is_withdrawn', false)
                      ->orWhereNull('ti1.is_withdrawn');
            })
            ->whereNull('ti1.deleted_at') // Tambahkan filter untuk soft delete
            ->where(function($query) {
                $query->where(DB::raw('LOWER(t.taxon_rank)'), '!=', 'unknown')
                      ->orWhereNull('t.taxon_rank');
            }) // Abaikan identifikasi UNKNOWN
            ->groupBy('ti1.taxon_id', 'ti1.id', 'ti1.user_id', 't.taxon_rank', 'ti1.excluded_from_quorum')
            ->get();

        // Cek apakah ada identifikasi baru dengan rank valid
        foreach ($identificationStats as $stat) {
            if (isset($stat->taxon_rank) && strtolower($stat->taxon_rank) !== 'unknown') {
                // Panggil fungsi auto-withdraw untuk UNKNOWN
                $this->autoWithdrawUnknownRankIdentifications($actualId, $stat);
                break;
            }
        }

        // Hitung total identifikasi aktif (tidak termasuk UNKNOWN dan yang sudah di-withdraw)
        // dan tidak termasuk yang excluded_from_quorum = 1/true kecuali jika ada persetujuan
        $activeIdentifications = 0;
        foreach ($identificationStats as $stat) {
            // Jika identifikasi tidak dikeluarkan dari kuorum
            if (!(isset($stat->excluded_from_quorum) && $stat->excluded_from_quorum == 1)) {
                $activeIdentifications += $stat->identifier_count;
            }
        }

        Log::info('Active identifications (excluding UNKNOWN, withdrawn, and excluded_from_quorum without agreements)', [
            'count' => $activeIdentifications,
            'stats' => $identificationStats
        ]);

        // Analisis statistik identifikasi
        $maxAgreements = 0;
        $taxaWithAgreements = 0;
        $taxonAgreements = [];
        $mostAgreedTaxonId = null;
        $totalParticipants = 0;
        
        // Buat struktur data untuk menyimpan informasi taksa dan pengusulnya
        $taxaUsers = [];
        $taxaIdentifications = [];
        
        // Pertama, kumpulkan semua user yang mengusulkan taksa tertentu
        foreach ($identificationStats as $stat) {
            // Abaikan identifikasi yang dikeluarkan dari kuorum
            if (isset($stat->excluded_from_quorum) && $stat->excluded_from_quorum == 1) {
                continue;
            }
            
            $taxonId = $stat->taxon_id;
            $userId = $stat->user_id;
            
            if (!isset($taxaUsers[$taxonId])) {
                $taxaUsers[$taxonId] = [];
                $taxaIdentifications[$taxonId] = [];
            }
            
            // Tambahkan user ke daftar pengusul taksa ini
            $taxaUsers[$taxonId][] = $userId;
            // Simpan juga ID identifikasi
            $taxaIdentifications[$taxonId][] = $stat->identification_id;
        }
        
        Log::info('Taxa users mapping (excluding excluded_from_quorum without agreements)', [
            'taxaUsers' => $taxaUsers
        ]);

        // Hitung total partisipan unik yang tidak dikecualikan dari kuorum
        $uniqueParticipants = [];
        foreach ($identificationStats as $stat) {
            // Abaikan identifikasi yang dikeluarkan dari kuorum
            if (isset($stat->excluded_from_quorum) && $stat->excluded_from_quorum == 1) {
                continue;
            }
            $uniqueParticipants[$stat->user_id] = true;
        }
        $totalParticipants = count($uniqueParticipants);
        
        Log::info('Total participants calculation (excluding excluded_from_quorum)', [
            'totalParticipants' => $totalParticipants,
            'uniqueParticipants' => array_keys($uniqueParticipants)
        ]);

        // Sekarang hitung persetujuan dengan perubahan untuk menghitung pengusul taksa yang sama
        foreach ($identificationStats as $stat) {
            // Abaikan identifikasi yang dikeluarkan dari kuorum
            if (isset($stat->excluded_from_quorum) && $stat->excluded_from_quorum == 1) {
                Log::info('Skipping doubtful identification in agreement calculation', [
                    'identification_id' => $stat->identification_id,
                    'taxon_id' => $stat->taxon_id,
                    'user_id' => $stat->user_id,
                    'excluded_from_quorum' => $stat->excluded_from_quorum
                ]);
                continue;
            }
            
            $taxonId = $stat->taxon_id;
            if (!isset($taxonAgreements[$taxonId])) {
                $taxonAgreements[$taxonId] = 0;
            }
            
            // Tambahkan 1 untuk pengusul identifikasi itu sendiri
            $agreementCount = ($stat->agreement_count ?? 0) + 1; // +1 untuk menghitung pengusul
            
            // Fitur baru: jika ada taksa yang sama diusulkan oleh beberapa pengamat (bukan persetujuan langsung),
            // hitung mereka sebagai persetujuan implisit
            if (isset($taxaUsers[$taxonId]) && count($taxaUsers[$taxonId]) > 1) {
                // Jumlah pengusul taksa ini sekarang dihitung sebagai persetujuan
                // agreementCount sudah termasuk pengusul asli, jadi kita hanya tambahkan jumlah pengusul lain
                $agreementCount = count($taxaUsers[$taxonId]);
                
                Log::info('Implicit agreements for taxon', [
                    'taxonId' => $taxonId,
                    'users' => $taxaUsers[$taxonId],
                    'agreementCount' => $agreementCount
                ]);
            }
            
            $taxonAgreements[$taxonId] = $agreementCount;

            // Update max agreements dan taxon_id dengan persetujuan terbanyak
            if ($taxonAgreements[$taxonId] > $maxAgreements) {
                $maxAgreements = $taxonAgreements[$taxonId];
                $mostAgreedTaxonId = $taxonId;
            }
        }

        // Hitung jumlah taxa yang memiliki persetujuan
        $taxaWithAgreements = count(array_filter($taxonAgreements, function($count) {
            return $count > 0;
        }));
        
        Log::info('Initial taxa with agreements before potential auto-withdraw', [
            'taxaWithAgreements' => $taxaWithAgreements,
            'activeIdentifications' => $activeIdentifications
        ]);

        // Implementasi logika hierarki taksonomi baru - hanya jika ada identifikasi yang tidak dikecualikan
        if (!empty($taxonAgreements)) {
            $hierarchyResult = $this->processHierarchicalConfirmation($actualId, $taxonAgreements, $totalParticipants);
            $mostAgreedTaxonId = $hierarchyResult['taxon_id'];
            $maxAgreements = $hierarchyResult['max_agreements'];
            $taxaWithAgreements = $hierarchyResult['taxa_with_agreements'];
            $gradeHint = $hierarchyResult['grade_hint'] ?? null;
            
            Log::info('Hierarchical confirmation processed with non-doubtful identifications', [
                'taxonAgreements' => $taxonAgreements,
                'mostAgreedTaxonId' => $mostAgreedTaxonId,
                'maxAgreements' => $maxAgreements
            ]);
        } else {
            // Jika semua identifikasi ragu-ragu, tidak ada hierarchical processing
            Log::info('All identifications are doubtful, skipping hierarchical confirmation', [
                'actualId' => $actualId,
                'totalParticipants' => $totalParticipants
            ]);
        }
        
        Log::info('Hierarchical confirmation result', [
            'mostAgreedTaxonId' => $mostAgreedTaxonId,
            'maxAgreements' => $maxAgreements,
            'taxaWithAgreements' => $taxaWithAgreements
        ]);

        // Update checklist taxon jika ada yang memiliki persetujuan terbanyak dan bukan dari identifikasi ragu-ragu
        if ($mostAgreedTaxonId && !empty($taxonAgreements)) {
            Log::info('Updating checklist taxon with non-doubtful consensus', [
                'actualId' => $actualId,
                'mostAgreedTaxonId' => $mostAgreedTaxonId,
                'maxAgreements' => $maxAgreements
            ]);
            $this->updateChecklistTaxon($actualId, $mostAgreedTaxonId);
        } else {
            Log::info('Skipping checklist taxon update - no valid consensus or all doubtful', [
                'actualId' => $actualId,
                'mostAgreedTaxonId' => $mostAgreedTaxonId,
                'taxonAgreements' => $taxonAgreements
            ]);
        }

        // Dapatkan informasi tentang taxon yang paling banyak disetujui dan level taksonominya
        $taxonRank = '';
        if ($mostAgreedTaxonId) {
            $taxon = DB::table('taxas')
                ->where('id', $mostAgreedTaxonId)
                ->select('taxon_rank')
                ->first();
            
            if ($taxon) {
                $taxonRank = strtolower($taxon->taxon_rank);
            }
        }

        Log::info('Agreement stats with implicit agreements', [
            'maxAgreements' => $maxAgreements,
            'taxaWithAgreements' => $taxaWithAgreements,
            'taxonAgreements' => $taxonAgreements,
            'activeIdentifications' => $activeIdentifications,
            'totalParticipants' => $totalParticipants,
            'mostAgreedTaxonId' => $mostAgreedTaxonId,
            'taxonRank' => $taxonRank
        ]);

        // Cek kuorum persetujuan (setidaknya 2 orang atau 2/3 dari total pengusul)
        $hasQuorum = $maxAgreements >= 2 && $maxAgreements >= ($totalParticipants * 2 / 3);

        // Cek apakah ada identifikasi dengan force_conflict yang memaksa Low Quality ID
        $identificationTable = match($source) {
            'burungnesia' => 'burnes_identifications',
            'kupunesia' => 'kupnes_identifications', 
            default => 'taxa_identifications'
        };
        
        $checklistColumn = match($source) {
            'burungnesia' => 'burnes_checklist_id',
            'kupunesia' => 'kupnes_checklist_id',
            default => 'checklist_id'
        };
        
        $forceConflictQuery = DB::table($identificationTable)
            ->where($checklistColumn, $actualId)
            ->where('force_conflict', 1)
            ->where(function($query) {
                $query->where('is_withdrawn', false)
                      ->orWhereNull('is_withdrawn');
            });
            
        $hasForceConflict = $forceConflictQuery->exists();
        
        // Debug: tampilkan semua identifikasi untuk checklist ini
        $allIdentifications = DB::table($identificationTable)
            ->where($checklistColumn, $actualId)
            ->select('id', 'user_id', 'taxon_id', 'force_conflict', 'confidence_level', 'is_withdrawn')
            ->get();
            
        Log::info('Force conflict debug', [
            'checklistId' => $actualId,
            'table' => $identificationTable,
            'column' => $checklistColumn,
            'allIdentifications' => $allIdentifications,
            'hasForceConflict' => $hasForceConflict
        ]);

        Log::info('Grade determination debug', [
            'maxAgreements' => $maxAgreements,
            'totalParticipants' => $totalParticipants,
            'hasQuorum' => $hasQuorum,
            'taxaWithAgreements' => $taxaWithAgreements,
            'activeIdentifications' => $activeIdentifications,
            'taxonRank' => $taxonRank,
            'hasMedia' => $hasMedia,
            'hasLocation' => $hasLocation,
            'hasDate' => $hasDate,
            'quorum_threshold' => ($totalParticipants * 2 / 3),
            'hasForceConflict' => $hasForceConflict
        ]);

        // PERBAIKAN: Gunakan grade hint dari hierarchical consensus dengan validasi tambahan
        if ($gradeHint) {
            switch ($gradeHint) {
                case 'low_quality_id':
                    Log::info('Determined as Low Quality ID (from hierarchical consensus)', [
                        'gradeHint' => $gradeHint,
                        'taxaWithAgreements' => $taxaWithAgreements,
                        'hasForceConflict' => $hasForceConflict
                    ]);
                    return 'low quality ID';
                    
                case 'research_grade':
                    // PERBAIKAN: Validasi ketat untuk research grade dengan kuorum
                    if ($hasMedia && $hasLocation && $hasDate && 
                        $taxonRank && in_array($taxonRank, ['species', 'subspecies', 'variety', 'form']) &&
                        $hasQuorum && $taxaWithAgreements == 1) {
                        Log::info('Determined as Research Grade (from hierarchical consensus)', [
                            'gradeHint' => $gradeHint,
                            'taxonRank' => $taxonRank,
                            'hasQuorum' => $hasQuorum,
                            'taxaWithAgreements' => $taxaWithAgreements,
                            'hasMedia' => $hasMedia,
                            'hasLocation' => $hasLocation,
                            'hasDate' => $hasDate
                        ]);
                        return 'research grade';
                    } else {
                        // PERBAIKAN: Untuk kasus species-subspecies same lineage, tetap research grade meski tidak ada kuorum reguler
                        // Ini khusus untuk kasus tie antara species dan subspecies dalam lineage yang sama
                        if ($taxonRank && in_array($taxonRank, ['species', 'subspecies']) && 
                            $taxaWithAgreements == 1 && $hasMedia && $hasLocation && $hasDate) {
                            Log::info('Research grade maintained for species-subspecies hierarchical consensus', [
                                'gradeHint' => $gradeHint,
                                'taxonRank' => $taxonRank,
                                'hasQuorum' => $hasQuorum,
                                'taxaWithAgreements' => $taxaWithAgreements,
                                'reason' => 'Species-subspecies same lineage hierarchical consensus overrides quorum requirement'
                            ]);
                            return 'research grade';
                        }
                        
                        // Jika tidak memenuhi syarat research grade, turun ke confirmed id
                        Log::info('Research grade hint downgraded to Confirmed ID - missing requirements', [
                            'gradeHint' => $gradeHint,
                            'taxonRank' => $taxonRank,
                            'hasQuorum' => $hasQuorum,
                            'taxaWithAgreements' => $taxaWithAgreements,
                            'hasMedia' => $hasMedia,
                            'hasLocation' => $hasLocation,
                            'hasDate' => $hasDate
                        ]);
                        return 'confirmed id';
                    }
                    
                case 'confirmed_id':
                    // PERBAIKAN: Validasi untuk confirmed id dengan pengecekan tie
                    // Kasus 2 vs 2 dalam superfamily yang sama HARUS jadi needs ID
                    if ($taxaWithAgreements > 1) {
                        Log::info('Confirmed ID hint overridden to Needs ID - tie detected', [
                            'gradeHint' => $gradeHint,
                            'taxaWithAgreements' => $taxaWithAgreements,
                            'reason' => 'Multiple taxa with agreements (tie) should be needs ID regardless of hierarchical consensus'
                        ]);
                        return 'needs ID';
                    }
                    
                    if ($hasQuorum && $taxaWithAgreements == 1) {
                        Log::info('Determined as Confirmed ID (from hierarchical consensus)', [
                            'gradeHint' => $gradeHint,
                            'taxonRank' => $taxonRank,
                            'hasQuorum' => $hasQuorum,
                            'taxaWithAgreements' => $taxaWithAgreements
                        ]);
                        return 'confirmed id';
                    } else {
                        // Jika tidak ada kuorum atau ada konflik, turun ke needs id
                        Log::info('Confirmed ID hint downgraded to Needs ID - no quorum or conflict', [
                            'gradeHint' => $gradeHint,
                            'hasQuorum' => $hasQuorum,
                            'taxaWithAgreements' => $taxaWithAgreements
                        ]);
                        return 'needs ID';
                    }
                    
                case 'needs_id':
                    Log::info('Determined as Needs ID (from hierarchical consensus)', [
                        'gradeHint' => $gradeHint,
                        'taxonRank' => $taxonRank,
                        'taxaWithAgreements' => $taxaWithAgreements
                    ]);
                    return 'needs ID';
            }
        }

        // Fallback ke logika lama jika tidak ada grade hint
        
        // PERBAIKAN: Low Quality ID dengan logika yang lebih ketat
        // - Sudah mencapai kuorum (min 2 orang atau ⅔ dari total pengusul)
        // - Tetapi masih ada pendapat/usulan lain yang berbeda (konflik identifikasi)
        // - ATAU ada force_conflict dari modal konfirmasi
        // - ATAU ada konflik signifikan dalam identifikasi
        if ($activeIdentifications > 0 && $hasQuorum && 
            ($taxaWithAgreements > 1 || $hasForceConflict || $this->hasSignificantConflict($taxonAgreements, $totalParticipants))) {
            Log::info('Determined as Low Quality ID (fallback logic)', [
                'conditions' => [
                    'activeIdentifications' => $activeIdentifications,
                    'taxonRank' => $taxonRank,
                    'hasQuorum' => $hasQuorum,
                    'taxaWithAgreements' => $taxaWithAgreements,
                    'maxAgreements' => $maxAgreements,
                    'hasForceConflict' => $hasForceConflict,
                    'hasSignificantConflict' => $this->hasSignificantConflict($taxonAgreements, $totalParticipants)
                ]
            ]);
            return 'low quality ID';
        }

        // PERBAIKAN: Research Grade dengan validasi yang lebih ketat
        // - Taksa level species (atau subspecies, variety, form)
        // - Sudah mencapai kuorum (min 2 orang dan 2/3 dari total pengusul)
        // - Tidak ada pendapat lain yang aktif (konsensus penuh)
        // - Memiliki media, lokasi, dan tanggal
        // - Tidak ada konflik signifikan
        if ($taxonRank && in_array($taxonRank, ['species', 'subspecies', 'variety', 'form']) &&
            $hasQuorum && $taxaWithAgreements == 1 && $hasMedia && $hasLocation && $hasDate &&
            !$this->hasSignificantConflict($taxonAgreements, $totalParticipants) && !$hasForceConflict) {
            Log::info('Determined as Research Grade (fallback logic)', [
                'conditions' => [
                    'taxonRank' => $taxonRank,
                    'hasQuorum' => $hasQuorum,
                    'taxaWithAgreements' => $taxaWithAgreements,
                    'hasMedia' => $hasMedia,
                    'hasLocation' => $hasLocation,
                    'hasDate' => $hasDate,
                    'hasSignificantConflict' => $this->hasSignificantConflict($taxonAgreements, $totalParticipants),
                    'hasForceConflict' => $hasForceConflict
                ]
            ]);
            return 'research grade';
        }

        // PERBAIKAN: Confirmed ID dengan validasi konflik
        // - Taksa level genus ke atas (termasuk subfamily, tribe, subtribe, dll)
        // - Sudah mencapai kuorum (min 2 orang dan 2/3 dari total pengusul)
        // - Tidak ada pendapat lain yang aktif (konsensus)
        // - Tidak ada konflik signifikan
        if ($taxonRank && in_array($taxonRank, [
            'genus', 'subgenus', 'tribe', 'subtribe', 'supertribe', 
            'subfamily', 'family', 'superfamily', 
            'infraorder', 'suborder', 'order', 'superorder',
            'infraclass', 'subclass', 'class', 'superclass',
            'subphylum', 'phylum', 'superphylum',
            'subkingdom', 'kingdom', 'superkingdom'
        ]) && $hasQuorum && $taxaWithAgreements == 1 && 
        !$this->hasSignificantConflict($taxonAgreements, $totalParticipants) && !$hasForceConflict) {
            Log::info('Determined as Confirmed ID (fallback logic)', [
                'conditions' => [
                    'taxonRank' => $taxonRank,
                    'hasQuorum' => $hasQuorum,
                    'taxaWithAgreements' => $taxaWithAgreements,
                    'hasSignificantConflict' => $this->hasSignificantConflict($taxonAgreements, $totalParticipants),
                    'hasForceConflict' => $hasForceConflict
                ]
            ]);
            return 'confirmed id';
        }

        // PERBAIKAN: Kasus tie atau konflik tanpa konsensus harus needs ID
        // - Skenario 2 vs 2 (Tachyspiza badia vs Gallus) langsung needs ID
        // - Tidak ada konsensus yang jelas (taxaWithAgreements > 1)
        if ($hasMedia && $hasLocation && $hasDate && $taxaWithAgreements > 1) {
            Log::info('Determined as Needs ID - tie or multiple taxa with agreements', [
                'conditions' => [
                    'taxaWithAgreements' => $taxaWithAgreements,
                    'hasQuorum' => $hasQuorum,
                    'maxAgreements' => $maxAgreements,
                    'totalParticipants' => $totalParticipants,
                    'reason' => 'Multiple taxa with agreements (tie/conflict)'
                ]
            ]);
            return 'needs ID';
        }

        // Needs ID:
        // - Memiliki media, lokasi, dan tanggal
        // - Belum mencapai kuorum 2/3 dari total pengusul
        // - Tidak ada identifikasi dominan yang mencapai persetujuan cukup
        if ($hasMedia && $hasLocation && $hasDate &&
            (!$hasQuorum || $activeIdentifications == 0)) {
            Log::info('Determined as Needs ID', [
                'conditions' => [
                    'hasQuorum' => $hasQuorum,
                    'activeIdentifications' => $activeIdentifications,
                    'maxAgreements' => $maxAgreements,
                    'totalParticipants' => $totalParticipants
                ]
            ]);
            return 'needs ID';
        }

        // Cek apakah ada identifikasi dengan force_conflict yang memaksa Low Quality ID
        $identificationTable = match($source) {
            'burungnesia' => 'burnes_identifications',
            'kupunesia' => 'kupnes_identifications', 
            default => 'taxa_identifications'
        };
        
        $checklistColumn = match($source) {
            'burungnesia' => 'burnes_checklist_id',
            'kupunesia' => 'kupnes_checklist_id',
            default => 'checklist_id'
        };
        
        $hasForceConflict = DB::table($identificationTable)
            ->where($checklistColumn, $actualId)
            ->where('force_conflict', 1)
            ->where(function($query) {
                $query->where('is_withdrawn', false)
                      ->orWhereNull('is_withdrawn');
            })
            ->exists();
            
        Log::info('Force conflict check', [
            'table' => $identificationTable,
            'column' => $checklistColumn,
            'checklistId' => $actualId,
            'hasForceConflict' => $hasForceConflict
        ]);

        // Low Quality ID:
        // - Sudah mencapai kuorum (min 2 orang atau ⅔ dari total pengusul)
        // - Tetapi masih ada pendapat/usulan lain yang berbeda (konflik identifikasi)
        // - ATAU ada force_conflict dari modal konfirmasi
        if ($activeIdentifications > 0 && $hasQuorum && ($taxaWithAgreements > 1 || $hasForceConflict)) {
            Log::info('Determined as Low Quality ID', [
                'conditions' => [
                    'activeIdentifications' => $activeIdentifications,
                    'taxonRank' => $taxonRank,
                    'hasQuorum' => $hasQuorum,
                    'taxaWithAgreements' => $taxaWithAgreements,
                    'maxAgreements' => $maxAgreements,
                    'hasForceConflict' => $hasForceConflict
                ]
            ]);
            return 'low quality ID';
        }

        // Default: Casual
        Log::info('Determined as Casual', [
            'reason' => 'No conditions met for higher grades',
            'final_conditions' => [
                'activeIdentifications' => $activeIdentifications,
                'hasQuorum' => $hasQuorum,
                'taxaWithAgreements' => $taxaWithAgreements,
                'taxonRank' => $taxonRank,
                'hasMedia' => $hasMedia,
                'hasLocation' => $hasLocation,
                'hasDate' => $hasDate
            ]
        ]);
        return 'casual';
    }

    // Method untuk mendapatkan status quality assessment
    public function getQualityAssessment($id)
    {
        try {
            $source = request()->query('source', $this->determineSource($id));
            $actualId = $this->getActualId($id, $source);
            $config = $this->getAssessmentConfig($source);

            Log::info('Starting getQualityAssessment', [
                'id' => $id,
                'source' => $source,
                'actualId' => $actualId,
                'config' => $config
            ]);

            // Get fauna/taxa ID first
            $faunaId = null;
            if ($source === 'burungnesia') {
                $faunaId = DB::table('fobi_checklist_faunasv1')
                    ->where('checklist_id', $actualId)
                    ->value('fauna_id');
            } elseif ($source === 'kupunesia') {
                $faunaId = DB::table('fobi_checklist_faunasv2')
                    ->where('checklist_id', $actualId)
                    ->value('fauna_id');
            } else {
                $faunaId = DB::table('fobi_checklist_taxas')
                    ->where('id', $actualId)
                    ->select([
                        DB::raw('COALESCE(taxa_id, original_taxa_id) as taxa_id')
                    ])
                    ->value('taxa_id');
            }

            Log::info('Fauna/Taxa ID retrieved', [
                'faunaId' => $faunaId,
                'source' => $source
            ]);

            // Get existing assessment
            $existingAssessment = DB::table($config['table'])
                ->where($config['id_column'], $actualId)
                ->get(); // Ambil semua untuk logging

            Log::info('Existing assessments found', [
                'count' => $existingAssessment->count(),
                'assessments' => $existingAssessment->toArray()
            ]);

            $assessment = DB::table($config['table'])
                ->where($config['id_column'], $actualId)
                ->when($faunaId, function($query) use ($config, $faunaId) {
                    return $query->where(function($q) use ($config, $faunaId) {
                        $q->where($config['fauna_column'], $faunaId)
                          ->orWhereNull($config['fauna_column']);
                    });
                })
                ->orderBy('id', 'desc')
                ->first();

            Log::info('Selected assessment', [
                'assessment' => $assessment,
                'query_conditions' => [
                    'id_column' => $actualId,
                    'fauna_column' => $faunaId
                ]
            ]);

            if (!$assessment) {
                Log::info('No assessment found, creating new one');

                // Buat query untuk menghitung agreement berdasarkan source
                $statsQuery = DB::table('taxa_identifications');
                
                if ($source === 'burungnesia') {
                    $statsQuery->where('burnes_checklist_id', $actualId);
                } elseif ($source === 'kupunesia') {
                    $statsQuery->where('kupnes_checklist_id', $actualId);
                } else {
                    $statsQuery->where('checklist_id', $actualId);
                }
                
                $stats = $statsQuery->selectRaw('COUNT(CASE WHEN agrees_with_id IS NOT NULL THEN 1 END) as agreement_count')
                    ->first();

                $hasMedia = $this->checkHasMedia($actualId, $source);
                $hasLocation = $this->checkHasLocation($actualId, $source);
                $hasDate = $this->checkHasDate($actualId, $source);

                $grade = $this->determineGrade(
                    0,
                    $stats->agreement_count ?? 0,
                    $hasMedia,
                    $hasLocation,
                    $hasDate,
                    $actualId
                );

                Log::info('Calculated assessment data', [
                    'stats' => $stats,
                    'hasMedia' => $hasMedia,
                    'hasLocation' => $hasLocation,
                    'hasDate' => $hasDate,
                    'grade' => $grade
                ]);

                $conditions = [
                    $config['id_column'] => $actualId
                ];

                if ($faunaId) {
                    $conditions[$config['fauna_column']] = $faunaId;
                }

                $assessmentData = [
                    'grade' => $grade,
                    'has_media' => $hasMedia,
                    'has_location' => $hasLocation,
                    'has_date' => $hasDate,
                    'agreement_count' => $stats->agreement_count ?? 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ];

                if ($source === 'fobi' && $faunaId) {
                    $assessmentData[$config['fauna_column']] = $faunaId;
                }

                Log::info('Attempting to update existing assessment', [
                    'conditions' => $conditions,
                    'data' => $assessmentData
                ]);

                // Coba update dulu yang existing
                $updated = DB::table($config['table'])
                    ->where($config['id_column'], $actualId)
                    ->update($assessmentData);

                if (!$updated) {
                    Log::info('No existing record updated, inserting new one');
                    // Jika tidak ada yang terupdate, baru insert baru
                    DB::table($config['table'])->insert(array_merge($conditions, $assessmentData));
                }

                $assessment = DB::table($config['table'])
                    ->where($conditions)
                    ->first();
            }

            // Hitung persentase keyakinan
            $confidencePercentage = 0;
            $confidenceTaxonName = null;
            if ($assessment) {
                // Ambil semua identifikasi aktif (tidak withdrawn dan tidak excluded_from_quorum) untuk menghitung total participants
                $activeIdentificationsQuery = DB::table('taxa_identifications')
                    ->where(function($query) use ($actualId, $source) {
                        if ($source === 'burungnesia') {
                            $query->where('burnes_checklist_id', $actualId);
                        } elseif ($source === 'kupunesia') {
                            $query->where('kupnes_checklist_id', $actualId);
                        } else {
                            $query->where('checklist_id', $actualId);
                        }
                    })
                    ->where(function($query) {
                        $query->whereNull('is_withdrawn')
                              ->orWhere('is_withdrawn', false);
                    })
                    ->where(function($query) {
                        $query->where('excluded_from_quorum', 0)
                              ->orWhereNull('excluded_from_quorum');
                    })
                    ->whereNull('deleted_at');
                
                $activeIdentifications = $activeIdentificationsQuery->get();
                
                $totalParticipants = $activeIdentifications->count();
                
                // Hitung agreement untuk current taxon (hanya yang tidak excluded_from_quorum)
                $currentTaxonAgreements = $activeIdentifications->where('taxon_id', $assessment->taxon_id ?? 0)->count();
                
                if ($totalParticipants > 0) {
                    $confidenceData = $this->calculateConfidencePercentage(
                        $currentTaxonAgreements, 
                        $totalParticipants, 
                        $assessment->taxon_id ?? null,
                        $actualId
                    );
                    $confidencePercentage = $confidenceData['percentage'];
                    $confidenceTaxonName = $confidenceData['taxon_name'];
                }
            }

            // Format response
            $formattedAssessment = [
                'id' => $assessment->id ?? null,
                'grade' => $assessment->grade ?? 'casual',
                'has_media' => $assessment->has_media ?? false,
                'has_location' => $assessment->has_location ?? false,
                'has_date' => $assessment->has_date ?? false,
                'agreement_count' => (string)($assessment->agreement_count ?? 0),
                'confidence_percentage' => $confidencePercentage,
                'confidence_taxon_name' => $confidenceTaxonName ?? null
            ];

            Log::info('Returning formatted assessment', [
                'formattedAssessment' => $formattedAssessment
            ]);

            return response()->json([
                'success' => true,
                'data' => $formattedAssessment
            ]);

        } catch (\Exception $e) {
            Log::error('Error in getQualityAssessment', [
                'id' => $id,
                'source' => $source ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil quality assessment'
            ], 500);
        }
    }

    private function checkHasLocation($id, $source)
    {
        $table = $this->getChecklistTable($source);
        return DB::table($table)
            ->where('id', $id)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->exists();
    }

    private function checkHasDate($id, $source)
    {
        $table = $this->getChecklistTable($source);

        // Sesuaikan kolom berdasarkan source
        $dateColumn = match($source) {
            'fobi' => 'created_at',  // Gunakan created_at untuk FOBI
            'burungnesia', 'kupunesia' => 'tgl_pengamatan'
        };

        return DB::table($table)
            ->where('id', $id)
            ->whereNotNull($dateColumn)
            ->exists();
    }

    // Method untuk batch update quality assessments
    public function batchUpdateQualityAssessments(Request $request)
    {
        try {
            $request->validate([
                'observation_ids' => 'required|array',
                'observation_ids.*' => 'required|string'
            ]);

            $results = [];
            foreach ($request->observation_ids as $id) {
                $source = $this->determineSource($id);
                try {
                    $grade = $this->updateQualityAssessment($id, $source);
                    $results[$id] = [
                        'success' => true,
                        'grade' => $grade
                    ];
                } catch (\Exception $e) {
                    $results[$id] = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('Error in batchUpdateQualityAssessments: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui quality assessments'
            ], 500);
        }
    }

    // Method untuk menentukan sumber data berdasarkan ID
    private function determineSource($id)
    {
        if (str_starts_with($id, 'BN')) return 'burungnesia';
        if (str_starts_with($id, 'KP')) return 'kupunesia';
        return 'fobi';
    }

    // Method untuk mendapatkan statistik quality assessment
    public function getQualityStats(Request $request)
    {
        try {
            $source = $request->input('source', 'all');

            $stats = [];
            $sources = $source === 'all' ? ['fobi', 'burungnesia', 'kupunesia'] : [$source];

            foreach ($sources as $src) {
                $table = match($src) {
                    'fobi' => 'taxa_quality_assessments',
                    'burungnesia' => 'data_quality_assessments',
                    'kupunesia' => 'data_quality_assessments_kupnes'
                };

                $stats[$src] = DB::table($table)
                    ->select(
                        DB::raw('CASE WHEN COUNT(*) = 0 THEN NULL ELSE COUNT(*) END as total'),
                        DB::raw("CASE WHEN COUNT(CASE WHEN grade = 'research grade' THEN 1 END) = 0 THEN NULL ELSE COUNT(CASE WHEN grade = 'research grade' THEN 1 END) END as research_grade"),
                        DB::raw("CASE WHEN COUNT(CASE WHEN grade = 'confirmed id' THEN 1 END) = 0 THEN NULL ELSE COUNT(CASE WHEN grade = 'confirmed id' THEN 1 END) END as confirmed_id"),
                        DB::raw("CASE WHEN COUNT(CASE WHEN grade = 'needs ID' THEN 1 END) = 0 THEN NULL ELSE COUNT(CASE WHEN grade = 'needs ID' THEN 1 END) END as needs_id"),
                        DB::raw("CASE WHEN COUNT(CASE WHEN grade = 'low quality ID' THEN 1 END) = 0 THEN NULL ELSE COUNT(CASE WHEN grade = 'low quality ID' THEN 1 END) END as low_quality"),
                        DB::raw("CASE WHEN COUNT(CASE WHEN grade = 'casual' THEN 1 END) = 0 THEN NULL ELSE COUNT(CASE WHEN grade = 'casual' THEN 1 END) END as casual")
                    )
                    ->first();
            }

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error in getQualityStats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil statistik quality assessment'
            ], 500);
        }
    }

    public function assessQuality($id)
    {
        try {
            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id, $source);

            // Tentukan tabel dan kolom berdasarkan sumber
            $checklistTable = $this->getChecklistTable($source);
            $assessmentTable = $this->getAssessmentTable($source);
            $assessmentConfig = $this->getAssessmentConfig($source);
            $idColumn = $assessmentConfig['id_column'];
            $faunaColumn = $assessmentConfig['fauna_column'];

            // Ambil data checklist
            $checklist = DB::table($checklistTable)
                ->where('id', $actualId)
                ->first();

            if (!$checklist) {
                return response()->json([
                    'success' => false,
                    'message' => 'Checklist tidak ditemukan'
                ], 404);
            }

            // Ambil atau buat assessment
            $assessment = DB::table($assessmentTable)
                ->where($idColumn, $actualId)
                ->first();

            if (!$assessment) {
                // Pastikan kita mendapatkan fauna/taxa_id yang benar
                $faunaOrTaxaId = null;
                if ($source === 'fobi') {
                    $faunaOrTaxaId = DB::table('fobi_checklist_taxas')
                        ->where('id', $actualId)
                        ->value(DB::raw('COALESCE(taxa_id, original_taxa_id)'));
                }

                // Buat assessment baru dengan ID yang benar
                $assessment = [
                    $idColumn => $actualId,
                    $faunaColumn => $faunaOrTaxaId, // Gunakan fauna/taxa_id yang sudah diambil
                    'grade' => 'needs ID',
                    'has_date' => $this->checkHasDate($actualId, $source),
                    'has_location' => !empty($checklist->latitude) && !empty($checklist->longitude),
                    'has_media' => $this->checkHasMedia($actualId, $source),
                    'agreement_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ];

                // Gunakan transaction untuk memastikan atomicity
                DB::beginTransaction();
                try {
                    // Gunakan updateOrInsert untuk mencegah duplikasi
                    DB::table($assessmentTable)->updateOrInsert(
                        [$idColumn => $actualId],
                        $assessment
                    );

                    // Ambil assessment yang baru dibuat
                    $assessment = DB::table($assessmentTable)
                        ->where($idColumn, $actualId)
                        ->first();

                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $assessment
            ]);

        } catch (\Exception $e) {
            Log::error('Error in assessQuality: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil quality assessment'
            ], 500);
        }
    }

    public function updateQualityAssessment($id, $source)
    {
        try {
            $actualId = $this->getActualId($id, $source);
            
            // Perbarui status excluded_from_quorum berdasarkan persetujuan
            $this->updateExcludedFromQuorumStatus($actualId, $source);
            
            // Cek apakah ada media
            $hasMedia = $this->checkHasMedia($actualId, $source);
            
            // Cek apakah ada lokasi
            $hasLocation = $this->checkHasLocation($actualId, $source);
            
            // Cek apakah ada tanggal
            $hasDate = $this->checkHasDate($actualId, $source);
            
            // Ambil identifikasi dengan kuorum
            $identificationWithQuorum = $this->getIdentificationWithQuorum($actualId, $source);
            
            // Hitung total identifikasi dan agreement count
            $identificationStats = DB::table('taxa_identifications')
                ->where(function($query) use ($actualId) {
                    $query->where('checklist_id', $actualId)
                          ->orWhere('burnes_checklist_id', $actualId)
                          ->orWhere('kupnes_checklist_id', $actualId);
                })
                ->whereNull('deleted_at')
                ->where(function($query) {
                    $query->where('is_withdrawn', false)
                          ->orWhereNull('is_withdrawn');
                })
                ->selectRaw('COUNT(*) as total_identifications, COUNT(CASE WHEN agrees_with_id IS NOT NULL THEN 1 END) as agreement_count')
                ->first();
                
            $totalIdentifications = $identificationStats->total_identifications ?? 0;
            $agreementCount = $identificationStats->agreement_count ?? 0;
            
            // Tentukan grade berdasarkan kriteria
            $grade = $this->determineGrade($totalIdentifications, $agreementCount, $hasMedia, $hasLocation, $hasDate, $actualId, $source);
            
            // Perbarui data quality assessment
            $assessmentTable = $this->getAssessmentTable($source);
            $assessmentConfig = $this->getAssessmentConfig($source);
            $idColumn = $assessmentConfig['id_column']; // Gunakan id_column dari config
            
            // Cek apakah sudah ada assessment
            $existingAssessment = DB::table($assessmentTable)
                ->where($idColumn, $actualId)
                ->first();
                
            // Buat data untuk update/insert sesuai dengan struktur tabel yang benar
            $assessmentData = [
                'grade' => $grade, // Grade sekarang adalah string langsung
                'has_media' => $hasMedia,
                'has_location' => $hasLocation,
                'has_date' => $hasDate,
                'agreement_count' => $agreementCount,
                'updated_at' => now()
            ];
            
            // Perbarui data takson di checklist jika ada identifikasi dengan kuorum
            if ($identificationWithQuorum) {
                $taxon = DB::table('taxas')->where('id', $identificationWithQuorum->taxon_id)->first();
                
                if ($taxon) {
                    // Update data takson di checklist
                    $this->updateChecklistTaxon($actualId, $source);
                    
                    // Simpan informasi takson untuk dikembalikan dalam hasil
                    $taxonInfo = [
                        'taxon_id' => $taxon->id,
                        'taxon_rank' => $taxon->taxon_rank,
                        'scientific_name' => $taxon->scientific_name
                    ];
                }
            } else {
                // Ambil data untuk hierarchical consensus
                $taxonAgreements = [];
                $totalParticipants = 0;
                
                $identifications = DB::table('taxa_identifications as ti')
                    ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                    ->where(function($query) use ($source, $actualId) {
                        if ($source === 'burungnesia') {
                            $query->where('ti.burnes_checklist_id', $actualId);
                        } elseif ($source === 'kupunesia') {
                            $query->where('ti.kupnes_checklist_id', $actualId);
                        } else {
                            $query->where('ti.checklist_id', $actualId);
                        }
                    })
                    ->where(function($query) {
                        $query->where('ti.is_withdrawn', false)
                              ->orWhereNull('ti.is_withdrawn');
                    })
                    ->whereNull('ti.deleted_at')
                    ->select('ti.taxon_id', 't.scientific_name', 't.taxon_rank', 'ti.user_id', 't.superfamily', 't.family', 't.order')
                    ->get();

                foreach ($identifications as $ident) {
                    if (!isset($taxonAgreements[$ident->taxon_id])) {
                        $taxonAgreements[$ident->taxon_id] = 0;
                    }
                    $taxonAgreements[$ident->taxon_id]++;
                }
                
                $totalParticipants = $identifications->pluck('user_id')->unique()->count();
                
                // Cek apakah ada hierarchical consensus yang sudah menentukan taxa
                $hierarchicalResult = $this->processHierarchicalConfirmation($actualId, $taxonAgreements, $totalParticipants);
                
                if ($hierarchicalResult && isset($hierarchicalResult['taxon_id']) && $hierarchicalResult['taxon_id']) {
                    // Jika ada hierarchical consensus, gunakan hasil tersebut
                    $hierarchicalTaxa = DB::table('taxas')->find($hierarchicalResult['taxon_id']);
                    if ($hierarchicalTaxa) {
                        Log::info('Using hierarchical consensus instead of common ancestor', [
                            'checklist_id' => $actualId,
                            'hierarchical_taxon_id' => $hierarchicalResult['taxon_id'],
                            'hierarchical_taxon_name' => $hierarchicalTaxa->scientific_name
                        ]);
                        
                        $taxonInfo = [
                            'taxon_id' => $hierarchicalTaxa->id,
                            'taxon_rank' => $hierarchicalTaxa->taxon_rank,
                            'scientific_name' => $hierarchicalTaxa->scientific_name
                        ];
                    }
                } else {
                    // Jika tidak ada hierarchical consensus, baru cari common ancestor
                    $commonAncestor = $this->findCommonAncestorTaxon($actualId, $source);
                    
                    if ($commonAncestor) {
                        // Update data takson di checklist dengan common ancestor
                        $this->updateChecklistWithCommonAncestor($actualId, $commonAncestor, $source);
                        
                        // Simpan informasi takson untuk dikembalikan dalam hasil
                        $taxonInfo = [
                            'taxon_id' => $commonAncestor->id,
                            'taxon_rank' => $commonAncestor->taxon_rank,
                            'scientific_name' => $commonAncestor->scientific_name
                        ];
                    }
                }
            }
            
            // Update atau insert assessment
            if ($existingAssessment) {
                DB::table($assessmentTable)
                    ->where($idColumn, $actualId)
                    ->update($assessmentData);
            } else {
                $assessmentData['created_at'] = now();
                $assessmentData[$idColumn] = $actualId;
                
                DB::table($assessmentTable)->insert($assessmentData);
            }
            
            // Kembalikan hasil dalam format yang konsisten untuk digunakan di tempat lain
            $result = [
                'grade' => $grade,
                'totalIdentifications' => $totalIdentifications,
                'agreementCount' => $agreementCount,
                'hasMedia' => $hasMedia,
                'hasLocation' => $hasLocation,
                'hasDate' => $hasDate
            ];
            
            // Tambahkan informasi takson jika ada
            if (isset($taxonInfo)) {
                $result['taxonInfo'] = $taxonInfo;
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('Error dalam updateQualityAssessment', [
                'id' => $id,
                'source' => $source,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    private function getFallbackFaunaId($actualId, $source)
    {
        try {
            if ($source === 'burungnesia') {
                $checklist = DB::table('fobi_checklist_faunasv1')
                    ->where('checklist_id', $actualId)
                    ->first();
                return $checklist->fauna_id ?? null;
            } elseif ($source === 'kupunesia') {
                $checklist = DB::table('fobi_checklist_faunasv2')
                    ->where('checklist_id', $actualId)
                    ->first();
                return $checklist->fauna_id ?? null;
            }
            return null;
        } catch (\Exception $e) {
            Log::error('Error getting fallback fauna_id: ' . $e->getMessage());
            return null;
        }
    }

    private function updateChecklistTaxon($id, $source)
    {
        try {
            $actualId = $this->getActualId($id, $source);
            $user = auth()->user();

            // Base query untuk identifikasi dengan persetujuan terbanyak
            $query = DB::table('taxa_identifications as ti')
                ->select(
                    'ti.taxon_id',
                    'ti.burnes_fauna_id',
                    'ti.kupnes_fauna_id',
                    't.scientific_name',
                    't.taxon_key',
                    't.accepted_scientific_name',
                    't.taxon_rank',
                    't.taxonomic_status',
                    't.domain',
                    't.cname_domain',
                    't.superkingdom',
                    't.kingdom',
                    't.phylum',
                    't.class',
                    't.order',
                    't.family',
                    't.genus',
                    't.species',
                    't.subspecies',
                    't.variety',
                    't.form',
                    't.subform',
                    't.cname_species',
                    't.iucn_red_list_category',
                    't.status_kepunahan',
                    DB::raw('COUNT(ti2.id) as agreement_count')
                )
                ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                ->leftJoin('taxa_identifications as ti2', 'ti.id', '=', 'ti2.agrees_with_id')
                ->where(function($query) use ($source, $actualId) {
                    if ($source === 'burungnesia') {
                        $query->where('ti.burnes_checklist_id', $actualId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('ti.kupnes_checklist_id', $actualId);
                    } else {
                        $query->where('ti.checklist_id', $actualId);
                    }
                })
                ->where(function($query) {
                    $query->where('ti.is_withdrawn', false)
                          ->orWhereNull('ti.is_withdrawn');
                })
                ->groupBy(
                    'ti.taxon_id',
                    'ti.burnes_fauna_id',
                    'ti.kupnes_fauna_id',
                    't.scientific_name',
                    't.taxon_key',
                    't.accepted_scientific_name',
                    't.taxon_rank',
                    't.taxonomic_status',
                    't.domain',
                    't.cname_domain',
                    't.superkingdom',
                    't.kingdom',
                    't.phylum',
                    't.class',
                    't.order',
                    't.family',
                    't.genus',
                    't.species',
                    't.subspecies',
                    't.variety',
                    't.form',
                    't.subform',
                    't.cname_species',
                    't.iucn_red_list_category',
                    't.status_kepunahan'
                )
                ->orderBy('agreement_count', 'desc')
                ->first();

            Log::info('Most agreed identification', [
                'identification' => $query,
                'source' => $source
            ]);

            if ($query) {
                if ($source === 'burungnesia') {
                    $currentFauna = DB::table('fobi_checklist_faunasv1')
                        ->where('checklist_id', $actualId)
                        ->first();

                    if ($currentFauna && $currentFauna->fauna_id != $query->burnes_fauna_id) {
                        // Ambil data taxa lengkap
                        $currentTaxa = DB::table('taxas')->find($currentFauna->fauna_id);
                        $newTaxa = DB::table('taxas')->find($query->taxon_id);

                        if ($currentTaxa && $newTaxa) {
                            $this->createTaxaIdentificationHistory(
                                $actualId,
                                $query->taxon_id,
                                $currentFauna->fauna_id,
                                $user->id,
                                $newTaxa,
                                $currentTaxa
                            );
                        }

                        DB::table('fobi_checklist_faunasv1')
                            ->where('checklist_id', $actualId)
                            ->update([
                                'fauna_id' => $query->burnes_fauna_id,
                                'updated_at' => now()
                            ]);

                        Log::info('Updated Burungnesia fauna', [
                            'checklist_id' => $actualId,
                            'fauna_id' => $query->burnes_fauna_id
                        ]);
                    }

                } elseif ($source === 'kupunesia') {
                    $currentFauna = DB::table('fobi_checklist_faunasv2')
                        ->where('checklist_id', $actualId)
                        ->first();

                    if ($currentFauna && $currentFauna->fauna_id != $query->kupnes_fauna_id) {
                        // Ambil data taxa lengkap
                        $currentTaxa = DB::table('taxas')->find($currentFauna->fauna_id);
                        $newTaxa = DB::table('taxas')->find($query->taxon_id);

                        if ($currentTaxa && $newTaxa) {
                            $this->createTaxaIdentificationHistory(
                                $actualId,
                                $query->taxon_id,
                                $currentFauna->fauna_id,
                                $user->id,
                                $newTaxa,
                                $currentTaxa
                            );
                        }

                        DB::table('fobi_checklist_faunasv2')
                            ->where('checklist_id', $actualId)
                            ->update([
                                'fauna_id' => $query->kupnes_fauna_id,
                                'updated_at' => now()
                            ]);

                        Log::info('Updated Kupunesia fauna', [
                            'checklist_id' => $actualId,
                            'fauna_id' => $query->kupnes_fauna_id
                        ]);
                    }

                } else {
                    $currentChecklist = DB::table('fobi_checklist_taxas')
                        ->where('id', $actualId)
                        ->first();

                    // Cek apakah ada hierarchical consensus yang baru saja ditetapkan
                    $recentHierarchicalUpdate = DB::table('fobi_checklist_taxas')
                        ->where('id', $actualId)
                        ->where('updated_at', '>=', now()->subMinutes(5)) // Update dalam 5 menit terakhir
                        ->exists();

                    // Cek apakah ini adalah situasi withdrawal identification
                    $activeIdentificationsCount = DB::table('taxa_identifications')
                        ->where('checklist_id', $actualId)
                        ->whereNull('is_withdrawn')
                        ->count();

                    // Cek apakah current taxa adalah hasil hierarchical consensus
                    $isHierarchicalConsensus = false;
                    if ($currentChecklist && $query) {
                        $isHierarchicalConsensus = DB::table('taxas as current')
                            ->join('taxas as most_agreed', function($join) use ($query) {
                                $join->where('most_agreed.id', '=', $query->taxon_id);
                            })
                            ->where('current.id', $currentChecklist->taxa_id)
                            ->whereColumn('current.genus', 'most_agreed.genus')
                            ->where('current.taxon_rank', 'GENUS')
                            ->where('most_agreed.taxon_rank', 'SPECIES')
                            ->exists();
                    }

                    // Hitung total participants dari identifikasi aktif
                    $totalParticipants = DB::table('taxa_identifications')
                        ->where('checklist_id', $actualId)
                        ->where(function($query) {
                            $query->where('is_withdrawn', false)
                                  ->orWhereNull('is_withdrawn');
                        })
                        ->whereNull('deleted_at')
                        ->distinct('user_id')
                        ->count('user_id');

                    // Cek apakah most agreed taxa sudah mencapai kuorum yang benar
                    $mostAgreedHasQuorum = false;
                    $mostAgreedAgreements = 0;
                    $quorumThreshold = 0;
                    if ($query) {
                        $mostAgreedTaxa = DB::table('taxas')->find($query->taxon_id);
                        if ($mostAgreedTaxa && $mostAgreedTaxa->taxon_rank === 'SPECIES') {
                            // Hitung agreement untuk most agreed taxa berdasarkan jumlah identifikasi yang sama
                            $mostAgreedAgreements = DB::table('taxa_identifications')
                                ->where('checklist_id', $actualId)
                                ->where('taxon_id', $query->taxon_id)
                                ->whereNull('is_withdrawn')
                                ->count();
                            
                            // Gunakan kuorum yang benar berdasarkan total participants
                            $quorumThreshold = max(2, ceil($totalParticipants * 2/3));
                            $mostAgreedHasQuorum = $mostAgreedAgreements >= $quorumThreshold;
                        }
                    }

                    // Ambil data taxon agreements untuk hierarchical consensus
                    $taxonAgreements = [];
                    $identifications = DB::table('taxa_identifications as ti')
                        ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                        ->where(function($query) use ($source, $actualId) {
                            if ($source === 'burungnesia') {
                                $query->where('ti.burnes_checklist_id', $actualId);
                            } elseif ($source === 'kupunesia') {
                                $query->where('ti.kupnes_checklist_id', $actualId);
                            } else {
                                $query->where('ti.checklist_id', $actualId);
                            }
                        })
                        ->where(function($query) {
                            $query->where('ti.is_withdrawn', false)
                                  ->orWhereNull('ti.is_withdrawn');
                        })
                        ->whereNull('ti.deleted_at')
                        ->select('ti.taxon_id', 't.scientific_name', 't.taxon_rank', 't.superfamily', 't.family', 't.order')
                        ->get();

                    foreach ($identifications as $ident) {
                        if (!isset($taxonAgreements[$ident->taxon_id])) {
                            $taxonAgreements[$ident->taxon_id] = 0;
                        }
                        $taxonAgreements[$ident->taxon_id]++;
                    }

                    // Cek apakah ada hierarchical consensus yang harus diterapkan
                    $hierarchicalResult = null;
                    if (!empty($taxonAgreements)) {
                        $hierarchicalResult = $this->processHierarchicalConfirmation($actualId, $taxonAgreements, $totalParticipants);
                        
                        if ($hierarchicalResult && isset($hierarchicalResult['taxon_id']) && $hierarchicalResult['taxon_id']) {
                            // Override query result dengan hierarchical consensus
                            $hierarchicalTaxa = DB::table('taxas')->find($hierarchicalResult['taxon_id']);
                            if ($hierarchicalTaxa) {
                                $query = (object) [
                                    'taxon_id' => $hierarchicalResult['taxon_id'],
                                    'scientific_name' => $hierarchicalTaxa->scientific_name,
                                    'taxon_rank' => $hierarchicalTaxa->taxon_rank
                                ];
                                
                                Log::info('Using hierarchical consensus for taxa update', [
                                    'checklist_id' => $actualId,
                                    'hierarchical_taxon_id' => $hierarchicalResult['taxon_id'],
                                    'hierarchical_taxon_name' => $hierarchicalTaxa->scientific_name,
                                    'grade_hint' => $hierarchicalResult['grade_hint'] ?? null
                                ]);
                            }
                        }
                    }

                    Log::info('Checking hierarchical consensus override', [
                        'checklist_id' => $actualId,
                        'current_taxa_id' => $currentChecklist->taxa_id ?? null,
                        'most_agreed_taxa_id' => $query->taxon_id ?? null,
                        'recent_hierarchical_update' => $recentHierarchicalUpdate,
                        'active_identifications_count' => $activeIdentificationsCount,
                        'is_hierarchical_consensus' => $isHierarchicalConsensus,
                        'most_agreed_has_quorum' => $mostAgreedHasQuorum,
                        'most_agreed_agreements' => $mostAgreedAgreements,
                        'quorum_threshold' => $quorumThreshold,
                        'total_participants' => $totalParticipants,
                        'updated_at' => $currentChecklist->updated_at ?? null
                    ]);

                    // Logika update taxa:
                    // 1. Jika ada hierarchical consensus, selalu apply
                    // 2. Jika most agreed species sudah mencapai kuorum, allow update (override genus consensus)
                    // 3. Jika ada hierarchical consensus baru (< 5 menit) DAN masih ada > 1 identifikasi aktif DAN most agreed belum quorum, skip update
                    // 4. Jika identification ditarik dan hanya tersisa 1 identifikasi, allow update ke most agreed
                    // 5. Jika current taxa adalah hierarchical consensus tapi identification ditarik, allow revert
                    
                    // Jika ada hierarchical consensus result, selalu apply
                    if ($hierarchicalResult && isset($hierarchicalResult['taxon_id'])) {
                        $shouldSkipUpdate = false;
                        Log::info('Allowing taxa update: hierarchical consensus', [
                            'checklist_id' => $actualId,
                            'hierarchical_taxon_id' => $hierarchicalResult['taxon_id'],
                            'grade_hint' => $hierarchicalResult['grade_hint'] ?? null
                        ]);
                    } elseif ($mostAgreedHasQuorum) {
                        // Jika species dominan mencapai kuorum sebenarnya, selalu allow update
                        $shouldSkipUpdate = false;
                        Log::info('Allowing taxa update: species has quorum', [
                            'checklist_id' => $actualId,
                            'species_agreements' => $mostAgreedAgreements,
                            'quorum_threshold' => $quorumThreshold
                        ]);
                    } else {
                        $shouldSkipUpdate = $recentHierarchicalUpdate && $activeIdentificationsCount > 1 && $isHierarchicalConsensus && !$mostAgreedHasQuorum;
                    }

                    if ($currentChecklist && $currentChecklist->taxa_id != $query->taxon_id && !$shouldSkipUpdate) {
                        // Ambil data taxa lengkap
                        $currentTaxa = DB::table('taxas')->find($currentChecklist->taxa_id);
                        $newTaxa = DB::table('taxas')->find($query->taxon_id);

                        if ($currentTaxa && $newTaxa) {
                            $this->createTaxaIdentificationHistory(
                                $actualId,
                                $query->taxon_id,
                                $currentChecklist->taxa_id,
                                $user->id,
                                $newTaxa,
                                $currentTaxa
                            );
                        }

                        DB::table('fobi_checklist_taxas')
                            ->where('id', $actualId)
                            ->update([
                                'original_taxa_id' => $currentChecklist->taxa_id,
                                'taxa_id' => $query->taxon_id,
                                'scientific_name' => $newTaxa->scientific_name,
                                'class' => $newTaxa->class,
                                'order' => $newTaxa->order,
                                'family' => $newTaxa->family,
                                'genus' => $newTaxa->genus,
                                'species' => $newTaxa->species,
                                'updated_at' => now()
                            ]);

                        Log::info('Updated FOBI taxa', [
                            'checklist_id' => $actualId,
                            'taxa_id' => $query->taxon_id,
                            'scientific_name' => $newTaxa->scientific_name
                        ]);
                    } else {
                        Log::info('Skipped FOBI taxa update', [
                            'checklist_id' => $actualId,
                            'current_taxa_id' => $currentChecklist->taxa_id,
                            'most_agreed_taxa_id' => $query->taxon_id,
                            'reason' => $shouldSkipUpdate ? 'hierarchical_consensus_protection' : 'no_change_needed',
                            'recent_hierarchical_update' => $recentHierarchicalUpdate,
                            'active_identifications_count' => $activeIdentificationsCount,
                            'is_hierarchical_consensus' => $isHierarchicalConsensus,
                            'most_agreed_has_quorum' => $mostAgreedHasQuorum
                        ]);
                    }
                }
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Error updating checklist taxon', [
                'checklist_id' => $actualId,
                'source' => $source,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // Method baru untuk membuat history
    private function createTaxaIdentificationHistory($checklistId, $taxaId, $previousTaxaId, $userId, $newTaxa, $currentTaxa)
    {
        try {
            TaxaIdentificationHistory::create([
                'checklist_id' => $checklistId,
                'taxa_id' => $taxaId,
                'previous_taxa_id' => $previousTaxaId,
                'user_id' => $userId,
                'action_type' => 'change',
                'scientific_name' => $newTaxa->scientific_name,
                'taxon_key' => $newTaxa->taxon_key,
                'accepted_scientific_name' => $newTaxa->accepted_scientific_name,
                'taxon_rank' => $newTaxa->taxon_rank,
                'taxonomic_status' => $newTaxa->taxonomic_status,

                // Current taxonomy data
                'current_taxonomy' => [
                    'domain' => $newTaxa->domain,
                    'cname_domain' => $newTaxa->cname_domain,
                    'superkingdom' => $newTaxa->superkingdom,
                    'kingdom' => $newTaxa->kingdom,
                    'phylum' => $newTaxa->phylum,
                    'class' => $newTaxa->class,
                    'order' => $newTaxa->order,
                    'family' => $newTaxa->family,
                    'genus' => $newTaxa->genus,
                    'species' => $newTaxa->species,
                    'subspecies' => $newTaxa->subspecies,
                    'variety' => $newTaxa->variety,
                    'form' => $newTaxa->form,
                    'subform' => $newTaxa->subform,
                    'cname_species' => $newTaxa->cname_species,
                    'iucn_red_list_category' => $newTaxa->iucn_red_list_category,
                    'status_kepunahan' => $newTaxa->status_kepunahan
                ],

                // Previous taxonomy data
                'previous_taxonomy' => [
                    'domain' => $currentTaxa->domain,
                    'cname_domain' => $currentTaxa->cname_domain,
                    'superkingdom' => $currentTaxa->superkingdom,
                    'kingdom' => $currentTaxa->kingdom,
                    'phylum' => $currentTaxa->phylum,
                    'class' => $currentTaxa->class,
                    'order' => $currentTaxa->order,
                    'family' => $currentTaxa->family,
                    'genus' => $currentTaxa->genus,
                    'species' => $currentTaxa->species,
                    'subspecies' => $currentTaxa->subspecies,
                    'variety' => $currentTaxa->variety,
                    'form' => $currentTaxa->form,
                    'subform' => $currentTaxa->subform,
                    'cname_species' => $currentTaxa->cname_species,
                    'iucn_red_list_category' => $currentTaxa->iucn_red_list_category,
                    'status_kepunahan' => $currentTaxa->status_kepunahan
                ],
                'reason' => 'Auto-updated based on consensus'
            ]);

            Log::info('Created taxa identification history', [
                'checklist_id' => $checklistId,
                'taxa_id' => $taxaId,
                'previous_taxa_id' => $previousTaxaId
            ]);

            // Tambahkan tracking untuk taxa yang sering tertukar
            $this->updateSimilarTaxa($previousTaxaId, $taxaId);

        } catch (\Exception $e) {
            Log::error('Error creating taxa identification history', [
                'checklist_id' => $checklistId,
                'taxa_id' => $taxaId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function updateSimilarTaxa($previousTaxaId, $newTaxaId)
    {
        try {
            if (!$previousTaxaId || !$newTaxaId || $previousTaxaId == $newTaxaId) {
                return;
            }

            // Ambil data taxa untuk menentukan similarity type
            $previousTaxa = DB::table('taxas')->find($previousTaxaId);
            $newTaxa = DB::table('taxas')->find($newTaxaId);

            if (!$previousTaxa || !$newTaxa) {
                return;
            }

            // Tentukan tipe kemiripan
            $similarityType = $this->determineSimilarityType($previousTaxa, $newTaxa);

            // Update atau buat record baru
            TaxaSimilarIdentification::updateOrCreate(
                [
                    'taxa_id' => min($previousTaxaId, $newTaxaId),
                    'similar_taxa_id' => max($previousTaxaId, $newTaxaId)
                ],
                [
                    'similarity_type' => $similarityType,
                    'confusion_count' => DB::raw('confusion_count + 1'),
                    'notes' => $this->generateSimilarityNotes($previousTaxa, $newTaxa)
                ]
            );

            Log::info('Updated similar taxa record', [
                'previous_taxa' => $previousTaxaId,
                'new_taxa' => $newTaxaId,
                'similarity_type' => $similarityType
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating similar taxa: ' . $e->getMessage(), [
                'previous_taxa' => $previousTaxaId,
                'new_taxa' => $newTaxaId
            ]);
        }
    }

    private function determineSimilarityType($previousTaxa, $newTaxa)
    {
        if ($previousTaxa->genus === $newTaxa->genus) {
            if ($previousTaxa->species === $newTaxa->species) {
                if ($previousTaxa->subspecies === $newTaxa->subspecies) {
                    return 'variety';
                }
                return 'subspecies';
            }
            return 'species';
        }
        return 'genus';
    }
    
    /**
     * Cek apakah identifikasi baru adalah species-subspecies dalam lineage yang sama
     * PERBAIKAN: Untuk menghindari doubtful pada kasus species-subspecies
     */
    private function isSpeciesSubspeciesSameLineage($taxon1, $taxon2)
    {
        // Cek apakah keduanya dalam genus dan species yang sama
        if ($taxon1->genus === $taxon2->genus && $taxon1->species === $taxon2->species) {
            // Salah satu species, satunya subspecies
            $rank1 = strtoupper($taxon1->taxon_rank);
            $rank2 = strtoupper($taxon2->taxon_rank);
            
            return ($rank1 === 'SPECIES' && $rank2 === 'SUBSPECIES') || 
                   ($rank1 === 'SUBSPECIES' && $rank2 === 'SPECIES');
        }
        
        return false;
    }

    private function generateSimilarityNotes($previousTaxa, $newTaxa)
    {
        $differences = [];

        // Bandingkan karakteristik utama
        if ($previousTaxa->genus !== $newTaxa->genus) {
            $differences[] = "Genus berbeda: {$previousTaxa->genus} vs {$newTaxa->genus}";
        }
        if ($previousTaxa->species !== $newTaxa->species) {
            $differences[] = "Species berbeda: {$previousTaxa->species} vs {$newTaxa->species}";
        }
        if ($previousTaxa->subspecies !== $newTaxa->subspecies) {
            $differences[] = "Subspecies berbeda: {$previousTaxa->subspecies} vs {$newTaxa->subspecies}";
        }

        return implode("; ", $differences);
    }

    // Method baru untuk mendapatkan taxa yang sering tertukar
    public function getSimilarTaxa($taxaId)
    {
        try {
            $similarTaxa = TaxaSimilarIdentification::where(function($query) use ($taxaId) {
                    $query->where('taxa_id', $taxaId)
                          ->orWhere('similar_taxa_id', $taxaId);
                })
                ->orderBy('confusion_count', 'desc')
                ->limit(5)
                ->get()
                ->map(function($item) use ($taxaId) {
                    $similarId = $item->taxa_id == $taxaId ?
                        $item->similar_taxa_id : $item->taxa_id;

                    $similarTaxa = DB::table('taxas')->find($similarId);

                    return [
                        'id' => $similarId,
                        'scientific_name' => $similarTaxa->scientific_name,
                        'confusion_count' => $item->confusion_count,
                        'similarity_type' => $item->similarity_type,
                        'notes' => $item->notes
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $similarTaxa
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting similar taxa: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data taxa yang mirip'
            ], 500);
        }
    }

    public function updateImprovementStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'can_be_improved' => 'required|boolean'
            ]);

            $source = request()->query('source', $this->determineSource($id));
            $actualId = $this->getActualId($id, $source);
            $config = $this->getAssessmentConfig($source);

            // Hitung jumlah identifikasi dan persetujuan
            $identificationStats = DB::table('taxa_identifications')
                ->where(function($query) use ($source, $actualId) {
                    if ($source === 'burungnesia') {
                        $query->where('burnes_checklist_id', $actualId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('kupnes_checklist_id', $actualId);
                    } else {
                        $query->where('checklist_id', $actualId);
                    }
                })
                ->selectRaw('
                    COUNT(*) as total_identifications,
                    COUNT(CASE WHEN agrees_with_id IS NOT NULL THEN 1 END) as agreement_count
                ')
                ->first();

            // Cek keberadaan media dan lokasi berdasarkan sumber
            $hasMedia = $this->checkHasMedia($actualId, $source);
            $hasLocation = $this->checkHasLocation($actualId, $source);
            $hasDate = $this->checkHasDate($actualId, $source);

            // Ambil atau buat assessment sesuai sumber
            $assessment = DB::table($config['table'])->where($config['id_column'], $actualId)->first();

            if (!$assessment) {
                // Buat assessment baru
                $assessmentData = [
                    $config['id_column'] => $actualId,
                    'grade' => 'needs ID',
                    'has_media' => $hasMedia,
                    'has_location' => $hasLocation,
                    'has_date' => $hasDate,
                    'is_wild' => true,
                    'location_accurate' => true,
                    'recent_evidence' => true,
                    'related_evidence' => true,
                    'can_be_improved' => $request->can_be_improved,
                    'created_at' => now(),
                    'updated_at' => now()
                ];

                DB::table($config['table'])->insert($assessmentData);
                $assessment = (object)$assessmentData;
            } else {
                // Update assessment yang ada
                $updateData = [
                    'can_be_improved' => $request->can_be_improved,
                    'updated_at' => now()
                ];

                // Evaluasi grade berdasarkan can_be_improved
                if ($request->can_be_improved) {
                    if ($this->determineGrade(
                        $identificationStats->total_identifications,
                        $identificationStats->agreement_count,
                        $hasMedia,
                        $hasLocation,
                        $hasDate,
                        $actualId
                    ) == 'needs ID') {
                        $updateData['grade'] = 'needs ID';
                    }
                } else {
                    // Langsung set research grade ketika can_be_improved false
                    $updateData['grade'] = 'research grade';
                }

                DB::table($config['table'])
                    ->where($config['id_column'], $actualId)
                    ->update($updateData);

                // Refresh assessment data
                $assessment = DB::table($config['table'])
                    ->where($config['id_column'], $actualId)
                    ->first();
            }

            // Update checklist taxon jika grade berubah
            $this->updateChecklistTaxon($id, $source);

            return response()->json([
                'success' => true,
                'data' => $assessment,
                'message' => 'Status berhasil diperbarui'
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating improvement status: ' . $e->getMessage(), [
                'id' => $id,
                'source' => $source ?? 'unknown',
                'exception' => $e
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui status'
            ], 500);
        }
    }

    private function updateAssessment($id, $source, $assessmentData)
    {
        try {
            $config = $this->getAssessmentConfig($source);
            $actualId = $this->getActualId($id, $source);

            // Get fauna/taxon id based on source
            if ($source === 'burungnesia') {
                $fauna = DB::table('fobi_checklist_faunasv1')
                    ->where('checklist_id', $actualId)
                    ->first();
                $faunaId = $fauna ? $fauna->fauna_id : null;

                if (!$faunaId) {
                    $lastIdentification = DB::table('taxa_identifications')
                        ->where('burnes_checklist_id', $actualId)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    $faunaId = $lastIdentification ? $lastIdentification->burnes_fauna_id : null;
                }
                
                // Cari induk taksonomi yang sama untuk Burungnesia
                $commonAncestorTaxon = $this->findCommonAncestorTaxon($actualId, $source);
                
                if ($commonAncestorTaxon) {
                    // Gunakan takson induk yang ditemukan
                    $faunaId = $commonAncestorTaxon->id;
                    
                    // Update data checklist dengan takson induk
                    $this->updateChecklistWithCommonAncestor($actualId, $commonAncestorTaxon, $source);
                    
                    Log::info('Menggunakan takson induk bersama untuk Burungnesia', [
                        'checklist_id' => $actualId,
                        'common_ancestor' => $commonAncestorTaxon->scientific_name,
                        'rank' => $commonAncestorTaxon->taxon_rank
                    ]);
                }

                $assessmentData['fauna_id'] = $faunaId;

            } elseif ($source === 'kupunesia') {
                $fauna = DB::table('fobi_checklist_faunasv2')
                    ->where('checklist_id', $actualId)
                    ->first();
                $faunaId = $fauna ? $fauna->fauna_id : null;

                if (!$faunaId) {
                    $lastIdentification = DB::table('taxa_identifications')
                        ->where('kupnes_checklist_id', $actualId)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    $faunaId = $lastIdentification ? $lastIdentification->kupnes_fauna_id : null;
                }
                
                // Cari induk taksonomi yang sama untuk Kupunesia
                $commonAncestorTaxon = $this->findCommonAncestorTaxon($actualId, $source);
                
                if ($commonAncestorTaxon) {
                    // Gunakan takson induk yang ditemukan
                    $faunaId = $commonAncestorTaxon->id;
                    
                    // Update data checklist dengan takson induk
                    $this->updateChecklistWithCommonAncestor($actualId, $commonAncestorTaxon, $source);
                    
                    Log::info('Menggunakan takson induk bersama untuk Kupunesia', [
                        'checklist_id' => $actualId,
                        'common_ancestor' => $commonAncestorTaxon->scientific_name,
                        'rank' => $commonAncestorTaxon->taxon_rank
                    ]);
                }

                $assessmentData['fauna_id'] = $faunaId;
            } else {
                // Untuk FOBI, gunakan taxon_id bukan fauna_id
                $taxa = DB::table('fobi_checklist_taxas')
                    ->where('id', $actualId)
                    ->first();
                $taxonId = $taxa ? $taxa->taxa_id : null;

                if (!$taxonId) {
                    $lastIdentification = DB::table('taxa_identifications')
                        ->where('checklist_id', $actualId)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    $taxonId = $lastIdentification ? $lastIdentification->taxon_id : null;
                }

                // Cari induk taksonomi yang sama jika ada beberapa identifikasi
                $commonAncestorTaxon = $this->findCommonAncestorTaxon($actualId, $source);
                
                if ($commonAncestorTaxon) {
                    // Gunakan takson induk yang ditemukan
                    $taxonId = $commonAncestorTaxon->id;
                    
                    // Update data checklist dengan takson induk
                    $this->updateChecklistWithCommonAncestor($actualId, $commonAncestorTaxon, $source);
                    
                    Log::info('Menggunakan takson induk bersama untuk FOBI', [
                        'checklist_id' => $actualId,
                        'common_ancestor' => $commonAncestorTaxon->scientific_name,
                        'rank' => $commonAncestorTaxon->taxon_rank
                    ]);
                }

                $assessmentData['taxon_id'] = $taxonId;
                // Hapus fauna_id jika ada untuk menghindari error
                unset($assessmentData['fauna_id']);
            }

            // Check if assessment exists
            $existingAssessment = DB::table($config['table'])
                ->where($config['id_column'], $actualId)
                ->first();

            if ($existingAssessment) {
                // Update existing assessment
                DB::table($config['table'])
                    ->where($config['id_column'], $actualId)
                    ->update($assessmentData);
            } else {
                // Create new assessment
                $assessmentData[$config['id_column']] = $actualId;
                $assessmentData['created_at'] = now();

                // Pastikan fauna_id/taxon_id ada sebelum insert
                if ($source === 'fobi') {
                    if (!isset($assessmentData['taxon_id']) || is_null($assessmentData['taxon_id'])) {
                        Log::warning('No taxon_id found for assessment', [
                            'source' => $source,
                            'checklist_id' => $actualId
                        ]);
                        $assessmentData['taxon_id'] = null;
                    }
                } else {
                    if (!isset($assessmentData['fauna_id']) || is_null($assessmentData['fauna_id'])) {
                        Log::warning('No fauna_id found for assessment', [
                            'source' => $source,
                            'checklist_id' => $actualId
                        ]);
                        $assessmentData['fauna_id'] = null;
                    }
                }

                DB::table($config['table'])->insert($assessmentData);
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Error in updateAssessment: ' . $e->getMessage(), [
                'id' => $id,
                'source' => $source,
                'data' => $assessmentData,
                'exception' => $e
            ]);
            throw $e;
        }
    }
    
    /**
     * Mencari takson induk bersama dari beberapa identifikasi
     * 
     * Fungsi ini akan mencari takson induk bersama dari beberapa identifikasi yang berbeda
     * dengan prioritas pada level taksonomi terendah (genus, family, order, dll)
     * 
     * @param string $checklistId ID checklist
     * @param string $source Sumber data (burungnesia, kupunesia, fobi)
     * @return object|null Objek takson induk bersama atau null jika tidak ditemukan
     */
    private function findCommonAncestorTaxon($checklistId, $source = 'fobi')
    {
        try {
            // Ambil semua identifikasi aktif untuk checklist ini
            $query = DB::table('taxa_identifications as ti')
                ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                ->where(function($query) {
                    $query->where('ti.is_withdrawn', false)
                          ->orWhereNull('ti.is_withdrawn');
                })
                ->whereNull('ti.agrees_with_id') // Hanya identifikasi utama, bukan persetujuan
                ->select([
                    'ti.id as identification_id',
                    'ti.user_id',
                    't.id as taxon_id',
                    't.scientific_name',
                    't.taxon_rank',
                    't.domain',
                    't.superkingdom',
                    't.kingdom',
                    't.subkingdom',
                    't.superphylum',
                    't.phylum',
                    't.subphylum',
                    't.superdivision',
                    't.division',
                    't.subdivision',
                    't.superclass',
                    't.class',
                    't.subclass',
                    't.infraclass',
                    't.superorder',
                    't.order',
                    't.suborder',
                    't.infraorder',
                    't.superfamily',
                    't.family',
                    't.subfamily',
                    't.supertribe',
                    't.tribe',
                    't.subtribe',
                    't.genus',
                    't.subgenus',
                    't.species',
                    't.subspecies',
                    't.variety',
                    't.form',
                    't.subform'
                ]);
                
            // Filter berdasarkan sumber data
            if ($source === 'burungnesia') {
                $query->where('ti.burnes_checklist_id', $checklistId);
            } elseif ($source === 'kupunesia') {
                $query->where('ti.kupnes_checklist_id', $checklistId);
            } else {
                $query->where('ti.checklist_id', $checklistId);
            }
            
            $identifications = $query->get();
                
            // Jika kurang dari 2 identifikasi, tidak perlu mencari induk bersama
            if ($identifications->count() < 2) {
                return null;
            }
            
            Log::info('Mencari takson induk bersama', [
                'checklist_id' => $checklistId,
                'source' => $source,
                'identifications_count' => $identifications->count()
            ]);
            
            // PENTING: Periksa apakah semua identifikasi memiliki taxon_id yang sama
            $uniqueTaxonIds = $identifications->pluck('taxon_id')->unique();
            if ($uniqueTaxonIds->count() === 1) {
                Log::info('Semua identifikasi memiliki taxon_id yang sama, tidak perlu mencari induk bersama', [
                    'taxon_id' => $uniqueTaxonIds->first()
                ]);
                return null;
            }
            
            // Cek apakah ada identifikasi yang ditarik (withdrawn)
            // Jika ada identifikasi yang ditarik, periksa apakah ada identifikasi yang tersisa
            $activeIdentifications = DB::table('taxa_identifications')
                ->where(function($query) use ($checklistId, $source) {
                    if ($source === 'burungnesia') {
                        $query->where('burnes_checklist_id', $checklistId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('kupnes_checklist_id', $checklistId);
                    } else {
                        $query->where('checklist_id', $checklistId);
                    }
                })
                ->where(function($query) {
                    $query->where('is_withdrawn', false)
                          ->orWhereNull('is_withdrawn');
                })
                ->whereNull('agrees_with_id') // Hanya identifikasi utama, bukan persetujuan
                ->count();
                
            // Jika hanya ada satu identifikasi aktif, tidak perlu mencari induk bersama
            if ($activeIdentifications <= 1) {
                Log::info('Hanya ada satu identifikasi aktif, tidak perlu mencari induk bersama');
                return null;
            }
            
            // Cek apakah ada identifikasi yang mencapai kuorum
            $quorumIdentification = $this->getIdentificationWithQuorum($checklistId, $source);
            if ($quorumIdentification) {
                // Jika ada identifikasi yang mencapai kuorum, periksa apakah persentase persetujuan tinggi (>67%)
                $percentAgreement = ($quorumIdentification->agreement_count / $quorumIdentification->total_participants) * 100;
                
                // Jika persentase persetujuan lebih dari 67%, gunakan identifikasi tersebut daripada mencari induk bersama
                if ($percentAgreement >= 67) {
                    Log::info('Identifikasi dengan kuorum signifikan ditemukan, tidak perlu mencari induk bersama', [
                        'taxon_id' => $quorumIdentification->taxon_id,
                        'agreement_count' => $quorumIdentification->agreement_count,
                        'percent_agreement' => $percentAgreement
                    ]);
                    
                    // Dapatkan takson yang mencapai kuorum
                    $taxon = DB::table('taxas')
                        ->where('id', $quorumIdentification->taxon_id)
                        ->first();
                        
                    if ($taxon) {
                        return $taxon;
                    }
                }
                
                // Jika persentase persetujuan tidak tinggi, lanjutkan mencari induk bersama
                Log::info('Identifikasi dengan kuorum ditemukan, tetapi persentase persetujuan tidak tinggi', [
                    'taxon' => $quorumIdentification->taxon_id,
                    'agreement_count' => $quorumIdentification->agreement_count,
                    'percent_agreement' => $percentAgreement
                ]);
            }
            
            // Pra-proses identifikasi untuk menangani kasus khusus division/phylum
            foreach ($identifications as $ident) {
                // Jika phylum kosong tapi division ada, gunakan division sebagai phylum
                if (empty($ident->phylum) && !empty($ident->division)) {
                    $ident->phylum = $ident->division;
                }
            }
            
            // Daftar level taksonomi dari yang paling spesifik ke yang paling umum
            // Sesuai dengan daftar di TabPanel.jsx
            $taxonomyLevels = [
                'subform',
                'form',
                'variety',
                'subspecies',
                'species',
                'subgenus',
                'genus',
                'subtribe',
                'tribe',
                'supertribe',
                'subfamily',
                'family',
                'superfamily',
                'infraorder',
                'suborder',
                'order',
                'superorder',
                'infraclass',
                'subclass',
                'class',
                'superclass',
                'subdivision',
                'division',
                'superdivision',
                'subphylum',
                'phylum',
                'superphylum',
                'subkingdom',
                'kingdom',
                'superkingdom',
                'domain'
            ];
            
            // Cek untuk setiap level taksonomi
            foreach ($taxonomyLevels as $level) {
                // Lewati level species, subspecies, variety, form, dan subform
                if (in_array($level, ['species', 'subspecies', 'variety', 'form', 'subform'])) {
                    continue;
                }
                
                // Ambil nilai dari takson pertama untuk level ini sebagai referensi
                $referenceValue = $identifications[0]->{$level};
                
                // Jika referensi tidak ada untuk level ini, lanjut ke level yang lebih tinggi
                if (empty($referenceValue)) {
                    continue;
                }
                
                // Periksa apakah semua takson memiliki nilai yang sama untuk level ini
                $allSame = true;
                foreach ($identifications as $ident) {
                    if (empty($ident->{$level}) || $ident->{$level} !== $referenceValue) {
                        $allSame = false;
                        break;
                    }
                }
                
                // Jika semua taxa memiliki nilai yang sama pada level ini, 
                // kita telah menemukan taksonomi induk yang sama
                if ($allSame) {
                    Log::info("Menemukan taksonomi induk yang sama pada level {$level}: {$referenceValue}");
                    
                    // Cari takson dengan level dan nilai ini
                    $commonAncestor = DB::table('taxas')
                        ->where($level, $referenceValue)
                        ->where('taxon_rank', strtoupper($level))
                        ->first();
                    
                    if ($commonAncestor) {
                        Log::info('Takson induk bersama ditemukan', [
                            'level' => $level,
                            'value' => $referenceValue,
                            'scientific_name' => $commonAncestor->scientific_name
                        ]);
                        
                        return $commonAncestor;
                    }
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Error dalam findCommonAncestorTaxon', [
                'checklist_id' => $checklistId,
                'source' => $source,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }
    
    /**
     * Mendapatkan identifikasi yang telah mencapai kuorum
     * 
     * @param string $checklistId ID checklist
     * @param string $source Sumber data (burungnesia, kupunesia, fobi)
     * @return object|null Objek identifikasi dengan kuorum atau null jika tidak ada
     */
    public function getIdentificationWithQuorum($checklistId, $source = 'fobi')
    {
        try {
            // Buat query dasar
            $baseQuery = DB::table('taxa_identifications as ti1')
                ->select([
                    'ti1.taxon_id',
                    'ti1.excluded_from_quorum',
                    DB::raw('COUNT(DISTINCT ti1.user_id) as identifier_count'),
                    DB::raw('(COUNT(DISTINCT CASE WHEN ti2.agrees_with_id = ti1.id AND (ti2.is_withdrawn = false OR ti2.is_withdrawn IS NULL) THEN ti2.user_id END) + 1) as agreement_count'),
                    DB::raw('COUNT(DISTINCT CASE WHEN (ti3.is_withdrawn = false OR ti3.is_withdrawn IS NULL) THEN ti3.user_id END) as total_participants')
                ])
                ->leftJoin('taxa_identifications as ti2', 'ti1.id', '=', 'ti2.agrees_with_id');
                
            // Filter berdasarkan sumber data
            if ($source === 'burungnesia') {
                $baseQuery->leftJoin('taxa_identifications as ti3', function($join) use ($checklistId) {
                    $join->on(function($query) {
                        $query->whereRaw('1=1'); // Dummy condition untuk join
                    })
                    ->where('ti3.burnes_checklist_id', $checklistId);
                })
                ->where('ti1.burnes_checklist_id', $checklistId);
            } elseif ($source === 'kupunesia') {
                $baseQuery->leftJoin('taxa_identifications as ti3', function($join) use ($checklistId) {
                    $join->on(function($query) {
                        $query->whereRaw('1=1'); // Dummy condition untuk join
                    })
                    ->where('ti3.kupnes_checklist_id', $checklistId);
                })
                ->where('ti1.kupnes_checklist_id', $checklistId);
            } else {
                $baseQuery->leftJoin('taxa_identifications as ti3', function($join) use ($checklistId) {
                    $join->on(function($query) {
                        $query->whereRaw('1=1'); // Dummy condition untuk join
                    })
                    ->where('ti3.checklist_id', $checklistId);
                })
                ->where('ti1.checklist_id', $checklistId);
            }
            
            // Tambahkan filter umum
            $identificationStats = $baseQuery
                ->where(function($query) {
                    $query->where('ti1.is_withdrawn', false)
                          ->orWhereNull('ti1.is_withdrawn');
                })
                ->whereNull('ti1.agrees_with_id') // Hanya identifikasi utama, bukan persetujuan
                ->groupBy('ti1.taxon_id', 'ti1.excluded_from_quorum')
                ->get();
                
            // Cari identifikasi dengan kuorum (2/3 dari total peserta)
            foreach ($identificationStats as $stat) {
                // Jika identifikasi dikeluarkan dari kuorum, lewati
                if (isset($stat->excluded_from_quorum) && $stat->excluded_from_quorum == 1) {
                    continue;
                }
                
                // Cek apakah mencapai kuorum (min 2 orang atau 2/3 dari total)
                if ($stat->agreement_count >= 2 && 
                    ($stat->agreement_count >= (2/3) * $stat->total_participants)) {
                    
                    // Ambil data identifikasi lengkap
                    $query = DB::table('taxa_identifications')
                        ->where('taxon_id', $stat->taxon_id)
                        ->whereNull('agrees_with_id')
                        ->where(function($query) {
                            $query->where('is_withdrawn', false)
                                  ->orWhereNull('is_withdrawn');
                        });
                        
                    // Filter berdasarkan sumber data
                    if ($source === 'burungnesia') {
                        $query->where('burnes_checklist_id', $checklistId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('kupnes_checklist_id', $checklistId);
                    } else {
                        $query->where('checklist_id', $checklistId);
                    }
                    
                    // Jika ada identifikasi dengan excluded_from_quorum=0, prioritaskan itu
                    if (isset($stat->excluded_from_quorum) && $stat->excluded_from_quorum == 1) {
                        $identification = $query->first();
                    } else {
                        $identification = $query->where(function($query) {
                            $query->where('excluded_from_quorum', 0)
                                  ->orWhereNull('excluded_from_quorum');
                        })->first();
                        
                        // Jika tidak ada yang excluded_from_quorum=0, ambil yang pertama
                        if (!$identification) {
                            $identification = $query->first();
                        }
                    }
                    
                    if ($identification) {
                        $identification->agreement_count = $stat->agreement_count;
                        $identification->total_participants = $stat->total_participants;
                        return $identification;
                    }
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Error dalam getIdentificationWithQuorum', [
                'checklist_id' => $checklistId,
                'source' => $source,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Memperbarui data checklist dengan takson induk bersama
     * 
     * @param string $checklistId ID checklist
     * @param object $commonAncestor Objek takson induk bersama
     * @param string $source Sumber data (burungnesia, kupunesia, fobi)
     * @param bool $forceUpdate Paksa update meskipun ada identifikasi dengan kuorum signifikan
     * @return bool Berhasil atau tidak
     */
    private function updateChecklistWithCommonAncestor($checklistId, $commonAncestor, $source = 'fobi', $forceUpdate = false)
    {
        try {
            // Periksa apakah ada identifikasi yang mencapai kuorum signifikan
            if (!$forceUpdate) {
                $identificationWithQuorum = $this->getIdentificationWithQuorum($checklistId, $source);
                
                if ($identificationWithQuorum) {
                    // Jika ada identifikasi dengan kuorum signifikan, periksa apakah itu berbeda dengan common ancestor
                    if ($identificationWithQuorum->taxon_id != $commonAncestor->id) {
                        // Hitung persentase persetujuan
                        $percentAgreement = ($identificationWithQuorum->agreement_count / $identificationWithQuorum->total_participants) * 100;
                        
                        // Jika persentase persetujuan lebih dari 67% (2/3), gunakan identifikasi tersebut daripada common ancestor
                        if ($percentAgreement >= 67) {
                            Log::info('Skipping common ancestor update because there is an identification with significant quorum', [
                                'checklistId' => $checklistId,
                                'identificationTaxonId' => $identificationWithQuorum->taxon_id,
                                'commonAncestorId' => $commonAncestor->id,
                                'percentAgreement' => $percentAgreement
                            ]);
                            
                            // Dapatkan takson yang mencapai kuorum
                            $quorumTaxon = DB::table('taxas')
                                ->where('id', $identificationWithQuorum->taxon_id)
                                ->first();
                                
                            if ($quorumTaxon) {
                                // Update dengan takson yang mencapai kuorum daripada common ancestor
                                return $this->updateChecklistWithTaxon($checklistId, $quorumTaxon, $source);
                            }
                            
                            return false;
                        }
                    }
                }
            }
            
            // Tentukan tabel dan kolom berdasarkan sumber data
            if ($source === 'burungnesia') {
                $table = 'fobi_checklist_faunasv1';
                $idColumn = 'checklist_id';
                $taxaColumn = 'fauna_id';
                
                // Ambil data checklist saat ini
                $currentChecklist = DB::table($table)
                    ->where($idColumn, $checklistId)
                    ->first();
                    
                if (!$currentChecklist) {
                    Log::warning('Checklist Burungnesia tidak ditemukan', [
                        'checklist_id' => $checklistId
                    ]);
                    return false;
                }
                
                // Perbarui data checklist dengan takson induk
                DB::table($table)
                    ->where($idColumn, $checklistId)
                    ->update([
                        $taxaColumn => $commonAncestor->id,
                        'updated_at' => now()
                    ]);
                    
                Log::info('Checklist Burungnesia diperbarui dengan takson induk', [
                    'checklist_id' => $checklistId,
                    'fauna_id' => $commonAncestor->id,
                    'scientific_name' => $commonAncestor->scientific_name
                ]);
                
                // Buat catatan history perubahan identifikasi
                if ($currentChecklist->fauna_id != $commonAncestor->id) {
                    $currentTaxa = DB::table('taxas')->find($currentChecklist->fauna_id);
                    $newTaxa = $commonAncestor;
                    
                    if ($currentTaxa) {
                        $this->createTaxaIdentificationHistory(
                            $checklistId,
                            $commonAncestor->id,
                            $currentChecklist->fauna_id,
                            auth()->user()->id ?? 1, // Gunakan ID admin jika tidak ada user
                            $newTaxa,
                            $currentTaxa
                        );
                    }
                }
                
            } elseif ($source === 'kupunesia') {
                $table = 'fobi_checklist_faunasv2';
                $idColumn = 'checklist_id';
                $taxaColumn = 'fauna_id';
                
                // Ambil data checklist saat ini
                $currentChecklist = DB::table($table)
                    ->where($idColumn, $checklistId)
                    ->first();
                    
                if (!$currentChecklist) {
                    Log::warning('Checklist Kupunesia tidak ditemukan', [
                        'checklist_id' => $checklistId
                    ]);
                    return false;
                }
                
                // Perbarui data checklist dengan takson induk
                DB::table($table)
                    ->where($idColumn, $checklistId)
                    ->update([
                        $taxaColumn => $commonAncestor->id,
                        'updated_at' => now()
                    ]);
                    
                Log::info('Checklist Kupunesia diperbarui dengan takson induk', [
                    'checklist_id' => $checklistId,
                    'fauna_id' => $commonAncestor->id,
                    'scientific_name' => $commonAncestor->scientific_name
                ]);
                
                // Buat catatan history perubahan identifikasi
                if ($currentChecklist->fauna_id != $commonAncestor->id) {
                    $currentTaxa = DB::table('taxas')->find($currentChecklist->fauna_id);
                    $newTaxa = $commonAncestor;
                    
                    if ($currentTaxa) {
                        $this->createTaxaIdentificationHistory(
                            $checklistId,
                            $commonAncestor->id,
                            $currentChecklist->fauna_id,
                            auth()->user()->id ?? 1, // Gunakan ID admin jika tidak ada user
                            $newTaxa,
                            $currentTaxa
                        );
                    }
                }
                
            } else {
                // FOBI
                $table = 'fobi_checklist_taxas';
                $idColumn = 'id';
                $taxaColumn = 'taxa_id';
                
                // Ambil data checklist saat ini
                $currentChecklist = DB::table($table)
                    ->where($idColumn, $checklistId)
                    ->first();
                    
                if (!$currentChecklist) {
                    Log::warning('Checklist FOBI tidak ditemukan', [
                        'checklist_id' => $checklistId
                    ]);
                    return false;
                }
                
                // Simpan taxa_id asli jika belum ada
                $originalTaxaId = $currentChecklist->original_taxa_id ?? $currentChecklist->taxa_id;
                
                // Perbarui data checklist dengan takson induk
                $updateData = [
                    'original_taxa_id' => $originalTaxaId,
                    'taxa_id' => $commonAncestor->id,
                    'scientific_name' => $commonAncestor->scientific_name,
                    'class' => $commonAncestor->class,
                    'order' => $commonAncestor->order,
                    'family' => $commonAncestor->family,
                    'genus' => $commonAncestor->genus,
                    'species' => $commonAncestor->species,
                    'updated_at' => now()
                ];
                
                // Simpan perubahan ke database
                DB::table($table)
                    ->where($idColumn, $checklistId)
                    ->update($updateData);
                    
                Log::info('Checklist FOBI diperbarui dengan takson induk', [
                    'checklist_id' => $checklistId,
                    'taxa_id' => $commonAncestor->id,
                    'scientific_name' => $commonAncestor->scientific_name,
                    'taxon_rank' => $commonAncestor->taxon_rank
                ]);
                
                // Buat catatan history perubahan identifikasi
                if ($currentChecklist->taxa_id != $commonAncestor->id) {
                    $currentTaxa = DB::table('taxas')->find($currentChecklist->taxa_id);
                    $newTaxa = $commonAncestor;
                    
                    if ($currentTaxa) {
                        $this->createTaxaIdentificationHistory(
                            $checklistId,
                            $commonAncestor->id,
                            $currentChecklist->taxa_id,
                            auth()->user()->id ?? 1, // Gunakan ID admin jika tidak ada user
                            $newTaxa,
                            $currentTaxa
                        );
                    }
                }
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Error dalam updateChecklistWithCommonAncestor', [
                'checklist_id' => $checklistId,
                'source' => $source,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Memperbarui data checklist dengan takson spesifik
     * 
     * @param string $checklistId ID checklist
     * @param object $taxon Objek takson
     * @param string $source Sumber data (burungnesia, kupunesia, fobi)
     * @return bool Berhasil atau tidak
     */
    private function updateChecklistWithTaxon($checklistId, $taxon, $source = 'fobi')
    {
        try {
            // Tentukan tabel dan kolom berdasarkan sumber data
            if ($source === 'burungnesia') {
                $table = 'fobi_checklist_faunasv1';
                $idColumn = 'checklist_id';
                $taxaColumn = 'fauna_id';
                
                // Ambil data checklist saat ini
                $currentChecklist = DB::table($table)
                    ->where($idColumn, $checklistId)
                    ->first();
                    
                if (!$currentChecklist) {
                    Log::warning('Checklist Burungnesia tidak ditemukan', [
                        'checklist_id' => $checklistId
                    ]);
                    return false;
                }
                
                // Perbarui data checklist dengan takson spesifik
                DB::table($table)
                    ->where($idColumn, $checklistId)
                    ->update([
                        $taxaColumn => $taxon->id,
                        'updated_at' => now()
                    ]);
                    
                Log::info('Checklist Burungnesia diperbarui dengan takson spesifik', [
                    'checklist_id' => $checklistId,
                    'fauna_id' => $taxon->id,
                    'scientific_name' => $taxon->scientific_name
                ]);
                
                // Buat catatan history perubahan identifikasi
                if ($currentChecklist->fauna_id != $taxon->id) {
                    $currentTaxa = DB::table('taxas')->find($currentChecklist->fauna_id);
                    
                    if ($currentTaxa) {
                        $this->createTaxaIdentificationHistory(
                            $checklistId,
                            $taxon->id,
                            $currentChecklist->fauna_id,
                            auth()->user()->id ?? 1, // Gunakan ID admin jika tidak ada user
                            $taxon,
                            $currentTaxa
                        );
                    }
                }
                
            } elseif ($source === 'kupunesia') {
                $table = 'fobi_checklist_faunasv2';
                $idColumn = 'checklist_id';
                $taxaColumn = 'fauna_id';
                
                // Ambil data checklist saat ini
                $currentChecklist = DB::table($table)
                    ->where($idColumn, $checklistId)
                    ->first();
                    
                if (!$currentChecklist) {
                    Log::warning('Checklist Kupunesia tidak ditemukan', [
                        'checklist_id' => $checklistId
                    ]);
                    return false;
                }
                
                // Perbarui data checklist dengan takson spesifik
                DB::table($table)
                    ->where($idColumn, $checklistId)
                    ->update([
                        $taxaColumn => $taxon->id,
                        'updated_at' => now()
                    ]);
                    
                Log::info('Checklist Kupunesia diperbarui dengan takson spesifik', [
                    'checklist_id' => $checklistId,
                    'fauna_id' => $taxon->id,
                    'scientific_name' => $taxon->scientific_name
                ]);
                
                // Buat catatan history perubahan identifikasi
                if ($currentChecklist->fauna_id != $taxon->id) {
                    $currentTaxa = DB::table('taxas')->find($currentChecklist->fauna_id);
                    
                    if ($currentTaxa) {
                        $this->createTaxaIdentificationHistory(
                            $checklistId,
                            $taxon->id,
                            $currentChecklist->fauna_id,
                            auth()->user()->id ?? 1, // Gunakan ID admin jika tidak ada user
                            $taxon,
                            $currentTaxa
                        );
                    }
                }
                
            } else {
                // FOBI
                $table = 'fobi_checklist_taxas';
                $idColumn = 'id';
                $taxaColumn = 'taxa_id';
                
                // Ambil data checklist saat ini
                $currentChecklist = DB::table($table)
                    ->where($idColumn, $checklistId)
                    ->first();
                    
                if (!$currentChecklist) {
                    Log::warning('Checklist FOBI tidak ditemukan', [
                        'checklist_id' => $checklistId
                    ]);
                    return false;
                }
                
                // Simpan taxa_id asli jika belum ada
                $originalTaxaId = $currentChecklist->original_taxa_id ?? $currentChecklist->taxa_id;
                
                // Perbarui data checklist dengan takson spesifik
                $updateData = [
                    'original_taxa_id' => $originalTaxaId,
                    'taxa_id' => $taxon->id,
                    'scientific_name' => $taxon->scientific_name,
                    'class' => $taxon->class,
                    'order' => $taxon->order,
                    'family' => $taxon->family,
                    'genus' => $taxon->genus,
                    'species' => $taxon->species,
                    'updated_at' => now()
                ];
                
                // Simpan perubahan ke database
                DB::table($table)
                    ->where($idColumn, $checklistId)
                    ->update($updateData);
                    
                Log::info('Checklist FOBI diperbarui dengan takson spesifik', [
                    'checklist_id' => $checklistId,
                    'taxa_id' => $taxon->id,
                    'scientific_name' => $taxon->scientific_name,
                    'taxon_rank' => $taxon->taxon_rank
                ]);
                
                // Buat catatan history perubahan identifikasi
                if ($currentChecklist->taxa_id != $taxon->id) {
                    $currentTaxa = DB::table('taxas')->find($currentChecklist->taxa_id);
                    
                    if ($currentTaxa) {
                        $this->createTaxaIdentificationHistory(
                            $checklistId,
                            $taxon->id,
                            $currentChecklist->taxa_id,
                            auth()->user()->id ?? 1, // Gunakan ID admin jika tidak ada user
                            $taxon,
                            $currentTaxa
                        );
                    }
                }
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Error dalam updateChecklistWithTaxon', [
                'checklist_id' => $checklistId,
                'source' => $source,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Membuat persetujuan implisit untuk identifikasi dengan taksa yang sama
     * 
     * Metode ini dapat dipanggil setelah identifikasi baru ditambahkan.
     * Ini akan mencari identifikasi sebelumnya yang memiliki taksa yang sama,
     * dan secara otomatis membuat persetujuan implisit jika diperlukan.
     * 
     * @param int $checklistId ID checklist
     * @param int $identificationId ID identifikasi baru
     * @param int $taxaId ID taksa yang diidentifikasi
     * @param int $userId ID pengguna yang membuat identifikasi
     * 
     * @return bool Berhasil atau tidak
     */
    public function createImplicitAgreements($checklistId, $identificationId, $taxaId, $userId)
    {
        try {
            Log::info('Creating implicit agreements', [
                'checklistId' => $checklistId,
                'identificationId' => $identificationId,
                'taxaId' => $taxaId,
                'userId' => $userId
            ]);

            // Cari identifikasi sebelumnya dengan taksa yang sama oleh pengguna lain
            $previousIdentifications = DB::table('taxa_identifications')
                ->where(function($query) use ($checklistId) {
                    $query->where('checklist_id', $checklistId)
                        ->orWhere('burnes_checklist_id', $checklistId)
                        ->orWhere('kupnes_checklist_id', $checklistId);
                })
                ->where('taxon_id', $taxaId)
                ->where('user_id', '!=', $userId) // Bukan pengusul saat ini
                ->where('id', '!=', $identificationId) // Bukan identifikasi saat ini
                ->where(function($query) {
                    $query->where('is_withdrawn', false)
                        ->orWhereNull('is_withdrawn');
                })
                ->get();

            Log::info('Found previous identifications with same taxa', [
                'count' => $previousIdentifications->count(),
                'identifications' => $previousIdentifications
            ]);

            if ($previousIdentifications->isEmpty()) {
                return false; // Tidak ada identifikasi sebelumnya dengan taksa yang sama
            }

            // Pilih identifikasi paling awal untuk disetujui
            $earliestIdentification = $previousIdentifications
                ->sortBy('created_at')
                ->first();

            // Cek apakah sudah ada persetujuan
            $existingAgreement = DB::table('taxa_identifications')
                ->where('user_id', $userId)
                ->where('agrees_with_id', $earliestIdentification->id)
                ->exists();

            if ($existingAgreement) {
                Log::info('Agreement already exists', [
                    'userId' => $userId,
                    'agreesWithId' => $earliestIdentification->id
                ]);
                return false;
            }

            // Buat persetujuan implisit dengan mengubah identifikasi saat ini menjadi persetujuan
            DB::table('taxa_identifications')
                ->where('id', $identificationId)
                ->update([
                    'agrees_with_id' => $earliestIdentification->id
                ]);

            Log::info('Created implicit agreement', [
                'identificationId' => $identificationId,
                'agreesWithId' => $earliestIdentification->id
            ]);

            // Perbarui penilaian kualitas
            $source = request()->query('source', $this->determineSource($checklistId));
            $this->updateQualityAssessment($checklistId, $source);

            return true;

        } catch (\Exception $e) {
            Log::error('Error creating implicit agreements', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Memproses ulang persetujuan implisit untuk checklist tertentu atau semua checklist
     * yang memiliki beberapa identifikasi taksa yang sama oleh pengamat berbeda.
     * 
     * @param Request $request
     * @param int|null $checklistId ID checklist tertentu atau null untuk memproses semua
     * @return \Illuminate\Http\JsonResponse
     */
    public function reprocessImplicitAgreements(Request $request, $checklistId = null)
    {
        try {
            // Batasi jumlah checklist yang diproses dalam satu panggilan jika tidak ada ID spesifik
            $limit = $request->input('limit', 100);
            
            $stats = [
                'processed_checklists' => 0,
                'created_agreements' => 0,
                'skipped' => 0,
                'errors' => 0
            ];
            
            // Ambil daftar checklist dengan beberapa identifikasi aktif
            $query = DB::table('taxa_identifications as ti1')
                ->select(DB::raw('
                    COALESCE(ti1.checklist_id, ti1.burnes_checklist_id, ti1.kupnes_checklist_id) as checklist_id,
                    ti1.taxon_id,
                    COUNT(*) as identification_count
                '))
                ->where(function($query) {
                    $query->where('ti1.is_withdrawn', false)
                          ->orWhereNull('ti1.is_withdrawn');
                })
                ->groupBy(DB::raw('COALESCE(ti1.checklist_id, ti1.burnes_checklist_id, ti1.kupnes_checklist_id), ti1.taxon_id'))
                ->having('identification_count', '>', 1);
                
            // Filter by checklist ID if provided
            if ($checklistId) {
                $query->where(function($q) use ($checklistId) {
                    $q->where('ti1.checklist_id', $checklistId)
                      ->orWhere('ti1.burnes_checklist_id', $checklistId)
                      ->orWhere('ti1.kupnes_checklist_id', $checklistId);
                });
            }
            
            $potentialChecklists = $query->limit($limit)->get();
            
            Log::info('Found potential checklists for implicit agreements', [
                'count' => $potentialChecklists->count(),
                'checklists' => $potentialChecklists->pluck('checklist_id')->unique()->toArray()
            ]);
            
            // Proses setiap checklist
            foreach ($potentialChecklists->pluck('checklist_id')->unique() as $clId) {
                try {
                    // Ambil semua identifikasi aktif untuk checklist ini
                    $identifications = DB::table('taxa_identifications')
                        ->where(function($query) use ($clId) {
                            $query->where('checklist_id', $clId)
                                  ->orWhere('burnes_checklist_id', $clId)
                                  ->orWhere('kupnes_checklist_id', $clId);
                        })
                        ->where(function($query) {
                            $query->where('is_withdrawn', false)
                                  ->orWhereNull('is_withdrawn');
                        })
                        ->whereNull('agrees_with_id') // Hanya yang belum menjadi persetujuan
                        ->orderBy('created_at')
                        ->get();
                    
                    // Group identifications by genus and species (one per user to avoid double counting)
                    $genusGroups = [];
                    $speciesGroups = [];
                    $userGenusMap = [];
                    $userSpeciesMap = [];
                    
                    foreach ($identifications as $identification) {
                        $taxon = DB::table('taxa')->where('id', $identification->taxon_id)->first();
                        $userId = $identification->user_id;
                        
                        if ($taxon) {
                            // Get genus name
                            $genusName = $this->getGenusName($taxon);
                            if ($genusName && !isset($userGenusMap[$userId][$genusName])) {
                                if (!isset($genusGroups[$genusName])) {
                                    $genusGroups[$genusName] = 0;
                                }
                                $genusGroups[$genusName]++;
                                $userGenusMap[$userId][$genusName] = true;
                            }
                            
                            // For species level consensus (including subspecies)
                            if ($taxon->rank === 'species' || $taxon->rank === 'subspecies') {
                                $speciesName = $this->getSpeciesName($taxon);
                                if ($speciesName && !isset($userSpeciesMap[$userId][$speciesName])) {
                                    if (!isset($speciesGroups[$speciesName])) {
                                        $speciesGroups[$speciesName] = 0;
                                    }
                                    $speciesGroups[$speciesName]++;
                                    $userSpeciesMap[$userId][$speciesName] = true;
                                }
                            }
                        }
                    }
                    
                    $taxaGroups = [];
                    
                    // Kelompokkan identifikasi berdasarkan taksa
                    foreach ($identifications as $ident) {
                        if (!isset($taxaGroups[$ident->taxon_id])) {
                            $taxaGroups[$ident->taxon_id] = [];
                        }
                        $taxaGroups[$ident->taxon_id][] = $ident;
                    }
                    
                    // Proses kelompok dengan lebih dari 1 identifikasi
                    foreach ($taxaGroups as $taxonId => $identGroup) {
                        if (count($identGroup) <= 1) {
                            continue;
                        }
                        
                        // Identifikasi pertama menjadi referensi
                        $referenceIdent = $identGroup[0];
                        
                        // Identifikasi berikutnya dikonversi menjadi persetujuan
                        for ($i = 1; $i < count($identGroup); $i++) {
                            $identToConvert = $identGroup[$i];
                            
                            // Buat persetujuan implisit
                            DB::table('taxa_identifications')
                                ->where('id', $identToConvert->id)
                                ->update([
                                    'agrees_with_id' => $referenceIdent->id
                                ]);
                                
                            $stats['created_agreements']++;
                        }
                    }
                    
                    // Perbarui penilaian kualitas untuk checklist ini
                    $source = $this->determineSource($clId);
                    $this->updateQualityAssessment($clId, $source);
                    
                    $stats['processed_checklists']++;
                    
                } catch (\Exception $e) {
                    Log::error('Error processing checklist for implicit agreements', [
                        'checklist_id' => $clId,
                        'error' => $e->getMessage()
                    ]);
                    $stats['errors']++;
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Berhasil memproses persetujuan implisit',
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in reprocessImplicitAgreements', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses persetujuan implisit: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menarik otomatis identifikasi taksa tingkat lebih tinggi ketika spesies tertentu telah disepakati
     * 
     * Method ini akan menarik identifikasi dengan rank yang lebih tinggi (genus, family, order, dll)
     * jika taksa tersebut berada dalam garis taksonomi yang sama dengan spesies yang telah disepakati.
     * 
     * Juga menangani kasus khusus untuk identifikasi genus/family yang diajukan setelah kuorum spesies:
     * - Jika identifikasi baru di level genus/family diajukan setelah kuorum spesies
     * - Dan tidak mendapat dukungan 2/3
     * - Maka akan ditandai excluded_from_quorum = true
     * 
     * @param string $checklistId ID checklist
     * @param object $agreedTaxon Data takson yang telah disepakati (harus level species)
     * @return int Jumlah identifikasi yang ditarik
     */
    public function autoWithdrawHigherRankIdentifications($checklistId, $agreedTaxon)
    {
        try {
            Log::info('Starting autoWithdrawHigherRankIdentifications', [
                'checklistId' => $checklistId,
                'agreedTaxon' => $agreedTaxon
            ]);
            
            // Validasi parameter agreedTaxon
            if (!$agreedTaxon) {
                Log::warning('Tidak dapat melakukan auto-withdraw: parameter agreedTaxon kosong');
                return 0;
            }
            
            // Periksa apakah properti wajib tersedia
            if (!isset($agreedTaxon->taxon_rank) || !isset($agreedTaxon->species)) {
                Log::warning('Tidak dapat melakukan auto-withdraw: properti taxon_rank atau species tidak tersedia', [
                    'agreedTaxon' => $agreedTaxon
                ]);
                return 0;
            }
            
            // Jika tidak memiliki id, coba dapatkan dari database
            if (!isset($agreedTaxon->id)) {
                Log::warning('Property id tidak ditemukan pada agreedTaxon, mencoba mendapatkan dari database');
                
                // Coba dapatkan taksa dari database berdasarkan genus dan species
                if (isset($agreedTaxon->genus) && isset($agreedTaxon->species)) {
                    $taxon = DB::table('taxas')
                        ->where('genus', $agreedTaxon->genus)
                        ->where('species', $agreedTaxon->species)
                        ->select('id', 'taxon_rank', 'kingdom', 'phylum', 'class', 'order', 'superfamily', 'family', 'genus', 'species')
                        ->first();
                    
                    if ($taxon) {
                        $agreedTaxon = $taxon;
                        Log::info('Berhasil mendapatkan data taksa dari database', [
                            'taxon_id' => $agreedTaxon->id,
                            'species' => $agreedTaxon->species
                        ]);
                    } else {
                        Log::error('Gagal mendapatkan data taksa dari database');
                        return 0;
                    }
                } else {
                    Log::error('Tidak cukup data untuk mengidentifikasi taksa', [
                        'agreedTaxon' => $agreedTaxon
                    ]);
                    return 0;
                }
            }
            
            if (strtolower($agreedTaxon->taxon_rank) !== 'species') {
                Log::info('Not processing auto-withdraw: not a species level identification');
                return 0;
            }
            
            // Daftar level taksonomi dalam hirarki (rendah ke tinggi)
            // PERBAIKAN: Tambahkan superfamily untuk support lengkap
            $taxonomyLevels = [
                'subspecies', 'species', 'subgenus', 'genus', 'subtribe', 'tribe', 'subfamily', 'family', 'superfamily', 'infraorder', 'suborder', 'order', 'superorder', 'infraclass', 'subclass', 'class', 'superclass', 'subphylum', 'phylum', 'superphylum', 'subkingdom', 'kingdom', 'superkingdom'
            ];
            
            // Ambil semua identifikasi aktif untuk checklist ini
            $identifications = DB::table('taxa_identifications as ti')
                ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                ->where(function($query) use ($checklistId) {
                    $query->where('ti.checklist_id', $checklistId)
                          ->orWhere('ti.burnes_checklist_id', $checklistId)
                          ->orWhere('ti.kupnes_checklist_id', $checklistId);
                })
                ->where(function($query) {
                    $query->where('ti.is_withdrawn', false)
                          ->orWhereNull('ti.is_withdrawn');
                })
                ->whereNull('ti.agrees_with_id') // Hanya identifikasi utama, bukan persetujuan
                ->where('t.id', '!=', $agreedTaxon->id) // Kecuali taksa yang sudah disepakati
                ->select([
                    'ti.id as identification_id',
                    'ti.user_id',
                    't.id as taxon_id',
                    't.taxon_rank',
                    't.kingdom',
                    't.phylum',
                    't.class',
                    't.order',
                    't.family',
                    't.genus',
                    't.species',
                    'ti.created_at',
                    DB::raw('(SELECT COUNT(*) FROM taxa_identifications ti2 WHERE ti2.agrees_with_id = ti.id AND (ti2.is_withdrawn = 0 OR ti2.is_withdrawn IS NULL)) as agreement_count')
                ])
                ->get();
                
            Log::info('Found identifications to check for auto-withdraw', [
                'count' => $identifications->count(),
                'identifications' => $identifications->pluck('identification_id')->toArray()
            ]);
            
            $withdrawnCount = 0;
            $now = now();
            
            // Hitung total pengusul untuk menentukan kuorum
            $totalIdentifiers = DB::table('taxa_identifications')
                ->where(function($query) use ($checklistId) {
                    $query->where('checklist_id', $checklistId)
                          ->orWhere('burnes_checklist_id', $checklistId)
                          ->orWhere('kupnes_checklist_id', $checklistId);
                })
                ->where(function($query) {
                    $query->where('is_withdrawn', false)
                          ->orWhereNull('is_withdrawn');
                })
                ->whereNull('agrees_with_id')
                ->distinct('user_id')
                ->count('user_id');
            
            // Ambil waktu kuorum spesies tercapai
            $speciesQuorumTime = DB::table('taxa_identifications')
                ->where('taxon_id', $agreedTaxon->id)
                ->min('created_at');
            
            foreach ($identifications as $identification) {
                $identificationRank = strtolower($identification->taxon_rank);
                
                // Cek apakah ini identifikasi genus/family yang diajukan setelah kuorum spesies
                if (($identificationRank === 'genus' || $identificationRank === 'family') && 
                    $identification->created_at > $speciesQuorumTime) {
                    
                    // Hitung apakah identifikasi ini mencapai kuorum 2/3
                    $hasQuorum = ($identification->agreement_count + 1) >= (2/3 * $totalIdentifiers);
                    
                    if (!$hasQuorum) {
                        // Tandai sebagai excluded_from_quorum jika tidak mencapai kuorum
                        DB::table('taxa_identifications')
                            ->where('id', $identification->identification_id)
                            ->update([
                                'excluded_from_quorum' => true,
                                'comment' => 'Identifikasi ' . ucfirst($identificationRank) . ' diajukan setelah kuorum spesies tercapai',
                                'updated_at' => $now
                            ]);
                            
                        Log::info('Identifikasi ditandai sebagai excluded_from_quorum', [
                            'identification_id' => $identification->identification_id,
                            'rank' => $identificationRank,
                            'agreement_count' => $identification->agreement_count,
                            'total_identifiers' => $totalIdentifiers
                        ]);
                        
                        continue; // Lanjut ke identifikasi berikutnya
                    }
                }
                
                // Cek apakah taksa ini ada dalam garis taksonomi yang sama dan levelnya lebih tinggi
                if ($this->isInSameTaxonomicLineage($identification, $agreedTaxon)) {
                    // Tarik identifikasi ini
                    DB::table('taxa_identifications')
                        ->where('id', $identification->identification_id)
                        ->update([
                            'is_withdrawn' => true,
                            'comment' => 'Ditarik otomatis karena identifikasi spesies ' . $agreedTaxon->species . ' telah mencapai konsensus dan berada dalam garis taksonomi yang sama.',
                            'updated_at' => $now
                        ]);
                        
                    // Buat notifikasi bahwa identifikasi telah ditarik otomatis
                    $this->createNotification(
                        $identification->user_id,
                        $checklistId,
                        'identification_withdrawn',
                        "Identifikasi Anda dengan level taksonomi " . ucfirst($identification->taxon_rank) . 
                        " ditarik otomatis karena identifikasi spesies " . $agreedTaxon->species . 
                        " telah mencapai konsensus dan berada dalam garis taksonomi yang sama."
                    );
                    
                    $withdrawnCount++;
                }
            }
            
            Log::info('Auto-withdrawn higher rank identifications', [
                'count' => $withdrawnCount
            ]);
            
            // Perbarui penilaian kualitas jika ada identifikasi yang ditarik
            if ($withdrawnCount > 0) {
                $source = $this->determineSource($checklistId);
                Log::info('Updating quality assessment after auto-withdraw', [
                    'checklistId' => $checklistId,
                    'source' => $source
                ]);
                $this->updateQualityAssessment($checklistId, $source);
            }
            
            return $withdrawnCount;
            
        } catch (\Exception $e) {
            Log::error('Error in autoWithdrawHigherRankIdentifications', [
                'checklistId' => $checklistId,
                'agreedTaxon' => $agreedTaxon,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 0;
        }
    }
    
    /**
     * Memeriksa apakah dua takson berada dalam garis taksonomi yang sama
     * Menggabungkan logika dari kedua implementasi untuk fleksibilitas maksimal
     * 
     * @param object $taxon1 Takson pertama
     * @param object $taxon2 Takson kedua
     * @param bool $checkRankOrder Apakah perlu cek urutan rank (default: false untuk kompatibilitas)
     * @return bool True jika berada dalam garis taksonomi yang sama
     */
    public function isInSameTaxonomicLineage($taxon1, $taxon2, $checkRankOrder = false)
    {
        try {
            // Validasi parameter
            if (!$taxon1 || !$taxon2) {
                Log::warning('Tidak dapat memeriksa garis taksonomi: parameter takson kosong');
                return false;
            }
            
            // Periksa apakah properti taxon_rank tersedia
            if (!isset($taxon1->taxon_rank) || !isset($taxon2->taxon_rank)) {
                Log::warning('Tidak dapat memeriksa garis taksonomi: properti taxon_rank tidak tersedia');
                return false;
            }
            
            // Daftar level taksonomi dari tinggi ke rendah
            // PERBAIKAN: Tambahkan superfamily dan level lainnya untuk support lengkap
            $taxonomyLevels = [
                'superkingdom', 'kingdom', 'subkingdom', 'superphylum', 'phylum', 'subphylum', 'superclass', 'class', 'subclass', 'infraclass', 'superorder', 'order', 'suborder', 'infraorder', 'superfamily', 'family', 'subfamily', 'tribe', 'subtribe', 'genus', 'subgenus', 'species', 'subspecies'
            ];
            
            $taxon1Rank = strtolower($taxon1->taxon_rank);
            $taxon2Rank = strtolower($taxon2->taxon_rank);
            
            // Jika checkRankOrder = true, periksa urutan rank (untuk logika penarikan)
            if ($checkRankOrder) {
                $rank1Index = array_search($taxon1Rank, $taxonomyLevels);
                $rank2Index = array_search($taxon2Rank, $taxonomyLevels);
                
                // Jika rank1 tidak ditemukan atau rank1 lebih rendah/sama dengan rank2, bukan kandidat untuk penarikan
                if ($rank1Index === false || $rank2Index === false || $rank1Index >= $rank2Index) {
                    return false;
                }
                
                // Periksa semua level dari kingdom sampai level taxon1
                for ($i = 0; $i <= $rank1Index; $i++) {
                    $level = $taxonomyLevels[$i];
                    
                    // Jika salah satu properti tidak tersedia, lewati
                    if (!isset($taxon1->$level) || !isset($taxon2->$level)) {
                        continue;
                    }
                    
                    // Jika salah satu nilai kosong, lewati level ini
                    if (empty($taxon1->$level) || empty($taxon2->$level)) {
                        continue;
                    }
                    
                    // Jika nilainya berbeda pada level ini, bukan dalam garis taksonomi yang sama
                    if ($taxon1->$level !== $taxon2->$level) {
                        return false;
                    }
                }
                
                return true;
            }
            
            // Logika default (untuk kompatibilitas dengan kode existing)
            // Cek berdasarkan hierarki taksonomi
            $hierarchyFields = ['kingdom', 'phylum', 'class', 'order', 'family', 'genus'];
            
            foreach ($hierarchyFields as $field) {
                // Jika salah satu field tidak ada, skip
                if (!isset($taxon1->$field) || !isset($taxon2->$field)) {
                    continue;
                }
                
                // Jika kedua field ada nilai dan berbeda, bukan lineage yang sama
                if ($taxon1->$field && $taxon2->$field && $taxon1->$field !== $taxon2->$field) {
                    return false;
                }
            }
            
            // Cek khusus untuk species-subspecies relationship
            // Species dan subspecies dalam lineage yang sama jika genus sama
            if (($taxon1Rank === 'species' && in_array($taxon2Rank, ['subspecies', 'variety', 'form'])) ||
                ($taxon2Rank === 'species' && in_array($taxon1Rank, ['subspecies', 'variety', 'form']))) {
                return isset($taxon1->genus) && isset($taxon2->genus) && $taxon1->genus === $taxon2->genus;
            }
            
            // Genus dan species dalam lineage yang sama jika genus sama
            if (($taxon1Rank === 'genus' && $taxon2Rank === 'species') || 
                ($taxon1Rank === 'species' && $taxon2Rank === 'genus')) {
                return isset($taxon1->genus) && isset($taxon2->genus) && $taxon1->genus === $taxon2->genus;
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Error dalam isInSameTaxonomicLineage', [
                'error' => $e->getMessage(),
                'taxon1' => $taxon1,
                'taxon2' => $taxon2,
                'checkRankOrder' => $checkRankOrder
            ]);
            return false;
        }
    }
    
    // Method untuk membuat notifikasi ke pengguna
    private function createNotification($userId, $checklistId, $type, $message)
    {
        try {
            DB::table('user_notifications')->insert([
                'user_id' => $userId,
                'checklist_id' => $checklistId,
                'type' => $type,
                'message' => $message,
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error('Error creating notification', [
                'user_id' => $userId,
                'checklist_id' => $checklistId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Mengkonversi identifikasi duplikat (dengan takson yang sama) menjadi persetujuan
     * 
     * @param string $checklistId ID checklist
     * @param string $source Sumber data (burungnesia, kupunesia, fobi)
     * @return int Jumlah identifikasi yang dikonversi
     */
    private function convertDuplicateIdentificationsToAgreements($checklistId, $source = 'fobi')
    {
        try {
            // Ambil semua identifikasi aktif untuk checklist ini
            $query = DB::table('taxa_identifications as ti')
                ->select([
                    'ti.id',
                    'ti.taxon_id',
                    'ti.user_id',
                    'ti.created_at'
                ])
                ->where(function($query) {
                    $query->where('ti.is_withdrawn', false)
                          ->orWhereNull('ti.is_withdrawn');
                })
                ->whereNull('ti.agrees_with_id'); // Hanya identifikasi utama, bukan persetujuan
                
            // Filter berdasarkan sumber data
            if ($source === 'burungnesia') {
                $query->where('ti.burnes_checklist_id', $checklistId);
            } elseif ($source === 'kupunesia') {
                $query->where('ti.kupnes_checklist_id', $checklistId);
            } else {
                $query->where('ti.checklist_id', $checklistId);
            }
            
            $identifications = $query->orderBy('ti.created_at')->get();
            
            // Kelompokkan identifikasi berdasarkan taxon_id
            $groupedIdentifications = [];
            foreach ($identifications as $identification) {
                if (!isset($groupedIdentifications[$identification->taxon_id])) {
                    $groupedIdentifications[$identification->taxon_id] = [];
                }
                $groupedIdentifications[$identification->taxon_id][] = $identification;
            }
            
            // Konversi identifikasi duplikat menjadi persetujuan
            $convertedCount = 0;
            foreach ($groupedIdentifications as $taxonId => $identificationGroup) {
                // Jika hanya ada satu identifikasi untuk takson ini, lewati
                if (count($identificationGroup) <= 1) {
                    continue;
                }
                
                // Gunakan identifikasi pertama sebagai referensi
                $referenceIdentification = $identificationGroup[0];
                
                // Konversi identifikasi lainnya menjadi persetujuan
                for ($i = 1; $i < count($identificationGroup); $i++) {
                    $identificationToConvert = $identificationGroup[$i];
                    
                    // Update identifikasi menjadi persetujuan
                    DB::table('taxa_identifications')
                        ->where('id', $identificationToConvert->id)
                        ->update([
                            'agrees_with_id' => $referenceIdentification->id,
                            'updated_at' => now()
                        ]);
                        
                    $convertedCount++;
                    
                    Log::info('Identifikasi duplikat dikonversi menjadi persetujuan', [
                        'checklist_id' => $checklistId,
                        'identification_id' => $identificationToConvert->id,
                        'agrees_with_id' => $referenceIdentification->id,
                        'taxon_id' => $taxonId
                    ]);
                }
            }
            
            return $convertedCount;
            
        } catch (\Exception $e) {
            Log::error('Error dalam convertDuplicateIdentificationsToAgreements', [
                'checklist_id' => $checklistId,
                'source' => $source,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 0;
        }
    }

    /**
     * Menjalankan konversi identifikasi duplikat secara batch
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchConvertDuplicateIdentifications(Request $request)
    {
        try {
            $limit = $request->input('limit', 100);
            $source = $request->input('source', 'all');
            
            // Ambil daftar checklist yang memiliki identifikasi duplikat
            $query = DB::table('taxa_identifications as ti')
                ->select(DB::raw('
                    COALESCE(ti.checklist_id, ti.burnes_checklist_id, ti.kupnes_checklist_id) as checklist_id,
                    ti.taxon_id,
                    COUNT(*) as identification_count
                '))
                ->where(function($query) {
                    $query->where('ti.is_withdrawn', false)
                          ->orWhereNull('ti.is_withdrawn');
                })
                ->whereNull('ti.agrees_with_id')
                ->groupBy(DB::raw('COALESCE(ti.checklist_id, ti.burnes_checklist_id, ti.kupnes_checklist_id), ti.taxon_id'))
                ->having('identification_count', '>', 1);
                
            // Filter berdasarkan sumber data jika ditentukan
            if ($source !== 'all') {
                if ($source === 'burungnesia') {
                    $query->whereNotNull('ti.burnes_checklist_id');
                } elseif ($source === 'kupunesia') {
                    $query->whereNotNull('ti.kupnes_checklist_id');
                } else {
                    $query->whereNotNull('ti.checklist_id');
                }
            }
            
            $potentialChecklists = $query->limit($limit)->get();
            
            Log::info('Ditemukan checklist dengan identifikasi duplikat', [
                'count' => $potentialChecklists->count(),
                'checklists' => $potentialChecklists->pluck('checklist_id')->unique()->toArray()
            ]);
            
            $stats = [
                'processed_checklists' => 0,
                'converted_identifications' => 0,
                'errors' => 0
            ];
            
            // Proses setiap checklist
            foreach ($potentialChecklists->pluck('checklist_id')->unique() as $checklistId) {
                try {
                    // Tentukan sumber data
                    $checklistSource = $this->determineSource($checklistId);
                    
                    // Konversi identifikasi duplikat
                    $convertedCount = $this->convertDuplicateIdentificationsToAgreements($checklistId, $checklistSource);
                    
                    // Update statistik
                    $stats['processed_checklists']++;
                    $stats['converted_identifications'] += $convertedCount;
                    
                    // Perbarui penilaian kualitas jika ada identifikasi yang dikonversi
                    if ($convertedCount > 0) {
                        $this->updateQualityAssessment($checklistId, $checklistSource);
                    }
                } catch (\Exception $e) {
                    Log::error('Error memproses checklist untuk konversi identifikasi duplikat', [
                        'checklist_id' => $checklistId,
                        'error' => $e->getMessage()
                    ]);
                    $stats['errors']++;
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Berhasil memproses konversi identifikasi duplikat',
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error dalam batchConvertDuplicateIdentifications', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses konversi identifikasi duplikat: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menarik otomatis identifikasi dengan rank UNKNOWN ketika ada identifikasi baru dengan rank valid
     * 
     * @param string $checklistId ID checklist
     * @param object $newIdentification Data identifikasi baru
     * @return int Jumlah identifikasi yang ditarik
     */
    public function autoWithdrawUnknownRankIdentifications($checklistId, $newIdentification)
    {
        try {
            Log::info('Starting autoWithdrawUnknownRankIdentifications', [
                'checklistId' => $checklistId,
                'newIdentification' => $newIdentification
            ]);
            
            // Validasi parameter
            if (!$newIdentification) {
                Log::warning('Tidak dapat melakukan auto-withdraw: parameter identifikasi tidak valid');
                return 0;
            }

            // Ekstrak objek identifikasi dari berbagai kemungkinan struktur
            $identificationObj = null;
            $taxonRank = null;
            $identificationId = null;
            
            // Cek jika $newIdentification adalah objek stdClass yang dibungkus dalam properti stdClass
            if (is_object($newIdentification) && isset($newIdentification->stdClass)) {
                $identificationObj = $newIdentification->stdClass;
                $taxonRank = isset($identificationObj->taxon_rank) ? $identificationObj->taxon_rank : null;
                $identificationId = isset($identificationObj->id) ? $identificationObj->id : null;
            } 
            // Cek jika $newIdentification adalah objek langsung
            else if (is_object($newIdentification)) {
                $identificationObj = $newIdentification;
                $taxonRank = isset($identificationObj->taxon_rank) ? $identificationObj->taxon_rank : null;
                $identificationId = isset($identificationObj->id) ? $identificationObj->id : null;
            }
            // Cek jika $newIdentification adalah array
            else if (is_array($newIdentification)) {
                $taxonRank = isset($newIdentification['taxon_rank']) ? $newIdentification['taxon_rank'] : null;
                $identificationId = isset($newIdentification['id']) ? $newIdentification['id'] : null;
                $identificationObj = (object)$newIdentification;
            }
            
            // Cek jika taxon_rank tidak ditemukan
            if (!$taxonRank) {
                Log::warning('Tidak dapat melakukan auto-withdraw: taxon_rank tidak ditemukan', [
                    'newIdentification' => $newIdentification
                ]);
                return 0;
            }
            
            // Jika identifikasi baru juga UNKNOWN, tidak perlu melakukan apa-apa
            if (strtolower($taxonRank) === 'unknown') {
                Log::info('Identifikasi baru juga UNKNOWN, tidak perlu melakukan auto-withdraw');
                return 0;
            }

            // Ambil semua identifikasi UNKNOWN yang aktif untuk checklist ini
            $unknownIdentifications = DB::table('taxa_identifications as ti')
                ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                ->where(function($query) use ($checklistId) {
                    $query->where('ti.checklist_id', $checklistId)
                          ->orWhere('ti.burnes_checklist_id', $checklistId)
                          ->orWhere('ti.kupnes_checklist_id', $checklistId);
                })
                ->where(function($query) {
                    $query->where('ti.is_withdrawn', false)
                          ->orWhereNull('ti.is_withdrawn');
                })
                ->whereNull('ti.deleted_at') // Pastikan belum di-soft delete
                ->whereNull('ti.agrees_with_id') // Hanya identifikasi utama
                ->where(DB::raw('LOWER(t.taxon_rank)'), 'unknown');
                
            // Kecualikan identifikasi baru jika ID-nya tersedia
            if ($identificationId) {
                $unknownIdentifications->where('ti.id', '!=', $identificationId);
            }
                
            $unknownIdentifications = $unknownIdentifications->select([
                    'ti.id as identification_id',
                    'ti.user_id',
                    't.id as taxon_id',
                    't.taxon_rank'
                ])
                ->get();
                
            if ($unknownIdentifications->isEmpty()) {
                Log::info('Tidak ada identifikasi UNKNOWN yang perlu ditarik');
                return 0;
            }
            
            Log::info('Found UNKNOWN rank identifications to withdraw', [
                'count' => $unknownIdentifications->count(),
                'identifications' => $unknownIdentifications->pluck('identification_id')->toArray()
            ]);
            
            $withdrawnCount = 0;
            $now = now();
            
            foreach ($unknownIdentifications as $identification) {
                // Tarik identifikasi UNKNOWN
                DB::table('taxa_identifications')
                    ->where('id', $identification->identification_id)
                    ->update([
                        'is_withdrawn' => true,
                        'deleted_at' => $now,
                        'comment' => 'Ditarik otomatis karena ada identifikasi baru dengan rank yang valid (' . 
                                   ucfirst(strtolower($taxonRank)) . ')',
                        'updated_at' => $now
                    ]);
                    
                // Buat notifikasi untuk pengguna
                $this->createNotification(
                    $identification->user_id,
                    $checklistId,
                    'identification_withdrawn',
                    "Identifikasi UNKNOWN Anda ditarik otomatis karena ada identifikasi baru dengan rank " . 
                    ucfirst(strtolower($taxonRank))
                );
                
                $withdrawnCount++;
            }
            
            Log::info('Auto-withdrawn UNKNOWN rank identifications', [
                'count' => $withdrawnCount
            ]);
            
            // Perbarui penilaian kualitas jika ada identifikasi yang ditarik
            if ($withdrawnCount > 0) {
                $source = $this->determineSource($checklistId);
                Log::info('Updating quality assessment after withdrawing UNKNOWN ranks', [
                    'checklistId' => $checklistId,
                    'source' => $source
                ]);
                $this->updateQualityAssessment($checklistId, $source);
            }
            
            return $withdrawnCount;
            
        } catch (\Exception $e) {
            Log::error('Error in autoWithdrawUnknownRankIdentifications', [
                'checklistId' => $checklistId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 0;
        }
    }

    /**
     * Memeriksa apakah kingdom sudah memiliki quorum
     * 
     * @param string $checklistId ID checklist
     * @param string $source Sumber data
     * @return array|null Array berisi status quorum dan kingdom name jika ada
     */
    public function checkKingdomQuorum($checklistId, $source = 'fobi')
    {
        try {
            $identificationWithQuorum = $this->getIdentificationWithQuorum($checklistId, $source);
            
            if ($identificationWithQuorum) {
                // Ambil data takson untuk identifikasi dengan quorum
                $taxon = DB::table('taxas')
                    ->select(['id', 'scientific_name', 'taxon_rank', 'kingdom', 'superfamily', 'family', 'order'])
                    ->where('id', $identificationWithQuorum->taxon_id)
                    ->first();

                if ($taxon && $taxon->kingdom) {
                    return [
                        'has_quorum' => true,
                        'kingdom_name' => $taxon->kingdom
                    ];
                }
            }
            
            return [
                'has_quorum' => false,
                'kingdom_name' => null
            ];
            
        } catch (\Exception $e) {
            Log::error('Error dalam checkKingdomQuorum', [
                'checklist_id' => $checklistId,
                'source' => $source,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    // Tambahkan fungsi getIUCNStatusFromAPI jika belum ada
    private function getIUCNStatusFromAPI($scientificName)
    {
        try {
            // Pisahkan nama ilmiah menjadi genus dan species
            $nameParts = explode(' ', $scientificName);
            $genusName = $nameParts[0];
            $speciesName = isset($nameParts[1]) ? $nameParts[1] : '';
            
            // Jika tidak ada species name, tidak bisa melakukan pencarian
            if (empty($speciesName)) {
                return null;
            }
            
            $client = new \GuzzleHttp\Client();
            $response = $client->request('GET', 
                "https://api.iucnredlist.org/api/v4/taxa/scientific_name?genus_name=".urlencode($genusName)."&species_name=".urlencode($speciesName), 
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'H4mxtPMSmNmCDZL1YmFrr85Y7tPJawcyRKhQ'
                    ]
                ]
            );
            
            $data = json_decode($response->getBody(), true);
            
            // Periksa apakah ada data assessment dan ambil yang terbaru (latest)
            if (isset($data['assessments']) && !empty($data['assessments'])) {
                foreach ($data['assessments'] as $assessment) {
                    if (isset($assessment['latest']) && $assessment['latest'] === true) {
                        return $assessment['red_list_category_code'];
                    }
                }
                
                // Jika tidak ada yang latest, ambil yang pertama
                return $data['assessments'][0]['red_list_category_code'];
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('Error fetching IUCN status: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Memeriksa dan memperbarui status excluded_from_quorum berdasarkan persetujuan
     * 
     * @param string $checklistId ID checklist
     * @param string $source Sumber data (burungnesia, kupunesia, fobi)
     * @return int Jumlah identifikasi yang diperbarui
     */
    public function updateExcludedFromQuorumStatus($checklistId, $source = 'fobi')
    {
        try {
            // Ambil semua identifikasi yang excluded_from_quorum=1 dan memiliki persetujuan
            $query = DB::table('taxa_identifications as ti1')
                ->select([
                    'ti1.id',
                    'ti1.taxon_id',
                    'ti1.comment',
                    'ti1.confidence_level',
                    DB::raw('COUNT(DISTINCT CASE WHEN ti2.agrees_with_id = ti1.id AND (ti2.is_withdrawn = false OR ti2.is_withdrawn IS NULL) THEN ti2.user_id END) as agreement_count')
                ])
                ->leftJoin('taxa_identifications as ti2', 'ti1.id', '=', 'ti2.agrees_with_id')
                ->where('ti1.excluded_from_quorum', 1)
                ->where(function($query) {
                    $query->where('ti1.is_withdrawn', false)
                          ->orWhereNull('ti1.is_withdrawn');
                })
                ->whereNull('ti1.deleted_at')
                ->whereNull('ti1.agrees_with_id'); // Hanya identifikasi utama, bukan persetujuan
                
            // Filter berdasarkan sumber data
            if ($source === 'burungnesia') {
                $query->where('ti1.burnes_checklist_id', $checklistId);
            } elseif ($source === 'kupunesia') {
                $query->where('ti1.kupnes_checklist_id', $checklistId);
            } else {
                $query->where('ti1.checklist_id', $checklistId);
            }
            
            $identifications = $query->groupBy('ti1.id', 'ti1.taxon_id', 'ti1.comment', 'ti1.confidence_level')
                ->having('agreement_count', '>', 0)
                ->get();
                
            $updatedCount = 0;
            
            foreach ($identifications as $identification) {
                // Pastikan confidence_level adalah integer
                $confidenceLevel = (int)$identification->confidence_level;
                
                // JANGAN update excluded_from_quorum untuk identifikasi ragu-ragu (confidence_level = 0)
                if ($confidenceLevel === 0) {
                    Log::info('Skipping excluded_from_quorum update for doubtful identification - must remain excluded', [
                        'identification_id' => $identification->id,
                        'confidence_level' => $confidenceLevel,
                        'agreement_count' => $identification->agreement_count,
                        'reason' => 'Doubtful identifications must always be excluded from quorum regardless of agreements'
                    ]);
                    continue;
                }
                
                // Update excluded_from_quorum menjadi 0 karena memiliki persetujuan (hanya untuk non-doubtful)
                DB::table('taxa_identifications')
                    ->where('id', $identification->id)
                    ->update([
                        'excluded_from_quorum' => 0,
                        'comment' => $identification->comment . ' - Identifikasi ini sekarang diperhitungkan dalam kuorum karena telah mendapatkan persetujuan.'
                    ]);
                    
                Log::info('Identification excluded_from_quorum status updated to 0 in batch update', [
                    'identification_id' => $identification->id,
                    'confidence_level' => $confidenceLevel,
                    'agreement_count' => $identification->agreement_count
                ]);
                
                $updatedCount++;
            }
            
            return $updatedCount;
            
        } catch (\Exception $e) {
            Log::error('Error dalam updateExcludedFromQuorumStatus', [
                'checklist_id' => $checklistId,
                'source' => $source,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Memproses konfirmasi hierarkis taksonomi berdasarkan aturan:
     * - Species -> Genus (kuorum) = Confirmed ID (tanpa mencoret species)
     * - Genus -> Species (kuorum) = Research Grade (ID lengkap)
     * - Species -> Subspecies (kuorum) = Research Grade (ID lengkap)
     * 
     * Perhitungan persentase keyakinan:
     * - Format: n setuju taksa X / total identifikasi
     * - 100% hanya untuk research grade atau confirmed id
     * - Jika ada 2 species berbeda dalam satu genus dan imbang -> simpulkan ke genus (needs ID)
     */
    private function processHierarchicalConfirmation($actualId, $taxonAgreements, $totalParticipants)
    {
        // Ambil semua taksa yang memiliki persetujuan
        $allTaxaInfo = [];
        
        foreach ($taxonAgreements as $taxonId => $agreementCount) {
            $taxon = DB::table('taxas')
                ->where('id', $taxonId)
                ->select('id', 'scientific_name', 'taxon_rank', 'kingdom', 'subkingdom', 'superphylum', 'phylum', 'subphylum', 'superclass', 'class', 'subclass', 'infraclass', 'superorder', 'order', 'suborder', 'infraorder', 'superfamily', 'family', 'subfamily', 'supertribe', 'tribe', 'subtribe', 'genus', 'subgenus', 'species', 'subspecies', 'variety', 'form')
                ->first();
                
            if ($taxon) {
                $allTaxaInfo[] = [
                    'taxon' => $taxon,
                    'agreement_count' => $agreementCount
                ];
            }
        }

        Log::info('Processing hierarchical consensus', [
            'total_participants' => $totalParticipants,
            'all_taxa' => array_map(function($item) {
                return [
                    'id' => $item['taxon']->id,
                    'name' => $item['taxon']->scientific_name,
                    'rank' => $item['taxon']->taxon_rank,
                    'agreements' => $item['agreement_count']
                ];
            }, $allTaxaInfo)
        ]);

        // Implementasi logika hierarchical consensus baru
        return $this->resolveHierarchicalConsensus($allTaxaInfo, $totalParticipants, $actualId);
    }

    /**
     * Resolves hierarchical consensus berdasarkan aturan lineage baru
     * 
     * Aturan untuk kasus lintas lineage (Accipitriformes vs Passeriformes):
     * 1. Hitung suara hierarkis (parent + children)
     * 2. Jika ada winner dengan kuorum → Confirmed ID dengan confidence sesuai persentase
     * 3. Jika imbang → Needs ID, update ke common ancestor (Aves)
     * 4. Modal konfirmasi untuk challenge Research Grade
     */
    private function resolveHierarchicalConsensus($allTaxaInfo, $totalParticipants, $checklistId)
    {
        if (empty($allTaxaInfo)) {
            return [
                'taxon_id' => null,
                'max_agreements' => 0,
                'taxa_with_agreements' => 0
            ];
        }

        // Step 1: Cek apakah ada konflik lintas lineage dengan perhitungan hierarkis
        $crossLineageResult = $this->resolveCrossLineageConflict($allTaxaInfo, $totalParticipants, $checklistId);
        if ($crossLineageResult) {
            return $crossLineageResult;
        }

        // Step 2: Cek kasus khusus species + subspecies dalam lineage yang sama
        $speciesSubspeciesResult = $this->handleSpeciesSubspeciesCombination($allTaxaInfo, $totalParticipants);
        if ($speciesSubspeciesResult) {
            return $speciesSubspeciesResult;
        }

        // Step 3: Cek apakah ada species dominan yang mencapai kuorum
        $dominantSpecies = $this->checkDominantSpecies($allTaxaInfo, $totalParticipants);
        if ($dominantSpecies) {
            // Ada species dominan → Research Grade
            return [
                'taxon_id' => $dominantSpecies['taxon']->id,
                'max_agreements' => $dominantSpecies['agreement_count'],
                'taxa_with_agreements' => 1, // Konsensus tercapai
                'grade_hint' => 'research_grade'
            ];
        }

        // Step 3: Cek konsensus di level yang lebih tinggi (genus, family, dll)
        $hierarchicalConsensus = $this->findHierarchicalConsensus($allTaxaInfo, $totalParticipants, $checklistId);
        if ($hierarchicalConsensus) {
            // PERBAIKAN: Jangan override taxa_with_agreements untuk kasus tie
            // Kasus 2 vs 2 dalam superfamily yang sama harus tetap needs ID
            $originalTaxaCount = count($allTaxaInfo);
            
            // Jika ada lebih dari 1 taxa dengan agreement yang sama, ini adalah tie
            $maxAgreements = max(array_column($allTaxaInfo, 'agreement_count'));
            $taxaWithMaxAgreements = array_filter($allTaxaInfo, function($item) use ($maxAgreements) {
                return $item['agreement_count'] == $maxAgreements;
            });
            
            $isTie = count($taxaWithMaxAgreements) > 1;
            
            Log::info('Hierarchical consensus analysis', [
                'original_taxa_count' => $originalTaxaCount,
                'max_agreements' => $maxAgreements,
                'taxa_with_max_agreements' => count($taxaWithMaxAgreements),
                'is_tie' => $isTie,
                'hierarchical_taxon_id' => $hierarchicalConsensus['taxon_id']
            ]);
            
            return [
                'taxon_id' => $hierarchicalConsensus['taxon_id'],
                'max_agreements' => $hierarchicalConsensus['total_agreements'],
                'total_agreements' => $hierarchicalConsensus['total_agreements'], // PERBAIKAN: Tambahkan total_agreements
                'taxa_with_agreements' => $isTie ? $originalTaxaCount : 1, // Preserve tie status
                'grade_hint' => $hierarchicalConsensus['grade_hint'] ?? ($isTie ? 'needs_id' : 'confirmed_id') // PERBAIKAN: Gunakan grade_hint dari hierarchical result
            ];
        }

        // Step 4: Tidak ada konsensus → ambil yang paling banyak dukungan
        $mostAgreed = $this->getMostAgreedTaxon($allTaxaInfo);
        
        // PERBAIKAN: Untuk kasus single taxon dengan agreements yang cukup, berikan confirmed_id
        // Ini mengatasi masalah family rank yang dianggap excluded_from_quorum tapi sebenarnya valid
        $gradeHint = 'needs_id';
        if (count($allTaxaInfo) == 1 && $mostAgreed['agreement_count'] >= 2) {
            $gradeHint = 'confirmed_id';
            Log::info('Single taxon with sufficient agreements - upgrading to confirmed_id', [
                'taxon_name' => $mostAgreed['taxon']->scientific_name,
                'agreements' => $mostAgreed['agreement_count'],
                'reason' => 'Single taxon fallback with quorum'
            ]);
        }
        
        return [
            'taxon_id' => $mostAgreed['taxon']->id,
            'max_agreements' => $mostAgreed['agreement_count'],
            'taxa_with_agreements' => count($allTaxaInfo),
            'grade_hint' => $gradeHint
        ];
    }

    /**
     * Cek apakah ada konflik lintas lineage dengan perhitungan suara hierarkis
     * PERBAIKAN: Jangan anggap sebagai cross-lineage jika masih dalam superfamily yang sama
     * Contoh: Ypthima baldus vs Graphium empedovana (keduanya Papilionoidea) = BUKAN cross-lineage
     */
    private function checkCrossLineageConflict($allTaxaInfo)
    {
        // 1. Cek dulu apakah semua taxa dalam superfamily yang sama
        $superfamilies = [];
        foreach ($allTaxaInfo as $item) {
            $taxon = $item['taxon'];
            if (!empty($taxon->superfamily)) {
                $superfamilies[$taxon->superfamily] = true;
            }
        }
        
        // Jika semua dalam superfamily yang sama, BUKAN cross-lineage conflict
        if (count($superfamilies) == 1) {
            Log::info('Not a cross-lineage conflict - all taxa in same superfamily', [
                'superfamily' => array_keys($superfamilies)[0],
                'reason' => 'Taxa within same superfamily should not be treated as cross-lineage'
            ]);
            return false;
        }
        
        // 2. Jika tidak ada superfamily atau berbeda superfamily, cek family
        $families = [];
        foreach ($allTaxaInfo as $item) {
            $taxon = $item['taxon'];
            if (!empty($taxon->family)) {
                $families[$taxon->family] = true;
            }
        }
        
        // Jika semua dalam family yang sama, BUKAN cross-lineage conflict
        if (count($families) == 1) {
            Log::info('Not a cross-lineage conflict - all taxa in same family', [
                'family' => array_keys($families)[0]
            ]);
            return false;
        }
        
        // 3. Baru cek order untuk konflik lintas lineage
        $orderVotes = [];
        $familyVotes = [];
        $genusVotes = [];
        
        foreach ($allTaxaInfo as $item) {
            $taxon = $item['taxon'];
            $votes = $item['agreement_count'];
            
            // Verifikasi bahwa taksa ini tidak dikecualikan dari kuorum
            $isExcludedFromQuorum = DB::table('taxa_identifications')
                ->where('taxon_id', $taxon->id)
                ->where('excluded_from_quorum', 1)
                ->whereNull('deleted_at')
                ->where(function($query) {
                    $query->where('is_withdrawn', false)
                          ->orWhereNull('is_withdrawn');
                })
                ->exists();

            if ($isExcludedFromQuorum) {
                Log::info('Skipping doubtful taxon in cross lineage check', [
                    'taxon_id' => $taxon->id,
                    'taxon_name' => $taxon->scientific_name,
                    'votes' => $votes
                ]);
                continue; // Skip identifikasi ragu-ragu
            }
            
            // PERBAIKAN: Hitung suara hierarkis berdasarkan level taksonomi dengan support superfamily
            if (!empty($taxon->order)) {
                if (!isset($orderVotes[$taxon->order])) {
                    $orderVotes[$taxon->order] = 0;
                }
                $orderVotes[$taxon->order] += $votes;
            }
            
            // PERBAIKAN: Tambahkan support untuk superfamily
            if (!empty($taxon->superfamily)) {
                if (!isset($familyVotes[$taxon->superfamily])) {
                    $familyVotes[$taxon->superfamily] = 0;
                }
                $familyVotes[$taxon->superfamily] += $votes;
            }
            
            if (!empty($taxon->family)) {
                if (!isset($familyVotes[$taxon->family])) {
                    $familyVotes[$taxon->family] = 0;
                }
                $familyVotes[$taxon->family] += $votes;
            }
            
            if (!empty($taxon->genus)) {
                if (!isset($genusVotes[$taxon->genus])) {
                    $genusVotes[$taxon->genus] = 0;
                }
                $genusVotes[$taxon->genus] += $votes;
            }
        }

        Log::info('Cross lineage conflict check with hierarchical votes', [
            'order_votes' => $orderVotes,
            'family_votes' => $familyVotes,
            'genus_votes' => $genusVotes,
            'order_count' => count($orderVotes),
            'family_count' => count($familyVotes),
            'genus_count' => count($genusVotes)
        ]);

        // Konflik lintas lineage jika ada lebih dari 1 order dengan suara signifikan
        $significantOrders = array_filter($orderVotes, function($votes) {
            return $votes >= 1; // Minimal 1 suara untuk dianggap signifikan
        });
        
        $isRealCrossLineage = count($significantOrders) > 1;
        
        Log::info('Cross lineage conflict determination', [
            'significant_orders' => array_keys($significantOrders),
            'is_cross_lineage' => $isRealCrossLineage,
            'reason' => $isRealCrossLineage ? 'Multiple orders with significant votes' : 'Single order or no significant conflict'
        ]);
        
        return $isRealCrossLineage;
    }

    /**
     * Resolve konflik lintas lineage dengan perhitungan suara hierarkis
     * Kasus: Accipitriformes (1+2 suara) vs Passeriformes (1 suara)
     * PERBAIKAN: Lindungi research grade species dari override oleh cross-lineage conflict
     */
    private function resolveCrossLineageConflict($allTaxaInfo, $totalParticipants, $checklistId)
    {
        // Cek apakah ada konflik lintas lineage
        if (!$this->checkCrossLineageConflict($allTaxaInfo)) {
            return null; // Tidak ada konflik lintas lineage
        }

        // PERBAIKAN: Cek apakah checklist saat ini sudah research grade di level species
        $currentGrade = $this->getCurrentChecklistGrade($checklistId);
        $currentTaxon = $this->getCurrentChecklistTaxon($checklistId);
        
        if ($currentGrade === 'research grade' && $currentTaxon && $currentTaxon->taxon_rank === 'SPECIES') {
            // PERBAIKAN: Cek apakah cross-lineage conflict ini signifikan atau tidak
            $isCrossClassConflict = $this->isCrossClassConflict($allTaxaInfo, $currentTaxon);
            
            Log::info('Protecting research grade species from cross-lineage override', [
                'current_taxon_id' => $currentTaxon->id,
                'current_taxon_name' => $currentTaxon->scientific_name,
                'current_grade' => $currentGrade,
                'is_cross_class_conflict' => $isCrossClassConflict
            ]);
            
            if ($isCrossClassConflict) {
                // Cross-class conflict (misal: burung vs kupu-kupu)
                // Tetap pertahankan research grade species, jangan override
                Log::info('Cross-class conflict detected - maintaining research grade species', [
                    'current_taxon' => $currentTaxon->scientific_name,
                    'current_class' => $currentTaxon->class ?? 'Unknown',
                    'reason' => 'Cross-class conflicts should not override established research grade'
                ]);
                return null;
            }
            
            // Cross-order dalam class yang sama (misal: Accipitriformes vs Columbiformes dalam Aves)
            // Tetap pertahankan research grade species, confidence akan menurun secara natural
            Log::info('Cross-order within same class - maintaining research grade species', [
                'current_taxon' => $currentTaxon->scientific_name,
                'current_order' => $currentTaxon->order ?? 'Unknown',
                'reason' => 'Cross-order conflicts within same class should not override research grade'
            ]);
            return null;
        }

        // Hitung suara hierarkis per order
        $orderHierarchicalVotes = $this->calculateHierarchicalVotes($allTaxaInfo);
        
        Log::info('Cross lineage conflict detected - calculating hierarchical votes', [
            'order_votes' => $orderHierarchicalVotes,
            'total_participants' => $totalParticipants,
            'current_grade' => $currentGrade,
            'current_taxon_rank' => $currentTaxon ? $currentTaxon->taxon_rank : null
        ]);

        // Cari order dengan suara terbanyak
        $winnerOrder = null;
        $maxVotes = 0;
        $secondMaxVotes = 0;
        
        foreach ($orderHierarchicalVotes as $order => $votes) {
            if ($votes > $maxVotes) {
                $secondMaxVotes = $maxVotes;
                $maxVotes = $votes;
                $winnerOrder = $order;
            } elseif ($votes > $secondMaxVotes) {
                $secondMaxVotes = $votes;
            }
        }

        // Hitung confidence percentage
        $totalVotes = array_sum($orderHierarchicalVotes);
        $confidencePercentage = $totalVotes > 0 ? round(($maxVotes / $totalVotes) * 100) : 0;
        
        Log::info('Cross lineage winner analysis', [
            'winner_order' => $winnerOrder,
            'max_votes' => $maxVotes,
            'second_max_votes' => $secondMaxVotes,
            'total_votes' => $totalVotes,
            'confidence_percentage' => $confidencePercentage
        ]);

        // Cek apakah ada kuorum (minimal 2/3 atau mayoritas mutlak)
        $quorumThreshold = max(2, ceil($totalParticipants * 2/3));
        $hasQuorum = $maxVotes >= $quorumThreshold;
        
        // Cek apakah imbang (tie)
        $isTie = ($maxVotes == $secondMaxVotes) && ($maxVotes > 0);
        
        if ($isTie) {
            // Kasus imbang → Needs ID, update ke common ancestor (Aves)
            $commonAncestorId = $this->findCommonAncestor($allTaxaInfo);
            
            Log::info('Cross lineage tie detected - updating to common ancestor', [
                'common_ancestor_id' => $commonAncestorId,
                'tied_votes' => $maxVotes
            ]);
            
            return [
                'taxon_id' => $commonAncestorId,
                'max_agreements' => $maxVotes,
                'taxa_with_agreements' => count($orderHierarchicalVotes), // > 1 untuk Needs ID
                'grade_hint' => 'needs_id',
                'confidence_percentage' => 50 // Imbang = 50%
            ];
        }
        
        // PERBAIKAN: Untuk cross-lineage dengan kuorum, tetap jangan otomatis update ke order
        // Kasus 2 Tachyspiza badia vs 1 Gallus seharusnya tetap research grade Tachyspiza badia
        // saat Gallus dibatalkan, bukan jadi confirmed ID Accipitriformes
        if ($hasQuorum && $winnerOrder) {
            Log::info('Cross lineage winner with quorum - but protecting existing research grade', [
                'winner_order' => $winnerOrder,
                'confidence_percentage' => $confidencePercentage,
                'has_quorum' => $hasQuorum,
                'current_grade' => $currentGrade,
                'reason' => 'Avoiding automatic upgrade to order level that overrides species consensus'
            ]);
            
            // Jangan otomatis update ke order level, biarkan logika lain yang menangani
            return null;
        }
        
        // PERBAIKAN: Untuk cross-lineage tanpa kuorum, jangan otomatis naik ke common ancestor
        // Biarkan logic lain yang menentukan apakah perlu update atau tidak
        Log::info('Cross lineage conflict without quorum - no automatic update to common ancestor', [
            'winner_order' => $winnerOrder,
            'confidence_percentage' => $confidencePercentage,
            'has_quorum' => false,
            'reason' => 'Protecting existing consensus from weak cross-lineage challenges'
        ]);
        
        return null; // Tidak ada update otomatis ke common ancestor
    }

    /**
     * Hitung suara hierarkis per order (parent + children)
     * Contoh: Accipitriformes (1) + Tachyspiza badia (2) = 3 suara total
     */
    private function calculateHierarchicalVotes($allTaxaInfo)
    {
        $orderVotes = [];
        
        foreach ($allTaxaInfo as $item) {
            $taxon = $item['taxon'];
            $votes = $item['agreement_count'];
            
            if (!empty($taxon->order)) {
                if (!isset($orderVotes[$taxon->order])) {
                    $orderVotes[$taxon->order] = 0;
                }
                $orderVotes[$taxon->order] += $votes;
            }
        }
        
        return $orderVotes;
    }

    /**
     * Cari common ancestor dengan hierarki yang tepat
     * PERBAIKAN: Periksa superfamily dulu sebelum naik ke order/class
     */
    private function findCommonAncestor($allTaxaInfo)
    {
        // 1. Cek superfamily dulu (untuk kasus kupu-kupu Papilionoidea)
        $superfamilies = [];
        foreach ($allTaxaInfo as $item) {
            $taxon = $item['taxon'];
            if (!empty($taxon->superfamily)) {
                $superfamilies[$taxon->superfamily] = true;
            }
        }
        
        if (count($superfamilies) == 1) {
            $superfamilyName = array_keys($superfamilies)[0];
            Log::info('Common ancestor found at superfamily level', [
                'superfamily' => $superfamilyName,
                'reason' => 'All taxa share same superfamily'
            ]);
            return $this->findTaxonIdByNameAndRank($superfamilyName, 'SUPERFAMILY');
        }
        
        // 2. Jika tidak ada superfamily yang sama, cek family
        $families = [];
        foreach ($allTaxaInfo as $item) {
            $taxon = $item['taxon'];
            if (!empty($taxon->family)) {
                $families[$taxon->family] = true;
            }
        }
        
        if (count($families) == 1) {
            $familyName = array_keys($families)[0];
            Log::info('Common ancestor found at family level', [
                'family' => $familyName
            ]);
            return $this->findTaxonIdByNameAndRank($familyName, 'FAMILY');
        }
        
        // 3. Cek order
        $orders = [];
        foreach ($allTaxaInfo as $item) {
            $taxon = $item['taxon'];
            if (!empty($taxon->order)) {
                $orders[$taxon->order] = true;
            }
        }
        
        if (count($orders) == 1) {
            $orderName = array_keys($orders)[0];
            Log::info('Common ancestor found at order level', [
                'order' => $orderName
            ]);
            return $this->findTaxonIdByNameAndRank($orderName, 'ORDER');
        }
        
        // 4. Cek class
        $classes = [];
        foreach ($allTaxaInfo as $item) {
            $taxon = $item['taxon'];
            if (!empty($taxon->class)) {
                $classes[$taxon->class] = true;
            }
        }
        
        if (count($classes) == 1) {
            $className = array_keys($classes)[0];
            Log::info('Common ancestor found at class level', [
                'class' => $className
            ]);
            return $this->findTaxonIdByNameAndRank($className, 'CLASS');
        }
        
        // 5. Cek phylum
        $phylums = [];
        foreach ($allTaxaInfo as $item) {
            $taxon = $item['taxon'];
            if (!empty($taxon->phylum)) {
                $phylums[$taxon->phylum] = true;
            }
        }
        
        if (count($phylums) == 1) {
            $phylumName = array_keys($phylums)[0];
            return $this->findTaxonIdByNameAndRank($phylumName, 'PHYLUM');
        }
        
        // 6. Default ke Kingdom
        $kingdoms = [];
        foreach ($allTaxaInfo as $item) {
            $taxon = $item['taxon'];
            if (!empty($taxon->kingdom)) {
                $kingdoms[$taxon->kingdom] = true;
            }
        }
        
        if (count($kingdoms) == 1) {
            $kingdomName = array_keys($kingdoms)[0];
            return $this->findTaxonIdByNameAndRank($kingdomName, 'KINGDOM');
        }
        
        return null;
    }

    /**
     * Get current checklist grade untuk proteksi research grade
     */
    public function getCurrentChecklistGrade($checklistId)
    {
        $assessment = DB::table('taxa_quality_assessments')
            ->where('taxa_id', $checklistId)
            ->first();
            
        if (!$assessment) {
            return null;
        }
        
        return $assessment->grade;
    }
    
    /**
     * PERBAIKAN: Cek apakah ada konflik signifikan dalam identifikasi
     * Konflik signifikan jika ada perbedaan pendapat yang substansial
     */
    private function hasSignificantConflict($taxonAgreements, $totalParticipants)
    {
        if (empty($taxonAgreements) || $totalParticipants < 2) {
            return false;
        }
        
        // Hitung distribusi suara
        $agreementCounts = array_values($taxonAgreements);
        rsort($agreementCounts); // Urutkan dari terbesar
        
        $topAgreement = $agreementCounts[0] ?? 0;
        $secondAgreement = $agreementCounts[1] ?? 0;
        
        // Konflik signifikan jika:
        // 1. Ada lebih dari 2 taksa dengan dukungan
        // 2. Perbedaan antara suara terbanyak dan kedua terbanyak < 50% dari total partisipan
        // 3. Tidak ada konsensus yang jelas (top agreement < 75% dari total)
        
        $hasMultipleTaxa = count($taxonAgreements) > 2;
        $closeCompetition = ($topAgreement - $secondAgreement) < ($totalParticipants * 0.5);
        $noStrongConsensus = $topAgreement < ($totalParticipants * 0.75);
        
        $isSignificant = $hasMultipleTaxa || ($closeCompetition && $noStrongConsensus);
        
        Log::info('Significant conflict analysis', [
            'taxonAgreements' => $taxonAgreements,
            'totalParticipants' => $totalParticipants,
            'topAgreement' => $topAgreement,
            'secondAgreement' => $secondAgreement,
            'hasMultipleTaxa' => $hasMultipleTaxa,
            'closeCompetition' => $closeCompetition,
            'noStrongConsensus' => $noStrongConsensus,
            'isSignificant' => $isSignificant
        ]);
        
        return $isSignificant;
    }
    
    /**
     * PERBAIKAN: Cek apakah ada konflik cross-class (misal: burung vs kupu-kupu)
     */
    private function isCrossClassConflict($allTaxaInfo, $currentTaxon)
    {
        $currentClass = $currentTaxon->class ?? null;
        
        if (!$currentClass) {
            return false;
        }
        
        foreach ($allTaxaInfo as $item) {
            $taxon = $item['taxon'];
            $taxonClass = $taxon->class ?? null;
            
            if ($taxonClass && $taxonClass !== $currentClass) {
                Log::info('Cross-class conflict detected', [
                    'current_class' => $currentClass,
                    'conflicting_class' => $taxonClass,
                    'current_taxon' => $currentTaxon->scientific_name,
                    'conflicting_taxon' => $taxon->scientific_name
                ]);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get current checklist taxon untuk proteksi research grade
     */
    public function getCurrentChecklistTaxon($checklistId)
    {
        $assessment = DB::table('taxa_quality_assessments')
            ->where('taxa_id', $checklistId)
            ->first();
            
        if (!$assessment || !$assessment->taxon_id) {
            return null;
        }
        
        return DB::table('taxas')
            ->where('id', $assessment->taxon_id)
            ->select('id', 'scientific_name', 'taxon_rank', 'superfamily', 'family', 'order')
            ->first();
    }

    /**
     * Get confidence data untuk API endpoint
     * Menghitung confidence percentage dengan aturan lintas lineage
     */
    public function getConfidenceData($checklistId, $source = 'fobi')
    {
        try {
            $actualId = $this->getActualId($checklistId, $source);
            
            // Ambil current quality assessment
            $assessmentTable = match($source) {
                'burungnesia' => 'data_quality_assessments',
                'kupunesia' => 'data_quality_assessments_kupnes',
                default => 'taxa_quality_assessments'
            };
            
            $idColumn = match($source) {
                'burungnesia' => 'burnes_checklist_id',
                'kupunesia' => 'kupnes_checklist_id',
                default => 'taxa_id'
            };
            
            $currentAssessment = DB::table($assessmentTable)
                ->where($idColumn, $actualId)
                ->first();
                
            if (!$currentAssessment) {
                return [
                    'confidence_percentage' => 0,
                    'grade' => 'needs id',
                    'most_agreed_taxon' => null
                ];
            }
            
            // Ambil semua identifikasi untuk checklist ini
            $identificationTable = match($source) {
                'burungnesia' => 'burnes_identifications',
                'kupunesia' => 'kupnes_identifications',
                default => 'taxa_identifications'
            };
            
            $checklistColumn = match($source) {
                'burungnesia' => 'burnes_checklist_id',
                'kupunesia' => 'kupnes_checklist_id',
                default => 'checklist_id'
            };
            
            // Hitung total identifikasi dan agreement untuk taxon yang disepakati
            $currentTaxonId = match($source) {
                'burungnesia' => $currentAssessment->burnes_fauna_id,
                'kupunesia' => $currentAssessment->kupnes_fauna_id,
                default => $currentAssessment->taxon_id
            };
            
            // Hitung total partisipan aktif
            $totalParticipants = DB::table($identificationTable)
                ->where($checklistColumn, $actualId)
                ->where(function($query) {
                    $query->where('is_withdrawn', false)
                          ->orWhereNull('is_withdrawn');
                })
                ->whereNull('agrees_with_id')
                ->where(function($query) {
                    $query->where('excluded_from_quorum', false)
                          ->orWhereNull('excluded_from_quorum')
                          ->orWhere(function($subQuery) {
                              $subQuery->where('excluded_from_quorum', true)
                                       ->whereExists(function($agreementQuery) {
                                           $agreementQuery->select(DB::raw(1))
                                                          ->from('taxa_identifications as ti2')
                                                          ->whereColumn('ti2.agrees_with_id', 'taxa_identifications.id')
                                                          ->where(function($agreeQuery) {
                                                              $agreeQuery->where('ti2.is_withdrawn', false)
                                                                         ->orWhereNull('ti2.is_withdrawn');
                                                          });
                                       });
                          });
                })
                ->distinct('user_id')
                ->count('user_id');
            
            // Hitung agreement untuk taxon yang disepakati (termasuk hierarkis)
            $taxonAgreements = $this->calculateTaxonAgreements($actualId, $source, $currentTaxonId);
            
            $grade = strtolower($currentAssessment->grade);
            $confidencePercentage = 0;
            
            if ($totalParticipants > 0) {
                if ($grade === 'research grade' || $grade === 'confirmed id') {
                    // Untuk grade tinggi, confidence bisa sampai 100%
                    $confidencePercentage = min(100, round(($taxonAgreements / $totalParticipants) * 100));
                } else {
                    // Untuk grade rendah, max 99%
                    $confidencePercentage = min(99, round(($taxonAgreements / $totalParticipants) * 100));
                }
            }
            
            // Ambil data taxon yang disepakati
            $mostAgreedTaxon = null;
            if ($currentTaxonId) {
                $mostAgreedTaxon = DB::table('taxas')
                    ->where('id', $currentTaxonId)
                    ->select('id', 'scientific_name', 'taxon_rank', 'superfamily', 'family', 'order')
                    ->first();
            }
            
            Log::info('Confidence data calculated', [
                'checklistId' => $actualId,
                'source' => $source,
                'grade' => $grade,
                'confidence_percentage' => $confidencePercentage,
                'taxon_agreements' => $taxonAgreements,
                'total_participants' => $totalParticipants,
                'most_agreed_taxon' => $mostAgreedTaxon ? $mostAgreedTaxon->scientific_name : null
            ]);
            
            return [
                'confidence_percentage' => $confidencePercentage,
                'grade' => $grade,
                'most_agreed_taxon' => $mostAgreedTaxon,
                'total_agreements' => $taxonAgreements,
                'total_participants' => $totalParticipants
            ];
            
        } catch (\Exception $e) {
            Log::error('Error calculating confidence data', [
                'checklistId' => $checklistId,
                'source' => $source,
                'error' => $e->getMessage()
            ]);
            
            return [
                'confidence_percentage' => 0,
                'grade' => 'needs id',
                'most_agreed_taxon' => null
            ];
        }
    }

    /**
     * Hitung agreement untuk taxon tertentu dengan logika hierarkis
     */
    private function calculateTaxonAgreements($checklistId, $source, $targetTaxonId)
    {
        $identificationTable = match($source) {
            'burungnesia' => 'burnes_identifications',
            'kupunesia' => 'kupnes_identifications',
            default => 'taxa_identifications'
        };
        
        $checklistColumn = match($source) {
            'burungnesia' => 'burnes_checklist_id',
            'kupunesia' => 'kupnes_checklist_id',
            default => 'checklist_id'
        };
        
        // Ambil target taxon data
        $targetTaxon = DB::table('taxas')->where('id', $targetTaxonId)->first();
        if (!$targetTaxon) {
            return 0;
        }
        
        $totalAgreements = 0;
        
        // Hitung direct identifications dan agreements
        $directIdentifications = DB::table($identificationTable . ' as ti')
            ->where($checklistColumn, $checklistId)
            ->where('ti.taxon_id', $targetTaxonId)
            ->where(function($query) {
                $query->where('ti.is_withdrawn', false)
                      ->orWhereNull('ti.is_withdrawn');
            })
            ->whereNull('ti.agrees_with_id')
            ->count();
            
        $directAgreements = DB::table($identificationTable . ' as ti')
            ->join($identificationTable . ' as ti2', 'ti.agrees_with_id', '=', 'ti2.id')
            ->where('ti2.' . $checklistColumn, $checklistId)
            ->where('ti2.taxon_id', $targetTaxonId)
            ->where(function($query) {
                $query->where('ti.is_withdrawn', false)
                      ->orWhereNull('ti.is_withdrawn');
            })
            ->where(function($query) {
                $query->where('ti2.is_withdrawn', false)
                      ->orWhereNull('ti2.is_withdrawn');
            })
            ->count();
            
        $totalAgreements = $directIdentifications + $directAgreements;
        
        // Untuk lintas lineage, hitung juga hierarchical agreements
        if (strtolower($targetTaxon->taxon_rank) === 'order') {
            $hierarchicalAgreements = DB::table($identificationTable . ' as ti')
                ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                ->where($checklistColumn, $checklistId)
                ->where('t.order', $targetTaxon->order)
                ->where('t.id', '!=', $targetTaxonId) // Exclude direct matches
                ->where(function($query) {
                    $query->where('ti.is_withdrawn', false)
                          ->orWhereNull('ti.is_withdrawn');
                })
                ->whereNull('ti.agrees_with_id')
                ->count();
                
            $totalAgreements += $hierarchicalAgreements;
        }
        
        return $totalAgreements;
    }

    /**
     * Cek apakah ada species yang dominan dan mencapai kuorum
     * Dengan logika khusus untuk subspecies/form/variety setelah research grade
     */
    private function checkDominantSpecies($allTaxaInfo, $totalParticipants)
    {
        $speciesLevel = ['species', 'subspecies', 'variety', 'form', 'subform'];
        $speciesTaxa = array_filter($allTaxaInfo, function($item) use ($speciesLevel) {
            return in_array(strtolower($item['taxon']->taxon_rank), $speciesLevel);
        });

        if (empty($speciesTaxa)) {
            return null;
        }

        // Pisahkan species dan subspecies/form/variety
        $speciesOnly = [];
        $subspeciesVariety = [];
        
        foreach ($speciesTaxa as $item) {
            $rank = strtolower($item['taxon']->taxon_rank);
            if ($rank === 'species') {
                $speciesOnly[] = $item;
            } else if (in_array($rank, ['subspecies', 'variety', 'form', 'subform'])) {
                $subspeciesVariety[] = $item;
            }
        }

        // PERBAIKAN: Cek kasus khusus species vs subspecies dalam lineage yang sama
        if (!empty($speciesOnly) && !empty($subspeciesVariety)) {
            $speciesSubspeciesResult = $this->handleSpeciesVsSubspeciesSameLineage($speciesOnly, $subspeciesVariety, $totalParticipants, $allTaxaInfo);
            if ($speciesSubspeciesResult) {
                return $speciesSubspeciesResult;
            }
        }
        
        // Cek apakah ada species yang sudah research grade
        $researchGradeSpecies = $this->checkExistingResearchGradeSpecies($speciesOnly, $totalParticipants);
        
        if ($researchGradeSpecies && !empty($subspeciesVariety)) {
            // Ada species research grade + usulan subspecies/variety
            return $this->handleSubspeciesAfterResearchGrade($researchGradeSpecies, $subspeciesVariety, $totalParticipants);
        }

        // Logika normal: cari yang paling dominan
        $maxAgreements = 0;
        $dominantSpecies = null;

        foreach ($speciesTaxa as $item) {
            if ($item['agreement_count'] > $maxAgreements) {
                $maxAgreements = $item['agreement_count'];
                $dominantSpecies = $item;
            }
        }

        // Cek apakah mencapai kuorum (minimal 2/3 atau mayoritas mutlak)
        $quorumThreshold = max(2, ceil($totalParticipants * 2/3));
        
        Log::info('Checking dominant species', [
            'max_agreements' => $maxAgreements,
            'quorum_threshold' => $quorumThreshold,
            'dominant_species' => $dominantSpecies ? $dominantSpecies['taxon']->scientific_name : null,
            'total_participants' => $totalParticipants
        ]);
        
        if ($dominantSpecies && $maxAgreements >= $quorumThreshold) {
            // Cek apakah ada species lain yang bersaing kuat
            $otherSpeciesCount = 0;
            $competingSpecies = [];
            
            foreach ($speciesTaxa as $item) {
                if ($item['taxon']->id !== $dominantSpecies['taxon']->id && $item['agreement_count'] > 0) {
                    $otherSpeciesCount += $item['agreement_count'];
                    $competingSpecies[] = [
                        'name' => $item['taxon']->scientific_name,
                        'agreements' => $item['agreement_count']
                    ];
                }
            }
            
            Log::info('Dominant species analysis', [
                'dominant_species' => $dominantSpecies['taxon']->scientific_name,
                'dominant_agreements' => $maxAgreements,
                'competing_species' => $competingSpecies,
                'other_species_total' => $otherSpeciesCount
            ]);
            
            // PERBAIKAN: Pastikan confidence_percentage ada untuk dominant species
            if (!isset($dominantSpecies['confidence_percentage'])) {
                // PERBAIKAN: Gunakan logika confidence yang lebih tepat untuk dominant species
                // Jika species dominan dan tidak ada kompetisi kuat, confidence lebih tinggi
                $competingAgreements = $otherSpeciesCount;
                $dominantAgreements = $maxAgreements;
                
                if ($competingAgreements == 0) {
                    // Tidak ada kompetisi sama sekali = confidence tinggi
                    $dominantSpecies['confidence_percentage'] = min(100, ($dominantAgreements / $totalParticipants) * 100 + 25);
                } else {
                    // Ada kompetisi = hitung berdasarkan margin kemenangan
                    $margin = $dominantAgreements - $competingAgreements;
                    $basePercentage = ($dominantAgreements / $totalParticipants) * 100;
                    $marginBonus = ($margin / $totalParticipants) * 25; // Bonus untuk margin kemenangan
                    $dominantSpecies['confidence_percentage'] = min(100, $basePercentage + $marginBonus);
                }
                
                Log::info('Adding confidence percentage to dominant species', [
                    'species' => $dominantSpecies['taxon']->scientific_name,
                    'agreements' => $maxAgreements,
                    'competing_agreements' => $competingAgreements,
                    'total_participants' => $totalParticipants,
                    'base_percentage' => ($dominantAgreements / $totalParticipants) * 100,
                    'final_confidence_percentage' => $dominantSpecies['confidence_percentage'],
                    'calculation_type' => $competingAgreements == 0 ? 'no_competition' : 'with_competition'
                ]);
            }
            
            // Species dominan dengan kuorum = Research Grade
            return $dominantSpecies;
        }

        return null;
    }

    /**
     * Cek apakah ada species yang sudah mencapai research grade
     */
    private function checkExistingResearchGradeSpecies($speciesOnly, $totalParticipants)
    {
        $quorumThreshold = max(2, ceil($totalParticipants * 2/3));
        
        foreach ($speciesOnly as $item) {
            if ($item['agreement_count'] >= $quorumThreshold) {
                Log::info('Found existing research grade species', [
                    'species' => $item['taxon']->scientific_name,
                    'agreements' => $item['agreement_count'],
                    'quorum_threshold' => $quorumThreshold
                ]);
                return $item;
            }
        }
        
        return null;
    }

    /**
     * Handle kasus subspecies/form/variety setelah species research grade
     */
    private function handleSubspeciesAfterResearchGrade($researchGradeSpecies, $subspeciesVariety, $totalParticipants)
    {
        $quorumThreshold = max(2, ceil($totalParticipants * 2/3));
        
        // Cari subspecies/variety dengan dukungan terbanyak
        $maxSubspeciesAgreements = 0;
        $dominantSubspecies = null;
        
        foreach ($subspeciesVariety as $item) {
            if ($item['agreement_count'] > $maxSubspeciesAgreements) {
                $maxSubspeciesAgreements = $item['agreement_count'];
                $dominantSubspecies = $item;
            }
        }
        
        Log::info('Handling subspecies after research grade', [
            'research_grade_species' => $researchGradeSpecies['taxon']->scientific_name,
            'species_agreements' => $researchGradeSpecies['agreement_count'],
            'dominant_subspecies' => $dominantSubspecies ? $dominantSubspecies['taxon']->scientific_name : null,
            'subspecies_agreements' => $maxSubspeciesAgreements,
            'quorum_threshold' => $quorumThreshold
        ]);
        
        // Jika subspecies/variety mencapai kuorum DAN lebih dominan dari species
        if ($dominantSubspecies && 
            $maxSubspeciesAgreements >= $quorumThreshold && 
            $maxSubspeciesAgreements > $researchGradeSpecies['agreement_count']) {
            
            Log::info('Subspecies/variety has quorum and is more dominant - updating to subspecies', [
                'subspecies' => $dominantSubspecies['taxon']->scientific_name,
                'subspecies_agreements' => $maxSubspeciesAgreements,
                'species_agreements' => $researchGradeSpecies['agreement_count']
            ]);
            
            // Update ke subspecies/variety tapi tetap research grade
            return $dominantSubspecies;
        }
        
        // Jika subspecies/variety belum mencapai kuorum atau tidak lebih dominan
        // Tetap gunakan species research grade
        Log::info('Subspecies/variety insufficient - keeping research grade species', [
            'keeping_species' => $researchGradeSpecies['taxon']->scientific_name,
            'subspecies_needs_more_support' => $dominantSubspecies ? $dominantSubspecies['taxon']->scientific_name : 'none'
        ]);
        
        return $researchGradeSpecies;
    }

    /**
     * Handle kasus khusus species + subspecies dalam lineage yang sama
     * Contoh: Accipitriformes (1) + Tachyspiza badia (2) + Tachyspiza badia poliopsis (1)
     */
    private function handleSpeciesSubspeciesCombination($allTaxaInfo, $totalParticipants)
    {
        // Pisahkan berdasarkan rank
        $orderLevel = [];
        $speciesLevel = [];
        $subspeciesLevel = [];
        
        foreach ($allTaxaInfo as $item) {
            $rank = strtolower($item['taxon']->taxon_rank);
            
            if ($rank === 'order') {
                $orderLevel[] = $item;
            } elseif ($rank === 'species') {
                $speciesLevel[] = $item;
            } elseif (in_array($rank, ['subspecies', 'variety', 'form', 'subform'])) {
                $subspeciesLevel[] = $item;
            }
        }
        
        // Cek apakah ada kombinasi order + species + subspecies dari genus yang sama
        if (!empty($orderLevel) && !empty($speciesLevel) && !empty($subspeciesLevel)) {
            return $this->resolveOrderSpeciesSubspeciesCombination($orderLevel, $speciesLevel, $subspeciesLevel, $totalParticipants);
        }
        
        // Cek apakah ada kombinasi species + subspecies dari genus yang sama
        if (!empty($speciesLevel) && !empty($subspeciesLevel)) {
            return $this->resolveSpeciesSubspeciesCombination($speciesLevel, $subspeciesLevel, $totalParticipants, $allTaxaInfo);
        }
        
        return null;
    }

    /**
     * Resolve kombinasi order + species + subspecies
     */
    private function resolveOrderSpeciesSubspeciesCombination($orderLevel, $speciesLevel, $subspeciesLevel, $totalParticipants)
    {
        $quorumThreshold = max(2, ceil($totalParticipants * 2/3));
        
        // Hitung total suara untuk species + subspecies (genus level)
        $genusVotes = 0;
        $dominantSpecies = null;
        $maxSpeciesAgreements = 0;
        
        // Gabungkan suara species dan subspecies dari genus yang sama
        foreach ($speciesLevel as $species) {
            $genusVotes += $species['agreement_count'];
            if ($species['agreement_count'] > $maxSpeciesAgreements) {
                $maxSpeciesAgreements = $species['agreement_count'];
                $dominantSpecies = $species;
            }
        }
        
        foreach ($subspeciesLevel as $subspecies) {
            // Cek apakah subspecies dari genus yang sama dengan species
            if (!empty($speciesLevel)) {
                $speciesGenus = $speciesLevel[0]['taxon']->genus;
                if ($subspecies['taxon']->genus === $speciesGenus) {
                    $genusVotes += $subspecies['agreement_count'];
                }
            }
        }
        
        // Hitung suara order
        $orderVotes = 0;
        $dominantOrder = null;
        foreach ($orderLevel as $order) {
            $orderVotes += $order['agreement_count'];
            $dominantOrder = $order;
        }
        
        Log::info('Resolving order + species + subspecies combination', [
            'order_votes' => $orderVotes,
            'genus_votes' => $genusVotes,
            'total_participants' => $totalParticipants,
            'quorum_threshold' => $quorumThreshold,
            'dominant_order' => $dominantOrder ? $dominantOrder['taxon']->scientific_name : null,
            'dominant_species' => $dominantSpecies ? $dominantSpecies['taxon']->scientific_name : null
        ]);
        
        // Jika genus votes (species + subspecies) mencapai kuorum dan lebih dominan
        if ($genusVotes >= $quorumThreshold && $genusVotes > $orderVotes && $dominantSpecies) {
            Log::info('Genus level (species + subspecies) wins over order', [
                'genus_votes' => $genusVotes,
                'order_votes' => $orderVotes,
                'winner_species' => $dominantSpecies['taxon']->scientific_name
            ]);
            
            return [
                'taxon_id' => $dominantSpecies['taxon']->id,
                'max_agreements' => $dominantSpecies['agreement_count'],
                'taxa_with_agreements' => 1,
                'grade_hint' => 'research_grade'
            ];
        }
        
        // Jika order lebih dominan atau genus belum kuorum
        if ($dominantOrder && $orderVotes >= $quorumThreshold) {
            Log::info('Order level wins over genus', [
                'order_votes' => $orderVotes,
                'genus_votes' => $genusVotes,
                'winner_order' => $dominantOrder['taxon']->scientific_name
            ]);
            
            return [
                'taxon_id' => $dominantOrder['taxon']->id,
                'max_agreements' => $orderVotes,
                'taxa_with_agreements' => 1,
                'grade_hint' => 'confirmed_id'
            ];
        }
        
        return null;
    }

    /**
     * Resolve kombinasi species + subspecies saja
     * Dengan aturan subspecies ⊂ species (subspecies adalah bagian dari species)
     */
    private function resolveSpeciesSubspeciesCombination($speciesLevel, $subspeciesLevel, $totalParticipants, $allTaxaInfo = [])
    {
        $quorumThreshold = max(2, ceil($totalParticipants * 2/3));
        
        // PERBAIKAN: Cek apakah ada family/genus proposal yang dominan
        // Jika ada, species dan subspecies diperlakukan sebagai competing proposals terpisah
        $hasHigherRankProposal = false;
        $higherRankAgreements = 0;
        
        foreach ($allTaxaInfo as $item) {
            $rank = strtolower($item['taxon']->taxon_rank);
            if (in_array($rank, ['family', 'subfamily', 'tribe', 'subtribe', 'genus', 'subgenus'])) {
                $hasHigherRankProposal = true;
                $higherRankAgreements = max($higherRankAgreements, $item['agreement_count']);
            }
        }
        
        // Cari species dan subspecies terdominan
        $maxSpeciesAgreements = 0;
        $maxSubspeciesAgreements = 0;
        
        foreach ($speciesLevel as $species) {
            $maxSpeciesAgreements = max($maxSpeciesAgreements, $species['agreement_count']);
        }
        
        foreach ($subspeciesLevel as $subspecies) {
            $maxSubspeciesAgreements = max($maxSubspeciesAgreements, $subspecies['agreement_count']);
        }
        
        // Jika ada higher rank proposal yang dominan, return null (treat as separate)
        if ($hasHigherRankProposal && $higherRankAgreements >= max($maxSpeciesAgreements, $maxSubspeciesAgreements)) {
            Log::info('Higher rank proposal dominates in species-subspecies combination - treating separately', [
                'higher_rank_agreements' => $higherRankAgreements,
                'max_species_agreements' => $maxSpeciesAgreements,
                'max_subspecies_agreements' => $maxSubspeciesAgreements
            ]);
            
            return null; // Let higher rank proposal win through normal hierarchical consensus
        }
        
        // Cari species terdominan
        $dominantSpecies = null;
        $maxSpeciesAgreements = 0;
        
        foreach ($speciesLevel as $species) {
            if ($species['agreement_count'] > $maxSpeciesAgreements) {
                $maxSpeciesAgreements = $species['agreement_count'];
                $dominantSpecies = $species;
            }
        }
        
        // Cari subspecies yang merupakan bagian dari species yang sama
        $sameSpeciesSubspecies = [];
        $differentSpeciesSubspecies = [];
        
        foreach ($subspeciesLevel as $subspecies) {
            if ($dominantSpecies && $this->isSubspeciesOfSpecies($subspecies['taxon'], $dominantSpecies['taxon'])) {
                $sameSpeciesSubspecies[] = $subspecies;
            } else {
                $differentSpeciesSubspecies[] = $subspecies;
            }
        }
        
        // Jika ada subspecies dari species yang sama
        if (!empty($sameSpeciesSubspecies)) {
            return $this->handleSameSpeciesSubspecies($dominantSpecies, $sameSpeciesSubspecies, $totalParticipants, $quorumThreshold, $allTaxaInfo);
        }
        
        // Jika subspecies dari species yang berbeda, cari yang terdominan
        $dominantSubspecies = null;
        $maxSubspeciesAgreements = 0;
        
        foreach ($subspeciesLevel as $subspecies) {
            if ($subspecies['agreement_count'] > $maxSubspeciesAgreements) {
                $maxSubspeciesAgreements = $subspecies['agreement_count'];
                $dominantSubspecies = $subspecies;
            }
        }
        
        Log::info('Resolving species + subspecies combination (different species)', [
            'species_agreements' => $maxSpeciesAgreements,
            'subspecies_agreements' => $maxSubspeciesAgreements,
            'quorum_threshold' => $quorumThreshold,
            'dominant_species' => $dominantSpecies ? $dominantSpecies['taxon']->scientific_name : null,
            'dominant_subspecies' => $dominantSubspecies ? $dominantSubspecies['taxon']->scientific_name : null
        ]);
        
        // Jika subspecies lebih dominan dan mencapai kuorum
        if ($dominantSubspecies && 
            $maxSubspeciesAgreements >= $quorumThreshold && 
            $maxSubspeciesAgreements > $maxSpeciesAgreements) {
            
            Log::info('Subspecies wins over species (different species)', [
                'winner_subspecies' => $dominantSubspecies['taxon']->scientific_name,
                'subspecies_agreements' => $maxSubspeciesAgreements,
                'species_agreements' => $maxSpeciesAgreements
            ]);
            
            return [
                'taxon_id' => $dominantSubspecies['taxon']->id,
                'taxon' => $dominantSubspecies['taxon'], // PERBAIKAN: Tambahkan taxon object
                'max_agreements' => $maxSubspeciesAgreements,
                'agreement_count' => $maxSubspeciesAgreements, // PERBAIKAN: Tambahkan agreement_count
                'taxa_with_agreements' => 1,
                'grade_hint' => 'research_grade'
            ];
        }
        
        // Jika species tetap dominan
        if ($dominantSpecies && $maxSpeciesAgreements >= $quorumThreshold) {
            Log::info('Species wins over subspecies (different species)', [
                'winner_species' => $dominantSpecies['taxon']->scientific_name,
                'species_agreements' => $maxSpeciesAgreements,
                'subspecies_agreements' => $maxSubspeciesAgreements
            ]);
            
            return [
                'taxon_id' => $dominantSpecies['taxon']->id,
                'taxon' => $dominantSpecies['taxon'], // PERBAIKAN: Tambahkan taxon object
                'max_agreements' => $maxSpeciesAgreements,
                'agreement_count' => $maxSpeciesAgreements, // PERBAIKAN: Tambahkan agreement_count
                'taxa_with_agreements' => 1,
                'grade_hint' => 'research_grade'
            ];
        }
        
        return null;
    }

    /**
     * Cek apakah subspecies adalah bagian dari species yang sama
     */
    private function isSubspeciesOfSpecies($subspeciesTaxon, $speciesTaxon)
    {
        // Cek apakah subspecies memiliki nama species yang sama
        return $subspeciesTaxon->species === $speciesTaxon->species && 
               $subspeciesTaxon->genus === $speciesTaxon->genus;
    }
    
    /**
     * PERBAIKAN: Handle kasus khusus species vs subspecies dalam lineage yang sama
     * Contoh: Centropus bengalensis +2 vs Centropus bengalensis javanensis +2
     * Hasil: Research grade dengan community ID di species
     */
    private function handleSpeciesVsSubspeciesSameLineage($speciesOnly, $subspeciesVariety, $totalParticipants, $allTaxaInfo = [])
    {
        $quorumThreshold = max(2, ceil($totalParticipants * 2/3));
        
        // PERBAIKAN: Cek apakah ada family/genus proposal yang dominan
        // Jika ada, species dan subspecies diperlakukan sebagai competing proposals terpisah
        $hasHigherRankProposal = false;
        $higherRankAgreements = 0;
        
        foreach ($allTaxaInfo as $item) {
            $rank = strtolower($item['taxon']->taxon_rank);
            if (in_array($rank, ['family', 'subfamily', 'tribe', 'subtribe', 'genus', 'subgenus'])) {
                $hasHigherRankProposal = true;
                $higherRankAgreements = max($higherRankAgreements, $item['agreement_count']);
            }
        }
        
        // Cari species dan subspecies yang paling dominan
        $dominantSpecies = null;
        $maxSpeciesAgreements = 0;
        
        foreach ($speciesOnly as $species) {
            if ($species['agreement_count'] > $maxSpeciesAgreements) {
                $maxSpeciesAgreements = $species['agreement_count'];
                $dominantSpecies = $species;
            }
        }
        
        $dominantSubspecies = null;
        $maxSubspeciesAgreements = 0;
        
        foreach ($subspeciesVariety as $subspecies) {
            if ($subspecies['agreement_count'] > $maxSubspeciesAgreements) {
                $maxSubspeciesAgreements = $subspecies['agreement_count'];
                $dominantSubspecies = $subspecies;
            }
        }
        
        // Jika ada higher rank proposal yang dominan, return null (treat as separate)
        if ($hasHigherRankProposal && $higherRankAgreements >= max($maxSpeciesAgreements, $maxSubspeciesAgreements)) {
            Log::info('Higher rank proposal dominates in species-subspecies same lineage - treating separately', [
                'higher_rank_agreements' => $higherRankAgreements,
                'max_species_agreements' => $maxSpeciesAgreements,
                'max_subspecies_agreements' => $maxSubspeciesAgreements
            ]);
            
            return null; // Let higher rank proposal win through normal hierarchical consensus
        }
        
        // Cek apakah subspecies adalah bagian dari species yang sama
        if ($dominantSpecies && $dominantSubspecies && 
            $this->isSubspeciesOfSpecies($dominantSubspecies['taxon'], $dominantSpecies['taxon'])) {
            
            Log::info('Species vs Subspecies same lineage detected', [
                'species' => $dominantSpecies['taxon']->scientific_name,
                'species_agreements' => $maxSpeciesAgreements,
                'subspecies' => $dominantSubspecies['taxon']->scientific_name,
                'subspecies_agreements' => $maxSubspeciesAgreements,
                'quorum_threshold' => $quorumThreshold
            ]);
            
            // ATURAN KHUSUS: Jika imbang atau subspecies tidak dominan signifikan,
            // community ID tetap di species dengan research grade
            if ($maxSpeciesAgreements == $maxSubspeciesAgreements || 
                $maxSubspeciesAgreements < $quorumThreshold ||
                $maxSubspeciesAgreements <= $maxSpeciesAgreements) {
                
                Log::info('Species vs Subspecies same lineage - keeping species (research grade)', [
                    'reason' => 'tie_or_subspecies_insufficient',
                    'species_agreements' => $maxSpeciesAgreements,
                    'subspecies_agreements' => $maxSubspeciesAgreements,
                    'result_taxon' => $dominantSpecies['taxon']->scientific_name
                ]);
                
                return [
                    'taxon_id' => $dominantSpecies['taxon']->id,
                    'taxon' => $dominantSpecies['taxon'], // PERBAIKAN: Tambahkan taxon object
                    'max_agreements' => $maxSpeciesAgreements,
                    'agreement_count' => $maxSpeciesAgreements, // PERBAIKAN: Tambahkan agreement_count
                    'taxa_with_agreements' => 1, // Konsensus di species
                    'grade_hint' => 'research_grade'
                ];
            }
            
            // Jika subspecies sangat dominan dan mencapai kuorum tinggi
            if ($maxSubspeciesAgreements > $maxSpeciesAgreements && 
                $maxSubspeciesAgreements >= $quorumThreshold) {
                
                Log::info('Species vs Subspecies same lineage - subspecies wins (research grade)', [
                    'reason' => 'subspecies_dominant',
                    'species_agreements' => $maxSpeciesAgreements,
                    'subspecies_agreements' => $maxSubspeciesAgreements,
                    'result_taxon' => $dominantSubspecies['taxon']->scientific_name
                ]);
                
                return [
                    'taxon_id' => $dominantSubspecies['taxon']->id,
                    'taxon' => $dominantSubspecies['taxon'], // PERBAIKAN: Tambahkan taxon object
                    'max_agreements' => $maxSubspeciesAgreements,
                    'agreement_count' => $maxSubspeciesAgreements, // PERBAIKAN: Tambahkan agreement_count
                    'taxa_with_agreements' => 1, // Konsensus di subspecies
                    'grade_hint' => 'research_grade'
                ];
            }
        }
        
        return null; // Tidak ada kasus khusus species-subspecies same lineage
    }

    /**
     * Handle kasus subspecies dari species yang sama
     * Aturan: subspecies ⊂ species (tidak lintas lineage)
     */
    private function handleSameSpeciesSubspecies($dominantSpecies, $sameSpeciesSubspecies, $totalParticipants, $quorumThreshold, $allTaxaInfo = [])
    {
        // PERBAIKAN: Cek apakah ada family/genus proposal yang dominan
        // Jika ada, subspecies TIDAK mendukung species
        $hasHigherRankProposal = false;
        $higherRankAgreements = 0;
        
        foreach ($allTaxaInfo as $item) {
            $rank = strtolower($item['taxon']->taxon_rank);
            if (in_array($rank, ['family', 'subfamily', 'tribe', 'subtribe', 'genus', 'subgenus'])) {
                $hasHigherRankProposal = true;
                $higherRankAgreements = max($higherRankAgreements, $item['agreement_count']);
            }
        }
        
        // Cari subspecies terdominan dari species yang sama
        $dominantSubspecies = null;
        $maxSubspeciesAgreements = 0;
        
        foreach ($sameSpeciesSubspecies as $subspecies) {
            if ($subspecies['agreement_count'] > $maxSubspeciesAgreements) {
                $maxSubspeciesAgreements = $subspecies['agreement_count'];
                $dominantSubspecies = $subspecies;
            }
        }
        
        $speciesAgreements = $dominantSpecies['agreement_count'];
        
        // PERBAIKAN: Jika ada higher rank proposal yang dominan, jangan gabungkan votes
        if ($hasHigherRankProposal && $higherRankAgreements >= max($speciesAgreements, $maxSubspeciesAgreements)) {
            Log::info('Higher rank proposal dominates - species/subspecies treated separately', [
                'higher_rank_agreements' => $higherRankAgreements,
                'species_agreements' => $speciesAgreements,
                'subspecies_agreements' => $maxSubspeciesAgreements
            ]);
            
            // Treat as separate competing proposals
            return null;
        }
        
        $totalVotes = $speciesAgreements + $maxSubspeciesAgreements;
        
        Log::info('Handling same species subspecies (subspecies ⊂ species)', [
            'species' => $dominantSpecies['taxon']->scientific_name,
            'species_agreements' => $speciesAgreements,
            'dominant_subspecies' => $dominantSubspecies ? $dominantSubspecies['taxon']->scientific_name : null,
            'subspecies_agreements' => $maxSubspeciesAgreements,
            'total_votes' => $totalVotes,
            'total_participants' => $totalParticipants,
            'quorum_threshold' => $quorumThreshold
        ]);
        
        // Jika subspecies mencapai kuorum DAN lebih dominan dari species
        if ($dominantSubspecies && 
            $maxSubspeciesAgreements >= $quorumThreshold && 
            $maxSubspeciesAgreements > $speciesAgreements) {
            
            // PERBAIKAN: Confidence subspecies = (subspecies_agreements - species_agreements) / total_participants * 100
            // Karena subspecies harus "mengalahkan" species untuk menang
            $subspeciesConfidence = (($maxSubspeciesAgreements - $speciesAgreements) / $totalParticipants) * 100;
            $subspeciesConfidence = max(0, min(100, $subspeciesConfidence)); // Clamp between 0-100
            
            Log::info('Subspecies wins within same species - updating to subspecies', [
                'winner_subspecies' => $dominantSubspecies['taxon']->scientific_name,
                'subspecies_agreements' => $maxSubspeciesAgreements,
                'species_agreements' => $speciesAgreements,
                'subspecies_confidence' => round($subspeciesConfidence, 2),
                'calculation' => "({$maxSubspeciesAgreements} - {$speciesAgreements}) / {$totalParticipants} * 100"
            ]);
            
            return [
                'taxon_id' => $dominantSubspecies['taxon']->id,
                'taxon' => $dominantSubspecies['taxon'], // PERBAIKAN: Tambahkan taxon object
                'max_agreements' => $maxSubspeciesAgreements,
                'agreement_count' => $maxSubspeciesAgreements, // PERBAIKAN: Tambahkan agreement_count
                'taxa_with_agreements' => 1,
                'grade_hint' => 'research_grade',
                'confidence_percentage' => round($subspeciesConfidence, 2) // PERBAIKAN: Tambahkan confidence
            ];
        }
        
        // Jika species + subspecies total mencapai kuorum, tetap di species level
        if ($totalVotes >= $quorumThreshold) {
            // PERBAIKAN: Untuk species dominan atau imbang = 100% confidence
            // Karena subspecies mendukung species (hierarchical consensus)
            $speciesConfidence = 100;
            
            Log::info('Species level maintained (subspecies insufficient)', [
                'species' => $dominantSpecies['taxon']->scientific_name,
                'species_agreements' => $speciesAgreements,
                'subspecies_agreements' => $maxSubspeciesAgreements,
                'total_votes' => $totalVotes,
                'species_confidence' => $speciesConfidence,
                'reason' => $speciesAgreements >= $maxSubspeciesAgreements ? 'species_dominant_or_tie' : 'subspecies_insufficient'
            ]);
            
            return [
                'taxon_id' => $dominantSpecies['taxon']->id,
                'taxon' => $dominantSpecies['taxon'], // PERBAIKAN: Tambahkan taxon object
                'max_agreements' => $totalVotes, // PERBAIKAN: Gunakan total votes untuk hierarchical consensus
                'agreement_count' => $totalVotes, // PERBAIKAN: Total votes species + subspecies
                'taxa_with_agreements' => 1,
                'grade_hint' => 'research_grade',
                'confidence_percentage' => $speciesConfidence // PERBAIKAN: 100% untuk species dominan/imbang
            ];
        }
        
        // Belum ada yang mencapai kuorum
        Log::info('Neither species nor subspecies has sufficient support', [
            'species_agreements' => $speciesAgreements,
            'subspecies_agreements' => $maxSubspeciesAgreements,
            'quorum_needed' => $quorumThreshold
        ]);
        
        return null;
    }

    /**
     * Cari konsensus di level hierarki yang lebih tinggi
     */
    private function findHierarchicalConsensus($allTaxaInfo, $totalParticipants, $checklistId)
    {
        // PERBAIKAN: Hierarchical consensus memerlukan minimal 2 partisipan
        // Jika hanya 1 partisipan, tidak ada "consensus" - hanya ada 1 pendapat
        if ($totalParticipants < 2) {
            Log::info('Skipping hierarchical consensus - insufficient participants', [
                'total_participants' => $totalParticipants,
                'reason' => 'need_at_least_2_participants_for_consensus'
            ]);
            return null;
        }
        
        // Kumpulkan semua taxa berdasarkan level hierarki
        $hierarchyLevels = [
            'genus' => [],
            'subfamily' => [],
            'tribe' => [],
            'subtribe' => [],
            'family' => [],
            'superfamily' => [],
            'infraorder' => [],
            'suborder' => [],
            'order' => [],
            'superorder' => [],
            'infraclass' => [],
            'subclass' => [],
            'class' => [],
            'superclass' => [],
            'subphylum' => [],
            'phylum' => [],
            'superphylum' => [],
            'subkingdom' => [],
            'kingdom' => []
        ];

        // HANYA hitung taksa yang TIDAK dikecualikan dari kuorum untuk hierarchical consensus
        foreach ($allTaxaInfo as $item) {
            $taxon = $item['taxon'];
            $agreements = $item['agreement_count'];

            // PERBAIKAN: Hanya skip jika SEMUA identifikasi untuk taxon ini excluded_from_quorum
            // Jika ada identifikasi yang tidak excluded, tetap hitung taxon ini
            $totalIdentifications = DB::table('taxa_identifications')
                ->where('taxon_id', $taxon->id)
                ->whereNull('deleted_at')
                ->where(function($query) {
                    $query->where('is_withdrawn', false)
                          ->orWhereNull('is_withdrawn');
                })
                ->count();
                
            $excludedIdentifications = DB::table('taxa_identifications')
                ->where('taxon_id', $taxon->id)
                ->where('excluded_from_quorum', 1)
                ->whereNull('deleted_at')
                ->where(function($query) {
                    $query->where('is_withdrawn', false)
                          ->orWhereNull('is_withdrawn');
                })
                ->count();

            // Skip hanya jika SEMUA identifikasi excluded
            if ($totalIdentifications > 0 && $excludedIdentifications == $totalIdentifications) {
                Log::info('Skipping fully doubtful taxon in hierarchical consensus', [
                    'taxon_id' => $taxon->id,
                    'taxon_name' => $taxon->scientific_name,
                    'agreements' => $agreements,
                    'total_identifications' => $totalIdentifications,
                    'excluded_identifications' => $excludedIdentifications
                ]);
                continue; // Skip hanya jika semua identifikasi ragu-ragu
            }

            // Tambahkan ke setiap level hierarki yang sesuai
            // PERBAIKAN: Akumulasi votes dari level spesifik ke level yang lebih tinggi
            $hierarchyOrder = [
                'subspecies', 'species', 'subgenus', 'genus', 'subtribe', 'tribe', 
                'subfamily', 'family', 'superfamily', 'infraorder', 'suborder', 
                'order', 'superorder', 'infraclass', 'subclass', 'class', 
                'superclass', 'subphylum', 'phylum', 'superphylum', 
                'subkingdom', 'kingdom'
            ];
            
            foreach ($hierarchyOrder as $level) {
                if (!empty($taxon->$level)) {
                    if (!isset($hierarchyLevels[$level][$taxon->$level])) {
                        $hierarchyLevels[$level][$taxon->$level] = 0;
                    }
                    $hierarchyLevels[$level][$taxon->$level] += $agreements;
                    
                    // KUNCI PERBAIKAN: Jika ini adalah vote untuk species/genus, 
                    // maka vote ini juga berkontribusi ke family level
                    // Contoh: Centropus sinensis (genus Centropus, family Cuculidae) 
                    // vote-nya juga dihitung untuk Cuculidae
                    if (in_array($level, ['subspecies', 'species', 'subgenus', 'genus']) && !empty($taxon->family)) {
                        if (!isset($hierarchyLevels['family'][$taxon->family])) {
                            $hierarchyLevels['family'][$taxon->family] = 0;
                        }
                        $hierarchyLevels['family'][$taxon->family] += $agreements;
                        
                        Log::info('Accumulating vote to family level', [
                            'original_level' => $level,
                            'original_taxon' => $taxon->$level,
                            'family' => $taxon->family,
                            'agreements' => $agreements,
                            'family_total' => $hierarchyLevels['family'][$taxon->family]
                        ]);
                    }
                }
            }
        }

        Log::info('Hierarchical consensus levels', [
            'hierarchy_levels' => $hierarchyLevels,
            'total_participants' => $totalParticipants,
            'quorum_threshold' => ceil($totalParticipants * 2/3)
        ]);

        // Cek apakah ada usulan family eksplisit (termasuk yang excluded_from_quorum)
        $hasExplicitFamily = false;
        $explicitFamilyNames = [];
        
        // Cek dari allTaxaInfo (non-doubtful)
        foreach ($allTaxaInfo as $item) {
            if (strtolower($item['taxon']->taxon_rank) === 'family') {
                $hasExplicitFamily = true;
                $explicitFamilyNames[] = $item['taxon']->family ?? $item['taxon']->scientific_name;
            }
        }
        
        // PERBAIKAN: Cek juga dari semua identifikasi termasuk yang doubtful
        // untuk mendeteksi family proposals yang mungkin di-exclude
        $allIdentifications = DB::table('taxa_identifications as ti')
            ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
            ->where('ti.checklist_id', $checklistId)
            ->whereNull('ti.deleted_at')
            ->where(function($query) {
                $query->where('ti.is_withdrawn', false)
                      ->orWhereNull('ti.is_withdrawn');
            })
            ->select('t.taxon_rank', 't.family', 't.scientific_name')
            ->get();
            
        foreach ($allIdentifications as $identification) {
            if (strtolower($identification->taxon_rank) === 'family') {
                $hasExplicitFamily = true;
                $familyName = $identification->family ?? $identification->scientific_name;
                if (!in_array($familyName, $explicitFamilyNames)) {
                    $explicitFamilyNames[] = $familyName;
                }
            }
        }

        Log::info('Explicit family check', [
            'has_explicit_family' => $hasExplicitFamily,
            'explicit_family_names' => $explicitFamilyNames,
            'family_agreements' => $hierarchyLevels['family'] ?? []
        ]);

        // PERBAIKAN: Analisis konflik family vs species/subspecies
        $familyAgreements = [];
        $speciesSubspeciesAgreements = [];
        
        // Kumpulkan agreements untuk family level
        if (isset($hierarchyLevels['family'])) {
            $familyAgreements = $hierarchyLevels['family'];
        }
        
        // Kumpulkan agreements untuk species/subspecies dari allTaxaInfo
        foreach ($allTaxaInfo as $item) {
            $rank = strtolower($item['taxon']->taxon_rank);
            if (in_array($rank, ['species', 'subspecies', 'variety', 'form'])) {
                $speciesSubspeciesAgreements[] = $item['agreement_count'];
            }
        }
        
        $maxFamilyAgreements = !empty($familyAgreements) ? max($familyAgreements) : 0;
        $maxSpeciesSubspeciesAgreements = !empty($speciesSubspeciesAgreements) ? max($speciesSubspeciesAgreements) : 0;
        
        Log::info('Family vs Species/Subspecies conflict analysis', [
            'max_family_agreements' => $maxFamilyAgreements,
            'max_species_subspecies_agreements' => $maxSpeciesSubspeciesAgreements,
            'has_explicit_family' => $hasExplicitFamily,
            'family_agreements' => $familyAgreements
        ]);
        
        // PRIORITAS: Cek family consensus jika ada explicit family dan dominan
        if ($hasExplicitFamily && !empty($familyAgreements)) {
            foreach ($familyAgreements as $taxonName => $totalAgreements) {
                $quorumThreshold = max(2, ceil($totalParticipants * 2/3));
                
                // PERBAIKAN: Jika family dominan atau setara dengan species/subspecies
                // dan ada explicit family proposal, prioritaskan family
                $shouldPrioritizeFamily = $totalAgreements >= $maxSpeciesSubspeciesAgreements;
                
                Log::info('Checking explicit family consensus', [
                    'family_name' => $taxonName,
                    'total_agreements' => $totalAgreements,
                    'quorum_threshold' => $quorumThreshold,
                    'should_prioritize' => $shouldPrioritizeFamily,
                    'explicit_family_names' => $explicitFamilyNames
                ]);
                
                if ($shouldPrioritizeFamily && $totalAgreements >= $quorumThreshold) {
                    $taxonId = $this->findTaxonIdByNameAndRank($taxonName, 'FAMILY');
                    if ($taxonId) {
                        Log::info('Family consensus found (priority over species/subspecies)', [
                            'level' => 'family',
                            'taxon_name' => $taxonName,
                            'taxon_id' => $taxonId,
                            'total_agreements' => $totalAgreements,
                            'beats_species_subspecies' => $totalAgreements >= $maxSpeciesSubspeciesAgreements,
                            'source' => 'explicit_family_proposal'
                        ]);
                        
                        // Ambil taxon object untuk return
                        $taxonObject = DB::table('taxas')->where('id', $taxonId)->first();
                        
                        return [
                            'taxon_id' => $taxonId,
                            'total_agreements' => $totalAgreements,
                            'level' => 'family',
                            'taxon_name' => $taxonName,
                            'taxon' => $taxonObject,
                            'agreement_count' => $totalAgreements,
                            'grade_hint' => 'confirmed_id'  // PERBAIKAN: Tambahkan grade_hint
                        ];
                    }
                }
            }
        }

        // Cek setiap level untuk konsensus (dari yang paling spesifik)
        foreach ($hierarchyLevels as $level => $taxa) {
            foreach ($taxa as $taxonName => $totalAgreements) {
                $quorumThreshold = max(2, ceil($totalParticipants * 2/3));
                
                Log::info('Checking hierarchical consensus', [
                    'level' => $level,
                    'taxon_name' => $taxonName,
                    'total_agreements' => $totalAgreements,
                    'quorum_threshold' => $quorumThreshold,
                    'has_quorum' => $totalAgreements >= $quorumThreshold
                ]);
                
                if ($totalAgreements >= $quorumThreshold) {
                    // Cari taxon_id untuk level ini
                    $taxonId = $this->findTaxonIdByNameAndRank($taxonName, strtoupper($level));
                    if ($taxonId) {
                        Log::info('Hierarchical consensus found', [
                            'level' => $level,
                            'taxon_name' => $taxonName,
                            'taxon_id' => $taxonId,
                            'total_agreements' => $totalAgreements
                        ]);
                        
                        // Ambil taxon object untuk return
                        $taxonObject = DB::table('taxas')->where('id', $taxonId)->first();
                        
                        return [
                            'taxon_id' => $taxonId,
                            'total_agreements' => $totalAgreements,
                            'level' => $level,
                            'taxon_name' => $taxonName,
                            'taxon' => $taxonObject,
                            'agreement_count' => $totalAgreements,
                            'grade_hint' => 'confirmed_id'  // PERBAIKAN: Tambahkan grade_hint
                        ];
                    }
                }
            }
        }

        Log::info('No hierarchical consensus found');
        return null;
    }

    /**
     * Cari taxon berdasarkan nama dan rank dengan support semua level taksa
     * PERBAIKAN: Support superfamily dan semua rank taksa
     */
    private function findTaxonIdByNameAndRank($taxonName, $rank)
    {
        // Mapping rank ke kolom database untuk pencarian alternatif
        $rankToColumn = [
            'SUPERFAMILY' => 'superfamily',
            'FAMILY' => 'family',
            'SUBFAMILY' => 'subfamily',
            'TRIBE' => 'tribe',
            'SUBTRIBE' => 'subtribe',
            'GENUS' => 'genus',
            'SUBGENUS' => 'subgenus',
            'SPECIES' => 'species',
            'SUBSPECIES' => 'subspecies',
            'VARIETY' => 'variety',
            'FORM' => 'form',
            'ORDER' => 'order',
            'SUBORDER' => 'suborder',
            'INFRAORDER' => 'infraorder',
            'CLASS' => 'class',
            'SUBCLASS' => 'subclass',
            'INFRACLASS' => 'infraclass',
            'PHYLUM' => 'phylum',
            'SUBPHYLUM' => 'subphylum',
            'KINGDOM' => 'kingdom',
            'SUBKINGDOM' => 'subkingdom'
        ];
        
        // Cari berdasarkan scientific_name dan taxon_rank
        $taxon = DB::table('taxas')
            ->where('scientific_name', $taxonName)
            ->where('taxon_rank', $rank)
            ->first();
            
        if ($taxon) {
            Log::info('Found taxon by scientific_name and rank', [
                'taxon_name' => $taxonName,
                'rank' => $rank,
                'taxon_id' => $taxon->id
            ]);
            return $taxon->id;
        }
        
        // Jika tidak ditemukan, cari berdasarkan kolom hierarki
        if (isset($rankToColumn[$rank])) {
            $column = $rankToColumn[$rank];
            $taxon = DB::table('taxas')
                ->where($column, $taxonName)
                ->where('taxon_rank', $rank)
                ->first();
                
            if ($taxon) {
                Log::info('Found taxon by hierarchy column', [
                    'taxon_name' => $taxonName,
                    'rank' => $rank,
                    'column' => $column,
                    'taxon_id' => $taxon->id
                ]);
                return $taxon->id;
            }
            
            // Cari tanpa filter rank jika masih tidak ditemukan
            $taxon = DB::table('taxas')
                ->where($column, $taxonName)
                ->orderBy('taxon_rank')
                ->first();
                
            if ($taxon) {
                Log::info('Found taxon by hierarchy column without rank filter', [
                    'taxon_name' => $taxonName,
                    'requested_rank' => $rank,
                    'found_rank' => $taxon->taxon_rank,
                    'column' => $column,
                    'taxon_id' => $taxon->id
                ]);
                return $taxon->id;
            }
        }
        
        Log::warning('Taxon not found', [
            'taxon_name' => $taxonName,
            'rank' => $rank
        ]);
        
        return null;
    }

    /**
     * Cari taxon berdasarkan level hierarki
     */
    private function findTaxonByLevel($levelValue, $levelColumn)
    {
        return DB::table('taxas')
            ->where($levelColumn, $levelValue)
            ->where('taxon_rank', strtoupper(str_replace('sub', '', $levelColumn)))
            ->first();
    }

    /**
     * Ambil taxon dengan dukungan terbanyak
     */
    private function getMostAgreedTaxon($allTaxaInfo)
    {
        $maxAgreements = 0;
        $mostAgreed = null;

        foreach ($allTaxaInfo as $item) {
            if ($item['agreement_count'] > $maxAgreements) {
                $maxAgreements = $item['agreement_count'];
                $mostAgreed = $item;
            }
        }

        return $mostAgreed;
    }

    /**
     * Menyelesaikan konflik hierarkis antara beberapa taksa yang mencapai kuorum
     * Dengan aturan lineage yang lebih komprehensif
     */
    private function resolveHierarchicalConflict($taxaWithQuorum, $actualId, $allTaxaInfo = [], $totalParticipants = 0)
    {
        // Urutkan berdasarkan spesifisitas (species > genus > family, dst)
        $rankOrder = [
            'subspecies' => 1,
            'variety' => 2,
            'form' => 3,
            'species' => 4,
            'genus' => 5,
            'family' => 6,
            'order' => 7,
            'class' => 8,
            'phylum' => 9,
            'kingdom' => 10
        ];

        usort($taxaWithQuorum, function($a, $b) use ($rankOrder) {
            $rankA = $rankOrder[strtolower($a['taxon']->taxon_rank)] ?? 99;
            $rankB = $rankOrder[strtolower($b['taxon']->taxon_rank)] ?? 99;
            return $rankA - $rankB;
        });

        // Cek apakah ada hubungan hierarkis
        for ($i = 0; $i < count($taxaWithQuorum); $i++) {
            for ($j = $i + 1; $j < count($taxaWithQuorum); $j++) {
                $lowerRankTaxon = $taxaWithQuorum[$i]['taxon']; // Rank lebih rendah (lebih spesifik)
                $higherRankTaxon = $taxaWithQuorum[$j]['taxon']; // Rank lebih tinggi (lebih umum)

                if ($this->isInSameTaxonomicLineage($lowerRankTaxon, $higherRankTaxon)) {
                    Log::info('Hierarchical relationship found', [
                        'lower_rank' => [
                            'id' => $lowerRankTaxon->id,
                            'name' => $lowerRankTaxon->scientific_name,
                            'rank' => $lowerRankTaxon->taxon_rank
                        ],
                        'higher_rank' => [
                            'id' => $higherRankTaxon->id,
                            'name' => $higherRankTaxon->scientific_name,
                            'rank' => $higherRankTaxon->taxon_rank
                        ]
                    ]);

                    // Implementasi aturan hierarki:
                    $lowerRank = strtolower($lowerRankTaxon->taxon_rank);
                    $higherRank = strtolower($higherRankTaxon->taxon_rank);

                    // Implementasi aturan lineage (inline taxa):
                    // 1. Jika usulan awal species, lalu ada usulan genus (masih satu lineage)
                    //    → bila genus mencapai kuorum maka grade = confirmed id, tanpa mencoret species
                    // 2. Jika usulan awal genus, lalu ada usulan species (masih satu lineage) 
                    //    → bila species mencapai kuorum maka grade = research grade, tanpa mencoret genus
                    
                    // Species lebih spesifik, jadi prioritaskan species jika keduanya punya kuorum
                    if ($lowerRank === 'species' && $higherRank === 'genus') {
                        // Cek mana yang datang lebih dulu berdasarkan waktu identifikasi
                        $speciesTime = $this->getFirstIdentificationTime($actualId, $lowerRankTaxon->id);
                        $genusTime = $this->getFirstIdentificationTime($actualId, $higherRankTaxon->id);
                        
                        if ($genusTime < $speciesTime) {
                            // Genus dulu, lalu species -> species menjadi research grade
                            Log::info('Genus -> Species hierarchy: Using species as research grade');
                            return [
                                'taxon_id' => $lowerRankTaxon->id,
                                'max_agreements' => $taxaWithQuorum[$i]['agreement_count'],
                                'taxa_count' => 1,
                                'grade_hint' => 'research grade'
                            ];
                        } else {
                            // Species dulu, lalu genus -> genus menjadi confirmed id
                            Log::info('Species -> Genus hierarchy: Using genus as confirmed ID');
                            return [
                                'taxon_id' => $higherRankTaxon->id,
                                'max_agreements' => $taxaWithQuorum[$j]['agreement_count'],
                                'taxa_count' => 1,
                                'grade_hint' => 'confirmed id'
                            ];
                        }
                    }
                    // Species -> Subspecies: Subspecies menjadi "research grade"
                    elseif (in_array($lowerRank, ['subspecies', 'variety', 'form']) && $higherRank === 'species') {
                        Log::info('Species -> Subspecies hierarchy: Using subspecies as research grade');
                        return [
                            'taxon_id' => $lowerRankTaxon->id,
                            'max_agreements' => $taxaWithQuorum[$i]['agreement_count'],
                            'taxa_count' => 1
                        ];
                    }
                    // Default: gunakan yang lebih spesifik
                    else {
                        Log::info('Default hierarchy: Using more specific taxon');
                        return [
                            'taxon_id' => $lowerRankTaxon->id,
                            'max_agreements' => $taxaWithQuorum[$i]['agreement_count'],
                            'taxa_count' => 1
                        ];
                    }
                }
            }
        }

        // Jika tidak ada hubungan hierarkis, gunakan yang memiliki persetujuan terbanyak
        $maxAgreements = 0;
        $selectedTaxon = null;
        foreach ($taxaWithQuorum as $item) {
            if ($item['agreement_count'] > $maxAgreements) {
                $maxAgreements = $item['agreement_count'];
                $selectedTaxon = $item['taxon'];
            }
        }

        Log::info('No hierarchical relationship, using taxon with most agreements');
        return [
            'taxon_id' => $selectedTaxon->id,
            'max_agreements' => $maxAgreements,
            'taxa_count' => count($taxaWithQuorum) // Masih ada konflik
        ];
    }

    /**
     * Menghitung persentase keyakinan berdasarkan hierarchical consensus
     * Format: n setuju taksa X / total identifikasi * 100
     * Menangani kasus degradasi dari species ke genus/family
     */
    public function calculateConfidencePercentage($maxAgreements, $totalParticipants, $taxonId = null, $checklistId = null)
    {
        if ($totalParticipants == 0) {
            return ['percentage' => 0, 'taxon_name' => null];
        }
        
        Log::info('Calculating confidence percentage', [
            'max_agreements' => $maxAgreements,
            'total_participants' => $totalParticipants,
            'taxon_id' => $taxonId,
            'checklist_id' => $checklistId
        ]);
        
        // PRIORITAS 1: Cek hierarchical consensus terlebih dahulu
        if ($checklistId) {
            $hierarchicalResult = $this->checkHierarchicalConsensusForConfidence($checklistId, $totalParticipants);
            if ($hierarchicalResult) {
                Log::info('Using hierarchical consensus for confidence', [
                    'result' => $hierarchicalResult
                ]);
                return $hierarchicalResult;
            }
        }
        
        // PRIORITAS 2: Cek apakah ada species yang pernah mencapai research grade
        $speciesConsensus = $this->checkSpeciesDegradation($checklistId, $taxonId);
        if ($speciesConsensus['has_species_history']) {
            $speciesPercentage = ($speciesConsensus['species_agreements'] / $totalParticipants) * 100;
            return [
                'percentage' => round($speciesPercentage, 2),
                'taxon_name' => $speciesConsensus['species_name']
            ];
        }
        
        // PRIORITAS 3: Cek genus consensus untuk kasus hierarchical consensus
        $consensus = $this->checkGenusConsensus($checklistId, $taxonId);
        if ($consensus['has_consensus']) {
            return [
                'percentage' => 100,
                'taxon_name' => $consensus['consensus_name']
            ];
        }
        
        $percentage = ($maxAgreements / $totalParticipants) * 100;
        
        // Jika ada taxonId, ambil nama takson untuk ditampilkan
        if ($taxonId) {
            $taxon = DB::table('taxas')->find($taxonId);
            $rank = strtolower($taxon->taxon_rank ?? '');
            $hasQuorum = $maxAgreements >= max(2, ceil($totalParticipants * 2/3));
            
            // Confirmed ID: genus ke atas dengan kuorum
            if (in_array($rank, ['genus', 'family', 'order', 'class', 'phylum', 'kingdom']) && $hasQuorum) {
                return [
                    'percentage' => 100,
                    'taxon_name' => $taxon->scientific_name
                ];
            }
            
            return [
                'percentage' => min(round($percentage, 2), 99),
                'taxon_name' => $taxon->scientific_name
            ];
        }
        
        return [
            'percentage' => min(round($percentage, 2), 99),
            'taxon_name' => null
        ];
    }

    /**
     * Cek hierarchical consensus khusus untuk confidence percentage
     */
    private function checkHierarchicalConsensusForConfidence($checklistId, $totalParticipants)
    {
        // Ambil semua identifikasi aktif
        $identifications = DB::table('taxa_identifications')
            ->where('checklist_id', $checklistId)
            ->where(function($query) {
                $query->where('is_withdrawn', false)
                      ->orWhereNull('is_withdrawn');
            })
            ->whereNull('deleted_at')
            ->get();

        if ($identifications->count() < 2) {
            return null;
        }

        // Hitung agreements per taxon (satu per user)
        $taxonAgreements = [];
        $userTaxonMap = [];
        
        foreach ($identifications as $identification) {
            $taxonId = $identification->taxon_id;
            $userId = $identification->user_id;
            
            if (!isset($userTaxonMap[$userId][$taxonId])) {
                if (!isset($taxonAgreements[$taxonId])) {
                    $taxonAgreements[$taxonId] = 0;
                }
                $taxonAgreements[$taxonId]++;
                $userTaxonMap[$userId][$taxonId] = true;
            }
        }

        // Ambil data taxa untuk semua identifikasi
        $allTaxaInfo = [];
        foreach ($taxonAgreements as $taxonId => $agreementCount) {
            $taxon = DB::table('taxas')->where('id', $taxonId)->first();
            if ($taxon) {
                $allTaxaInfo[] = [
                    'taxon' => $taxon,
                    'agreement_count' => $agreementCount
                ];
            }
        }

        // PRIORITAS 1: Gunakan hierarchical consensus terlebih dahulu
        // Karena untuk confirmed_id, kita perlu menggunakan consensus taxon, bukan dominant species
        $hierarchicalResult = $this->resolveHierarchicalConsensus($allTaxaInfo, $totalParticipants, $checklistId);
        
        Log::info('Hierarchical result for confidence calculation', [
            'hierarchical_result' => $hierarchicalResult,
            'has_grade_hint' => isset($hierarchicalResult['grade_hint']) ? $hierarchicalResult['grade_hint'] : 'none'
        ]);
        
        if ($hierarchicalResult && isset($hierarchicalResult['grade_hint'])) {
            // Ambil nama taxon dari taxon_id
            $consensusTaxon = DB::table('taxas')->find($hierarchicalResult['taxon_id']);
            
            if ($consensusTaxon) {
                // Untuk confirmed_id, gunakan perhitungan berdasarkan max_agreements
                if ($hierarchicalResult['grade_hint'] === 'confirmed_id') {
                    // PERBAIKAN: Pastikan percentage tidak melebihi 100%
                    // Untuk hierarchical consensus, gunakan total_agreements jika ada
                    $agreements = isset($hierarchicalResult['total_agreements']) ? 
                        $hierarchicalResult['total_agreements'] : $hierarchicalResult['max_agreements'];
                    $percentage = min(100, ($agreements / $totalParticipants) * 100);
                    
                    Log::info('Hierarchical consensus found for confidence', [
                        'taxon_id' => $hierarchicalResult['taxon_id'],
                        'taxon_name' => $consensusTaxon->scientific_name,
                        'agreements_used' => $agreements,
                        'max_agreements' => $hierarchicalResult['max_agreements'] ?? null,
                        'total_agreements' => $hierarchicalResult['total_agreements'] ?? null,
                        'total_participants' => $totalParticipants,
                        'calculated_percentage' => $percentage,
                        'capped_at_100' => $percentage == 100 && ($agreements / $totalParticipants) * 100 > 100
                    ]);
                    
                    return [
                        'percentage' => round($percentage, 2),
                        'taxon_name' => $consensusTaxon->scientific_name
                    ];
                }
                
                // Untuk needs_id (common ancestor), gunakan confidence percentage dari hierarchical result
                if ($hierarchicalResult['grade_hint'] === 'needs_id') {
                    $percentage = isset($hierarchicalResult['confidence_percentage']) ? 
                        min(100, $hierarchicalResult['confidence_percentage']) : 100;
                    
                    Log::info('Hierarchical consensus needs_id found for confidence', [
                        'taxon_id' => $hierarchicalResult['taxon_id'],
                        'taxon_name' => $consensusTaxon->scientific_name,
                        'confidence_percentage' => $percentage,
                        'grade_hint' => 'needs_id'
                    ]);
                    
                    return [
                        'percentage' => $percentage,
                        'taxon_name' => $consensusTaxon->scientific_name
                    ];
                }
                
                // PERBAIKAN: Untuk research_grade, gunakan confidence percentage dari hierarchical result
                if ($hierarchicalResult['grade_hint'] === 'research_grade') {
                    $percentage = isset($hierarchicalResult['confidence_percentage']) ? 
                        min(100, $hierarchicalResult['confidence_percentage']) : 
                        min(100, ($hierarchicalResult['max_agreements'] / $totalParticipants) * 100);
                    
                    Log::info('Hierarchical consensus research_grade found for confidence', [
                        'taxon_id' => $hierarchicalResult['taxon_id'],
                        'taxon_name' => $consensusTaxon->scientific_name,
                        'confidence_percentage' => $percentage,
                        'max_agreements' => $hierarchicalResult['max_agreements'],
                        'total_participants' => $totalParticipants,
                        'grade_hint' => 'research_grade'
                    ]);
                    
                    return [
                        'percentage' => round($percentage, 2),
                        'taxon_name' => $consensusTaxon->scientific_name
                    ];
                }
            }
        }

        // PRIORITAS 2: Fallback ke dominant species jika tidak ada hierarchical consensus
        // PERBAIKAN: Gunakan confidence calculation yang konsisten dengan handleSameSpeciesSubspecies
        $dominantSpecies = $this->checkDominantSpecies($allTaxaInfo, $totalParticipants);
        if ($dominantSpecies) {
            // Cek apakah ada confidence_percentage yang sudah dihitung dari checkDominantSpecies
            $percentage = isset($dominantSpecies['confidence_percentage']) ? 
                $dominantSpecies['confidence_percentage'] : 
                ($dominantSpecies['agreement_count'] / $totalParticipants) * 100;
            
            // PERBAIKAN: Jika tidak ada confidence khusus dan hanya satu species dominan,
            // gunakan logika confidence yang lebih tepat
            if (!isset($dominantSpecies['confidence_percentage'])) {
                // Hitung jumlah species lain yang berkompetisi
                $speciesCount = 0;
                $otherAgreements = 0;
                
                foreach ($allTaxaInfo as $item) {
                    $rank = strtolower($item['taxon']->taxon_rank);
                    if (in_array($rank, ['species', 'subspecies', 'variety', 'form'])) {
                        $speciesCount++;
                        if ($item['taxon']->id !== $dominantSpecies['taxon']->id) {
                            $otherAgreements += $item['agreement_count'];
                        }
                    }
                }
                
                if ($speciesCount == 1) {
                    // Hanya ada satu species = confidence tinggi
                    $percentage = min(100, ($dominantSpecies['agreement_count'] / $totalParticipants) * 100 + 30);
                } else if ($otherAgreements == 0) {
                    // Ada species lain tapi tidak ada yang dapat votes = confidence tinggi
                    $percentage = min(100, ($dominantSpecies['agreement_count'] / $totalParticipants) * 100 + 25);
                }
                
                Log::info('Enhanced confidence calculation for single dominant species', [
                    'species_count' => $speciesCount,
                    'other_agreements' => $otherAgreements,
                    'enhanced_percentage' => $percentage
                ]);
            }
            
            Log::info('Dominant species found for confidence (fallback)', [
                'taxon_id' => $dominantSpecies['taxon']->id,
                'taxon_name' => $dominantSpecies['taxon']->scientific_name,
                'agreements' => $dominantSpecies['agreement_count'],
                'max_agreements' => $dominantSpecies['max_agreements'] ?? $dominantSpecies['agreement_count'],
                'has_confidence_percentage' => isset($dominantSpecies['confidence_percentage']),
                'calculated_percentage' => $percentage,
                'total_participants' => $totalParticipants
            ]);
            
            return [
                'percentage' => round($percentage, 2),
                'taxon_name' => $dominantSpecies['taxon']->scientific_name
            ];
        }

        return null;
    }

    /**
     * Cek degradasi species - apakah ada species yang pernah mencapai research grade
     */
    public function checkSpeciesDegradation($checklistId, $currentTaxonId)
    {
        // Ambil history identifikasi untuk melihat apakah pernah ada species
        $speciesHistory = DB::table('taxa_identification_histories')
            ->join('taxas', 'taxa_identification_histories.taxa_id', '=', 'taxas.id')
            ->where('taxa_identification_histories.checklist_id', $checklistId)
            ->where('taxas.taxon_rank', 'SPECIES')
            ->orderBy('taxa_identification_histories.created_at', 'desc')
            ->first();

        if ($speciesHistory) {
            // Hitung berapa banyak yang masih setuju dengan species ini
            $speciesAgreements = DB::table('taxa_identifications')
                ->where('checklist_id', $checklistId)
                ->where('taxon_id', $speciesHistory->taxa_id)
                ->whereNull('is_withdrawn')
                ->count();

            if ($speciesAgreements > 0) {
                return [
                    'has_species_history' => true,
                    'species_agreements' => $speciesAgreements,
                    'species_name' => $speciesHistory->scientific_name,
                    'species_id' => $speciesHistory->taxa_id
                ];
            }
        }

        return ['has_species_history' => false];
    }

    /**
     * Cek hierarchical consensus - genus, species, dan subspecies
     */
    public function checkGenusConsensus($checklistId, $currentTaxonId)
    {
        // Ambil semua identifikasi aktif (tidak withdrawn)
        $identifications = DB::table('taxa_identifications')
            ->where('checklist_id', $checklistId)
            ->whereNull('is_withdrawn')
            ->get();

        if ($identifications->count() < 2) {
            return ['has_consensus' => false, 'consensus_type' => null, 'consensus_name' => null];
        }

        // Ambil data taxa untuk semua identifikasi
        $taxaIds = $identifications->pluck('taxon_id')->unique();
        $taxaData = DB::table('taxas')
            ->whereIn('id', $taxaIds)
            ->get()
            ->keyBy('id');

        // Cek subspecies consensus (species yang sama dengan subspecies berbeda)
        $speciesGroups = [];
        foreach ($taxaIds as $taxaId) {
            $taxa = $taxaData->get($taxaId);
            if ($taxa && in_array(strtolower($taxa->taxon_rank), ['species', 'subspecies'])) {
                $speciesKey = $taxa->genus . ' ' . explode(' ', $taxa->scientific_name)[1] ?? '';
                if (!isset($speciesGroups[$speciesKey])) {
                    $speciesGroups[$speciesKey] = [];
                }
                $speciesGroups[$speciesKey][] = $taxa;
            }
        }

        // Cek apakah ada species consensus (semua identifikasi adalah species yang sama atau subspecies dari species yang sama)
        foreach ($speciesGroups as $speciesName => $speciesTaxa) {
            if (count($speciesTaxa) === $taxaIds->count()) {
                $currentTaxon = DB::table('taxas')->find($currentTaxonId);
                if ($currentTaxon) {
                    $currentSpeciesKey = $currentTaxon->genus . ' ' . (explode(' ', $currentTaxon->scientific_name)[1] ?? '');
                    if ($currentSpeciesKey === $speciesName) {
                        return [
                            'has_consensus' => true,
                            'consensus_type' => 'species',
                            'consensus_name' => $speciesName,
                            'total_identifications' => $identifications->count()
                        ];
                    }
                }
            }
        }

        // Cek genus consensus (semua identifikasi dalam genus yang sama)
        $genera = $taxaData->pluck('genus')->unique()->filter();
        
        if ($genera->count() === 1) {
            $consensusGenus = $genera->first();
            
            // Cek apakah current taxon adalah genus yang sama
            $currentTaxon = DB::table('taxas')->find($currentTaxonId);
            if ($currentTaxon && $currentTaxon->genus === $consensusGenus) {
                return [
                    'has_consensus' => true,
                    'consensus_type' => 'genus', 
                    'consensus_name' => $consensusGenus,
                    'total_identifications' => $identifications->count()
                ];
            }
        }

        return ['has_consensus' => false, 'consensus_type' => null, 'consensus_name' => null];
    }

    /**
     * Cek konflik species dalam genus yang sama
     * Jika ada 2 species berbeda dalam satu genus dan imbang, simpulkan ke genus
     */
    private function checkSpeciesConflictInSameGenus($allTaxaInfo, $totalParticipants)
    {
        // Filter hanya species
        $speciesTaxa = array_filter($allTaxaInfo, function($item) {
            return strtolower($item['taxon']->taxon_rank) === 'species';
        });
        
        if (count($speciesTaxa) < 2) {
            return null;
        }
        
        // Kelompokkan berdasarkan genus
        $genuGroups = [];
        foreach ($speciesTaxa as $item) {
            $genus = $item['taxon']->genus;
            if (!isset($genuGroups[$genus])) {
                $genuGroups[$genus] = [];
            }
            $genuGroups[$genus][] = $item;
        }
        
        // Cek apakah ada genus dengan lebih dari 1 species
        foreach ($genuGroups as $genus => $speciesGroup) {
            if (count($speciesGroup) >= 2) {
                // Cek apakah mereka imbang atau salah satu unggul
                $maxAgreement = 0;
                $totalAgreements = 0;
                $dominantSpecies = null;
                
                foreach ($speciesGroup as $species) {
                    $totalAgreements += $species['agreement_count'];
                    if ($species['agreement_count'] > $maxAgreement) {
                        $maxAgreement = $species['agreement_count'];
                        $dominantSpecies = $species;
                    }
                }
                
                // Cek apakah ada yang dominan (lebih dari 50% dari total dalam genus)
                $dominanceRatio = $maxAgreement / max($totalAgreements, 1);
                
                if ($dominanceRatio > 0.5) {
                    // Ada species yang dominan -> low quality ID
                    Log::info('Species conflict in genus: dominant species found', [
                        'genus' => $genus,
                        'dominant_species' => $dominantSpecies['taxon']->scientific_name,
                        'dominance_ratio' => $dominanceRatio
                    ]);
                    
                    return [
                        'taxon_id' => $dominantSpecies['taxon']->id,
                        'max_agreements' => $dominantSpecies['agreement_count'],
                        'taxa_with_agreements' => count($speciesGroup),
                        'grade_hint' => 'low quality ID',
                        'confidence_percentage' => $this->calculateConfidencePercentage($dominantSpecies['agreement_count'], $totalParticipants)
                    ];
                } else {
                    // Cek apakah ada identifikasi dengan force_conflict
                    $hasForceConflict = DB::table('taxa_identifications')
                        ->where('checklist_id', $checklistId)
                        ->where('force_conflict', 1)
                        ->where(function($query) {
                            $query->where('is_withdrawn', false)
                                  ->orWhereNull('is_withdrawn');
                        })
                        ->exists();
                    
                    if ($hasForceConflict) {
                        // Jika ada force_conflict, tetap sebagai konflik (Low Quality ID)
                        Log::info('Species conflict in genus: force conflict detected, maintaining as Low Quality ID', [
                            'genus' => $genus,
                            'species_count' => count($speciesGroup)
                        ]);
                        
                        // Return konflik tanpa konsolidasi
                        return [
                            'taxon_id' => $mostAgreedTaxon['taxon_id'],
                            'max_agreements' => $mostAgreedTaxon['agreement_count'],
                            'taxa_with_agreements' => count($speciesGroup), // Tetap > 1 untuk Low Quality ID
                            'grade_hint' => 'low quality ID'
                        ];
                    }
                    
                    // Imbang -> simpulkan ke genus (needs ID)
                    Log::info('Species conflict in genus: balanced, reverting to genus', [
                        'genus' => $genus,
                        'species_count' => count($speciesGroup)
                    ]);
                    
                    // Cari atau buat identifikasi genus
                    $genusTaxon = DB::table('taxas')
                        ->where('genus', $genus)
                        ->where('taxon_rank', 'genus')
                        ->first();
                        
                    if ($genusTaxon) {
                        return [
                            'taxon_id' => $genusTaxon->id,
                            'max_agreements' => $totalAgreements,
                            'taxa_with_agreements' => 1,
                            'grade_hint' => 'needs ID',
                            'confidence_percentage' => 100 // Konsensus pada genus
                        ];
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Mendapatkan waktu identifikasi pertama untuk takson tertentu
     */
    private function getFirstIdentificationTime($checklistId, $taxonId)
    {
        $identification = DB::table('taxa_identifications')
            ->where(function($query) use ($checklistId) {
                $query->where('checklist_id', $checklistId)
                      ->orWhere('burnes_checklist_id', $checklistId)
                      ->orWhere('kupnes_checklist_id', $checklistId);
            })
            ->where('taxon_id', $taxonId)
            ->whereNull('agrees_with_id')
            ->orderBy('created_at', 'asc')
            ->first();
            
        return $identification ? strtotime($identification->created_at) : PHP_INT_MAX;
    }

}

