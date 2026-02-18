<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\ChecklistQualityAssessmentController;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use App\Helpers\MediaStorageHelper;

class ChecklistObservationController extends Controller
{

    protected $qualityAssessmentController;

    public function __construct(ChecklistQualityAssessmentController $qualityAssessmentController)
    {
        $this->qualityAssessmentController = $qualityAssessmentController;
    }

    public function getObservationDetail($id)
    {
        try {
            $source = request()->query('source', $this->determineSource($id));
            $userId = JWTAuth::user()->id;
            $actualId = $this->getActualId($id, $source);

            // Get quality assessment
            $assessmentConfig = $this->qualityAssessmentController->getAssessmentConfig($source);
            $assessment = DB::table($assessmentConfig['table'])
                ->where($assessmentConfig['id_column'], $actualId)
                ->first();

            // Get checklist details
            $checklistConfig = $this->getChecklistConfig($source);

            // Build query based on source
            if ($source === 'burungnesia') {
                $checklist = DB::table('fobi_checklists as c')
                    ->join('fobi_checklist_faunasv1 as f', 'c.id', '=', 'f.checklist_id')
                    ->leftJoin('taxas as t', 'f.fauna_id', '=', 't.burnes_fauna_id')
                    ->leftJoin('faunas', 'f.fauna_id', '=', 'faunas.id')
                    ->leftJoin('data_quality_assessments as qa', function($join) {
                        $join->on('f.fauna_id', '=', 'qa.fauna_id')
                             ->where('qa.observation_id', DB::raw('c.id'));
                    })
                    ->leftJoin('fobi_users as u', 'c.fobi_user_id', '=', 'u.id')
                    ->select([
                        'c.id',
                        'c.fobi_user_id',
                        'f.fauna_id',
                        'c.latitude',
                        'c.longitude',
                        'c.location_details',
                        'u.uname as observer',
                        'c.additional_note as notes',
                        'c.tgl_pengamatan as observation_date',
                        DB::raw('COALESCE(t.cname_species, faunas.nameId) as common_name'),
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
                        't.subform',
                        // Common names untuk semua level
                        't.cname_domain',
                        't.cname_superkingdom',
                        't.cname_kingdom',
                        't.cname_subkingdom',
                        't.cname_superphylum',
                        't.cname_phylum',
                        't.cname_subphylum',
                        't.cname_superdivision',
                        't.cname_division',
                        't.cname_subdivision',
                        't.cname_superclass',
                        't.cname_class',
                        't.cname_subclass',
                        't.cname_infraclass',
                        't.cname_superorder',
                        't.cname_order',
                        't.cname_suborder',
                        't.cname_superfamily',
                        't.cname_family',
                        't.cname_subfamily',
                        't.cname_supertribe',
                        't.cname_tribe',
                        't.cname_subtribe',
                        't.cname_genus',
                        't.cname_subgenus',
                        't.cname_species',
                        't.cname_subspecies',
                        't.cname_variety',
                        DB::raw('COALESCE(t.scientific_name, faunas.nameLat) as scientific_name'),
                        'qa.grade',
                        DB::raw('CASE WHEN qa.grade = "research grade" THEN t.iucn_red_list_category ELSE NULL END as iucn_status'),
                        'c.created_at',
                        'c.updated_at',
                        't.id as taxa_id'
                    ])
                    ->where('c.id', $actualId)
                    ->first();

                // Get media
                if ($checklist) {
                    $images = DB::table('fobi_checklist_fauna_imgs')
                        ->where('checklist_id', $actualId)
                        ->select('id', 'images', 'storage_type', 'checklist_id', 'created_at', 'updated_at')
                        ->get()
                        ->map(function($img) {
                            $img->url = MediaStorageHelper::getMediaUrl(
                                $img->images,
                                $img->storage_type ?? 'local',
                                $img->id
                            );
                            return $img;
                        });
                    
                    $sounds = DB::table('fobi_checklist_sounds')
                        ->where('checklist_id', $actualId)
                        ->select('id', 'sounds', 'spectrogram', 'storage_type', 'checklist_id', 'created_at', 'updated_at')
                        ->get()
                        ->map(function($sound) {
                            $sound->url = MediaStorageHelper::getMediaUrl(
                                $sound->sounds,
                                $sound->storage_type ?? 'local',
                                $sound->id
                            );
                            if ($sound->spectrogram) {
                                $sound->spectrogram_url = MediaStorageHelper::getMediaUrl(
                                    $sound->spectrogram,
                                    $sound->storage_type ?? 'local',
                                    $sound->id
                                );
                            }
                            return $sound;
                        });
                    
                    $media = [
                        'images' => $images,
                        'sounds' => $sounds
                    ];

                    // Attach media to checklist
                    $checklist->media = $media;
                }
            } elseif ($source === 'kupunesia') {
                $checklist = DB::table('fobi_checklists_kupnes as c')
                    ->join('fobi_checklist_faunasv2 as f', 'c.id', '=', 'f.checklist_id')
                    ->leftJoin('taxas as t', 'f.fauna_id', '=', 't.kupnes_fauna_id')
                    ->leftJoin('faunas_kupnes', 'f.fauna_id', '=', 'faunas_kupnes.id')
                    ->leftJoin('data_quality_assessments_kupnes as qa', function($join) {
                        $join->on('f.fauna_id', '=', 'qa.fauna_id')
                             ->where('qa.observation_id', DB::raw('c.id'));
                    })
                    ->leftJoin('fobi_users as u', 'c.fobi_user_id', '=', 'u.id')
                    ->select([
                        'c.id',
                        'c.fobi_user_id',
                        'f.fauna_id',
                        'c.latitude',
                        'c.longitude',
                        'c.location_details',
                        'u.uname as observer',
                        'c.additional_note as notes',
                        'c.tgl_pengamatan as observation_date',
                        DB::raw('COALESCE(t.cname_species, faunas_kupnes.nameId) as common_name'),
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
                        't.subform',
                        // Common names untuk semua level
                        't.cname_domain',
                        't.cname_superkingdom',
                        't.cname_kingdom',
                        't.cname_subkingdom',
                        't.cname_superphylum',
                        't.cname_phylum',
                        't.cname_subphylum',
                        't.cname_superdivision',
                        't.cname_division',
                        't.cname_subdivision',
                        't.cname_superclass',
                        't.cname_class',
                        't.cname_subclass',
                        't.cname_infraclass',
                        't.cname_superorder',
                        't.cname_order',
                        't.cname_suborder',
                        't.cname_superfamily',
                        't.cname_family',
                        't.cname_subfamily',
                        't.cname_supertribe',
                        't.cname_tribe',
                        't.cname_subtribe',
                        't.cname_genus',
                        't.cname_subgenus',
                        't.cname_species',
                        't.cname_subspecies',
                        't.cname_variety',
                        DB::raw('COALESCE(t.scientific_name, faunas_kupnes.nameLat) as scientific_name'),
                        'qa.grade',
                        DB::raw('CASE WHEN qa.grade = "research grade" THEN t.iucn_red_list_category ELSE NULL END as iucn_status'),
                        'c.created_at',
                        'c.updated_at',
                        't.id as taxa_id'
                    ])
                    ->where('c.id', $actualId)
                    ->first();

                // Get media untuk Kupunesia (hanya gambar)
                if ($checklist) {
                    $images = DB::table('fobi_checklist_fauna_imgs_kupnes')
                        ->where('checklist_id', $actualId)
                        ->select('id', 'images', 'storage_type', 'checklist_id', 'created_at', 'updated_at')
                        ->get()
                        ->map(function($img) {
                            $img->url = MediaStorageHelper::getMediaUrl(
                                $img->images,
                                $img->storage_type ?? 'local',
                                $img->id
                            );
                            return $img;
                        });
                    
                    $media = [
                        'images' => $images
                    ];

                    // Attach media to checklist
                    $checklist->media = $media;
                }
            } else {
                // Query untuk FOBI
                $checklist = DB::table('fobi_checklist_taxas as c')
                    ->leftJoin('taxas as t', 'c.taxa_id', '=', 't.id')
                    ->leftJoin('taxa_quality_assessments as qa', 'c.id', '=', 'qa.taxa_id')
                    ->leftJoin('fobi_users as u', 'c.user_id', '=', 'u.id')
                    ->select([
                        'c.id',
                        'c.user_id',
                        'c.taxa_id as fauna_id',
                        'c.latitude',
                        'c.longitude',
                        'c.observation_details as location_details',
                        'c.license_observation',
                        'u.uname as observer',
                        'c.date as observation_date',
                        't.scientific_name',
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
                        't.subform',
                        // Common names untuk semua level
                        't.cname_domain',
                        't.cname_superkingdom',
                        't.cname_kingdom',
                        't.cname_subkingdom',
                        't.cname_superphylum',
                        't.cname_phylum',
                        't.cname_subphylum',
                        't.cname_superdivision',
                        't.cname_division',
                        't.cname_subdivision',
                        't.cname_superclass',
                        't.cname_class',
                        't.cname_subclass',
                        't.cname_infraclass',
                        't.cname_superorder',
                        't.cname_order',
                        't.cname_suborder',
                        't.cname_superfamily',
                        't.cname_family',
                        't.cname_subfamily',
                        't.cname_supertribe',
                        't.cname_tribe',
                        't.cname_subtribe',
                        't.cname_genus',
                        't.cname_subgenus',
                        't.cname_species',
                        't.cname_subspecies',
                        't.cname_variety',
                        'qa.grade',
                        DB::raw('CASE WHEN qa.grade = "research grade" THEN t.iucn_red_list_category ELSE NULL END as iucn_status'),
                        'c.created_at',
                        'c.updated_at',
                        't.id as taxa_id'
                    ])
                    ->where('c.id', $actualId)
                    ->first();

                // Get media untuk FOBI
                if ($checklist) {
                    $images = DB::table('fobi_checklist_media')
                        ->where('checklist_id', $actualId)
                        ->where('media_type', 'photo')
                        ->select('id', 'file_path', 'storage_type', 'license', 'checklist_id', 'created_at', 'updated_at')
                        ->get()
                        ->map(function($img) {
                            $img->images = MediaStorageHelper::getMediaUrl(
                                $img->file_path,
                                $img->storage_type ?? 'local',
                                $img->id
                            );
                            $img->url = $img->images; // Alias untuk compatibility
                            return $img;
                        });
                    
                    $sounds = DB::table('fobi_checklist_media')
                        ->where('checklist_id', $actualId)
                        ->where('media_type', 'audio')
                        ->select('id', 'file_path', 'spectrogram', 'storage_type', 'license', 'checklist_id', 'created_at', 'updated_at')
                        ->get()
                        ->map(function($sound) {
                            $sound->url = MediaStorageHelper::getMediaUrl(
                                $sound->file_path,
                                $sound->storage_type ?? 'local',
                                $sound->id
                            );
                            if ($sound->spectrogram) {
                                $sound->spectrogram_url = MediaStorageHelper::getMediaUrl(
                                    $sound->spectrogram,
                                    $sound->storage_type ?? 'local',
                                    $sound->id
                                );
                            }
                            return $sound;
                        });
                    
                    $media = [
                        'images' => $images,
                        'sounds' => $sounds
                    ];

                    // Attach media ke checklist
                    $checklist->media = $media;
                }
            }

            // Log untuk debugging
            Log::info('Checklist found', ['checklist' => $checklist]);

            // Get identifications dengan parameter yang benar
            $identifications = DB::table('taxa_identifications as i')
                ->join('fobi_users as u', 'i.user_id', '=', 'u.id')
                ->leftJoin('taxas as t', 'i.taxon_id', '=', 't.id')
                ->where(function($query) use ($actualId, $source) {
                    if ($source === 'burungnesia') {
                        $query->where('i.burnes_checklist_id', $actualId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('i.kupnes_checklist_id', $actualId);
                    } else {
                        $query->where('i.checklist_id', $actualId);
                    }
                })
                ->select(
                    'i.*',
                    'u.uname as identifier_name',
                    'u.profile_picture as raw_profile_pic',
                    't.scientific_name',
                    't.kingdom',
                    't.subkingdom',
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
                    't.cname_species as common_name',
                    't.variety',
                    't.form',
                    't.subform',
                    // Tambahkan common name untuk setiap level taksonomi
                    't.cname_kingdom',
                    't.cname_phylum',
                    't.cname_class',
                    't.cname_order',
                    't.cname_family',
                    't.cname_genus',
                    't.cname_species',
                    't.cname_subspecies',
                    't.cname_variety',
                    DB::raw("CASE
                        WHEN i.excluded_from_quorum = 1 THEN '0' -- Identifikasi ragu-ragu tidak memiliki suara
                        WHEN (SELECT COUNT(*) FROM taxa_identifications WHERE agrees_with_id = i.id AND (excluded_from_quorum = 0 OR excluded_from_quorum IS NULL)) = 0
                        THEN '1' -- Mengembalikan 1 bukan string kosong, karena pengusul dihitung sebagai 1 suara
                        ELSE CAST((SELECT COUNT(*) FROM taxa_identifications WHERE agrees_with_id = i.id AND (excluded_from_quorum = 0 OR excluded_from_quorum IS NULL)) + 1 AS CHAR) -- Tambahkan +1 untuk pengusul, hanya hitung agreement yang tidak ragu-ragu
                    END as agreement_count"),
                    DB::raw('CASE
                        WHEN EXISTS(SELECT 1 FROM taxa_identifications WHERE agrees_with_id = i.id AND user_id = ?)
                        THEN true
                        ELSE NULL
                    END as user_agreed')
                )
                ->addBinding($userId, 'select')
                ->get();

            // Format response
            if ($checklist) {
                $formattedChecklist = [
                    'id' => $checklist->id,
                    'user_id' => $checklist->fobi_user_id ?? $checklist->user_id ?? null,
                    'fauna_id' => $checklist->fauna_id,
                    'latitude' => $checklist->latitude ? (float)$checklist->latitude : null,
                    'longitude' => $checklist->longitude ? (float)$checklist->longitude : null,
                    'location_details' => $checklist->location_details ?? $checklist->observation_details ?? null,
                    'observer' => $checklist->observer ?? 'Pengamat tidak diketahui',
                    'notes' => $checklist->notes ?? null,
                    'observation_date' => $checklist->observation_date ?? $checklist->created_at,
                    'scientific_name' => $checklist->scientific_name ?? 'Nama tidak tersedia',
                    // Semua level taksonomi
                    'domain' => $checklist->domain ?? null,
                    'superkingdom' => $checklist->superkingdom ?? null,
                    'kingdom' => $checklist->kingdom ?? null,
                    'subkingdom' => $checklist->subkingdom ?? null,
                    'superphylum' => $checklist->superphylum ?? null,
                    'phylum' => $checklist->phylum ?? null,
                    'subphylum' => $checklist->subphylum ?? null,
                    'superdivision' => $checklist->superdivision ?? null,
                    'division' => $checklist->division ?? null,
                    'subdivision' => $checklist->subdivision ?? null,
                    'superclass' => $checklist->superclass ?? null,
                    'class' => $checklist->class ?? null,
                    'subclass' => $checklist->subclass ?? null,
                    'infraclass' => $checklist->infraclass ?? null,
                    'superorder' => $checklist->superorder ?? null,
                    'order' => $checklist->order ?? null,
                    'suborder' => $checklist->suborder ?? null,
                    'infraorder' => $checklist->infraorder ?? null,
                    'superfamily' => $checklist->superfamily ?? null,
                    'family' => $checklist->family ?? null,
                    'subfamily' => $checklist->subfamily ?? null,
                    'supertribe' => $checklist->supertribe ?? null,
                    'tribe' => $checklist->tribe ?? null,
                    'subtribe' => $checklist->subtribe ?? null,
                    'genus' => $checklist->genus ?? null,
                    'subgenus' => $checklist->subgenus ?? null,
                    'species' => $checklist->species ?? null,
                    'subspecies' => $checklist->subspecies ?? null,
                    'variety' => $checklist->variety ?? null,
                    'form' => $checklist->form ?? null,
                    'subform' => $checklist->subform ?? null,
                    'common_name' => $checklist->common_name ?? null,
                    // Common names untuk semua level
                    'cname_domain' => $checklist->cname_domain ?? null,
                    'cname_superkingdom' => $checklist->cname_superkingdom ?? null,
                    'cname_kingdom' => $checklist->cname_kingdom ?? null,
                    'cname_subkingdom' => $checklist->cname_subkingdom ?? null,
                    'cname_superphylum' => $checklist->cname_superphylum ?? null,
                    'cname_phylum' => $checklist->cname_phylum ?? null,
                    'cname_subphylum' => $checklist->cname_subphylum ?? null,
                    'cname_superdivision' => $checklist->cname_superdivision ?? null,
                    'cname_division' => $checklist->cname_division ?? null,
                    'cname_subdivision' => $checklist->cname_subdivision ?? null,
                    'cname_superclass' => $checklist->cname_superclass ?? null,
                    'cname_class' => $checklist->cname_class ?? null,
                    'cname_subclass' => $checklist->cname_subclass ?? null,
                    'cname_infraclass' => $checklist->cname_infraclass ?? null,
                    'cname_superorder' => $checklist->cname_superorder ?? null,
                    'cname_order' => $checklist->cname_order ?? null,
                    'cname_suborder' => $checklist->cname_suborder ?? null,
                    'cname_superfamily' => $checklist->cname_superfamily ?? null,
                    'cname_family' => $checklist->cname_family ?? null,
                    'cname_subfamily' => $checklist->cname_subfamily ?? null,
                    'cname_supertribe' => $checklist->cname_supertribe ?? null,
                    'cname_tribe' => $checklist->cname_tribe ?? null,
                    'cname_subtribe' => $checklist->cname_subtribe ?? null,
                    'cname_genus' => $checklist->cname_genus ?? null,
                    'cname_subgenus' => $checklist->cname_subgenus ?? null,
                    'cname_species' => $checklist->cname_species ?? null,
                    'cname_subspecies' => $checklist->cname_subspecies ?? null,
                    'cname_variety' => $checklist->cname_variety ?? null,
                    'grade' => $checklist->grade ?? 'casual',
                    'iucn_status' => $checklist->iucn_status,
                    'created_at' => $checklist->created_at,
                    'updated_at' => $checklist->updated_at,
                    'media' => $checklist->media ?? ['images' => [], 'sounds' => []],
                    'taxa_id' => $checklist->taxa_id ?? null,
                    'license_observation' => $checklist->license_observation ?? null
                ];

                // Kasus khusus untuk division/phylum (Bryophyta)
                // Jika phylum kosong tapi division ada, gunakan division sebagai phylum
                if (!$formattedChecklist['phylum'] && $formattedChecklist['division']) {
                    $formattedChecklist['phylum'] = $formattedChecklist['division'];
                    $formattedChecklist['cname_phylum'] = $formattedChecklist['cname_division'] ?? null;
                }

                // Update IUCN status dari API jika perlu
                $formattedChecklist = $this->updateIUCNStatus($formattedChecklist);
                
                // Process identifications untuk konversi profile_pic ke URL S3
                $processedIdentifications = collect($identifications ?? [])->map(function($ident) {
                    $ident->profile_pic = $this->getProfilePictureUrl($ident->raw_profile_pic ?? null);
                    unset($ident->raw_profile_pic);
                    return $ident;
                })->toArray();

                return response()->json([
                    'success' => true,
                    'data' => [
                        'checklist' => $formattedChecklist,
                        'identifications' => $processedIdentifications,
                        'media' => $checklist->media ?? ['images' => [], 'sounds' => []],
                        'quality_assessment' => $assessment,
                        'iucn_status' => $formattedChecklist['iucn_status'] ?? null,
                    ]
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error in getObservationDetail: ' . $e->getMessage(), [
                'id' => $id,
                'source' => $source ?? 'unknown',
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil detail observasi'
            ], 500);
        }
    }

    private function determineSource($id)
    {
        if (str_starts_with($id, 'BN')) return 'burungnesia';
        if (str_starts_with($id, 'KP')) return 'kupunesia';
        return 'fobi';
    }

    private function getChecklistTable($source)
    {
        return match($source) {
            'burungnesia' => 'fobi_checklists',
            'kupunesia' => 'fobi_checklists_kupnes',
            default => 'fobi_checklist_taxas'
        };
    }

    private function getMediaTables($source)
    {
        return match($source) {
            'burungnesia' => [
                'images' => 'fobi_checklist_fauna_imgs',
                'sounds' => 'fobi_checklist_sounds'
            ],
            'kupunesia' => [
                'images' => 'fobi_checklist_fauna_imgs_kupnes'
            ],
            default => [
                'images' => 'fobi_checklist_media'
            ]
        };
    }

    private function getTaxaTables($source)
    {
        return match($source) {
            'burungnesia' => [
                'primary' => 'taxas',
                'fallback' => 'faunas'
            ],
            'kupunesia' => [
                'primary' => 'taxas',
                'fallback' => 'faunas_kupnes'
            ],
            default => [
                'primary' => 'taxas'
            ]
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

    private function getFobiObservation($id)
    {
        return DB::table('fobi_checklist_taxas as fct')
            ->join('fobi_users as fu', 'fct.user_id', '=', 'fu.id')
            ->join('taxas as t', 'fct.taxa_id', '=', 't.id')
            ->where('fct.id', $id)
            ->select(
                'fct.*',
                'fu.uname as observer_name',
                't.scientific_name',
                't.class',
                't.order',
                't.family',
                't.genus',
                't.species',
                // Tambahkan common name untuk setiap level taksonomi
                't.cname_kingdom',
                't.cname_phylum',
                't.cname_class',
                't.cname_order',
                't.cname_family',
                't.cname_genus',
                't.cname_species',
                't.cname_subspecies',
                't.cname_variety'
            )
            ->first();
    }

    private function getBurungnesiaObservation($id)
    {
        return DB::table('fobi_checklists as fc')
            ->join('fobi_checklist_faunasv1 as fcf', 'fc.id', '=', 'fcf.checklist_id')
            ->join('fobi_users as fu', 'fc.fobi_user_id', '=', 'fu.id')
            ->where('fc.id', $id)
            ->select(
                'fc.*',
                'fcf.fauna_id',
                'fcf.count',
                'fcf.notes',
                'fu.uname as observer_name'
            )
            ->first();
    }

    private function getKupunesiaObservation($id)
    {
        return DB::table('fobi_checklists_kupnes as fck')
            ->join('fobi_checklist_faunasv2 as fcf', 'fck.id', '=', 'fcf.checklist_id')
            ->join('fobi_users as fu', 'fck.fobi_user_id', '=', 'fu.id')
            ->where('fck.id', $id)
            ->select(
                'fck.*',
                'fcf.fauna_id',
                'fcf.count',
                'fcf.notes',
                'fu.uname as observer_name'
            )
            ->first();
    }

    // Update only observation license (FOBI only)
    public function updateObservationLicense(Request $request, $id)
    {
        try {
            $source = $this->determineSource($id);
            if ($source !== 'fobi') {
                return response()->json([
                    'success' => false,
                    'message' => 'License update supported for FOBI observations only'
                ], 400);
            }

            $actualId = $this->getActualId($id, $source);
            $userId = JWTAuth::user()->id;

            // Allowed licenses
            $allowed = [
                'CC0', 'CC BY', 'CC BY-SA', 'CC BY-NC', 'CC BY-NC-SA', 'CC BY-ND', 'CC BY-NC-ND',
                'All rights reserved'
            ];

            $request->validate([
                'license' => ['nullable', 'string', Rule::in($allowed)]
            ]);

            // Normalize empty string to null (to remove license)
            $license = $request->input('license');
            if ($license === '') {
                $license = null;
            }

            // Ownership or admin check
            $owner = $this->getChecklistOwner($actualId, $source);
            $isAdmin = DB::table('fobi_users')
                ->where('id', $userId)
                ->whereIn('level', [3,4])
                ->exists();

            if ((!$owner || $owner->id !== $userId) && !$isAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki izin untuk memperbarui lisensi observasi ini'
                ], 403);
            }

            $updated = DB::table('fobi_checklist_taxas')
                ->where('id', $actualId)
                ->update([
                    'license_observation' => $license,
                    'updated_at' => now()
                ]);

            if ($updated === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Observasi tidak ditemukan atau tidak ada perubahan'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Lisensi observasi berhasil diperbarui',
                'data' => [
                    'id' => $actualId,
                    'license_observation' => $license
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error in updateObservationLicense: '.$e->getMessage(), [
                'id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui lisensi observasi'
            ], 500);
        }
    }

    // Update only media license (FOBI only)
    public function updateMediaLicense(Request $request, $mediaId)
    {
        try {
            $userId = JWTAuth::user()->id;

            // Validate request
            $allowed = [
                'CC0', 'CC BY', 'CC BY-SA', 'CC BY-NC', 'CC BY-NC-SA', 'CC BY-ND', 'CC BY-NC-ND',
                'All rights reserved'
            ];
            $request->validate([
                'license' => ['nullable', 'string', Rule::in($allowed)]
            ]);

            $license = $request->input('license');
            if ($license === '') {
                $license = null;
            }

            // Load media (FOBI only table)
            $media = DB::table('fobi_checklist_media')
                ->where('id', $mediaId)
                ->select('id', 'checklist_id')
                ->first();

            if (!$media) {
                return response()->json([
                    'success' => false,
                    'message' => 'Media tidak ditemukan'
                ], 404);
            }

            // Check ownership via checklist owner
            $owner = $this->getChecklistOwner($media->checklist_id, 'fobi');
            $isAdmin = DB::table('fobi_users')
                ->where('id', $userId)
                ->whereIn('level', [3,4])
                ->exists();

            if ((!$owner || $owner->id !== $userId) && !$isAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki izin untuk memperbarui lisensi media ini'
                ], 403);
            }

            $updated = DB::table('fobi_checklist_media')
                ->where('id', $mediaId)
                ->update([
                    'license' => $license,
                    'updated_at' => now()
                ]);

            if ($updated === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada perubahan lisensi'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Lisensi media berhasil diperbarui',
                'data' => [
                    'media_id' => (int)$mediaId,
                    'license' => $license
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error in updateMediaLicense: '.$e->getMessage(), [
                'media_id' => $mediaId,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui lisensi media'
            ], 500);
        }
    }

    public function addIdentification(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $request->validate([
                'taxon_id' => 'required|exists:taxas,id',
                'burnes_fauna_id' => 'nullable|integer',
                'kupnes_fauna_id' => 'nullable|integer',
                'comment' => 'nullable|string|max:500',
                'photo' => 'nullable|image|max:5120',
                'force_submit' => 'nullable|boolean',
                'confidence_level' => 'nullable|integer|in:0,1',
                'force_conflict' => 'nullable|boolean'
            ]);

            $userId = JWTAuth::user()->id;
            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id);

            // Cek quorum kingdom
            $kingdomQuorum = $this->qualityAssessmentController->checkKingdomQuorum($actualId, $source);
            if ($kingdomQuorum && $kingdomQuorum['has_quorum']) {
                // Ambil kingdom dari takson yang akan diidentifikasi
                $newTaxonKingdom = DB::table('taxas')
                    ->where('id', $request->taxon_id)
                    ->value('kingdom');

                if ($newTaxonKingdom !== $kingdomQuorum['kingdom_name']) {
                    // Jika force_submit tidak ada atau false, kembalikan error
                    if (!$request->has('force_submit') || !$request->force_submit) {
                        return response()->json([
                            'status' => 'error',
                            'message' => "Usulan {$newTaxonKingdom} tidak dapat diterima karena Kingdom telah ditetapkan berdasarkan kesepakatan quorum: {$kingdomQuorum['kingdom_name']}",
                            'code' => 'KINGDOM_QUORUM_LOCKED'
                        ], 409);
                    }
                    
                    // Jika force_submit=true, lanjutkan tetapi tandai identifikasi akan otomatis di-withdraw
                    $autoWithdraw = true;
                    Log::info('Force submitting identification with different kingdom', [
                        'user_id' => $userId,
                        'checklist_id' => $actualId,
                        'existing_kingdom' => $kingdomQuorum['kingdom_name'],
                        'new_kingdom' => $newTaxonKingdom
                    ]);
                }
            }

            // Cek apakah user sudah pernah membuat identifikasi sebelumnya
            $previousIdentification = DB::table('taxa_identifications')
                ->where('user_id', $userId)
                ->where(function($query) use ($actualId, $source) {
                    if ($source === 'burungnesia') {
                        $query->where('burnes_checklist_id', $actualId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('kupnes_checklist_id', $actualId);
                    } else {
                        $query->where('checklist_id', $actualId);
                    }
                })
                ->whereNull('agrees_with_id') // Hanya cek identifikasi langsung, bukan agreement
                ->whereNull('deleted_at')
                ->first();

            // Jika ada identifikasi sebelumnya, soft delete dan withdraw
            if ($previousIdentification) {
                DB::table('taxa_identifications')
                    ->where('id', $previousIdentification->id)
                    ->update([
                        'deleted_at' => now(),
                        'is_withdrawn' => true
                    ]);

                // Ambil semua persetujuan yang terkait dengan identifikasi sebelumnya
                $relatedAgreements = DB::table('taxa_identifications')
                    ->where('agrees_with_id', $previousIdentification->id)
                    ->get();
                    
                Log::info('Agreements found for identification being withdrawn due to new identification', [
                    'identification_id' => $previousIdentification->id,
                    'agreement_count' => $relatedAgreements->count()
                ]);
                
                // Konversi persetujuan menjadi identifikasi mandiri, bukan menghapusnya
                foreach ($relatedAgreements as $agreement) {
                    // Konversi persetujuan menjadi identifikasi mandiri dengan data yang sama
                    DB::table('taxa_identifications')
                        ->where('id', $agreement->id)
                        ->update([
                            'agrees_with_id' => null,      // Hapus referensi ke identifikasi yang ditarik
                            'comment' => 'Dikonversi dari persetujuan karena identifikasi utama ditarik',
                            'updated_at' => now()
                        ]);
                    
                    Log::info('Agreement converted to standalone identification in addIdentification flow', [
                        'agreement_id' => $agreement->id,
                        'user_id' => $agreement->user_id,
                        'taxon_id' => $agreement->taxon_id
                    ]);
                    
                    // Kirim notifikasi ke pengguna bahwa persetujuannya telah dikonversi menjadi identifikasi
                    $this->createNotification(
                        $agreement->user_id,
                        $actualId,
                        'agreement_converted',
                        "Persetujuan Anda telah dikonversi menjadi identifikasi karena identifikasi utama ditarik"
                    );
                }
            }

            // Cek dan tarik semua persetujuan yang pernah dibuat oleh user ini
            $userAgreements = DB::table('taxa_identifications')
                ->where('user_id', $userId)
                ->where(function($query) use ($actualId, $source) {
                    if ($source === 'burungnesia') {
                        $query->where('burnes_checklist_id', $actualId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('kupnes_checklist_id', $actualId);
                    } else {
                        $query->where('checklist_id', $actualId);
                    }
                })
                ->whereNotNull('agrees_with_id') // Hanya ambil persetujuan
                ->whereNull('deleted_at')
                ->get();

            // Withdraw semua persetujuan yang ada dari user ini
            // (bukan persetujuan dari user lain terhadap identifikasi user ini)
            foreach ($userAgreements as $agreement) {
                DB::table('taxa_identifications')
                    ->where('id', $agreement->id)
                    ->update([
                        'deleted_at' => now(),
                        'is_withdrawn' => true,
                        'agrees_with_id' => null
                    ]);
            }

            // Handle photo upload
            $photoPath = null;
            if ($request->hasFile('photo')) {
                $photo = $request->file('photo');
                $filename = time() . '_' . $photo->getClientOriginalName();
                $path = $photo->storeAs(
                    'identification_photos/' . $source,
                    $filename,
                    'public'
                );
                $photoPath = $path;
            }

            // Ambil data taxa
            $taxaQuery = DB::table('taxas')->where('id', $request->taxon_id);
            if ($source === 'burungnesia') {
                $taxaQuery->whereNotNull('burnes_fauna_id');
                $faunaId = 'burnes_fauna_id';
            } elseif ($source === 'kupunesia') {
                $taxaQuery->whereNotNull('kupnes_fauna_id');
                $faunaId = 'kupnes_fauna_id';
            } else {
                $faunaId = null;
            }

            if ($faunaId) {
                $taxon = $taxaQuery->select('taxon_rank', $faunaId)->first();
            } else {
                $taxon = $taxaQuery->select('taxon_rank')->first();
            }

            if (!$taxon) {
                throw new \Exception('Taxa tidak valid untuk sumber data ini');
            }
            
            // Cek apakah perlu modal konfirmasi untuk grade tinggi
            $modalConfirmationNeeded = $this->checkModalConfirmationNeeded($actualId, $request->taxon_id, $source);
            
            if ($modalConfirmationNeeded && !$request->has('confidence_level')) {
                // Return response yang trigger modal di frontend
                return response()->json([
                    'success' => false,
                    'modal_confirmation_needed' => true,
                    'current_grade' => $modalConfirmationNeeded['current_grade'] ?? 'research grade',
                    'modal_data' => $modalConfirmationNeeded,
                    'message' => 'Konfirmasi diperlukan untuk identifikasi ini'
                ]);
            }
            
            // Jika ada confidence_level dari modal, proses sesuai pilihan user
            $confidenceLevel = $request->input('confidence_level', null);
            $forceConflictFromRequest = $request->input('force_conflict', null);
            $excludeFromQuorum = false;
            
            // Debug: Log semua input request untuk troubleshooting
            Log::info('Full request data for addIdentification', [
                'all_input' => $request->all(),
                'confidence_level' => $confidenceLevel,
                'confidence_level_type' => gettype($confidenceLevel),
                'force_conflict_from_request' => $forceConflictFromRequest,
                'modal_confirmation_needed' => $modalConfirmationNeeded,
                'user_id' => $userId,
                'checklist_id' => $actualId
            ]);
            
            if ($modalConfirmationNeeded) {
                // Konversi confidence_level ke integer jika berupa string
                $confidenceLevel = is_numeric($confidenceLevel) ? (int)$confidenceLevel : $confidenceLevel;
                
                if ($confidenceLevel === 0 || $confidenceLevel === '0') {
                    // User ragu-ragu, tidak diperhitungkan dalam kuorum dan tidak mempengaruhi confidence
                    $excludeFromQuorum = true;
                    
                    Log::info('User chose doubtful identification, will be excluded from quorum', [
                        'confidence_level' => $confidenceLevel,
                        'confidence_level_converted' => (int)$confidenceLevel,
                        'exclude_from_quorum' => $excludeFromQuorum,
                        'user_id' => $userId,
                        'checklist_id' => $actualId,
                        'taxon_id' => $request->taxon_id
                    ]);
                } elseif ($confidenceLevel === 1 || $confidenceLevel === '1') {
                    // User yakin, grade turun jadi low quality ID
                    $excludeFromQuorum = false;
                    
                    Log::info('User chose confident identification, will create conflicting identification', [
                        'confidence_level' => $confidenceLevel,
                        'confidence_level_converted' => (int)$confidenceLevel,
                        'exclude_from_quorum' => $excludeFromQuorum
                    ]);
                } else {
                    // Log jika confidence_level tidak sesuai ekspektasi
                    Log::warning('Unexpected confidence_level value', [
                        'confidence_level' => $confidenceLevel,
                        'confidence_level_type' => gettype($confidenceLevel),
                        'user_id' => $userId,
                        'checklist_id' => $actualId
                    ]);
                }
            } else {
                // Implementasi logika hierarki taksonomi normal hanya jika bukan identifikasi ragu-ragu
                // Jika tidak ada modal confirmation, berarti identifikasi normal (yakin)
                $hierarchyResult = $this->processHierarchicalIdentification($actualId, $request->taxon_id, $source);
                $excludeFromQuorum = $hierarchyResult['exclude_from_quorum'];
            }

            // Cek apakah ini adalah identifikasi pertama untuk observasi ini
            // is_first = true jika belum ada identifikasi lain yang aktif (tidak withdrawn) untuk observasi ini
            $existingIdentificationCount = DB::table('taxa_identifications')
                ->where(function($query) use ($actualId, $source) {
                    if ($source === 'burungnesia') {
                        $query->where('burnes_checklist_id', $actualId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('kupnes_checklist_id', $actualId);
                    } else {
                        $query->where('checklist_id', $actualId);
                    }
                })
                ->where('is_withdrawn', false)
                ->whereNull('deleted_at')
                ->whereNull('agrees_with_id') // Hanya hitung identifikasi langsung, bukan agreement
                ->count();
            
            $isFirst = ($existingIdentificationCount === 0);
            
            Log::info('Checking is_first status for identification', [
                'checklist_id' => $actualId,
                'source' => $source,
                'existing_count' => $existingIdentificationCount,
                'is_first' => $isFirst
            ]);

            // Base identification data
            $identificationData = [
                'user_id' => $userId,
                'taxon_id' => $request->taxon_id,
                'comment' => $request->comment,
                'identification_level' => strtoupper($taxon->taxon_rank),
                'photo_path' => $photoPath,
                'created_at' => now(),
                'updated_at' => now(),
                'checklist_id' => null,
                'burnes_checklist_id' => null,
                'kupnes_checklist_id' => null,
                'burnes_fauna_id' => null,
                'kupnes_fauna_id' => null,
                'excluded_from_quorum' => $excludeFromQuorum ? 1 : 0,
                'confidence_level' => is_numeric($confidenceLevel) ? (int)$confidenceLevel : 1,
                'is_modal_confirmation' => $modalConfirmationNeeded ? 1 : 0,
                'force_conflict' => $forceConflictFromRequest ?? (($modalConfirmationNeeded && $confidenceLevel === 1) ? 1 : 0),
                'is_first' => $isFirst ? 1 : 0
            ];
            

            // Set values based on source
            if ($source === 'burungnesia') {
                $identificationData['burnes_checklist_id'] = $actualId;
                $identificationData['burnes_fauna_id'] = $taxon->burnes_fauna_id;
            } elseif ($source === 'kupunesia') {
                $identificationData['kupnes_checklist_id'] = $actualId;
                $identificationData['kupnes_fauna_id'] = $taxon->kupnes_fauna_id;
            } else {
                $identificationData['checklist_id'] = $actualId;
            }

            // Jika ini adalah force submit dengan kingdom berbeda, tandai sebagai withdrawn
            if (isset($autoWithdraw) && $autoWithdraw) {
                $identificationData['is_withdrawn'] = true;
                $identificationData['deleted_at'] = now();
                $identificationData['comment'] = ($request->comment ? $request->comment . ' - ' : '') . 
                    "Identifikasi ini ditarik otomatis karena Kingdom berbeda dari konsensus yang telah dicapai: {$kingdomQuorum['kingdom_name']}";
            }

            $identificationId = DB::table('taxa_identifications')->insertGetId($identificationData);

            // Jika tidak ditandai withdrawn, buat persetujuan implisit
            if (!isset($autoWithdraw) || !$autoWithdraw) {
                // Cek dan buat persetujuan implisit jika ada identifikasi taksa yang sama sebelumnya
                $this->qualityAssessmentController->createImplicitAgreements(
                    $actualId,
                    $identificationId,
                    $request->taxon_id,
                    $userId
                );
            }
            
            // Update quality assessment
            $this->qualityAssessmentController->updateQualityAssessment($actualId, $source);

            // Buat notifikasi
            $checklistOwner = $this->getChecklistOwner($actualId, $source);
            if ($checklistOwner && $checklistOwner->id !== $userId) {
                // Ambil username dari user yang melakukan identifikasi
                $user = DB::table('fobi_users')->where('id', $userId)->first();
                $username = $user ? $user->uname : 'Pengguna';
                
                $this->createNotification(
                    $checklistOwner->id,
                    $actualId,
                    'new_identification',
                    "{$username} telah menambahkan identifikasi baru pada pengamatan Anda"
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => isset($autoWithdraw) && $autoWithdraw ? 
                    'Identifikasi berhasil ditambahkan tetapi ditandai sebagai ditarik karena Kingdom berbeda dari konsensus' : 
                    ($excludeFromQuorum ? 
                        'Identifikasi berhasil ditambahkan' : 
                        'Identifikasi berhasil ditambahkan'),
                'identification_id' => $identificationId,
                'excluded_from_quorum' => $excludeFromQuorum,
                'confidence_level' => $confidenceLevel,
                'modal_was_shown' => $modalConfirmationNeeded !== false
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error submitting identification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function agreeWithIdentification(Request $request, $id, $identificationId)
    {
        try {
            DB::beginTransaction();

            $request->validate([
                'comment' => 'nullable|string|max:500',
                'photo' => 'nullable|image|max:5120'
            ]);

            $userId = JWTAuth::user()->id;
            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id);

            // Cek apakah identifikasi yang akan disetujui valid
            $identificationQuery = DB::table('taxa_identifications as ti')
                ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                ->select([
                    'ti.*',
                    't.scientific_name',
                    't.taxon_rank',
                    't.kingdom'
                ]);

            if ($source === 'burungnesia') {
                $identificationQuery->where('ti.burnes_checklist_id', $actualId);
            } elseif ($source === 'kupunesia') {
                $identificationQuery->where('ti.kupnes_checklist_id', $actualId);
            } else {
                $identificationQuery->where('ti.checklist_id', $actualId);
            }

            $identification = $identificationQuery
                ->where('ti.id', $identificationId)
                ->where(function($query) {
                    $query->where('ti.is_withdrawn', false)
                          ->orWhereNull('ti.is_withdrawn');
                })
                ->whereNull('ti.deleted_at')
                ->first();

            if (!$identification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifikasi tidak ditemukan atau sudah ditarik'
                ], 404);
            }

            // Cek apakah user mencoba menyetujui identifikasi mereka sendiri
            if ($identification->user_id == $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak dapat menyetujui identifikasi Anda sendiri'
                ], 400);
            }

            // Cek quorum kingdom
            $kingdomQuorum = $this->qualityAssessmentController->checkKingdomQuorum($actualId, $source);
            if ($kingdomQuorum && $kingdomQuorum['has_quorum'] && 
                $identification->kingdom !== $kingdomQuorum['kingdom_name']) {
                
                // Jika force_submit tidak ada atau false, kembalikan error
                if (!$request->has('force_submit') || !$request->force_submit) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Tidak dapat menyetujui identifikasi dengan Kingdom {$identification->kingdom} karena Kingdom telah ditetapkan berdasarkan kesepakatan quorum: {$kingdomQuorum['kingdom_name']}",
                        'code' => 'KINGDOM_QUORUM_LOCKED'
                    ], 409);
                }
            }

            // Cek apakah perlu modal konfirmasi untuk grade tinggi saat agree
            $modalConfirmationNeeded = $this->checkModalConfirmationNeeded($actualId, $identification->taxon_id, $source);
            
            if ($modalConfirmationNeeded && !$request->has('confidence_level')) {
                // Return response yang trigger modal di frontend
                return response()->json([
                    'success' => false,
                    'modal_confirmation_needed' => true,
                    'current_grade' => $modalConfirmationNeeded['current_grade'] ?? 'research grade',
                    'modal_data' => $modalConfirmationNeeded,
                    'message' => 'Konfirmasi diperlukan untuk menyetujui identifikasi ini',
                    'identification_id' => $identificationId,
                    'action_type' => 'agree'
                ]);
            }

            // Handle confidence level dari modal untuk agree action
            $confidenceLevel = $request->input('confidence_level', null);
            $excludeFromQuorum = false;
            
            // Debug: Log semua input request untuk troubleshooting agree action
            Log::info('Full request data for agreeWithIdentification', [
                'all_input' => $request->all(),
                'confidence_level' => $confidenceLevel,
                'confidence_level_type' => gettype($confidenceLevel),
                'modal_confirmation_needed' => $modalConfirmationNeeded,
                'identification_id' => $identificationId,
                'user_id' => $userId
            ]);
            
            if ($modalConfirmationNeeded) {
                // Konversi confidence_level ke integer jika berupa string
                $confidenceLevel = is_numeric($confidenceLevel) ? (int)$confidenceLevel : $confidenceLevel;
                
                if ($confidenceLevel === 0 || $confidenceLevel === '0') {
                    // User ragu-ragu, tidak diperhitungkan dalam kuorum dan tidak mempengaruhi confidence
                    $excludeFromQuorum = true;
                    
                    Log::info('User chose doubtful agreement, will be excluded from quorum', [
                        'confidence_level' => $confidenceLevel,
                        'confidence_level_converted' => (int)$confidenceLevel,
                        'exclude_from_quorum' => $excludeFromQuorum,
                        'identification_id' => $identificationId,
                    ]);
                } elseif ($confidenceLevel === 1 || $confidenceLevel === '1') {
                    // User yakin, persetujuan normal
                    $excludeFromQuorum = false;
                    
                    Log::info('User chose confident agreement', [
                        'confidence_level' => $confidenceLevel,
                        'confidence_level_converted' => (int)$confidenceLevel,
                        'exclude_from_quorum' => $excludeFromQuorum,
                        'identification_id' => $identificationId
                    ]);
                } else {
                    // Log jika confidence_level tidak sesuai ekspektasi
                    Log::warning('Unexpected confidence_level value in agree action', [
                        'confidence_level' => $confidenceLevel,
                        'confidence_level_type' => gettype($confidenceLevel),
                        'identification_id' => $identificationId,
                        'user_id' => $userId
                    ]);
                }
            }

            // Cek apakah user sudah pernah membuat identifikasi sebelumnya
            $previousIdentification = DB::table('taxa_identifications')
                ->where('user_id', $userId)
                ->where(function($query) use ($actualId, $source) {
                    if ($source === 'burungnesia') {
                        $query->where('burnes_checklist_id', $actualId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('kupnes_checklist_id', $actualId);
                    } else {
                        $query->where('checklist_id', $actualId);
                    }
                })
                ->whereNull('agrees_with_id') // Hanya cek identifikasi langsung, bukan agreement
                ->whereNull('deleted_at')
                ->first();

            // Jika ada identifikasi sebelumnya, soft delete dan withdraw
            if ($previousIdentification) {
                DB::table('taxa_identifications')
                    ->where('id', $previousIdentification->id)
                    ->update([
                        'deleted_at' => now(),
                        'is_withdrawn' => true
                    ]);
            }

            // Cek dan tarik semua persetujuan yang pernah dibuat oleh user ini
            $userAgreements = DB::table('taxa_identifications')
                ->where('user_id', $userId)
                ->where(function($query) use ($actualId, $source) {
                    if ($source === 'burungnesia') {
                        $query->where('burnes_checklist_id', $actualId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('kupnes_checklist_id', $actualId);
                    } else {
                        $query->where('checklist_id', $actualId);
                    }
                })
                ->whereNotNull('agrees_with_id') // Hanya ambil persetujuan
                ->whereNull('deleted_at')
                ->get();

            // Withdraw semua persetujuan yang ada dari user ini
            foreach ($userAgreements as $agreement) {
                DB::table('taxa_identifications')
                    ->where('id', $agreement->id)
                    ->update([
                        'deleted_at' => now(),
                        'is_withdrawn' => true,
                        'agrees_with_id' => null
                    ]);
            }

            // Handle photo upload
            $photoPath = null;
            if ($request->hasFile('photo')) {
                $photo = $request->file('photo');
                $filename = time() . '_' . $photo->getClientOriginalName();
                $path = $photo->storeAs(
                    'identification_photos/' . $source,
                    $filename,
                    'public'
                );
                $photoPath = $path;
            }

            // Buat persetujuan baru
            $agreementData = [
                'user_id' => $userId,
                'taxon_id' => $identification->taxon_id,
                'agrees_with_id' => $identificationId,
                'comment' => $request->comment,
                'identification_level' => strtoupper($identification->taxon_rank),
                'photo_path' => $photoPath,
                'created_at' => now(),
                'updated_at' => now(),
                'checklist_id' => null,
                'burnes_checklist_id' => null,
                'kupnes_checklist_id' => null,
                'burnes_fauna_id' => $identification->burnes_fauna_id,
                'kupnes_fauna_id' => $identification->kupnes_fauna_id,
                'excluded_from_quorum' => $excludeFromQuorum ? 1 : 0,
                'confidence_level' => is_numeric($confidenceLevel) ? (int)$confidenceLevel : 1
            ];

            // Set values based on source
            if ($source === 'burungnesia') {
                $agreementData['burnes_checklist_id'] = $actualId;
            } elseif ($source === 'kupunesia') {
                $agreementData['kupnes_checklist_id'] = $actualId;
            } else {
                $agreementData['checklist_id'] = $actualId;
            }

            $agreementId = DB::table('taxa_identifications')->insertGetId($agreementData);
            
            // Jika identifikasi yang disetujui memiliki excluded_from_quorum=1, 
            // update menjadi excluded_from_quorum=0 karena sekarang ada persetujuan
            // KECUALI untuk identifikasi ragu-ragu (confidence_level = 0) yang harus tetap excluded
            if (isset($identification->excluded_from_quorum) && $identification->excluded_from_quorum == 1) {
                // Pastikan confidence_level adalah integer
                $confidenceLevel = (int)$identification->confidence_level;
                
                // JANGAN update excluded_from_quorum untuk identifikasi ragu-ragu (confidence_level = 0)
                if ($confidenceLevel === 0) {
                    Log::info('Skipping excluded_from_quorum update for doubtful identification - must remain excluded', [
                        'identification_id' => $identificationId,
                        'confidence_level' => $confidenceLevel,
                        'agreement_id' => $agreementId,
                        'user_id' => $userId,
                        'reason' => 'Doubtful identifications must always be excluded from quorum regardless of agreements'
                    ]);
                } else {
                    // Update hanya untuk identifikasi non-doubtful
                    DB::table('taxa_identifications')
                        ->where('id', $identificationId)
                        ->update([
                            'excluded_from_quorum' => 0,
                            'comment' => $identification->comment . ' - Identifikasi ini sekarang diperhitungkan dalam kuorum karena telah mendapatkan persetujuan.'
                        ]);
                    
                    Log::info('Identification excluded_from_quorum status updated to 0 after receiving agreement', [
                        'identification_id' => $identificationId,
                        'confidence_level' => $confidenceLevel,
                        'agreement_id' => $agreementId,
                        'user_id' => $userId
                    ]);
                }
            }

            // Update quality assessment
            $this->qualityAssessmentController->updateQualityAssessment($actualId, $source);

            // Buat notifikasi untuk pemilik identifikasi yang disetujui
            if ($identification->user_id) {
                // Ambil username dari user yang melakukan persetujuan
                $user = DB::table('fobi_users')->where('id', $userId)->first();
                $username = $user ? $user->uname : 'Pengguna';
                
                $this->createNotification(
                    $identification->user_id,
                    $actualId,
                    'identification_agreed',
                    "{$username} menyetujui identifikasi Anda"
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Berhasil menyetujui identifikasi',
                'agreement_id' => $agreementId
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in agreeWithIdentification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function withdrawIdentification(Request $request, $id, $identificationId)
    {
        try {
            DB::beginTransaction();

            $userId = JWTAuth::user()->id;
            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id);

            // Cek apakah identifikasi ada dan milik user
            $identification = DB::table('taxa_identifications')
                ->where('id', $identificationId)
                ->where('user_id', $userId)
                ->whereNull('agrees_with_id')
                ->first();

            if (!$identification) {
                throw new \Exception('Identifikasi tidak ditemukan atau bukan milik Anda');
            }

            // Tambahkan alasan penarikan jika ada
            $updateData = [
                'is_withdrawn' => true,
                'updated_at' => now()
            ];

            if ($request->has('reason')) {
                $updateData['comment'] = $request->reason;
            }

            // Update status is_withdrawn menjadi true alih-alih menghapus
            DB::table('taxa_identifications')
                ->where('id', $identificationId)
                ->update($updateData);

            // Ambil semua persetujuan untuk identifikasi ini
            $agreements = DB::table('taxa_identifications')
                ->where('agrees_with_id', $identificationId)
                ->get();
                
            Log::info('Agreements found for withdrawn identification', [
                'identification_id' => $identificationId,
                'agreement_count' => $agreements->count(),
                'agreements' => $agreements->toArray()
            ]);

            // Ubah semua persetujuan menjadi identifikasi mandiri, bukan menghapusnya
            foreach ($agreements as $agreement) {
                // Konversi persetujuan menjadi identifikasi mandiri dengan data yang sama
                DB::table('taxa_identifications')
                    ->where('id', $agreement->id)
                    ->update([
                        'agrees_with_id' => null,      // Hapus referensi ke identifikasi yang ditarik
                        'comment' => 'Dikonversi dari persetujuan karena identifikasi utama ditarik',
                        'updated_at' => now()
                    ]);
                
                Log::info('Agreement converted to standalone identification', [
                    'agreement_id' => $agreement->id,
                    'user_id' => $agreement->user_id,
                    'taxon_id' => $agreement->taxon_id
                ]);
                
                // Kirim notifikasi ke pengguna bahwa persetujuannya telah dikonversi menjadi identifikasi
                $this->createNotification(
                    $agreement->user_id,
                    $actualId,
                    'agreement_converted',
                    "Persetujuan Anda telah dikonversi menjadi identifikasi karena identifikasi utama ditarik"
                );
            }

            $this->qualityAssessmentController->updateQualityAssessment($actualId, $source);

            // Ambil data checklist owner
            $checklistOwner = $this->getChecklistOwner($actualId, $source);

            // Buat notifikasi untuk pemilik checklist
            if ($checklistOwner && $checklistOwner->id !== $userId) {
                // Ambil username dari user yang menarik identifikasi
                $user = DB::table('fobi_users')->where('id', $userId)->first();
                $username = $user ? $user->uname : 'Pengguna';
                
                $this->createNotification(
                    $checklistOwner->id,
                    $actualId,
                    'withdraw_identification',
                    "{$username} telah menarik identifikasi pada pengamatan Anda"
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Identifikasi berhasil ditarik' . (count($agreements) > 0 ? ' dan ' . count($agreements) . ' persetujuan dikonversi menjadi identifikasi mandiri' : '')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in withdrawIdentification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function cancelAgreement($id, $identificationId)
    {
        try {
            DB::beginTransaction();

            $userId = JWTAuth::user()->id;
            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id);

            // Cari agreement yang sesuai
            $agreement = DB::table('taxa_identifications')
                ->where(function($query) use ($actualId, $source) {
                    if ($source === 'burungnesia') {
                        $query->where('burnes_checklist_id', $actualId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('kupnes_checklist_id', $actualId);
                    } else {
                        $query->where('checklist_id', $actualId);
                    }
                })
                ->where('user_id', $userId)
                ->where('agrees_with_id', $identificationId)
                ->first();

            if (!$agreement) {
                throw new \Exception('Agreement tidak ditemukan');
            }

            // Update agreement: soft delete dan kosongkan agrees_with_id
            $updated = DB::table('taxa_identifications')
                ->where('id', $agreement->id)
                ->update([
                    'deleted_at' => now(),
                    'is_withdrawn' => true,
                    'agrees_with_id' => null  // Mengosongkan agrees_with_id
                ]);

            if (!$updated) {
                throw new \Exception('Gagal membatalkan persetujuan');
            }

            // Hitung ulang jumlah agreement yang aktif
            $agreementCount = DB::table('taxa_identifications')
                ->where('agrees_with_id', $identificationId)
                ->whereNull('deleted_at')
                ->count();

            // Ambil data identifikasi yang diperbarui
            $updatedIdentification = DB::table('taxa_identifications as ti')
                ->join('fobi_users as fu', 'ti.user_id', '=', 'fu.id')
                ->leftJoin('taxas as t', 'ti.taxon_id', '=', 't.id')
                ->where('ti.id', $identificationId)
                ->select(
                    'ti.*',
                    'fu.uname as identifier_name',
                    't.scientific_name',
                    DB::raw("$agreementCount as agreement_count"),
                    DB::raw('false as user_agreed')
                )
                ->first();

            // PERBAIKAN: Cek apakah checklist saat ini research grade species sebelum update
            $currentGrade = $this->qualityAssessmentController->getCurrentChecklistGrade($actualId);
            $currentTaxon = $this->qualityAssessmentController->getCurrentChecklistTaxon($actualId);
            
            Log::info('Checking current state before quality assessment update after cancel agreement', [
                'checklist_id' => $actualId,
                'current_grade' => $currentGrade,
                'current_taxon_rank' => $currentTaxon ? $currentTaxon->taxon_rank : null,
                'current_taxon_name' => $currentTaxon ? $currentTaxon->scientific_name : null
            ]);
            
            // Perbarui kualitas penilaian setelah membatalkan persetujuan
            // Gunakan instance yang sudah diinject melalui constructor
            $grade = $this->qualityAssessmentController->updateQualityAssessment($actualId, $source);
            
            Log::info('Quality assessment updated after canceling agreement', [
                'checklist_id' => $actualId,
                'source' => $source,
                'previous_grade' => $currentGrade,
                'new_grade' => $grade,
                'protected_research_grade' => ($currentGrade === 'research grade' && $currentTaxon && $currentTaxon->taxon_rank === 'SPECIES')
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Persetujuan berhasil dibatalkan',
                'data' => [
                    'identification' => $updatedIdentification,
                    'agreement_count' => $agreementCount
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in cancelAgreement:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function disagreeWithIdentification(Request $request, $id, $identificationId)
    {
        try {
            DB::beginTransaction();

            $userId = JWTAuth::user()->id;
            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id);

            // Validasi request
            $request->validate([
                'taxon_id' => 'required|exists:taxas,id',
                'comment' => 'required|string|max:500',
                'identification_level' => 'required|string',
                'existing_identification_id' => 'nullable|integer|exists:taxa_identifications,id',
                'force_new_identification' => 'nullable|boolean'
            ]);

            // Cek identifikasi yang akan ditolak
            $identification = DB::table('taxa_identifications')
                ->where('id', $identificationId)
                ->first();

            if (!$identification) {
                throw new \Exception('Identifikasi tidak ditemukan');
            }

            // Cek apakah user meminta untuk membuat identifikasi baru (force new identification)
            $forceNewIdentification = $request->input('force_new_identification', false);
            
            // Jika force_new_identification = true, langsung lompat ke pembuatan identifikasi baru
            if ($forceNewIdentification) {
                Log::info('Forcing new disagreement identification as requested', [
                    'user_id' => $userId,
                    'checklist_id' => $actualId
                ]);
                
                // Arahkan ke pembuatan identifikasi baru
                goto createNewDisagreement;
            }
            
            // Cek apakah parameter existing_identification_id disediakan
            if ($request->has('existing_identification_id') && $request->existing_identification_id) {
                // Verifikasi existing identification milik user saat ini dan untuk checklist yang sama
                $existingIdentification = DB::table('taxa_identifications')
                    ->where('id', $request->existing_identification_id)
                    ->where('user_id', $userId)
                    ->where(function($query) use ($actualId, $source) {
                        if ($source === 'burungnesia') {
                            $query->where('burnes_checklist_id', $actualId);
                        } elseif ($source === 'kupunesia') {
                            $query->where('kupnes_checklist_id', $actualId);
                        } else {
                            $query->where('checklist_id', $actualId);
                        }
                    })
                    ->whereNull('deleted_at')
                    ->whereNull('agrees_with_id') // Pastikan ini adalah identifikasi langsung, bukan persetujuan
                    ->first();
                
                if ($existingIdentification) {
                    // Update existing identification sebagai disagreement
                    DB::table('taxa_identifications')
                        ->where('id', $existingIdentification->id)
                        ->update([
                            'disagrees_with_id' => $identificationId,
                            'comment' => $request->comment,
                            'taxon_id' => $request->taxon_id,
                            'identification_level' => $request->identification_level,
                            'updated_at' => now()
                        ]);
                    
                    Log::info('Updated existing identification as disagreement from explicit ID', [
                        'existing_id' => $existingIdentification->id,
                        'user_id' => $userId,
                        'taxon_id' => $request->taxon_id,
                        'disagreeing_with' => $identificationId
                    ]);
                    
                    // Gunakan existing_identification->id sebagai ID disagreement
                    $disagreementId = $existingIdentification->id;
                    
                    // Lompat langsung ke bagian akhir (skip pembuatan identifikasi baru)
                    goto updateAssessment;
                }
            }
            
            // Periksa apakah sudah ada identifikasi dengan takson yang sama dari user ini
            $existingIdentification = DB::table('taxa_identifications')
                ->where('user_id', $userId)
                ->where('taxon_id', $request->taxon_id)
                ->where(function($query) use ($actualId, $source) {
                    if ($source === 'burungnesia') {
                        $query->where('burnes_checklist_id', $actualId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('kupnes_checklist_id', $actualId);
                    } else {
                        $query->where('checklist_id', $actualId);
                    }
                })
                ->whereNull('deleted_at')
                ->whereNull('agrees_with_id') // Pastikan ini adalah identifikasi langsung, bukan persetujuan
                ->first();
                
            if ($existingIdentification) {
                // Jika sudah ada identifikasi dengan takson yang sama, gunakan itu sebagai dasar
                // UPDATE saja menjadi penolakan, bukan buat baru
                DB::table('taxa_identifications')
                    ->where('id', $existingIdentification->id)
                    ->update([
                        'disagrees_with_id' => $identificationId,
                        'comment' => $request->comment,
                        'updated_at' => now()
                    ]);
                
                Log::info('Updated existing identification as disagreement', [
                    'existing_id' => $existingIdentification->id,
                    'user_id' => $userId,
                    'taxon_id' => $request->taxon_id,
                    'disagreeing_with' => $identificationId
                ]);
                
                // Gunakan existingIdentification->id sebagai ID penolakan
                $disagreementId = $existingIdentification->id;
            } else {
                // Label untuk pembuatan identifikasi baru
                createNewDisagreement:
                
                // Cek dan withdraw identifikasi sebelumnya dari user (jika beda takson)
            $previousIdentification = DB::table('taxa_identifications')
                ->where('user_id', $userId)
                ->where(function($query) use ($actualId, $source) {
                    if ($source === 'burungnesia') {
                        $query->where('burnes_checklist_id', $actualId);
                    } elseif ($source === 'kupunesia') {
                        $query->where('kupnes_checklist_id', $actualId);
                    } else {
                        $query->where('checklist_id', $actualId);
                    }
                })
                ->whereNull('agrees_with_id') // Hanya cek identifikasi langsung, bukan agreement
                ->whereNull('deleted_at')
                ->first();

            // Jika ada identifikasi sebelumnya, soft delete dan withdraw
            if ($previousIdentification) {
                DB::table('taxa_identifications')
                    ->where('id', $previousIdentification->id)
                    ->update([
                        'deleted_at' => now(),
                        'is_withdrawn' => true
                    ]);

                // Ambil semua persetujuan yang terkait dengan identifikasi sebelumnya
                $relatedAgreements = DB::table('taxa_identifications')
                    ->where('agrees_with_id', $previousIdentification->id)
                    ->get();
                
                    Log::info('Agreements found for identification being withdrawn due to new disagreement', [
                    'identification_id' => $previousIdentification->id,
                    'agreement_count' => $relatedAgreements->count()
                ]);

                    // Konversi persetujuan menjadi identifikasi mandiri
                foreach ($relatedAgreements as $agreement) {
                    DB::table('taxa_identifications')
                        ->where('id', $agreement->id)
                        ->update([
                                'agrees_with_id' => null,
                            'comment' => 'Dikonversi dari persetujuan karena identifikasi utama ditarik',
                            'updated_at' => now()
                        ]);
                    
                    Log::info('Agreement converted to standalone identification in disagreeWithIdentification flow', [
                        'agreement_id' => $agreement->id,
                        'user_id' => $agreement->user_id,
                        'taxon_id' => $agreement->taxon_id
                    ]);
                    
                        // Kirim notifikasi
                    $this->createNotification(
                        $agreement->user_id,
                        $actualId,
                        'agreement_converted',
                        "Persetujuan Anda telah dikonversi menjadi identifikasi karena identifikasi utama ditarik"
                    );
                }
            }

                // Cek dan withdraw persetujuan sebelumnya dari user
                $previousAgreement = DB::table('taxa_identifications')
                    ->where('user_id', $userId)
                    ->where(function($query) use ($actualId, $source) {
                        if ($source === 'burungnesia') {
                            $query->where('burnes_checklist_id', $actualId);
                        } elseif ($source === 'kupunesia') {
                            $query->where('kupnes_checklist_id', $actualId);
                        } else {
                            $query->where('checklist_id', $actualId);
                        }
                    })
                    ->whereNotNull('agrees_with_id') // Khusus untuk agreement
                    ->whereNull('deleted_at')
                    ->first();

                // Jika ada persetujuan sebelumnya, soft delete dan withdraw
                if ($previousAgreement) {
                    DB::table('taxa_identifications')
                        ->where('id', $previousAgreement->id)
                        ->update([
                            'deleted_at' => now(),
                            'is_withdrawn' => true,
                            'agrees_with_id' => null // Hapus referensi ke identifikasi yang disetujui
                        ]);
                    
                    Log::info('Previous agreement withdrawn due to disagreement', [
                        'agreement_id' => $previousAgreement->id,
                        'user_id' => $userId
                    ]);
            }

            // Ambil data taxa
            $taxon = DB::table('taxas')
                ->where('id', $request->taxon_id)
                ->select('taxon_rank', 'burnes_fauna_id', 'kupnes_fauna_id')
                ->first();

            if (!$taxon) {
                throw new \Exception('Taxa tidak valid');
            }

            // Siapkan data disagreement
            $disagreementData = [
                'user_id' => $userId,
                'taxon_id' => $request->taxon_id,
                'comment' => $request->comment,
                'identification_level' => $request->identification_level,
                'disagrees_with_id' => $identificationId,
                'created_at' => now(),
                'updated_at' => now(),
                'checklist_id' => null,
                'burnes_checklist_id' => null,
                'kupnes_checklist_id' => null,
                'burnes_fauna_id' => $taxon->burnes_fauna_id,
                'kupnes_fauna_id' => $taxon->kupnes_fauna_id
            ];

            // Set ID yang sesuai berdasarkan source
            if ($source === 'burungnesia') {
                $disagreementData['burnes_checklist_id'] = $actualId;
            } elseif ($source === 'kupunesia') {
                $disagreementData['kupnes_checklist_id'] = $actualId;
            } else {
                $disagreementData['checklist_id'] = $actualId;
            }

            // Simpan disagreement
                $disagreementId = DB::table('taxa_identifications')->insertGetId($disagreementData);
                
                Log::info('Created new disagreement identification', [
                    'disagreement_id' => $disagreementId,
                    'user_id' => $userId,
                    'taxon_id' => $request->taxon_id,
                    'disagreeing_with' => $identificationId
                ]);
            }

            // Label untuk goto
            updateAssessment:

            // Update quality assessment
            $this->qualityAssessmentController->updateQualityAssessment($actualId, $source);

            // Ambil data identification owner
            $identificationOwner = DB::table('taxa_identifications as ti')
                ->join('fobi_users as fu', 'ti.user_id', '=', 'fu.id')
                ->where('ti.id', $identificationId)
                ->select('fu.id')
                ->first();

            // Buat notifikasi untuk pemilik identifikasi
            if ($identificationOwner && $identificationOwner->id !== $userId) {
                // Ambil username dari user yang tidak setuju dengan identifikasi
                $user = DB::table('fobi_users')->where('id', $userId)->first();
                $username = $user ? $user->uname : 'Pengguna';
                
                $this->createNotification(
                    $identificationOwner->id,
                    $actualId,
                    'disagree_identification',
                    "{$username} tidak setuju dengan identifikasi Anda"
                );
            }

            DB::commit();

            // Ambil data identifikasi yang diperbarui
            $updatedIdentification = DB::table('taxa_identifications as ti')
                ->join('fobi_users as fu', 'ti.user_id', '=', 'fu.id')
                ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                ->where('ti.id', $identificationId)
                ->select(
                    'ti.*',
                    'fu.uname as identifier_name',
                    't.scientific_name',
                    DB::raw("CASE WHEN (SELECT COUNT(*) FROM taxa_identifications WHERE agrees_with_id = ti.id) = 0 THEN '1' ELSE CAST((SELECT COUNT(*) FROM taxa_identifications WHERE agrees_with_id = ti.id) + 1 AS CHAR) END as agreement_count"),
                    DB::raw('CASE
                        WHEN EXISTS(SELECT 1 FROM taxa_identifications WHERE agrees_with_id = ti.id AND user_id = ?)
                        THEN true
                        ELSE NULL
                    END as user_agreed')
                )
                ->addBinding($userId, 'select') // Tambahkan binding untuk user_id
                ->first();

            // Ambil data penolakan yang baru dibuat/diperbarui
            $disagreeIdentification = DB::table('taxa_identifications as ti')
                ->join('fobi_users as fu', 'ti.user_id', '=', 'fu.id')
                ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                ->where('ti.id', $disagreementId)
                ->select(
                    'ti.*',
                    'fu.uname as identifier_name',
                    't.scientific_name',
                    't.kingdom',
                    't.phylum',
                    't.class',
                    't.order',
                    't.family',
                    't.genus', 
                    't.species',
                    't.cname_species'
                )
                ->first();
                
            // Ambil informasi tentang persetujuan pengguna yang ditarik, jika ada
            $withdrawnAgreement = null;
            if (isset($previousAgreement) && $previousAgreement) {
                $withdrawnAgreement = DB::table('taxa_identifications as ti')
                    ->join('fobi_users as fu', 'ti.user_id', '=', 'fu.id')
                    ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                    ->where('ti.id', $previousAgreement->id)
                    ->select(
                        'ti.*',
                        'fu.uname as identifier_name',
                        't.scientific_name'
                    )
                    ->first();
            }

            return response()->json([
                'success' => true,
                'message' => 'Penolakan identifikasi berhasil disimpan',
                'data' => $updatedIdentification,
                'disagreement' => $disagreeIdentification,
                'withdrawn_agreement' => $withdrawnAgreement
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in disagreeWithIdentification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    private function getObservationMedia($id, $source)
    {
        try {
            $mediaTable = match($source) {
                'fobi' => 'fobi_checklist_media',
                'burungnesia' => 'fobi_checklist_fauna_imgs',
                'kupunesia' => 'fobi_checklist_fauna_imgs_kupnes', // Sesuaikan jika berbeda
            };

            $audioTable = match($source) {
                'fobi' => 'fobi_checklist_media',
                'burungnesia' => 'fobi_checklist_sounds',
                'kupunesia' => 'fobi_checklist_sounds', // Sesuaikan jika berbeda
            };

            // Get images
            $images = DB::table($mediaTable)
                ->where('checklist_id', $id)
                ->select('id', 'file_path', 'storage_type', DB::raw("'image' as type"))
                ->get();

            // Get audio
            $audio = DB::table($audioTable)
                ->where('checklist_id', $id)
                ->select(
                    'id',
                    'file_path',
                    'spectrogram_path',
                    'storage_type',
                    DB::raw("'audio' as type")
                )
                ->get();

            // Transform URLs
            $medias = $images->concat($audio)->map(function($media) {
                $media->url = MediaStorageHelper::getMediaUrl(
                    $media->file_path,
                    $media->storage_type ?? 'local',
                    $media->id
                );
                
                if (isset($media->spectrogram_path)) {
                    $media->spectrogram = MediaStorageHelper::getMediaUrl(
                        $media->spectrogram_path,
                        $media->storage_type ?? 'local',
                        $media->id
                    );
                }
                
                // Remove file_path from response
                unset($media->file_path);
                unset($media->spectrogram_path);
                unset($media->storage_type);
                
                return $media;
            });

            return $medias;

        } catch (\Exception $e) {
            Log::error('Error in getObservationMedia: ' . $e->getMessage());
            throw $e;
        }
    }

    public function searchTaxa(Request $request)
    {
        try {
            $request->validate([
                'q' => 'required|string|min:2',
                'include_locations' => 'nullable|boolean',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'rank' => 'nullable|string' // Parameter rank untuk memfilter berdasarkan tingkat taksonomi
            ]);

            $query = $request->input('q');
            $source = $request->input('source', 'fobi');
            $includeLocations = $request->input('include_locations', false);
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);
            $rank = $request->input('rank'); // Ambil parameter rank jika ada
            
            Log::info("Taxa search query: {$query}, page: {$page}, per_page: {$perPage}, source: {$source}, rank: {$rank}");

            // Untuk menangani kasus spesial, seperti "Pinus merkusii Jungh. & de Vriese"
            $originalRank = $rank;
            if ($rank == 'SUBSPECIES') {
                // Periksa pola yang menandakan bahwa ini sebenarnya spesies dengan author
                $nameParts = explode(' ', $query);
                if (count($nameParts) >= 3) {
                    $genusName = $nameParts[0];
                    $speciesEpithet = $nameParts[1];
                    
                    // Periksa apakah bagian ketiga diawali huruf kapital (kemungkinan author)
                    $potentialAuthor = $nameParts[2] ?? '';
                    $hasAuthorPattern = preg_match('/^[A-Z]/', $potentialAuthor);
                    
                    // Periksa jika ini adalah genus dari kingdom Plantae
                    $isPlantGenus = DB::table('taxas')
                        ->where('genus', $genusName)
                        ->where('kingdom', 'Plantae')
                        ->exists();
                    
                    // Jika ini kemungkinan besar species tumbuhan dengan author, ubah rank
                    if ($hasAuthorPattern && $isPlantGenus) {
                        Log::info("Mengubah rank dari SUBSPECIES ke SPECIES untuk: {$query}");
                        $rank = 'SPECIES';
                    }
                    
                    // Daftar genus tumbuhan umum
                    $commonPlantGenera = [
                        'Pinus', 'Quercus', 'Ficus', 'Acacia', 'Eucalyptus', 'Magnolia', 
                        'Oryza', 'Zea', 'Bambusa', 'Shorea', 'Dipterocarpus', 'Artocarpus',
                        'Mangifera', 'Citrus', 'Durio', 'Garcinia', 'Rhizophora', 'Avicennia',
                        'Bruguiera', 'Sonneratia', 'Amorphophallus', 'Rafflesia', 'Nepenthes',
                        // Genus tumbuhan Indonesia
                        'Agathis', 'Alstonia', 'Anthocephalus', 'Aquilaria', 'Areca', 'Arenga',
                        'Barringtonia', 'Borassus', 'Calamus', 'Cananga', 'Casuarina', 'Ceriops',
                        'Cocos', 'Cyrtostachys', 'Daemonorops', 'Diospyros', 'Dryobalanops',
                        'Elaeis', 'Elettaria', 'Eusideroxylon', 'Gonystylus', 'Hopea', 'Intsia',
                        'Johannesteijsmannia', 'Koompassia', 'Lansium', 'Licuala', 'Livistona',
                        'Metroxylon', 'Myristica', 'Nypa', 'Oncosperma', 'Paraserianthes',
                        'Parkia', 'Pterocarpus', 'Pterospermum', 'Santalum', 'Schima',
                        'Swietenia', 'Tectona', 'Terminalia', 'Vitex', 'Zingiber',
                        // Genus ekonomis penting
                        'Hevea', 'Theobroma', 'Coffea', 'Camellia', 'Musa', 'Saccharum',
                        'Piper', 'Cinnamomum', 'Syzygium', 'Vanilla', 'Psidium', 'Ananas', 'Persea'
                    ];
                    
                    if (in_array($genusName, $commonPlantGenera)) {
                        Log::info("Genus tumbuhan umum terdeteksi: {$genusName}, mengubah rank menjadi SPECIES");
                        $rank = 'SPECIES';
                    }
                }
            }
            
            // Base query dengan prefix table untuk menghindari ambiguous columns
            $results = DB::table('taxas')
                ->where(function($q) use ($query) {
                    // Bersihkan query dari tanda strip
                    $cleanQuery = str_replace('-', ' ', $query);

                    $q->where(DB::raw("REPLACE(taxas.cname_species, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.species, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.scientific_name, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.genus, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_genus, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.family, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_family, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.superfamily, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_superfamily, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.subspecies, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_subspecies, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.order, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_order, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.class, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_class, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.kingdom, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_kingdom, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.phylum, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.subphylum, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.tribe, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_tribe, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.subfamily, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_subfamily, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.suborder, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_suborder, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      // Add support for Cname fields (Cname, Cname_two through Cname_ten)
                      ->orWhere(DB::raw("REPLACE(taxas.Cname, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.Cname_two, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.Cname_three, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.Cname_four, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.Cname_five, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.Cname_six, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.Cname_seven, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.Cname_eight, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.Cname_nine, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.Cname_ten, '-', ' ')"), 'like', "%{$cleanQuery}%");
                });

            // Filter taksa dengan taxonomic_status ACCEPTED, SYNONYM, dan NULL untuk Unknown taxa
            $results->where(function($q) {
                $q->whereIn('taxas.taxonomic_status', ['ACCEPTED', 'SYNONYM'])
                  ->orWhereNull('taxas.taxonomic_status'); // Include Unknown taxa with NULL taxonomic_status
            });
                
            // Filter berdasarkan rank jika parameter rank diberikan
            if ($rank) {
                $rankUpper = strtoupper($rank);
                Log::info("Filtering results by rank: {$rankUpper}");
                $results->where('taxas.taxon_rank', $rankUpper);
            }

            // Filter berdasarkan sumber
            if ($source === 'burungnesia') {
                $results->whereNotNull('taxas.burnes_fauna_id')
                       ->leftJoin('faunas', 'taxas.burnes_fauna_id', '=', 'faunas.id')
                       ->select(
                           'taxas.id',
                           'taxas.taxon_rank',
                           DB::raw('COALESCE(taxas.scientific_name, faunas.nameLat) as scientific_name'),
                           DB::raw('COALESCE(taxas.species, faunas.nameLat) as species'),
                           DB::raw('COALESCE(taxas.cname_species, faunas.nameId) as cname_species'),
                           DB::raw('COALESCE(taxas.genus, faunas.nameLat) as genus'),
                           DB::raw('COALESCE(taxas.cname_genus, faunas.nameLat) as cname_genus'),
                           DB::raw('COALESCE(taxas.family, faunas.nameLat) as family'),
                           DB::raw('COALESCE(taxas.cname_family, faunas.nameLat) as cname_family'),
                           'taxas.accepted_scientific_name',
                           'taxas.taxonomic_status',
                           'taxas.burnes_fauna_id',
                           'taxas.kupnes_fauna_id'
                       );
            } elseif ($source === 'kupunesia') {
                $results->whereNotNull('taxas.kupnes_fauna_id')
                       ->leftJoin('faunas_kupnes', 'taxas.kupnes_fauna_id', '=', 'faunas_kupnes.id')
                       ->select(
                           'taxas.id',
                           'taxas.taxon_rank',
                           DB::raw('COALESCE(taxas.scientific_name, faunas_kupnes.nameLat) as scientific_name'),
                           DB::raw('COALESCE(taxas.species, faunas_kupnes.nameLat) as species'),
                           DB::raw('COALESCE(taxas.cname_species, faunas_kupnes.nameId) as cname_species'),
                           DB::raw('COALESCE(taxas.genus, faunas_kupnes.nameLat) as genus'),
                           DB::raw('COALESCE(taxas.cname_genus, faunas_kupnes.nameLat) as cname_genus'),
                           DB::raw('COALESCE(taxas.family, faunas_kupnes.nameLat) as family'),
                           DB::raw('COALESCE(taxas.cname_family, faunas_kupnes.nameLat) as cname_family'),
                           'taxas.accepted_scientific_name',
                           'taxas.taxonomic_status',
                           'taxas.burnes_fauna_id',
                           'taxas.kupnes_fauna_id'
                       );
            } else {
                $results->select(
                    'taxas.id',
                    'taxas.taxon_rank',
                    'taxas.scientific_name',
                    'taxas.domain',
                    'taxas.cname_domain',
                    'taxas.superkingdom',
                    'taxas.cname_superkingdom',
                    'taxas.kingdom',
                    'taxas.cname_kingdom',
                    'taxas.subkingdom',
                    'taxas.cname_subkingdom',
                    'taxas.superphylum',
                    'taxas.cname_superphylum',
                    'taxas.phylum',
                    'taxas.cname_phylum',
                    'taxas.subphylum',
                    'taxas.cname_subphylum',
                    'taxas.superclass',
                    'taxas.cname_superclass',
                    'taxas.class',
                    'taxas.cname_class',
                    'taxas.subclass',
                    'taxas.cname_subclass',
                    'taxas.infraclass',
                    'taxas.cname_infraclass',
                    'taxas.superorder',
                    'taxas.cname_superorder',
                    'taxas.order',
                    'taxas.cname_order',
                    'taxas.suborder',
                    'taxas.cname_suborder',
                    'taxas.infraorder',
                    'taxas.superfamily',
                    'taxas.cname_superfamily',
                    'taxas.family',
                    'taxas.cname_family',
                    'taxas.subfamily',
                    'taxas.cname_subfamily',
                    'taxas.supertribe',
                    'taxas.cname_supertribe',
                    'taxas.tribe',
                    'taxas.cname_tribe',
                    'taxas.subtribe',
                    'taxas.cname_subtribe',
                    'taxas.genus',
                    'taxas.cname_genus',
                    'taxas.subgenus',
                    'taxas.cname_subgenus',
                    'taxas.species',
                    'taxas.cname_species',
                    'taxas.subspecies',
                    'taxas.cname_subspecies',
                    'taxas.variety',
                    'taxas.cname_variety',
                    'taxas.accepted_scientific_name',
                    'taxas.taxonomic_status',
                    'taxas.burnes_fauna_id',
                    'taxas.kupnes_fauna_id'
                );
            }

            // Advanced ordering matching SpeciesSuggestionController.php
            $normalizedQuery = strtolower($query);
            $cleanQuery = str_replace('-', ' ', $query);
            $exactPattern = $normalizedQuery;
            $searchPattern = '%' . $normalizedQuery . '%';
            
            // Apply sophisticated ordering similar to SpeciesSuggestionController
            $results = $results->orderByRaw("
                CASE 
                    -- Special priority for Unknown taxa
                    WHEN (LOWER(taxas.scientific_name) = 'unknown' OR taxas.taxon_rank = 'UNKNOWN') AND LOWER(?) LIKE '%unknown%' THEN 0
                    WHEN (LOWER(taxas.scientific_name) = 'unknown' OR taxas.taxon_rank = 'UNKNOWN') AND LOWER(?) LIKE '%tidak%' THEN 0
                    WHEN (LOWER(taxas.scientific_name) = 'unknown' OR taxas.taxon_rank = 'UNKNOWN') AND LOWER(?) LIKE '%belum%' THEN 0
                    
                    -- Highest priority: Exact scientific name matches
                    WHEN LOWER(taxas.scientific_name) = ? THEN 1
                    WHEN LOWER(taxas.scientific_name) LIKE ? THEN 2
                    
                    -- Second priority: Exact common name matches by rank
                    WHEN (taxas.taxon_rank = 'KINGDOM' AND LOWER(taxas.cname_kingdom) = ?) THEN 3
                    WHEN (taxas.taxon_rank = 'PHYLUM' AND LOWER(taxas.cname_phylum) = ?) THEN 4
                    WHEN (taxas.taxon_rank = 'CLASS' AND LOWER(taxas.cname_class) = ?) THEN 5
                    WHEN (taxas.taxon_rank = 'ORDER' AND LOWER(taxas.cname_order) = ?) THEN 6
                    WHEN (taxas.taxon_rank = 'FAMILY' AND LOWER(taxas.cname_family) = ?) THEN 7
                    WHEN (taxas.taxon_rank = 'GENUS' AND LOWER(taxas.cname_genus) = ?) THEN 8
                    WHEN (taxas.taxon_rank = 'SPECIES' AND LOWER(taxas.cname_species) = ?) THEN 9
                    
                    -- Third priority: Partial common name matches
                    WHEN (taxas.taxon_rank = 'KINGDOM' AND LOWER(taxas.cname_kingdom) LIKE ?) THEN 10
                    WHEN (taxas.taxon_rank = 'PHYLUM' AND LOWER(taxas.cname_phylum) LIKE ?) THEN 11
                    WHEN (taxas.taxon_rank = 'CLASS' AND LOWER(taxas.cname_class) LIKE ?) THEN 12
                    WHEN (taxas.taxon_rank = 'ORDER' AND LOWER(taxas.cname_order) LIKE ?) THEN 13
                    WHEN (taxas.taxon_rank = 'FAMILY' AND LOWER(taxas.cname_family) LIKE ?) THEN 14
                    WHEN (taxas.taxon_rank = 'GENUS' AND LOWER(taxas.cname_genus) LIKE ?) THEN 15
                    WHEN (taxas.taxon_rank = 'SPECIES' AND LOWER(taxas.cname_species) LIKE ?) THEN 16
                    
                    -- Fourth priority: Scientific name partial matches by specificity
                    WHEN LOWER(taxas.species) LIKE ? THEN 17
                    WHEN LOWER(taxas.genus) LIKE ? THEN 18
                    WHEN LOWER(taxas.family) LIKE ? THEN 19
                    WHEN LOWER(taxas.`order`) LIKE ? THEN 20
                    WHEN LOWER(taxas.class) LIKE ? THEN 21
                    WHEN LOWER(taxas.phylum) LIKE ? THEN 22
                    WHEN LOWER(taxas.kingdom) LIKE ? THEN 23
                    
                    -- Fifth priority: Other taxonomic fields
                    WHEN LOWER(taxas.subfamily) LIKE ? THEN 24
                    WHEN LOWER(taxas.superfamily) LIKE ? THEN 25
                    WHEN LOWER(taxas.suborder) LIKE ? THEN 26
                    WHEN LOWER(taxas.superorder) LIKE ? THEN 27
                    WHEN LOWER(taxas.subclass) LIKE ? THEN 28
                    WHEN LOWER(taxas.superclass) LIKE ? THEN 29
                    WHEN LOWER(taxas.subphylum) LIKE ? THEN 30
                    WHEN LOWER(taxas.superphylum) LIKE ? THEN 31
                    
                    -- Sixth priority: Cname field matches (exact matches first)
                    WHEN LOWER(taxas.Cname) = ? THEN 32
                    WHEN LOWER(taxas.Cname_two) = ? THEN 33
                    WHEN LOWER(taxas.Cname_three) = ? THEN 34
                    WHEN LOWER(taxas.Cname_four) = ? THEN 35
                    WHEN LOWER(taxas.Cname_five) = ? THEN 36
                    WHEN LOWER(taxas.Cname_six) = ? THEN 37
                    WHEN LOWER(taxas.Cname_seven) = ? THEN 38
                    WHEN LOWER(taxas.Cname_eight) = ? THEN 39
                    WHEN LOWER(taxas.Cname_nine) = ? THEN 40
                    WHEN LOWER(taxas.Cname_ten) = ? THEN 41
                    
                    -- Seventh priority: Cname field partial matches
                    WHEN LOWER(taxas.Cname) LIKE ? THEN 42
                    WHEN LOWER(taxas.Cname_two) LIKE ? THEN 43
                    WHEN LOWER(taxas.Cname_three) LIKE ? THEN 44
                    WHEN LOWER(taxas.Cname_four) LIKE ? THEN 45
                    WHEN LOWER(taxas.Cname_five) LIKE ? THEN 46
                    WHEN LOWER(taxas.Cname_six) LIKE ? THEN 47
                    WHEN LOWER(taxas.Cname_seven) LIKE ? THEN 48
                    WHEN LOWER(taxas.Cname_eight) LIKE ? THEN 49
                    WHEN LOWER(taxas.Cname_nine) LIKE ? THEN 50
                    WHEN LOWER(taxas.Cname_ten) LIKE ? THEN 51
                    
                    ELSE 52
                END, taxas.scientific_name
            ", [
                $normalizedQuery, $normalizedQuery, $normalizedQuery, // Unknown taxa conditions
                $exactPattern, $searchPattern, // scientific name exact and partial
                $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, // rank-specific cname exact matches
                $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, // rank-specific cname partial matches
                $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, // scientific name partial matches
                $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, // other taxonomic fields
                $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, // Cname exact matches
                $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern // Cname partial matches
            ]);

            // Ambil total sebelum paginasi
            $total = $results->count();
            
            // Lakukan paginasi (ordering sudah diterapkan di atas)
            $resultsData = $results->offset(($page - 1) * $perPage)
                               ->limit($perPage)
                               ->get();

            Log::info("Found {$total} taxa results, returning page {$page} with {$resultsData->count()} items");
            
            // Menghilangkan duplikasi berdasarkan kombinasi scientific_name dan taxon_rank
            $uniqueResults = collect();
            $uniqueKeys = collect();
            
            foreach ($resultsData as $taxa) {
                // Buat kunci unik berdasarkan scientific_name dan taxon_rank
                $uniqueKey = $taxa->scientific_name . '_' . $taxa->taxon_rank;
                
                // Hanya tambahkan ke hasil jika kunci belum ada
                if (!$uniqueKeys->contains($uniqueKey)) {
                    $uniqueKeys->push($uniqueKey);
                    $uniqueResults->push($taxa);
                } else {
                    Log::info("Removing duplicate taxa: {$taxa->scientific_name} ({$taxa->taxon_rank})");
                }
            }
            
            Log::info("After deduplication: " . $uniqueResults->count() . " unique results");

            // Format hasil untuk memasukkan informasi taksonomi lengkap
            $results = $uniqueResults->map(function($taxa) {
                $rank = strtolower($taxa->taxon_rank);
                
                // Buat tampilan hierarki taksonomi detail
                $taxonomicHierarchy = [];
                
                // Tambahkan baris data taksonomi berdasarkan level yang terisi
                if ($taxa->kingdom) {
                    $taxonomicHierarchy['kingdom'] = [
                        'name' => $taxa->kingdom,
                        'common_name' => $taxa->cname_kingdom ?? null
                    ];
                }
                
                if ($taxa->phylum) {
                    $taxonomicHierarchy['phylum'] = [
                        'name' => $taxa->phylum,
                        'common_name' => $taxa->cname_phylum ?? null
                    ];
                }
                
                if ($taxa->class) {
                    $taxonomicHierarchy['class'] = [
                        'name' => $taxa->class,
                        'common_name' => $taxa->cname_class ?? null
                    ];
                }
                
                if ($taxa->order) {
                    $taxonomicHierarchy['order'] = [
                        'name' => $taxa->order,
                        'common_name' => $taxa->cname_order ?? null
                    ];
                }
                
                if ($taxa->family) {
                    $taxonomicHierarchy['family'] = [
                        'name' => $taxa->family,
                        'common_name' => $taxa->cname_family ?? null
                    ];
                }
                
                if ($taxa->genus) {
                    $taxonomicHierarchy['genus'] = [
                        'name' => $taxa->genus,
                        'common_name' => $taxa->cname_genus ?? null
                    ];
                }
                
                if ($taxa->species) {
                    $taxonomicHierarchy['species'] = [
                        'name' => $taxa->species,
                        'common_name' => $taxa->cname_species ?? null
                    ];
                }
                
                // Tentukan nama ilmiah dan nama umum berdasarkan rank
                $commonName = '';
                $scientificName = '';
                
                // Get the appropriate name based on rank
                if (isset($taxa->{$rank}) && isset($taxa->{"cname_$rank"})) {
                    $scientificName = $taxa->{$rank};
                    $commonName = $taxa->{"cname_$rank"};
                }
                
                // If no specific rank name found, use scientific_name
                if (empty($scientificName)) {
                    $scientificName = $taxa->scientific_name;
                }
                
                // Get the family name for context
                $familyContext = $taxa->family ?? null;
                
                return [
                    'id' => $taxa->id,
                    'rank' => $rank,
                    'scientific_name' => $taxa->scientific_name, // Use raw scientific_name like SpeciesSuggestionController
                    'common_name' => $commonName,
                    'taxonomic_status' => $taxa->taxonomic_status,
                    'accepted_scientific_name' => $taxa->accepted_scientific_name,
                    'display_name' => $scientificName . ($commonName ? " ({$commonName})" : ''),
                    'family_context' => $familyContext,
                    'hierarchy' => $taxonomicHierarchy,
                    'full_data' => $taxa
                ];
            });
            
            // Kembalikan hasil dengan pagination
            $paginationInfo = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage)
            ];
            
            return response()->json([
                'success' => true,
                'data' => $results,
                'pagination' => $paginationInfo,
                'message' => "Berhasil mengambil data taksa untuk '{$query}'."
            ]);

        } catch (\Exception $e) {
            Log::error('Error in searchTaxa:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getObservations(Request $request)
    {
        try {
            $request->validate([
                'source' => 'required|in:fobi,burungnesia,kupunesia',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'sort' => 'nullable|in:latest,oldest',
                'taxon_id' => 'nullable|exists:taxas,id',
                'user_id' => 'nullable|exists:fobi_users,id'
            ]);

            $perPage = $request->input('per_page', 20);
            $source = $request->input('source');
            $sort = $request->input('sort', 'latest');

            $query = match($source) {
                'fobi' => DB::table('fobi_checklist_taxas as fct')
                    ->join('fobi_users as fu', 'fct.user_id', '=', 'fu.id')
                    ->join('taxas as t', 'fct.taxa_id', '=', 't.id')
                    ->join('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                    ->select(
                        'fct.*',
                        'fu.uname as observer_name',
                        't.scientific_name',
                        't.species',
                        't.genus',
                        't.family',
                        't.order',
                        'tqa.grade',
                        'tqa.identification_count'
                    ),

                'burungnesia' => DB::table('fobi_checklists as fc')
                    ->join('fobi_checklist_faunasv1 as fcf', 'fc.id', '=', 'fcf.checklist_id')
                    ->join('fobi_users as fu', 'fc.fobi_user_id', '=', 'fu.id')
                    ->join('data_quality_assessments as dqa', 'fc.id', '=', 'dqa.observation_id')
                    ->select(
                        'fc.*',
                        'fcf.fauna_id',
                        'fu.uname as observer_name',
                        'dqa.grade',
                        'dqa.identification_count'
                    ),

                'kupunesia' => DB::table('fobi_checklists_kupnes as fck')
                    ->join('fobi_checklist_faunasv2 as fcf', 'fck.id', '=', 'fcf.checklist_id')
                    ->join('fobi_users as fu', 'fck.fobi_user_id', '=', 'fu.id')
                    ->join('data_quality_assessments_kupnes as dqa', 'fck.id', '=', 'dqa.observation_id')
                    ->select(
                        'fck.*',
                        'fcf.fauna_id',
                        'fu.uname as observer_name',
                        'dqa.grade',
                        'dqa.identification_count'
                    )
            };

            // Apply filters
            if ($request->has('user_id')) {
                $query->where('fu.id', $request->user_id);
            }

            if ($request->has('taxon_id')) {
                $query->where('taxa_id', $request->taxon_id);
            }

            // Apply sorting
            $query->orderBy('created_at', $sort === 'latest' ? 'desc' : 'asc');

            // Get paginated results
            $observations = $query->paginate($perPage);

            // Add media to each observation
            foreach ($observations as $observation) {
                $observation->medias = $this->getObservationMedia($observation->id, $source);
            }

            return response()->json([
                'success' => true,
                'data' => $observations->items(),
                'meta' => [
                    'current_page' => $observations->currentPage(),
                    'per_page' => $observations->perPage(),
                    'total' => $observations->total(),
                    'last_page' => $observations->lastPage()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error in getObservations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data observasi'
            ], 500);
        }
    }

    public function getObservationStatistics(Request $request)
    {
        try {
            $request->validate([
                'source' => 'required|in:fobi,burungnesia,kupunesia',
                'period' => 'nullable|in:daily,weekly,monthly,yearly',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'user_id' => 'nullable|exists:fobi_users,id'
            ]);

            $source = $request->input('source');
            $period = $request->input('period', 'monthly');
            $startDate = $request->input('start_date', now()->subMonth());
            $endDate = $request->input('end_date', now());
            $userId = $request->input('user_id');

            // Tentukan format date berdasarkan period
            $dateFormat = match($period) {
                'daily' => '%Y-%m-%d',
                'weekly' => '%Y-%u',
                'monthly' => '%Y-%m',
                'yearly' => '%Y'
            };

            // Base query berdasarkan sumber
            $query = match($source) {
                'fobi' => DB::table('fobi_checklist_taxas')
                    ->join('taxa_quality_assessments', 'fobi_checklist_taxas.id', '=', 'taxa_quality_assessments.taxa_id'),

                'burungnesia' => DB::table('fobi_checklists')
                    ->join('data_quality_assessments', 'fobi_checklists.id', '=', 'data_quality_assessments.observation_id'),

                'kupunesia' => DB::table('fobi_checklists_kupnes')
                    ->join('data_quality_assessments_kupnes', 'fobi_checklists_kupnes.id', '=', 'data_quality_assessments_kupnes.observation_id')
            };

            // Filter berdasarkan tanggal dan user
            $query->whereBetween('created_at', [$startDate, $endDate]);
            if ($userId) {
                $query->where($source === 'fobi' ? 'user_id' : 'fobi_user_id', $userId);
            }

            // Statistik dasar
            $basicStats = $query->select([
                DB::raw('COUNT(*) as total_observations'),
                DB::raw('COUNT(DISTINCT ' . ($source === 'fobi' ? 'user_id' : 'fobi_user_id') . ') as total_observers'),
                DB::raw("COUNT(CASE WHEN grade = 'research grade' THEN 1 END) as research_grade_count"),
                DB::raw("COUNT(CASE WHEN grade = 'needs ID' THEN 1 END) as needs_id_count"),
                DB::raw('AVG(identification_count) as avg_identifications')
            ])->first();

            // Trend observasi berdasarkan periode
            $trends = $query->select([
                DB::raw("DATE_FORMAT(created_at, '$dateFormat') as period"),
                DB::raw('COUNT(*) as count'),
                DB::raw("COUNT(CASE WHEN grade = 'research grade' THEN 1 END) as research_grade_count")
            ])
            ->groupBy('period')
            ->orderBy('period')
            ->get();

            // Top taxa/species
            $topTaxa = match($source) {
                'fobi' => DB::table('fobi_checklist_taxas')
                    ->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id')
                    ->select('taxas.scientific_name', DB::raw('COUNT(*) as count'))
                    ->groupBy('taxa_id', 'scientific_name')
                    ->orderByDesc('count')
                    ->limit(10)
                    ->get(),

                'burungnesia' => DB::table('fobi_checklists')
                    ->join('fobi_checklist_faunasv1', 'fobi_checklists.id', '=', 'fobi_checklist_faunasv1.checklist_id')
                    ->join('taxas', 'fobi_checklist_faunasv1.fauna_id', '=', 'taxas.burnes_fauna_id')
                    ->select('taxas.scientific_name', DB::raw('COUNT(*) as count'))
                    ->groupBy('fauna_id', 'scientific_name')
                    ->orderByDesc('count')
                    ->limit(10)
                    ->get(),

                'kupunesia' => DB::table('fobi_checklists_kupnes')
                    ->join('fobi_checklist_faunasv2', 'fobi_checklists_kupnes.id', '=', 'fobi_checklist_faunasv2.checklist_id')
                    ->join('taxas', 'fobi_checklist_faunasv2.fauna_id', '=', 'taxas.kupnes_fauna_id')
                    ->select('taxas.scientific_name', DB::raw('COUNT(*) as count'))
                    ->groupBy('fauna_id', 'scientific_name')
                    ->orderByDesc('count')
                    ->limit(10)
                    ->get()
            };

            return response()->json([
                'success' => true,
                'data' => [
                    'basic_stats' => $basicStats,
                    'trends' => $trends,
                    'top_taxa' => $topTaxa
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error in getObservationStatistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil statistik observasi'
            ], 500);
        }
    }

    public function getComments($id)
    {
        try {
            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id, $source);

            // Tentukan kolom ID yang sesuai
            $idColumn = match($source) {
                'burungnesia' => 'burnes_checklist_id',
                'kupunesia' => 'kupnes_checklist_id',
                default => 'observation_id'
            };

            // Buat query berdasarkan source dan ID
            $query = DB::table('observation_comments as c')
                ->join('fobi_users as u', 'c.user_id', '=', 'u.id')
                ->where("c.$idColumn", $actualId)
                ->select(
                    'c.id',
                    'c.comment',
                    'c.created_at',
                    'c.updated_at',
                    'u.uname as user_name',
                    'c.user_id', // Pastikan user_id selalu diselect
                    'c.deleted_at'
                )
                ->whereNull('c.deleted_at')
                ->orderBy('c.created_at', 'desc');

            $comments = $query->get();

            // Log untuk debugging
            Log::info('Comments retrieved:', [
                'id' => $id,
                'actualId' => $actualId,
                'source' => $source,
                'comments' => $comments
            ]);

            return response()->json([
                'success' => true,
                'data' => $comments
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting comments: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil komentar'
            ], 500);
        }
    }

    // Method untuk menambah komentar
    public function addComment(Request $request, $id)
    {
        try {
            $request->validate([
                'comment' => 'required|string|max:1000'
            ]);

            $userId = JWTAuth::user()->id;
            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id, $source);

            // Tentukan kolom ID yang sesuai
            $idColumn = match($source) {
                'burungnesia' => 'burnes_checklist_id',
                'kupunesia' => 'kupnes_checklist_id',
                default => 'observation_id'
            };

            // Siapkan data komentar
            $commentData = [
                $idColumn => $actualId,
                'user_id' => $userId,
                'comment' => $request->comment,
                'created_at' => now(),
                'updated_at' => now()
            ];

            // Insert komentar
            DB::table('observation_comments')->insert($commentData);

            // Ambil data komentar yang baru ditambahkan untuk response
            $comment = DB::table('observation_comments as c')
                ->join('fobi_users as u', 'c.user_id', '=', 'u.id')
                ->where("c.$idColumn", $actualId)
                ->orderBy('c.created_at', 'desc')
                ->select(
                    'c.id',
                    "c.$idColumn as observation_id",
                    'c.comment',
                    'c.created_at',
                    'c.updated_at',
                    'u.uname as user_name',
                    'c.user_id'
                )
                ->first();

            // Buat notifikasi untuk pemilik checklist dan pengguna yang disebut (@mention)
            try {
                // Dapatkan nama pengguna pengirim komentar
                $commenterName = DB::table('fobi_users')->where('id', $userId)->value('uname');

                // 1) Notifikasi ke pemilik checklist (kecuali diri sendiri)
                $owner = $this->getChecklistOwner($actualId, $source);
                if ($owner && isset($owner->id) && $owner->id !== $userId) {
                    $this->createNotification(
                        $owner->id,
                        $actualId,
                        'comment',
                        ($commenterName ? $commenterName : 'Seseorang') . ' mengomentari observasi Anda'
                    );
                }

                // 2) Notifikasi untuk @mentions di dalam komentar
                $text = (string) $request->comment;
                $mentionedUsernames = [];
                if (preg_match_all('/@([A-Za-z0-9_\.]+)/u', $text, $matches)) {
                    $mentionedUsernames = array_unique(array_filter($matches[1] ?? []));
                }

                if (!empty($mentionedUsernames)) {
                    // Cari user id untuk username yang disebut
                    $mentionedUsers = DB::table('fobi_users')
                        ->whereIn('uname', $mentionedUsernames)
                        ->select('id', 'uname')
                        ->get();

                    // Hindari duplikasi dan diri sendiri
                    $notified = [];
                    foreach ($mentionedUsers as $mu) {
                        if (!$mu || !isset($mu->id)) continue;
                        if ($mu->id === $userId) continue; // Jangan notifikasi diri sendiri
                        if (isset($owner->id) && $mu->id === $owner->id && ($owner->id === $userId)) continue; // redundant guard
                        if (in_array($mu->id, $notified, true)) continue;

                        $this->createNotification(
                            $mu->id,
                            $actualId,
                            'mention',
                            ($commenterName ? $commenterName : 'Seseorang') . ' menyebut Anda dalam komentar'
                        );
                        $notified[] = $mu->id;
                    }
                }
            } catch (\Exception $e) {
                // Jika terjadi error saat membuat notifikasi, log saja dan lanjutkan
                Log::error('Error creating notifications for comment: ' . $e->getMessage(), [
                    'observation_id' => $actualId,
                    'source' => $source,
                    'user_id' => $userId
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $comment,
                'message' => 'Komentar berhasil ditambahkan'
            ]);
        } catch (\Exception $e) {
            Log::error('Error adding comment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan komentar'
            ], 500);
        }
    }

    public function getRelatedLocations($taxaId)
    {
        try {
            $source = $this->determineSource($taxaId);

            // Tentukan tabel yang akan digunakan berdasarkan sumber
            $checklistTable = $this->getChecklistTable($source);
            $assessmentTable = match($source) {
                'burungnesia' => 'burnes_quality_assessments',
                'kupunesia' => 'kupnes_quality_assessments',
                default => 'taxa_quality_assessments'
            };

            // Query untuk mendapatkan lokasi terkait
            $relatedLocations = DB::table("$checklistTable as c")
                ->leftJoin("$assessmentTable as qa", 'c.id', '=', 'qa.taxa_id')
                ->where('c.taxa_id', $taxaId)
                ->select(
                    'c.id',
                    'c.latitude',
                    'c.longitude',
                    'c.scientific_name',
                    'c.created_at',
                    DB::raw('COALESCE(qa.grade, "needs ID") as grade')
                )
                ->whereNotNull('c.latitude')
                ->whereNotNull('c.longitude')
                ->get();

            // Format response
            $formattedLocations = $relatedLocations->map(function($location) {
                return [
                    'id' => $location->id,
                    'latitude' => (float)$location->latitude,
                    'longitude' => (float)$location->longitude,
                    'scientific_name' => $location->scientific_name,
                    'created_at' => $location->created_at,
                    'grade' => $location->grade
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedLocations
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting related locations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil lokasi terkait'
            ], 500);
        }
    }

    private function getChecklistConfig($source)
    {
        return match($source) {
            'burungnesia' => [
                'table' => 'fobi_checklists',
                'fauna_table' => 'fobi_checklist_faunasv1',
                'id_column' => 'id',
                'columns' => [
                    'fobi_checklists.id',
                    'fobi_checklist_faunasv1.fauna_id as taxa_id',
                    'fobi_checklists.latitude',
                    'fobi_checklists.longitude',
                    'fobi_checklists.location_details',
                    'fobi_checklists.observer',
                    'fobi_checklists.additional_note as notes',
                    'fobi_checklists.tgl_pengamatan as observation_date',
                    'fobi_checklists.created_at',
                    'fobi_checklists.updated_at'
                ]
            ],
            'kupunesia' => [
                'table' => 'fobi_checklists_kupnes',
                'fauna_table' => 'fobi_checklist_faunasv2',
                'id_column' => 'id',
                'columns' => [
                    'fobi_checklists_kupnes.id',
                    'fobi_checklist_faunasv2.fauna_id as taxa_id',
                    'fobi_checklists_kupnes.latitude',
                    'fobi_checklists_kupnes.longitude',
                    'fobi_checklists_kupnes.location_details',
                    'fobi_checklists_kupnes.observer',
                    'fobi_checklists_kupnes.additional_note as notes',
                    'fobi_checklists_kupnes.tgl_pengamatan as observation_date',
                    'fobi_checklists_kupnes.created_at',
                    'fobi_checklists_kupnes.updated_at'
                ]
            ],
            default => [
                'table' => 'fobi_checklist_taxas',
                'id_column' => 'id',
                'columns' => ['*']
            ]
        };
    }

    private function getMediaForChecklist($checklistId, $source)
    {
        $media = [];

        try {
            if ($source === 'burungnesia') {
                $images = DB::table('fobi_checklist_fauna_imgs')
                    ->where('checklist_id', $checklistId)
                    ->get();
                $sounds = DB::table('fobi_checklist_sounds')
                    ->where('checklist_id', $checklistId)
                    ->get();

                $media['images'] = $images;
                $media['sounds'] = $sounds;
            }
            elseif ($source === 'kupunesia') {
                $images = DB::table('fobi_checklist_fauna_imgs_kupnes')
                    ->where('checklist_id', $checklistId)
                    ->get();

                $media['images'] = $images;
            }
            else {
                $media['images'] = DB::table('fobi_checklist_media')
                    ->where('checklist_id', $checklistId)
                    ->get();
            }

            return $media;
            
        } catch (\Exception $e) {
            Log::error('Error getting media', [
                'error' => $e->getMessage(),
                'checklistId' => $checklistId
            ]);
            return ['images' => [], 'sounds' => []];
        }
    }

    private function getActualId($id, $source = null)
    {
        if (is_numeric($id)) {
            return (int)$id;
        }

        if (!$source) {
            $source = $this->determineSource($id);
        }

        return match($source) {
            'burungnesia' => (int)substr($id, 2), // Remove 'BN' prefix
            'kupunesia' => (int)substr($id, 2),   // Remove 'KP' prefix
            default => (int)$id
        };
    }

    private function getTaxaInfo($taxaId, $source)
    {
        // Coba cari di tabel taxas dulu
        $taxaInfo = DB::table('taxas')
            ->where('id', $taxaId)
            ->first();

        // Jika tidak ditemukan, cek di tabel alternatif sesuai source
        if (!$taxaInfo) {
            $table = match($source) {
                'burungnesia' => 'faunas',
                'kupunesia' => 'faunas_kupnes',
                default => null
            };

            if ($table) {
                $taxaInfo = DB::table($table)
                    ->where('id', $taxaId)
                    ->first();
            }
        }

        return $taxaInfo;
    }

    private function getIdentificationsWithPhotos($id, $source = null)
    {
        if (!$source) {
            $source = $this->determineSource($id);
        }
        $actualId = $this->getActualId($id);
        $userId = optional(JWTAuth::user())->id;

        $query = DB::table('taxa_identifications as i')
            ->join('fobi_users as u', 'i.user_id', '=', 'u.id')
            ->leftJoin('taxas as t', 'i.taxon_id', '=', 't.id')
            ->select([
                'i.*',
                'u.uname as identifier_name',
                // Semua level taksonomi
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
                't.subform',
                't.scientific_name',
                // Common names untuk semua level
                't.cname_domain',
                't.cname_superkingdom',
                't.cname_kingdom',
                't.cname_subkingdom',
                't.cname_superphylum',
                't.cname_phylum',
                't.cname_subphylum',
                't.cname_superdivision',
                't.cname_division',
                't.cname_subdivision',
                't.cname_superclass',
                't.cname_class',
                't.cname_subclass',
                't.cname_infraclass',
                't.cname_superorder',
                't.cname_order',
                't.cname_suborder',
                't.cname_superfamily',
                't.cname_family',
                't.cname_subfamily',
                't.cname_supertribe',
                't.cname_tribe',
                't.cname_subtribe',
                't.cname_genus',
                't.cname_subgenus',
                't.cname_species',
                't.cname_subspecies',
                't.cname_variety',
                DB::raw("CASE
                    WHEN i.photo_path IS NOT NULL
                    THEN CONCAT('" . config('app.url') . "/storage/', i.photo_path)
                    ELSE NULL
                END as photo_url"),
                'i.photo_path'
            ]);

        // Sesuaikan where clause berdasarkan sumber
        if ($source === 'burungnesia') {
            $query->where('i.burnes_checklist_id', $actualId);
        } elseif ($source === 'kupunesia') {
            $query->where('i.kupnes_checklist_id', $actualId);
        } else {
            $query->where('i.checklist_id', $actualId);
        }

        // Tambahkan perhitungan agreement_count dan user_agreed
        $query->addSelect(DB::raw('(SELECT COUNT(*) FROM taxa_identifications WHERE agrees_with_id = i.id) + 1 as agreement_count')); // +1 untuk pengusul
        $query->addSelect(DB::raw('CASE
            WHEN EXISTS(SELECT 1 FROM taxa_identifications WHERE agrees_with_id = i.id AND user_id = ?)
            THEN true
            ELSE NULL
        END as user_agreed'))
            ->addBinding($userId, 'select');

        $identifications = $query->orderBy('i.created_at', 'desc')->get();

        // Transform hasil query untuk memastikan URL foto benar
        return $identifications->map(function($identification) {
            // Convert object to array
            $identification = (array) $identification;

            // Pastikan URL foto benar
            if (!empty($identification['photo_path'])) {
                $identification['photo_url'] = config('app.url') . '/storage/' . $identification['photo_path'];
            } else {
                $identification['photo_url'] = null;
            }

            // Kasus khusus untuk division/phylum
            if (empty($identification['phylum']) && !empty($identification['division'])) {
                $identification['phylum'] = $identification['division'];
                $identification['cname_phylum'] = $identification['cname_division'] ?? null;
            }

            return $identification;
        });
    }

    public function getNeedsIdObservations(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $sort = $request->input('sort', 'latest');

            // Query untuk FOBI
            $fobiQuery = DB::table('fobi_checklist_taxas as fct')
                ->join('fobi_users as fu', 'fct.user_id', '=', 'fu.id')
                ->leftJoin('taxa_quality_assessments as tqa', function($join) {
                    $join->on('fct.id', '=', 'tqa.observation_id')
                         ->where('tqa.type', '=', 'fobi');
                })
                ->leftJoin('taxas as t', 'fct.taxa_id', '=', 't.id')
                ->where(function($query) {
                    $query->where('tqa.grade', 'needs id')
                          ->orWhere('tqa.grade', 'low quality id');
                })
                ->select([
                    'fct.*',
                    'fu.uname as observer_name',
                    't.scientific_name',
                    't.kingdom',
                    't.phylum',
                    't.class',
                    't.order',
                    't.suborder',
                    't.family',
                    't.genus',
                    't.species',
                    't.subspecies',
                    
                    'tqa.grade',
                    'tqa.identification_count'
                ]);

            // Query untuk Burungnesia
            $burungnesiaQuery = DB::table('fobi_checklists as fc')
                ->join('fobi_checklist_faunasv1 as fcf', 'fc.id', '=', 'fcf.checklist_id')
                ->join('fobi_users as fu', 'fc.fobi_user_id', '=', 'fu.id')
                ->join('data_quality_assessments as dqa', 'fc.id', '=', 'dqa.observation_id')
                ->leftJoin('faunas as f', 'fcf.fauna_id', '=', 'f.id')
                ->where(function($query) {
                    $query->where('dqa.grade', 'needs id')
                          ->orWhere('dqa.grade', 'low quality id');
                })
                ->select([
                    DB::raw("CONCAT('BN', fc.id) as id"),
                    'fcf.fauna_id',
                    'fu.uname as observer_name',
                    'dqa.grade',
                    'fc.created_at',
                    DB::raw("'bird' as type"),
                    DB::raw("'burungnesia' as source"),
                    'f.nameLat as title',
                    'f.description',
                    DB::raw("CASE WHEN dqa.identification_count = 0 THEN '' ELSE dqa.identification_count END as identifications_count"),
                    DB::raw('(SELECT JSON_ARRAYAGG(fci.url) FROM fobi_checklist_fauna_imgs fci WHERE fci.checklist_id = fc.id) as images')
                ]);

            // Query untuk Kupunesia
            $kupunesiaQuery = DB::table('fobi_checklists_kupnes as fck')
                ->join('fobi_checklist_faunasv2 as fcf', 'fck.id', '=', 'fcf.checklist_id')
                ->join('fobi_users as fu', 'fck.fobi_user_id', '=', 'fu.id')
                ->join('data_quality_assessments_kupnes as dqa', 'fck.id', '=', 'dqa.observation_id')
                ->leftJoin('faunas_kupnes as fk', 'fcf.fauna_id', '=', 'fk.id')
                ->where(function($query) {
                    $query->where('dqa.grade', 'needs id')
                          ->orWhere('dqa.grade', 'low quality id');
                })
                ->select([
                    DB::raw("CONCAT('KP', fck.id) as id"),
                    'fcf.fauna_id',
                    'fu.uname as observer_name',
                    'dqa.grade',
                    'fck.created_at',
                    DB::raw("'butterfly' as type"),
                    DB::raw("'kupunesia' as source"),
                    'fk.nameLat as title',
                    'fk.description',
                    DB::raw("CASE WHEN dqa.identification_count = 0 THEN '' ELSE dqa.identification_count END as identifications_count"),
                    DB::raw('(SELECT JSON_ARRAYAGG(fci.url) FROM fobi_checklist_fauna_imgs_kupnes fci WHERE fci.checklist_id = fck.id) as images')
                ]);

            // Gabungkan semua query
            $query = $fobiQuery
                ->union($burungnesiaQuery)
                ->union($kupunesiaQuery);

            // Tambahkan sorting dan pagination
            $observations = DB::query()
                ->fromSub($query, 'combined_observations')
                ->orderBy('created_at', $sort === 'latest' ? 'desc' : 'asc')
                ->paginate($perPage);

            // Format data untuk response
            $formattedObservations = collect($observations->items())->map(function($item) {
                return [
                    'id' => $item->id,
                    'fauna_id' => $item->fauna_id,
                    'observer' => $item->observer_name,
                    'title' => $item->title ?? 'Tidak ada nama',
                    'description' => $item->description ?? '',
                    'type' => $item->type,
                    'source' => $item->source,
                    'created_at' => $item->created_at,
                    'images' => json_decode($item->images) ?? [],
                    'quality' => [
                        'grade' => strtolower($item->grade),
                        'has_media' => !empty(json_decode($item->images)),
                        'needs_id' => strtolower($item->grade) === 'needs id',
                        'is_wild' => true,
                        'location_accurate' => true
                    ],
                    'identifications_count' => $item->identifications_count ? (string)$item->identifications_count : ''
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedObservations,
                'meta' => [
                    'current_page' => $observations->currentPage(),
                    'per_page' => $observations->perPage(),
                    'total' => $observations->total(),
                    'last_page' => $observations->lastPage(),
                    'has_more' => $observations->hasMorePages()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error in getNeedsIdObservations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data observasi'
            ], 500);
        }
    }

    /**
     * Menambahkan flag/laporan untuk sebuah checklist
     */
    public function addFlag(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $request->validate([
                'flag_type' => [
                    'required',
                    Rule::in(['identification', 'location', 'media', 'date', 'other'])
                ],
                'reason' => 'required|string|max:1000'
            ]);

            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id);
            $userId = JWTAuth::user()->id;

            // Tentukan kolom ID berdasarkan source untuk taxa_flags
            $flagIdColumn = match($source) {
                'burungnesia' => 'burnes_checklist_id',
                'kupunesia' => 'kupnes_checklist_id',
                default => 'checklist_id'
            };

            // Tentukan tabel dan kolom ID untuk quality assessment
            $assessmentConfig = match($source) {
                'burungnesia' => [
                    'table' => 'data_quality_assessments',
                    'id_column' => 'observation_id'  // Sesuaikan dengan nama kolom yang benar
                ],
                'kupunesia' => [
                    'table' => 'data_quality_assessments_kupnes',
                    'id_column' => 'observation_id'  // Sesuaikan dengan nama kolom yang benar
                ],
                default => [
                    'table' => 'taxa_quality_assessments',
                    'id_column' => 'taxa_id'
                ]
            };

            // Allow multiple flags from the same user - removed restriction
            // Users can now submit unlimited flags for the same checklist

            // Siapkan data flag
            $flagData = [
                $flagIdColumn => $actualId,
                'user_id' => $userId,
                'flag_type' => $request->flag_type,
                'reason' => $request->reason,
                'created_at' => now(),
                'updated_at' => now()
            ];

            // Simpan flag baru
            $flagId = DB::table('taxa_flags')->insertGetId($flagData);

            // Update quality assessment
            DB::table($assessmentConfig['table'])
                ->where($assessmentConfig['id_column'], $actualId)
                ->update([
                    'has_flags' => true,
                    'updated_at' => now()
                ]);

            Log::info('Flag added to checklist', [
                'source' => $source,
                'checklist_id' => $actualId,
                'flag_id' => $flagId,
                'user_id' => $userId,
                'flag_type' => $request->flag_type,
                'assessment_table' => $assessmentConfig['table'],
                'assessment_id_column' => $assessmentConfig['id_column']
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Laporan berhasil ditambahkan',
                'data' => [
                    'flag_id' => $flagId
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding flag: ' . $e->getMessage(), [
                'source' => $source ?? 'unknown',
                'id' => $id,
                'actual_id' => $actualId ?? null
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mengambil daftar flag untuk sebuah checklist
     */
    public function getFlags($id)
    {
        try {
            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id);

            $flags = DB::table('taxa_flags as tf')
                ->join('fobi_users as fu', 'tf.user_id', '=', 'fu.id')
                ->leftJoin('fobi_users as resolver', 'tf.resolved_by', '=', 'resolver.id')
                ->where('tf.checklist_id', $actualId)
                ->select(
                    'tf.*',
                    'fu.uname as reporter_name',
                    'resolver.uname as resolver_name'
                )
                ->orderBy('tf.created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $flags
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting flags: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data laporan'
            ], 500);
        }
    }

    /**
     * Menyelesaikan/resolve sebuah flag
     */
    public function resolveFlag(Request $request, $id, $flagId)
    {
        try {
            DB::beginTransaction();

            $request->validate([
                'resolution_notes' => 'required|string|max:1000'
            ]);

            $userId = JWTAuth::user()->id;
            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id);

            // Update flag
            $updated = DB::table('taxa_flags')
                ->where('id', $flagId)
                ->where('checklist_id', $actualId)
                ->update([
                    'is_resolved' => true,
                    'resolution_notes' => $request->resolution_notes,
                    'resolved_by' => $userId,
                    'resolved_at' => now(),
                    'updated_at' => now()
                ]);

            if (!$updated) {
                throw new \Exception('Flag tidak ditemukan');
            }

            // Cek apakah masih ada flag aktif
            $activeFlags = DB::table('taxa_flags')
                ->where('checklist_id', $actualId)
                ->where('is_resolved', false)
                ->exists();

            // Update quality assessment jika tidak ada flag aktif
            if (!$activeFlags) {
                DB::table('taxa_quality_assessments')
                    ->where('taxa_id', $actualId)
                    ->update([
                        'has_flags' => false,
                        'updated_at' => now()
                    ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Flag berhasil diselesaikan'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error resolving flag: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function createNotification($userId, $checklistId, $type, $message)
    {
        DB::table('taxa_notifications')->insert([
            'user_id' => $userId,
            'checklist_id' => $checklistId,
            'type' => $type,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    // Tambahkan method helper untuk mendapatkan pemilik checklist
    private function getChecklistOwner($id, $source)
    {
        $query = match($source) {
            'burungnesia' => DB::table('fobi_checklists as fc')
                ->join('fobi_users as fu', 'fc.fobi_user_id', '=', 'fu.id')
                ->where('fc.id', $id),
            'kupunesia' => DB::table('fobi_checklists_kupnes as fck')
                ->join('fobi_users as fu', 'fck.fobi_user_id', '=', 'fu.id')
                ->where('fck.id', $id),
            default => DB::table('fobi_checklist_taxas as fct')
                ->join('fobi_users as fu', 'fct.user_id', '=', 'fu.id')
                ->where('fct.id', $id)
        };

        return $query->select('fu.id')->first();
    }

    public function deleteComment($id, $commentId)
    {
        try {
            DB::beginTransaction();
            
            $userId = JWTAuth::user()->id;
            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id, $source);

            // Tentukan kolom ID yang sesuai berdasarkan source
            $idColumn = match($source) {
                'burungnesia' => 'burnes_checklist_id',
                'kupunesia' => 'kupnes_checklist_id',
                default => 'observation_id'
            };

            // Cek apakah user memiliki izin
            $isAdmin = DB::table('fobi_users')
                ->where('id', $userId)
                ->whereIn('level', [3, 4])
                ->exists();

            // Cek kepemilikan komentar dan checklist
            $comment = DB::table('observation_comments as c')
                ->leftJoin('fobi_checklist_taxas as fct', 'c.observation_id', '=', 'fct.id')
                ->leftJoin('fobi_checklists as fc', 'c.burnes_checklist_id', '=', 'fc.id')
                ->leftJoin('fobi_checklists_kupnes as fck', 'c.kupnes_checklist_id', '=', 'fck.id')
                ->where('c.id', $commentId)
                ->where("c.$idColumn", $actualId)
                ->whereNull('c.deleted_at')
                ->select(
                    'c.*',
                    'fct.user_id as fobi_owner_id',
                    'fc.fobi_user_id as burnes_owner_id',
                    'fck.fobi_user_id as kupnes_owner_id'
                )
                ->first();

            if (!$comment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Komentar tidak ditemukan atau sudah dihapus'
                ], 404);
            }

            // Cek apakah user adalah pemilik komentar atau admin atau pemilik checklist
            $isOwner = $comment->user_id === $userId;
            $isChecklistOwner = match($source) {
                'burungnesia' => $comment->burnes_owner_id === $userId,
                'kupunesia' => $comment->kupnes_owner_id === $userId,
                default => $comment->fobi_owner_id === $userId
            };

            if (!$isAdmin && !$isOwner && !$isChecklistOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki izin untuk menghapus komentar ini'
                ], 403);
            }

            // Soft delete komentar
            $updated = DB::table('observation_comments')
                ->where('id', $commentId)
                ->where($idColumn, $actualId)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now()
                ]);

            if (!$updated) {
                throw new \Exception('Gagal menghapus komentar');
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Komentar berhasil dihapus',
                'data' => [
                    'id' => $commentId,
                    'deleted_at' => now()
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting comment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus komentar'
            ], 500);
        }
    }

    public function flagComment($id, $commentId, Request $request)
    {
        try {
            $request->validate([
                'reason' => 'required|string|max:1000'
            ]);

            $userId = JWTAuth::user()->id;
            $source = $this->determineSource($id);
            $actualId = $this->getActualId($id, $source);

            // Insert ke taxa_flags dengan flag_type 'comment'
            DB::table('taxa_flags')->insert([
                'checklist_id' => $source === 'fobi' ? $actualId : null,
                'burnes_checklist_id' => $source === 'burungnesia' ? $actualId : null,
                'kupnes_checklist_id' => $source === 'kupunesia' ? $actualId : null,
                'user_id' => $userId,
                'flag_type' => 'other', // atau tambahkan enum 'comment' di database
                'reason' => "Comment ID: {$commentId} - " . $request->reason,
                'is_resolved' => false,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Komentar berhasil dilaporkan'
            ]);

        } catch (\Exception $e) {
            Log::error('Error flagging comment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat melaporkan komentar'
            ], 500);
        }
    }

    /**
     * Mengambil status IUCN dari IUCN Red List API
     * 
     * @param string $scientificName Nama ilmiah spesies
     * @return string|null Status IUCN atau null jika tidak ditemukan
     */
    private function getIUCNStatusFromAPI($scientificName)
    {
        try {
            // Pisahkan nama ilmiah menjadi genus dan species
            $nameParts = explode(' ', $scientificName);
            $genusName = $nameParts[0];
            $speciesName = isset($nameParts[1]) ? $nameParts[1] : '';
            
            // Jika tidak ada species name, tidak bisa melakukan pencarian
            if (empty($speciesName)) {
                \Log::info('IUCN search skipped: No species name provided', [
                    'scientific_name' => $scientificName
                ]);
                return null;
            }
            
            $client = new \GuzzleHttp\Client([
                'timeout' => 10, // Tambahkan timeout untuk mencegah request terlalu lama
                'connect_timeout' => 5,
                'http_errors' => false, // Jangan lempar exception untuk HTTP error
                'verify' => false // Disable SSL verification jika diperlukan
            ]);
            
            \Log::info('Requesting IUCN status', [
                'genus' => $genusName,
                'species' => $speciesName,
                'url' => "https://api.iucnredlist.org/api/v4/taxa/scientific_name?genus_name=".urlencode($genusName)."&species_name=".urlencode($speciesName)
            ]);
            
            $response = $client->request('GET', 
                "https://api.iucnredlist.org/api/v4/taxa/scientific_name?genus_name=".urlencode($genusName)."&species_name=".urlencode($speciesName), 
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'H4mxtPMSmNmCDZL1YmFrr85Y7tPJawcyRKhQ'
                    ]
                ]
            );
            
            // Periksa status code
            if ($response->getStatusCode() !== 200) {
                \Log::warning('IUCN API returned non-200 status code', [
                    'status_code' => $response->getStatusCode(),
                    'scientific_name' => $scientificName,
                    'reason' => $response->getReasonPhrase()
                ]);
                return null;
            }
            
            // Ambil body respons
            $body = (string) $response->getBody();
            
            // Periksa apakah body kosong
            if (empty($body)) {
                \Log::warning('IUCN API returned empty response', [
                    'scientific_name' => $scientificName
                ]);
                return null;
            }
            
            // Coba decode JSON dengan penanganan error
            $data = json_decode($body, true);
            
            // Periksa apakah JSON valid
            if (json_last_error() !== JSON_ERROR_NONE) {
                \Log::warning('IUCN API returned invalid JSON', [
                    'scientific_name' => $scientificName,
                    'error' => json_last_error_msg(),
                    'body_sample' => substr($body, 0, 500), // Log sebagian dari body untuk debugging
                    'body_length' => strlen($body)
                ]);
                return null;
            }
            
            // Log respons untuk debugging
            \Log::info('IUCN API response received', [
                'scientific_name' => $scientificName,
                'has_assessments' => isset($data['assessments']) && !empty($data['assessments']),
                'assessments_count' => isset($data['assessments']) ? count($data['assessments']) : 0
            ]);
            
            // Periksa apakah ada data assessment dan ambil yang terbaru (latest)
            if (isset($data['assessments']) && !empty($data['assessments'])) {
                foreach ($data['assessments'] as $assessment) {
                    if (isset($assessment['latest']) && $assessment['latest'] === true) {
                        \Log::info('IUCN status found (latest)', [
                            'scientific_name' => $scientificName,
                            'status' => $assessment['red_list_category_code']
                        ]);
                        return $assessment['red_list_category_code'];
                    }
                }
                
                // Jika tidak ada yang latest, ambil yang pertama
                \Log::info('IUCN status found (first)', [
                    'scientific_name' => $scientificName,
                    'status' => $data['assessments'][0]['red_list_category_code']
                ]);
                return $data['assessments'][0]['red_list_category_code'];
            }
            
            \Log::info('No IUCN status found', [
                'scientific_name' => $scientificName
            ]);
            return null;
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            \Log::error('IUCN API connection error: ' . $e->getMessage(), [
                'scientific_name' => $scientificName,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return null;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            \Log::error('IUCN API request error: ' . $e->getMessage(), [
                'scientific_name' => $scientificName,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return null;
        } catch (\Exception $e) {
            \Log::error('Error fetching IUCN status: ' . $e->getMessage(), [
                'scientific_name' => $scientificName,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }
    
    /**
     * Memperbarui status IUCN pada detail observasi
     * 
     * @param object $checklist Detail observasi
     * @return object Checklist yang sudah diperbarui
     */
    private function updateIUCNStatus($checklist)
    {
        if (!$checklist || !isset($checklist->scientific_name)) {
            return $checklist;
        }
        
        try {
            $iucnStatus = $this->getIUCNStatusFromAPI($checklist->scientific_name);
            
            if ($iucnStatus) {
                // Update status IUCN di database
                DB::table('fobi_checklist_taxas')
                    ->where('id', $checklist->id)
                    ->update([
                        'iucn_status' => $iucnStatus,
                        'updated_at' => now()
                    ]);
                    
                // Update juga di tabel taxas jika ada
                if (isset($checklist->taxa_id)) {
                    DB::table('taxas')
                        ->where('id', $checklist->taxa_id)
                        ->update([
                            'iucn_red_list_category' => $iucnStatus,
                            'updated_at' => now()
                        ]);
                }
                
                // Update objek checklist dengan status IUCN yang baru
                $checklist->iucn_status = $iucnStatus;
            }
            
            return $checklist;
        } catch (\Exception $e) {
            \Log::error('Error updating IUCN status: ' . $e->getMessage());
            return $checklist;
        }
    }

    /**
     * Mengambil status IUCN dari API untuk nama ilmiah tertentu
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getIUCNStatus(Request $request)
    {
        try {
            $request->validate([
                'scientific_name' => 'required|string'
            ]);

            $scientificName = $request->scientific_name;
            
            \Log::info('Fetching IUCN status for: ' . $scientificName);
            
            $iucnStatus = $this->getIUCNStatusFromAPI($scientificName);
            
            if ($iucnStatus === null) {
                \Log::info('No IUCN status found for: ' . $scientificName);
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'scientific_name' => $scientificName,
                        'iucn_status' => null,
                        'message' => 'Status IUCN tidak ditemukan untuk spesies ini'
                    ]
                ]);
            }

            \Log::info('IUCN status found: ' . $iucnStatus . ' for: ' . $scientificName);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'scientific_name' => $scientificName,
                    'iucn_status' => $iucnStatus
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Nama ilmiah harus diisi',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error getting IUCN status: ' . $e->getMessage(), [
                'scientific_name' => $request->scientific_name ?? 'unknown',
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil status IUCN: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cek apakah perlu modal konfirmasi untuk identifikasi baru
     * Modal hanya muncul jika grade saat ini sudah research grade atau confirmed id
     */
    private function checkModalConfirmationNeeded($checklistId, $newTaxonId, $source)
    {
        try {
            Log::info('Checking modal confirmation needed', [
                'checklistId' => $checklistId,
                'newTaxonId' => $newTaxonId,
                'source' => $source
            ]);
            // Ambil current quality assessment
            $assessmentTable = match($source) {
                'burungnesia' => 'data_quality_assessments',
                'kupunesia' => 'data_quality_assessments_kupnes',
                default => 'taxa_quality_assessments'
            };
            
            $idColumn = match($source) {
                'burungnesia' => 'burnes_checklist_id',
                'kupunesia' => 'kupnes_checklist_id',
                default => 'taxa_id'  // Untuk taxa_quality_assessments gunakan taxa_id
            };
            
            $currentAssessment = DB::table($assessmentTable)
                ->where($idColumn, $checklistId)
                ->first();
                
            if (!$currentAssessment) {
                return false;
            }
            
            $currentGrade = $currentAssessment->grade;
            
            // Modal hanya untuk research grade atau confirmed id
            if (!in_array($currentGrade, ['research grade', 'confirmed id'])) {
                Log::info('Modal not needed - grade not high enough', [
                    'currentGrade' => $currentGrade
                ]);
                return false;
            }
            
            Log::info('Current assessment found', [
                'grade' => $currentGrade,
                'assessment' => $currentAssessment
            ]);
            
            // Ambil takson baru yang diusulkan
            $newTaxon = DB::table('taxas')
                ->where('id', $newTaxonId)
                ->first();
                
            if (!$newTaxon) {
                return false;
            }
            
            $newRank = strtolower($newTaxon->taxon_rank);
            
            // Ambil takson yang saat ini disepakati
            $currentTaxonId = match($source) {
                'burungnesia' => $currentAssessment->burnes_fauna_id,
                'kupunesia' => $currentAssessment->kupnes_fauna_id,
                default => $currentAssessment->taxon_id  // Perbaikan: gunakan taxon_id bukan taxa_id
            };
            
            if (!$currentTaxonId) {
                return false;
            }
            
            $currentTaxon = DB::table('taxas')
                ->where('id', $currentTaxonId)
                ->first();
                
            if (!$currentTaxon) {
                return false;
            }
            
            $currentRank = strtolower($currentTaxon->taxon_rank);
            
            // Modal muncul jika:
            // 1. Current grade adalah research grade (species/subspecies) dan usulan baru adalah genus ke atas
            // 2. Current grade adalah confirmed id (genus ke atas) dan usulan baru adalah rank yang lebih tinggi
            
            $higherRanks = ['genus', 'subgenus', 'subfamily', 'family', 'tribe', 'subtribe', 'order', 'class', 'phylum', 'kingdom'];
            
            Log::info('Checking modal conditions', [
                'currentGrade' => $currentGrade,
                'currentRank' => $currentRank,
                'newRank' => $newRank,
                'currentTaxon' => $currentTaxon->scientific_name,
                'newTaxon' => $newTaxon->scientific_name
            ]);

            if ($currentGrade === 'research grade') {
                // Current adalah species/subspecies dengan research grade
                if (in_array($newRank, $higherRanks)) {
                    // Check if in same lineage OR cross lineage (untuk kasus Accipitriformes vs Passeriformes)
                    $isInSameLineage = $this->qualityAssessmentController->isInSameTaxonomicLineage($currentTaxon, $newTaxon);
                    $isCrossLineage = $this->isCrossLineageChallenge($currentTaxon, $newTaxon);
                    
                    Log::info('Lineage check result for modal confirmation', [
                        'isInSameLineage' => $isInSameLineage,
                        'isCrossLineage' => $isCrossLineage,
                        'currentTaxon' => $currentTaxon->scientific_name,
                        'newTaxon' => $newTaxon->scientific_name,
                        'currentOrder' => $currentTaxon->order ?? 'N/A',
                        'newOrder' => $newTaxon->order ?? 'N/A'
                    ]);
                    
                    // PERBAIKAN: Modal hanya muncul untuk kasus yang benar-benar memerlukan konfirmasi
                    if ($isInSameLineage) {
                        // Same lineage: Modal untuk hierarkis challenge (species -> genus/family/order)
                        Log::info('Modal confirmation needed - hierarchical identification detected');
                        return [
                            'current_grade' => $currentGrade,
                            'current_taxon' => [
                                'id' => $currentTaxon->id,
                                'name' => $currentTaxon->scientific_name,
                                'rank' => $currentRank,
                                'order' => $currentTaxon->order ?? null
                            ],
                            'new_taxon' => [
                                'id' => $newTaxon->id,
                                'name' => $newTaxon->scientific_name,
                                'rank' => $newRank,
                                'order' => $newTaxon->order ?? null
                            ],
                            'challenge_type' => 'hierarchical',
                            'message' => 'Identifikasi saat ini sudah Research Grade (' . $currentTaxon->scientific_name . '). Apakah Anda yakin dengan identifikasi tingkat ' . $newRank . ' (' . $newTaxon->scientific_name . ')?'
                        ];
                    } elseif ($isCrossLineage && $this->shouldShowCrossLineageModal($currentTaxon, $newTaxon)) {
                        // Cross lineage: Modal hanya untuk kasus tertentu yang memerlukan konfirmasi
                        Log::info('Modal confirmation needed - significant cross lineage challenge detected');
                        return [
                            'current_grade' => $currentGrade,
                            'current_taxon' => [
                                'id' => $currentTaxon->id,
                                'name' => $currentTaxon->scientific_name,
                                'rank' => $currentRank,
                                'order' => $currentTaxon->order ?? null
                            ],
                            'new_taxon' => [
                                'id' => $newTaxon->id,
                                'name' => $newTaxon->scientific_name,
                                'rank' => $newRank,
                                'order' => $newTaxon->order ?? null
                            ],
                            'challenge_type' => 'cross_lineage',
                            'message' => 'Identifikasi saat ini sudah Research Grade (' . $currentTaxon->scientific_name . ' - ' . ($currentTaxon->order ?? 'Unknown Order') . '). Anda mengusulkan taksa dari lineage berbeda (' . $newTaxon->scientific_name . ' - ' . ($newTaxon->order ?? 'Unknown Order') . '). Apakah Anda yakin?'
                        ];
                    }
                }
            } elseif ($currentGrade === 'confirmed id') {
                // Current adalah genus ke atas dengan confirmed id
                $rankOrder = [
                    'subspecies' => 1,
                    'species' => 2,
                    'genus' => 3,
                    'family' => 4,
                    'order' => 5,
                    'class' => 6,
                    'phylum' => 7,
                    'kingdom' => 8
                ];
                
                $currentRankOrder = $rankOrder[$currentRank] ?? 99;
                $newRankOrder = $rankOrder[$newRank] ?? 99;
                
                if ($newRankOrder > $currentRankOrder) {
                    // Usulan rank lebih tinggi dari current
                    if ($this->qualityAssessmentController->isInSameTaxonomicLineage($currentTaxon, $newTaxon)) {
                        return [
                            'current_grade' => $currentGrade,
                            'current_taxon' => [
                                'id' => $currentTaxon->id,
                                'name' => $currentTaxon->scientific_name,
                                'rank' => $currentRank
                            ],
                            'new_taxon' => [
                                'id' => $newTaxon->id,
                                'name' => $newTaxon->scientific_name,
                                'rank' => $newRank
                            ],
                            'message' => 'Identifikasi saat ini sudah Confirmed ID. Apakah Anda yakin dengan identifikasi tingkat ' . $newRank . '?'
                        ];
                    }
                }
            }
            
            Log::info('Modal not needed - conditions not met');
            return false;
        } catch (Exception $e) {
            Log::error('Error checking modal confirmation: ' . $e->getMessage());
            return false;
        }
    }
    
    public function searchBirdTaxa(Request $request)
    {
        try {
            $request->validate([
                'q' => 'required|string|min:2',
                'include_locations' => 'nullable|boolean',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'rank' => 'nullable|string'
            ]);

            $query = $request->input('q');
            $source = $request->input('source', 'burungnesia');
            $includeLocations = $request->input('include_locations', false);
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);
            $rank = $request->input('rank');
            
            Log::info("Bird taxa search query: {$query}, page: {$page}, per_page: {$perPage}, source: {$source}, rank: {$rank}");

            // Base query dengan filter untuk class Aves
            $results = DB::table('taxas')
                ->where('taxas.class', 'Aves')
                ->where(function($q) use ($query) {
                    // Bersihkan query dari tanda strip
                    $cleanQuery = str_replace('-', ' ', $query);

                    $q->where(DB::raw("REPLACE(taxas.cname_species, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.species, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.scientific_name, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.genus, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_genus, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.family, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_family, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.order, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_order, '-', ' ')"), 'like', "%{$cleanQuery}%");
                });

            // Filter berdasarkan rank jika parameter rank diberikan
            if ($rank) {
                $rankUpper = strtoupper($rank);
                $results->where('taxas.taxon_rank', $rankUpper);
            }

            // Filter berdasarkan sumber
            // PENTING: Untuk Burungnesia, tampilkan SEMUA taxa (termasuk genus, family, dll)
            // Tidak hanya yang punya burnes_fauna_id, karena genus/family mungkin tidak punya fauna_id
            if ($source === 'burungnesia') {
                $results->leftJoin('faunas', 'taxas.burnes_fauna_id', '=', 'faunas.id')
                       ->select(
                           'taxas.id',
                           'taxas.taxon_rank',
                           DB::raw('COALESCE(taxas.scientific_name, faunas.nameLat) as scientific_name'),
                           DB::raw('COALESCE(taxas.species, faunas.nameLat) as species'),
                           DB::raw('COALESCE(taxas.cname_species, faunas.nameId) as cname_species'),
                           'taxas.genus',
                           'taxas.cname_genus',
                           'taxas.family',
                           'taxas.cname_family',
                           'taxas.burnes_fauna_id as fauna_id'
                       );
            } else {
                $results->select(
                    'taxas.id',
                    'taxas.taxon_rank',
                    'taxas.scientific_name',
                    'taxas.species',
                    'taxas.cname_species',
                    'taxas.genus',
                    'taxas.cname_genus',
                    'taxas.family',
                    'taxas.cname_family',
                    'taxas.burnes_fauna_id as fauna_id'
                );
            }

            // Pagination
            $total = $results->count();
            $items = $results->skip(($page - 1) * $perPage)
                            ->take($perPage)
                            ->get();

            // Format hasil untuk respons
            // PENTING: Gunakan taxas.id sebagai ID utama, bukan fauna_id (burnes_fauna_id)
            // Ini untuk menghindari ID bentrok dan memastikan data tersimpan dengan benar
            $formattedItems = $items->map(function ($item) {
                // Tentukan nama lokal berdasarkan taxon_rank
                $namaLokal = null;
                $taxonRank = strtoupper($item->taxon_rank ?? 'SPECIES');
                
                switch ($taxonRank) {
                    case 'SPECIES':
                    case 'SUBSPECIES':
                        $namaLokal = $item->cname_species ?? null;
                        break;
                    case 'GENUS':
                        $namaLokal = $item->cname_genus ?? null;
                        break;
                    case 'FAMILY':
                        $namaLokal = $item->cname_family ?? null;
                        break;
                    default:
                        $namaLokal = $item->cname_species ?? $item->cname_genus ?? $item->cname_family ?? null;
                }
                
                // Fallback untuk displayName
                $displayName = $namaLokal ?: ($item->species ?: $item->scientific_name);
                
                return [
                    'id' => $item->id, // Gunakan taxas.id sebagai ID utama
                    'nameId' => $namaLokal ?: $displayName, // Nama lokal/Indonesia
                    'nameLat' => $item->scientific_name ?: $item->species, // Nama Latin
                    'displayName' => $displayName,
                    'taxa_id' => $item->id,
                    'fauna_id' => $item->fauna_id, // Simpan fauna_id (burnes_fauna_id) sebagai referensi
                    'taxon_rank' => $taxonRank
                ];
            });

            // Jika tidak ada hasil, coba fallback ke metode lama
            if ($formattedItems->isEmpty() && $source === 'burungnesia') {
                try {
                    // Fallback ke pencarian di tabel faunas
                    $fallbackResults = DB::connection('second')
                        ->table('faunas')
                        ->where('nameId', 'like', "%{$query}%")
                        ->orWhere('nameLat', 'like', "%{$query}%")
                        ->select('id', 'nameId', 'nameLat')
                        ->limit($perPage)
                        ->get();

                    if ($fallbackResults->isEmpty()) {
                        $fallbackResults = DB::table('faunas')
                            ->where('nameId', 'like', "%{$query}%")
                            ->orWhere('nameLat', 'like', "%{$query}%")
                            ->select('id', 'nameId', 'nameLat')
                            ->limit($perPage)
                            ->get();
                    }

                    $formattedItems = $fallbackResults->map(function ($fauna) {
                        return [
                            'id' => $fauna->id,
                            'nameId' => $fauna->nameId,
                            'nameLat' => $fauna->nameLat,
                            'displayName' => $fauna->nameId
                        ];
                    });
                } catch (\Exception $e) {
                    Log::error('Error in fallback bird search: ' . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'data' => $formattedItems,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'last_page' => ceil($total / $perPage)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error in searchBirdTaxa: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Terjadi kesalahan saat mencari data taksonomi burung.'
            ], 500);
        }
    }

    public function searchButterflyTaxa(Request $request)
    {
        try {
            $request->validate([
                'q' => 'required|string|min:2',
                'include_locations' => 'nullable|boolean',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'rank' => 'nullable|string'
            ]);

            $query = $request->input('q');
            $source = $request->input('source', 'kupunesia');
            $includeLocations = $request->input('include_locations', false);
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);
            $rank = $request->input('rank');
            
            Log::info("Butterfly taxa search query: {$query}, page: {$page}, per_page: {$perPage}, source: {$source}, rank: {$rank}");

            // Base query dengan filter untuk order Lepidoptera
            $results = DB::table('taxas')
                ->where('taxas.order', 'Lepidoptera')
                ->where(function($q) use ($query) {
                    // Bersihkan query dari tanda strip
                    $cleanQuery = str_replace('-', ' ', $query);

                    $q->where(DB::raw("REPLACE(taxas.cname_species, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.species, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.scientific_name, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.genus, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_genus, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.family, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_family, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.subfamily, '-', ' ')"), 'like', "%{$cleanQuery}%")
                      ->orWhere(DB::raw("REPLACE(taxas.cname_subfamily, '-', ' ')"), 'like', "%{$cleanQuery}%");
                });

            // Filter berdasarkan rank jika parameter rank diberikan
            if ($rank) {
                $rankUpper = strtoupper($rank);
                $results->where('taxas.taxon_rank', $rankUpper);
            }

            // Filter berdasarkan sumber (untuk taxas table)
            if ($source === 'kupunesia') {
                $results->whereNotNull('taxas.kupnes_fauna_id')
                       ->leftJoin('faunas_kupnes', 'taxas.kupnes_fauna_id', '=', 'faunas_kupnes.id')
                       ->select(
                           'taxas.id',
                           'taxas.taxon_rank',
                           DB::raw('COALESCE(taxas.scientific_name, faunas_kupnes.nameLat) as scientific_name'),
                           DB::raw('COALESCE(taxas.species, faunas_kupnes.nameLat) as species'),
                           DB::raw('COALESCE(taxas.cname_species, faunas_kupnes.nameId) as cname_species'),
                           DB::raw('COALESCE(taxas.genus, faunas_kupnes.nameLat) as genus'),
                           DB::raw('COALESCE(taxas.cname_genus, faunas_kupnes.nameLat) as cname_genus'),
                           DB::raw('COALESCE(taxas.family, faunas_kupnes.nameLat) as family'),
                           DB::raw('COALESCE(taxas.cname_family, faunas_kupnes.nameLat) as cname_family'),
                           'taxas.kupnes_fauna_id as fauna_id'
                       );
            } else {
                $results->select(
                    'taxas.id',
                    'taxas.taxon_rank',
                    'taxas.scientific_name',
                    'taxas.species',
                    'taxas.cname_species',
                    'taxas.genus',
                    'taxas.cname_genus',
                    'taxas.family',
                    'taxas.cname_family',
                    'taxas.kupnes_fauna_id as fauna_id'
                );
            }

            // Pagination
            $total = $results->count();
            $items = $results->skip(($page - 1) * $perPage)
                            ->take($perPage)
                            ->get();

            // Format hasil untuk respons
            $formattedItems = $items->map(function ($item) {
                $displayName = $item->cname_species ?: ($item->species ?: $item->scientific_name);
                
                return [
                    'id' => $item->fauna_id,
                    'nameId' => $item->cname_species ?: $displayName,
                    'nameLat' => $item->scientific_name ?: $item->species,
                    'displayName' => $displayName,
                    'taxa_id' => $item->id,
                    'taxon_rank' => $item->taxon_rank
                ];
            });

            // Jika tidak ada hasil, coba fallback ke metode lama
            if ($formattedItems->isEmpty() && $source === 'kupunesia') {
                try {
                    // Fallback ke pencarian di tabel faunas_kupnes
                    $fallbackResults = DB::connection('third')
                        ->table('faunas')
                        ->where('nameId', 'like', "%{$query}%")
                        ->orWhere('nameLat', 'like', "%{$query}%")
                        ->orWhere('nameEn', 'like', "%{$query}%")
                        ->select('id', 'nameId', 'nameLat', 'nameEn')
                        ->limit($perPage)
                        ->get();

                    $formattedItems = $fallbackResults->map(function ($fauna) {
                        return [
                            'id' => $fauna->id,
                            'nameId' => $fauna->nameId,
                            'nameLat' => $fauna->nameLat,
                            'displayName' => $fauna->nameId . ' (' . $fauna->nameLat . ')'
                        ];
                    });
                } catch (\Exception $e) {
                    Log::error('Error in fallback butterfly search: ' . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'data' => $formattedItems,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'last_page' => ceil($total / $perPage)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error in searchButterflyTaxa: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Terjadi kesalahan saat mencari data taksonomi kupu-kupu.'
            ], 500);
        }
    }

    /**
     * Memproses logika hierarki taksonomi untuk identifikasi baru
     * Menentukan apakah identifikasi harus dikecualikan dari kuorum berdasarkan aturan hierarki
     */
    private function processHierarchicalIdentification($actualId, $newTaxonId, $source)
    {
        try {
            // Ambil data takson baru yang akan diidentifikasi
            $newTaxon = DB::table('taxas')
                ->select(['id', 'scientific_name', 'taxon_rank', 'kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species', 'subspecies'])
                ->where('id', $newTaxonId)
                ->first();

            if (!$newTaxon) {
                return [
                    'exclude_from_quorum' => false,
                    'message' => null
                ];
            }

            // Ambil semua identifikasi aktif yang sudah ada untuk checklist ini
            // KECUALIKAN identifikasi ragu-ragu dari logika hierarki
            $existingIdentifications = DB::table('taxa_identifications as ti')
                ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                ->where(function($query) use ($actualId, $source) {
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
                ->whereNull('ti.agrees_with_id') // Hanya identifikasi langsung, bukan persetujuan
                ->where(function($query) {
                    // KECUALIKAN identifikasi ragu-ragu dari logika hierarki
                    $query->where('ti.excluded_from_quorum', 0)
                          ->orWhereNull('ti.excluded_from_quorum');
                })
                ->select([
                    'ti.id as identification_id',
                    'ti.taxon_id',
                    'ti.user_id',
                    'ti.excluded_from_quorum',
                    'ti.confidence_level',
                    't.scientific_name',
                    't.taxon_rank',
                    't.kingdom', 't.phylum', 't.class', 't.order', 't.family', 't.genus', 't.species', 't.subspecies'
                ])
                ->get();

            Log::info('Processing hierarchical identification (excluding doubtful)', [
                'new_taxon' => [
                    'id' => $newTaxon->id,
                    'name' => $newTaxon->scientific_name,
                    'rank' => $newTaxon->taxon_rank
                ],
                'existing_identifications_count' => $existingIdentifications->count(),
                'existing_identifications' => $existingIdentifications->map(function($id) {
                    return [
                        'id' => $id->identification_id,
                        'taxon_id' => $id->taxon_id,
                        'name' => $id->scientific_name,
                        'excluded_from_quorum' => $id->excluded_from_quorum,
                        'confidence_level' => $id->confidence_level
                    ];
                })->toArray()
            ]);

            // Hitung persetujuan untuk setiap taksa yang ada
            $taxonAgreements = [];
            foreach ($existingIdentifications as $identification) {
                $taxonId = $identification->taxon_id;
                if (!isset($taxonAgreements[$taxonId])) {
                    $taxonAgreements[$taxonId] = [
                        'taxon' => $identification,
                        'count' => 0
                    ];
                }
                $taxonAgreements[$taxonId]['count']++;

                // Tambahkan persetujuan eksplisit
                $agreementCount = DB::table('taxa_identifications')
                    ->where('agrees_with_id', $identification->identification_id)
                    ->where(function($query) {
                        $query->where('is_withdrawn', false)
                              ->orWhereNull('is_withdrawn');
                    })
                    ->whereNull('deleted_at')
                    ->count();
                
                $taxonAgreements[$taxonId]['count'] += $agreementCount;
            }

            // Cari taksa yang sudah mencapai kuorum
            $totalParticipants = $existingIdentifications->count();
            $taxaWithQuorum = [];
            
            foreach ($taxonAgreements as $taxonId => $data) {
                $agreementCount = $data['count'];
                if ($agreementCount >= 2 && $agreementCount >= (2/3) * $totalParticipants) {
                    $taxaWithQuorum[] = [
                        'taxon' => $data['taxon'],
                        'agreement_count' => $agreementCount
                    ];
                }
            }

            if (empty($taxaWithQuorum)) {
                // Tidak ada taksa dengan kuorum, identifikasi baru dapat berpartisipasi normal
                return [
                    'exclude_from_quorum' => false,
                    'message' => null
                ];
            }

            // Cek hubungan hierarkis dengan taksa yang sudah mencapai kuorum
            foreach ($taxaWithQuorum as $quorumData) {
                $quorumTaxon = $quorumData['taxon'];
                
                if ($this->qualityAssessmentController->isInSameTaxonomicLineage($newTaxon, $quorumTaxon)) {
                    $newRank = strtolower($newTaxon->taxon_rank);
                    $quorumRank = strtolower($quorumTaxon->taxon_rank);

                    Log::info('Hierarchical relationship detected', [
                        'new_taxon' => [
                            'name' => $newTaxon->scientific_name,
                            'rank' => $newRank
                        ],
                        'quorum_taxon' => [
                            'name' => $quorumTaxon->scientific_name,
                            'rank' => $quorumRank
                        ]
                    ]);

                    // Implementasi aturan hierarki:

                    // 1. Jika ada species dengan kuorum, dan identifikasi baru adalah genus dari species tersebut
                    if ($quorumRank === 'species' && $newRank === 'genus') {
                        // Species -> Genus: Genus akan menjadi "confirmed ID" tanpa mencoret species
                        // Identifikasi genus tidak dikecualikan dari kuorum, akan berkontribusi untuk confirmed ID
                        return [
                            'exclude_from_quorum' => false,
                            'message' => 'Identifikasi genus ini akan berkontribusi untuk status "Confirmed ID" karena ada species terkait yang sudah mencapai kuorum.'
                        ];
                    }

                    // 2. Jika ada genus dengan kuorum, dan identifikasi baru adalah species dari genus tersebut
                    if ($quorumRank === 'genus' && $newRank === 'species') {
                        // Genus -> Species: Species akan menjadi "research grade" (ID lengkap)
                        // Identifikasi species tidak dikecualikan, akan upgrade ke research grade
                        return [
                            'exclude_from_quorum' => false,
                            'message' => 'Identifikasi species ini akan mengupgrade status menjadi "Research Grade" (ID lengkap) karena ada genus terkait yang sudah mencapai kuorum.'
                        ];
                    }

                    // 3. Jika ada species dengan kuorum, dan identifikasi baru adalah subspecies/variety/form
                    if ($quorumRank === 'species' && in_array($newRank, ['subspecies', 'variety', 'form'])) {
                        // Species -> Subspecies: Subspecies akan menjadi "research grade"
                        return [
                            'exclude_from_quorum' => false,
                            'message' => 'Identifikasi subspecies/variety/form ini akan mengupgrade status menjadi "Research Grade" karena ada species terkait yang sudah mencapai kuorum.'
                        ];
                    }

                    // PERBAIKAN: Jika ada subspecies dengan kuorum, dan identifikasi baru adalah species dari lineage yang sama
                    if (in_array($quorumRank, ['subspecies', 'variety', 'form']) && $newRank === 'species') {
                        // Cek apakah species baru adalah parent dari subspecies yang sudah ada kuorum
                        if ($this->isSpeciesSubspeciesSameLineage($newTaxon, $quorumTaxon)) {
                            Log::info('Species-subspecies same lineage detected - allowing species identification', [
                                'new_species' => $newTaxon->scientific_name,
                                'existing_subspecies' => $quorumTaxon->scientific_name,
                                'reason' => 'Species identification in same lineage as existing subspecies should not be doubtful'
                            ]);
                            
                            return [
                                'exclude_from_quorum' => false,
                                'message' => 'Identifikasi species ini akan berpartisipasi dalam hierarchical consensus dengan subspecies terkait yang sudah ada.'
                            ];
                        }
                    }

                    // 4. Jika identifikasi baru adalah rank yang lebih tinggi dari yang sudah mencapai kuorum
                    $rankHierarchy = [
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

                    $newRankLevel = $rankHierarchy[$newRank] ?? 99;
                    $quorumRankLevel = $rankHierarchy[$quorumRank] ?? 99;

                    if ($newRankLevel > $quorumRankLevel) {
                        // Identifikasi baru adalah rank yang lebih tinggi (lebih umum)
                        // Dikecualikan dari kuorum karena sudah ada yang lebih spesifik
                        return [
                            'exclude_from_quorum' => true,
                            'message' => "Identifikasi ini tidak akan mempengaruhi kuorum karena sudah ada takson yang lebih spesifik ({$quorumTaxon->scientific_name}) yang mencapai kuorum."
                        ];
                    }
                }
            }

            // Tidak ada hubungan hierarkis atau kondisi khusus
            return [
                'exclude_from_quorum' => false,
                'message' => null
            ];

        } catch (\Exception $e) {
            Log::error('Error in processHierarchicalIdentification', [
                'actualId' => $actualId,
                'newTaxonId' => $newTaxonId,
                'source' => $source,
                'error' => $e->getMessage()
            ]);

            // Jika terjadi error, default ke behavior normal (tidak dikecualikan)
            return [
                'exclude_from_quorum' => false,
                'message' => null
            ];
        }
    }

    /**
     * Cek apakah ini adalah challenge lintas lineage (order berbeda)
     * Untuk kasus seperti Accipitriformes vs Passeriformes
     */
    private function isCrossLineageChallenge($currentTaxon, $newTaxon)
    {
        // Cek apakah order berbeda
        $currentOrder = $currentTaxon->order ?? null;
        $newOrder = $newTaxon->order ?? null;
        
        // Jika salah satu tidak memiliki order, tidak dianggap cross lineage
        if (!$currentOrder || !$newOrder) {
            return false;
        }
        
        // Cross lineage jika order berbeda
        $isCrossLineage = $currentOrder !== $newOrder;
        
        Log::info('Cross lineage challenge check', [
            'currentOrder' => $currentOrder,
            'newOrder' => $newOrder,
            'isCrossLineage' => $isCrossLineage
        ]);
        
        return $isCrossLineage;
    }
    
    /**
     * PERBAIKAN: Tentukan apakah cross lineage challenge memerlukan modal konfirmasi
     * Modal hanya muncul untuk kasus yang benar-benar signifikan
     */
    private function shouldShowCrossLineageModal($currentTaxon, $newTaxon)
    {
        $currentRank = strtolower($currentTaxon->taxon_rank);
        $newRank = strtolower($newTaxon->taxon_rank);
        
        // Modal cross-lineage hanya muncul jika:
        // 1. Current taxon adalah species/subspecies (research grade)
        // 2. New taxon adalah genus atau lebih tinggi dari order yang berbeda
        // 3. Bukan kasus identifikasi yang jelas salah (misal: mamalia vs burung)
        
        if (!in_array($currentRank, ['species', 'subspecies'])) {
            return false; // Current bukan species, tidak perlu modal
        }
        
        if (!in_array($newRank, ['genus', 'family', 'order', 'class'])) {
            return false; // New taxon bukan level yang memerlukan konfirmasi
        }
        
        // PERBAIKAN: Cek apakah masih dalam class yang sama (misal: keduanya Aves)
        $currentClass = $currentTaxon->class ?? null;
        $newClass = $newTaxon->class ?? null;
        
        if ($currentClass && $newClass && $currentClass === $newClass) {
            // PERBAIKAN: Untuk cross-lineage dalam class yang sama (misal Aves), 
            // TIDAK perlu modal karena sistem sudah bisa handle dengan baik
            // Modal hanya untuk kasus yang benar-benar memerlukan user intervention
            Log::info('Cross lineage modal NOT needed - same class, system can handle automatically', [
                'currentClass' => $currentClass,
                'newClass' => $newClass,
                'currentOrder' => $currentTaxon->order ?? 'N/A',
                'newOrder' => $newTaxon->order ?? 'N/A',
                'reason' => 'Cross-lineage within same class should not require modal confirmation'
            ]);
            return false; // TIDAK perlu modal
        }
        
        // Jika class berbeda, kemungkinan kesalahan besar - tidak perlu modal
        if ($currentClass && $newClass && $currentClass !== $newClass) {
            Log::info('Cross lineage modal not needed - different class (likely error)', [
                'currentClass' => $currentClass,
                'newClass' => $newClass
            ]);
            return false;
        }
        
        // Default: tampilkan modal untuk kasus yang tidak jelas
        return true;
    }

    /**
     * Cek apakah species dan subspecies berada dalam lineage yang sama
     * PERBAIKAN: Untuk menghindari doubtful pada kasus species-subspecies
     */
    private function isSpeciesSubspeciesSameLineage($taxon1, $taxon2)
    {
        // Pastikan salah satu adalah species dan yang lain subspecies/variety/form
        $rank1 = strtolower($taxon1->taxon_rank ?? '');
        $rank2 = strtolower($taxon2->taxon_rank ?? '');
        
        $speciesRanks = ['species'];
        $subspeciesRanks = ['subspecies', 'variety', 'form'];
        
        $isSpeciesSubspeciesCombo = 
            (in_array($rank1, $speciesRanks) && in_array($rank2, $subspeciesRanks)) ||
            (in_array($rank2, $speciesRanks) && in_array($rank1, $subspeciesRanks));
            
        if (!$isSpeciesSubspeciesCombo) {
            return false;
        }
        
        // Cek apakah dalam genus dan species yang sama
        $genus1 = $taxon1->genus ?? '';
        $genus2 = $taxon2->genus ?? '';
        $species1 = $taxon1->species ?? '';
        $species2 = $taxon2->species ?? '';
        
        return $genus1 === $genus2 && $species1 === $species2 && 
               !empty($genus1) && !empty($species1);
    }

    /**
     * Get profile picture URL dengan support S3
     * 
     * @param string|null $path Path foto profil dari database
     * @return string|null URL lengkap foto profil
     */
    private function getProfilePictureUrl($path)
    {
        if (empty($path)) {
            return null;
        }

        // Jika sudah URL lengkap (S3 atau URL lainnya), gunakan langsung
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Bersihkan path dari prefix yang tidak perlu
        $cleanPath = preg_replace('/^\/?(storage\/)?/', '', $path);
        
        // Cek apakah S3 credentials tersedia
        $storageDisk = config('filesystems.media_storage_disk', 's3');
        $awsKey = config('filesystems.disks.s3.key');
        $awsBucket = config('filesystems.disks.s3.bucket');
        $s3Available = !empty($awsKey) && !empty($awsBucket);
        
        try {
            if ($storageDisk === 's3' && $s3Available) {
                // Cek apakah file ada di S3
                if (Storage::disk('s3')->exists($cleanPath)) {
                    return Storage::disk('s3')->url($cleanPath);
                }
            }
            
            // Fallback ke local storage URL
            return config('app.url') . '/storage/' . $cleanPath;
            
        } catch (\Exception $e) {
            Log::debug('Error getting profile picture URL:', [
                'path' => $cleanPath,
                'error' => $e->getMessage()
            ]);
            
            // Fallback ke local URL
            return config('app.url') . '/storage/' . $cleanPath;
        }
    }
}

