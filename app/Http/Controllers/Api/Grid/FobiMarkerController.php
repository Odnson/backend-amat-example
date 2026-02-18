<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class FobiMarkerController extends Controller
{
    public function getMarkers(Request $request)
    {
        try {
            $query = DB::table('fobi_checklists');
            $queryKupunes = DB::table('fobi_checklists_kupnes');
            $queryTaxa = DB::table('fobi_checklist_taxas');

            // Handle spatial search
            if ($request->has('shape')) {
                $shape = json_decode($request->shape, true);
                
                if ($shape['type'] === 'Polygon') {
                    $coordinates = $shape['coordinates'][0];
                    $polygonWKT = 'POLYGON((' . implode(',', array_map(function($point) {
                        return $point[0] . ' ' . $point[1];
                    }, $coordinates)) . '))';
                    
                    $query->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(longitude, latitude))', [$polygonWKT]);
                    $queryKupunes->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(longitude, latitude))', [$polygonWKT]);
                    $queryTaxa->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(longitude, latitude))', [$polygonWKT]);
                } 
                else if ($shape['type'] === 'Circle') {
                    $center = $shape['center'];
                    $radius = $shape['radius']; // in meters
                    
                    $haversine = "(6371000 * acos(cos(radians(?)) 
                        * cos(radians(latitude)) 
                        * cos(radians(longitude) - radians(?)) 
                        + sin(radians(?)) 
                        * sin(radians(latitude))))";
                    
                    $query->whereRaw("{$haversine} <= ?", [$center[1], $center[0], $center[1], $radius]);
                    $queryKupunes->whereRaw("{$haversine} <= ?", [$center[1], $center[0], $center[1], $radius]);
                    $queryTaxa->whereRaw("{$haversine} <= ?", [$center[1], $center[0], $center[1], $radius]);
                }
            }

            // Filter berdasarkan sumber data
            $dataSources = $request->input('data_source', ['fobi', 'burungnesia', 'kupunesia']);
            if (is_string($dataSources)) {
                $dataSources = explode(',', $dataSources);
            }
            // Jika fobi tidak diminta, skip taxa query
            $includeFobi = in_array('fobi', $dataSources);
            $includeBurungnesia = in_array('burungnesia', $dataSources);
            $includeKupunesia = in_array('kupunesia', $dataSources);
            
            if (!$includeFobi && !$includeBurungnesia && !$includeKupunesia) {
                return response()->json([]);
            }

            // Filter berdasarkan tanggal dengan date_type support
            $dateColumn = $request->input('date_type', 'created_at') === 'observation_date' ? 'tgl_pengamatan' : 'created_at';
            // fobi_checklist_taxas tidak punya tgl_pengamatan, selalu pakai created_at
            $taxaDateColumn = 'created_at';
            if ($request->has('start_date') && $request->start_date) {
                $query->where($dateColumn, '>=', $request->start_date);
                $queryKupunes->where($dateColumn, '>=', $request->start_date);
                $queryTaxa->where($taxaDateColumn, '>=', $request->start_date);
            }

            if ($request->has('end_date') && $request->end_date) {
                $query->where($dateColumn, '<=', $request->end_date);
                $queryKupunes->where($dateColumn, '<=', $request->end_date);
                $queryTaxa->where($taxaDateColumn, '<=', $request->end_date);
            }

            // Filter berdasarkan grade
            // fobi_checklists → data_quality_assessments (observation_id = fobi_checklists.id)
            // fobi_checklists_kupnes → data_quality_assessments_kupnes (observation_id = fobi_checklists_kupnes.id)
            // fobi_checklist_taxas → taxa_quality_assessments (taxa_id = fobi_checklist_taxas.id)
            if ($request->has('grade') && !empty($request->grade)) {
                $query->whereExists(function ($q) use ($request) {
                    $q->select(DB::raw(1))
                      ->from('data_quality_assessments')
                      ->whereColumn('data_quality_assessments.observation_id', 'fobi_checklists.id')
                      ->whereIn('data_quality_assessments.grade', $request->grade);
                });
                $queryKupunes->whereExists(function ($q) use ($request) {
                    $q->select(DB::raw(1))
                      ->from('data_quality_assessments_kupnes')
                      ->whereColumn('data_quality_assessments_kupnes.observation_id', 'fobi_checklists_kupnes.id')
                      ->whereIn('data_quality_assessments_kupnes.grade', $request->grade);
                });
                $queryTaxa->whereExists(function ($q) use ($request) {
                    $q->select(DB::raw(1))
                      ->from('taxa_quality_assessments')
                      ->whereColumn('taxa_quality_assessments.taxa_id', 'fobi_checklist_taxas.id')
                      ->whereIn('taxa_quality_assessments.grade', $request->grade);
                });
            }

            // Filter berdasarkan media
            // Semua media (foto+audio) ada di fobi_checklist_media (checklist_id → fobi_checklist_taxas.id)
            // Foto legacy juga di fobi_checklist_fauna_imgs (checklist_id → fobi_checklists.id)
            // Foto kupnes di fobi_checklist_fauna_imgs_kupnes (checklist_id → fobi_checklists_kupnes.id)
            $hasMediaType = $request->has('media_type') && $request->media_type;
            // has_media filter hanya jika media_type tidak di-set (media_type sudah imply has_media)
            if (!$hasMediaType && $request->has('has_media') && $request->has_media) {
                // fobi_checklists: cek fobi_checklist_fauna_imgs (foto)
                $query->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('fobi_checklist_fauna_imgs')
                      ->whereColumn('fobi_checklist_fauna_imgs.checklist_id', 'fobi_checklists.id')
                      ->whereNotNull('fobi_checklist_fauna_imgs.images');
                });
                // fobi_checklists_kupnes: cek fobi_checklist_fauna_imgs_kupnes (foto)
                $queryKupunes->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('fobi_checklist_fauna_imgs_kupnes')
                      ->whereColumn('fobi_checklist_fauna_imgs_kupnes.checklist_id', 'fobi_checklists_kupnes.id')
                      ->whereNotNull('fobi_checklist_fauna_imgs_kupnes.images');
                });
                // fobi_checklist_taxas: cek fobi_checklist_media (foto+audio)
                $queryTaxa->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('fobi_checklist_media')
                      ->whereColumn('fobi_checklist_media.checklist_id', 'fobi_checklist_taxas.id')
                      ->whereNotNull('fobi_checklist_media.file_path');
                });
            }

            if ($hasMediaType) {
                if ($request->media_type === 'photo') {
                    // fobi_checklists: foto di fobi_checklist_fauna_imgs
                    $query->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                          ->from('fobi_checklist_fauna_imgs')
                          ->whereColumn('fobi_checklist_fauna_imgs.checklist_id', 'fobi_checklists.id')
                          ->whereNotNull('fobi_checklist_fauna_imgs.images');
                    });
                    // fobi_checklists_kupnes: foto di fobi_checklist_fauna_imgs_kupnes
                    $queryKupunes->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                          ->from('fobi_checklist_fauna_imgs_kupnes')
                          ->whereColumn('fobi_checklist_fauna_imgs_kupnes.checklist_id', 'fobi_checklists_kupnes.id')
                          ->whereNotNull('fobi_checklist_fauna_imgs_kupnes.images');
                    });
                    // fobi_checklist_taxas: foto di fobi_checklist_media
                    $queryTaxa->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                          ->from('fobi_checklist_media')
                          ->whereColumn('fobi_checklist_media.checklist_id', 'fobi_checklist_taxas.id')
                          ->where('fobi_checklist_media.media_type', 'photo');
                    });
                } else if ($request->media_type === 'audio') {
                    // fobi_checklists: tidak punya audio (audio hanya di fobi_checklist_media → fobi_checklist_taxas)
                    $query->whereRaw('1 = 0');
                    // fobi_checklists_kupnes: tidak punya audio
                    $queryKupunes->whereRaw('1 = 0');
                    // fobi_checklist_taxas: audio di fobi_checklist_media
                    $queryTaxa->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                          ->from('fobi_checklist_media')
                          ->whereColumn('fobi_checklist_media.checklist_id', 'fobi_checklist_taxas.id')
                          ->where('fobi_checklist_media.media_type', 'audio');
                    });
                }
            }

            // Filter berdasarkan lokasi dan radius
            if ($request->has(['latitude', 'longitude', 'radius'])) {
                $lat = $request->latitude;
                $lon = $request->longitude;
                $radius = $request->radius;

                $haversine = "(6371 * acos(cos(radians($lat))
                    * cos(radians(latitude))
                    * cos(radians(longitude) - radians($lon))
                    + sin(radians($lat))
                    * sin(radians(latitude))))";

                $query->whereRaw("{$haversine} <= ?", [$radius]);
                $queryKupunes->whereRaw("{$haversine} <= ?", [$radius]);
                $queryTaxa->whereRaw("{$haversine} <= ?", [$radius]);
            }

            // Filter user_id
            if ($request->has('user_id') && $request->user_id) {
                $query->where('fobi_checklists.fobi_user_id', $request->user_id);
                $queryKupunes->where('fobi_checklists_kupnes.fobi_user_id', $request->user_id);
                $queryTaxa->where('fobi_checklist_taxas.user_id', $request->user_id);
            }

            // Filter taxonomy
            if ($request->has('taxonomy_value') && $request->taxonomy_value) {
                $taxonomyRank = $request->input('taxonomy_rank', '');
                $taxonomyValue = $request->taxonomy_value;

                // Filter fobi_checklists (burungnesia) - join faunas
                $query->whereExists(function($q) use ($taxonomyRank, $taxonomyValue) {
                    $q->select(DB::raw(1))
                      ->from('fobi_checklist_faunasv1')
                      ->join('faunas', 'fobi_checklist_faunasv1.fauna_id', '=', 'faunas.id')
                      ->whereColumn('fobi_checklist_faunasv1.checklist_id', 'fobi_checklists.id');
                    if ($taxonomyRank === 'family') {
                        $q->where('faunas.family', $taxonomyValue);
                    } else {
                        $q->where('faunas.nameLat', 'LIKE', "%{$taxonomyValue}%");
                    }
                });

                // Filter fobi_checklists_kupnes (kupunesia) - join faunas_kupnes
                $queryKupunes->whereExists(function($q) use ($taxonomyRank, $taxonomyValue) {
                    $q->select(DB::raw(1))
                      ->from('fobi_checklist_faunasv2')
                      ->join('faunas_kupnes', 'fobi_checklist_faunasv2.fauna_id', '=', 'faunas_kupnes.id')
                      ->whereColumn('fobi_checklist_faunasv2.checklist_id', 'fobi_checklists_kupnes.id');
                    if ($taxonomyRank === 'family') {
                        $q->where('faunas_kupnes.family', $taxonomyValue);
                    } else {
                        $q->where('faunas_kupnes.nameLat', 'LIKE', "%{$taxonomyValue}%");
                    }
                });

                // Filter fobi_checklist_taxas - join taxas
                $taxaIds = DB::table('taxas')
                    ->where(function($q) use ($taxonomyRank, $taxonomyValue) {
                        if ($taxonomyRank && in_array($taxonomyRank, ['kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'])) {
                            $q->where("taxas.{$taxonomyRank}", $taxonomyValue);
                        } else {
                            $q->where('taxas.scientific_name', 'LIKE', "%{$taxonomyValue}%");
                        }
                    })
                    ->pluck('id');
                $queryTaxa->whereIn('fobi_checklist_taxas.taxa_id', $taxaIds);
            }

            // Filter location_name - hanya untuk fobi_checklist_taxas (data FOBi asli)
            // fobi_checklists dan fobi_checklists_kupnes tidak perlu filter di sini
            // karena data lokasi diambil dari database second (Burungnesia) dan third (Kupunesia)
            // melalui endpoint /markers yang sudah handle filter location_name via checklists.label
            if ($request->has('location_name') && $request->location_name) {
                $locationName = $request->location_name;
                
                // Filter fobi_checklist_taxas - cari di fobi_checklist_media.location
                $queryTaxa->whereExists(function($q) use ($locationName) {
                    $q->select(DB::raw(1))
                      ->from('fobi_checklist_media')
                      ->whereColumn('fobi_checklist_media.checklist_id', 'fobi_checklist_taxas.id')
                      ->where('fobi_checklist_media.location', 'LIKE', "%{$locationName}%");
                });
                
                // Untuk fobi_checklists dan fobi_checklists_kupnes, 
                // skip filter karena tidak ada kolom location_name
                // Data lokasi dari Burungnesia/Kupunesia dihandle oleh /markers endpoint
            }

            // Ambil data hanya dari sumber yang diminta
            $markers = collect();

            if ($includeBurungnesia) {
                $checklistsBurungnesia = $query->select(
                    'latitude',
                    'longitude',
                    DB::raw("CONCAT('fobi_b_', id) as id"),
                    'created_at',
                    DB::raw("'burungnesia_fobi' as source")
                )->get();
                $markers = $markers->concat($checklistsBurungnesia);
            }

            if ($includeKupunesia) {
                $checklistsKupunesia = $queryKupunes->select(
                    'latitude',
                    'longitude',
                    DB::raw("CONCAT('fobi_k_', id) as id"),
                    'created_at',
                    DB::raw("'kupunesia_fobi' as source")
                )->get();
                $markers = $markers->concat($checklistsKupunesia);
            }

            if ($includeFobi) {
                $checklistsTaxa = $queryTaxa->select(
                    'latitude',
                    'longitude',
                    DB::raw("CONCAT('fobi_t_', id) as id"),
                    'created_at',
                    DB::raw("'taxa_fobi' as source")
                )->get();
                $markers = $markers->concat($checklistsTaxa);
            }

            return response()->json($markers);
        } catch (\Exception $e) {
            \Log::error('Error in FobiMarkerController:', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getSpeciesInChecklist($checklist_id, $source)
    {
        try {
            $id = str_replace(['fobi_b_', 'fobi_k_', 'fobi_t_'], '', $checklist_id);
            
            if (strpos($checklist_id, 'fobi_b_') === 0) {
                // Query untuk FOBI Burungnesia
                $checklistData = DB::table('fobi_checklists as fc')
                    ->leftJoin('fobi_users as fu', 'fc.fobi_user_id', '=', 'fu.id')
                    ->where('fc.id', $id)
                    ->select([
                        'fc.id',
                        'fc.latitude',
                        'fc.longitude',
                        DB::raw('COALESCE(fc.tgl_pengamatan, fc.created_at) as observation_date'),
                        DB::raw("COALESCE(CONCAT(TRIM(fu.fname), ' ', TRIM(fu.lname)), fu.uname, 'Tidak diketahui') as observer_name"),
                        'fu.uname',
                        'fc.additional_note as observation_details',
                        'fu.id as observer_id',
                        'fc.created_at'
                    ])
                    ->first();

                // Query species untuk FOBI Burungnesia
                $species = DB::table('fobi_checklist_faunasv1 as fcf')
                    ->join('faunas as f', 'fcf.fauna_id', '=', 'f.id')
                    ->where('fcf.checklist_id', $id)
                    ->select([
                        'f.id',
                        'f.nameLat',
                        'f.nameId',
                        'fcf.count',
                        'fcf.notes'
                    ])
                    ->get();

                // Ambil nama observer
                $observerName = trim($checklistData->observer_name ?? '') ?: ($checklistData->uname ?? 'Anonim');

                // Fetch media FOBI Burungnesia
                $media = DB::table('fobi_checklist_fauna_imgs')
                    ->select('id', 'images as url', 'fauna_id', 'created_at')
                    ->where('checklist_id', $id)
                    ->whereNull('deleted_at')
                    ->get()
                    ->map(function($img) use ($observerName) {
                        $img->photographer = $observerName;
                        $img->license = null;
                        return $img;
                    });

                $sounds = DB::table('fobi_checklist_sounds')
                    ->select('id', 'sounds as url', 'spectrogram', 'fauna_id', 'created_at')
                    ->where('checklist_id', $id)
                    ->whereNull('deleted_at')
                    ->get()
                    ->map(function($s) use ($observerName) {
                        $s->photographer = $observerName;
                        $s->license = null;
                        return $s;
                    });

                // Ambil grade dari data_quality_assessments
                $gradeData = DB::table('data_quality_assessments')
                    ->where('observation_id', $id)
                    ->select('grade')
                    ->first();
                $grade = $gradeData->grade ?? 'Needs ID';

            } elseif (strpos($checklist_id, 'fobi_k_') === 0) {
                // Query untuk FOBI Kupunesia
                $checklistData = DB::table('fobi_checklists_kupnes as fck')
                    ->leftJoin('fobi_users as fu', 'fck.fobi_user_id', '=', 'fu.id')
                    ->where('fck.id', $id)
                    ->select([
                        'fck.id',
                        'fck.latitude',
                        'fck.longitude',
                        DB::raw('COALESCE(fck.tgl_pengamatan, fck.created_at) as observation_date'),
                        DB::raw("CONCAT(COALESCE(fu.fname, ''), ' ', COALESCE(fu.lname, '')) as observer_name"),
                        'fu.uname',
                        'fck.additional_note as observation_details',
                        'fu.id as observer_id'
                    ])
                    ->first();

                // Query species untuk FOBI Kupunesia
                $species = DB::table('fobi_checklist_faunasv2 as fcf')
                    ->join('faunas_kupnes as f', 'fcf.fauna_id', '=', 'f.id')
                    ->where('fcf.checklist_id', $id)
                    ->select([
                        'f.id',
                        'f.nameLat',
                        'f.nameId',
                        'fcf.count',
                        'fcf.notes'
                    ])
                    ->get();

                // Ambil nama observer
                $observerName = trim($checklistData->observer_name ?? '') ?: ($checklistData->uname ?? 'Anonim');

                // Fetch media FOBI Kupunesia
                $media = DB::table('fobi_checklist_fauna_imgs_kupnes')
                    ->select('id', 'images as url', 'fauna_id', 'created_at')
                    ->where('checklist_id', $id)
                    ->whereNull('deleted_at')
                    ->get()
                    ->map(function($img) use ($observerName) {
                        $img->photographer = $observerName;
                        $img->license = null;
                        return $img;
                    });

                // Ambil grade dari data_quality_assessments_kupnes
                $gradeData = DB::table('data_quality_assessments_kupnes')
                    ->where('observation_id', $id)
                    ->select('grade')
                    ->first();
                $grade = $gradeData->grade ?? 'Needs ID';

                // Kupunesia tidak punya sounds
                $sounds = collect();

            } else {
                // Query untuk FOBI Taxa (kode yang sudah ada)
                $checklistData = DB::table('fobi_checklist_taxas as fct')
                    ->leftJoin('fobi_users as fu', 'fct.user_id', '=', 'fu.id')
                    ->where('fct.id', $id)
                    ->select([
                        'fct.id',
                        'fct.latitude',
                        'fct.longitude',
                        DB::raw('COALESCE(fct.date, fct.created_at) as observation_date'),
                        DB::raw("CONCAT(COALESCE(fu.fname, ''), ' ', COALESCE(fu.lname, '')) as observer_name"),
                        'fu.uname',
                        'fct.observation_details',
                        'fct.scientific_name',
                        'fct.species',
                        'fu.id as observer_id'
                    ])
                    ->first();

                // Query species untuk FOBI Taxa
                $species = DB::table('fobi_checklist_taxas as fct')
                    ->leftJoin('taxas as t', 'fct.taxa_id', '=', 't.id')
                    ->where('fct.id', $id)
                    ->select([
                        'fct.id',
                        DB::raw('COALESCE(t.scientific_name, fct.scientific_name) as nameLat'),
                        DB::raw('COALESCE(t.cname_species, fct.species) as nameId'),
                        DB::raw('1 as count'),
                        'fct.observation_details as notes'
                    ])
                    ->get();

                // Ambil nama observer
                $observerName = trim($checklistData->observer_name ?? '') ?: ($checklistData->uname ?? 'Anonim');

                // Fetch media FOBI Taxa - proses URL via MediaStorageHelper
                $rawMedia = DB::table('fobi_checklist_media')
                    ->select('id', 'file_path', 'media_type', 'spectrogram', 'storage_type', 'license', 'created_at')
                    ->where('checklist_id', $id)
                    ->get();

                $media = collect();
                $sounds = collect();

                foreach ($rawMedia as $m) {
                    $url = \App\Helpers\MediaStorageHelper::getMediaUrl(
                        $m->file_path,
                        $m->storage_type ?? 'local',
                        $m->id
                    );

                    if ($m->media_type === 'audio') {
                        $spectrogramUrl = $m->spectrogram 
                            ? \App\Helpers\MediaStorageHelper::getMediaUrl(
                                $m->spectrogram,
                                $m->storage_type ?? 'local',
                                $m->id
                            ) : null;

                        $sounds->push((object)[
                            'id' => $m->id,
                            'url' => $url,
                            'spectrogram' => $spectrogramUrl,
                            'created_at' => $m->created_at,
                            'photographer' => $observerName,
                            'license' => $m->license,
                        ]);
                    } else {
                        $media->push((object)[
                            'id' => $m->id,
                            'url' => $url,
                            'created_at' => $m->created_at,
                            'photographer' => $observerName,
                            'license' => $m->license,
                        ]);
                    }
                }

                // Ambil grade dari taxa_quality_assessments
                $gradeData = DB::table('taxa_quality_assessments')
                    ->where('taxa_id', $id)
                    ->select('grade')
                    ->first();
                $grade = $gradeData->grade ?? 'Needs ID';
            }

            // Format tanggal dan observer untuk semua sumber
            if ($checklistData) {
                // Format observer name
                $checklistData->observer_name = trim($checklistData->observer_name) ?: 'Tidak diketahui';
                
                // Format tanggal
                if ($checklistData->observation_date) {
                    try {
                        $date = new \DateTime($checklistData->observation_date);
                        $checklistData->observation_date = $date->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        $checklistData->observation_date = date('Y-m-d H:i:s', strtotime($checklistData->created_at));
                    }
                } else {
                    $checklistData->observation_date = date('Y-m-d H:i:s', strtotime($checklistData->created_at));
                }
            }

            // Fallback jika tidak ada species
            if ($species->isEmpty() && $checklistData) {
                $species = collect([
                    [
                        'id' => $checklistData->id,
                        'nameLat' => $checklistData->scientific_name ?? 'Species tidak diketahui',
                        'nameId' => $checklistData->species ?? 'Nama umum tidak tersedia',
                        'count' => 1,
                        'notes' => $checklistData->observation_details
                    ]
                ]);
            }

            return response()->json([
                'checklist' => $checklistData,
                'species' => $species,
                'media' => isset($media) ? $media : [],
                'sounds' => isset($sounds) ? $sounds : [],
                'grade' => isset($grade) ? $grade : 'Needs ID',
            ]);

        } catch (\Exception $e) {
            \Log::error('Error in getSpeciesInChecklist:', [
                'error' => $e->getMessage(),
                'checklist_id' => $checklist_id,
                'source' => $source,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getMarkersByTaxa(Request $request)
    {
        try {
            $taxaId = $request->taxa_id;
            if (!$taxaId) {
                return response()->json([]);
            }

            // Get taxa data first
            $taxaData = DB::table('taxas')
                ->where('id', $taxaId)
                ->select('id', 'burnes_fauna_id', 'kupnes_fauna_id')
                ->first();

            if (!$taxaData) {
                return response()->json([]);
            }

            // Query untuk fobi_checklist_faunasv1 (burungnesia)
            $burungnesiaMarkers = DB::table('fobi_checklists')
                ->join('fobi_checklist_faunasv1', 'fobi_checklists.id', '=', 'fobi_checklist_faunasv1.checklist_id')
                ->where('fobi_checklist_faunasv1.fauna_id', $taxaData->burnes_fauna_id)
                ->whereNotNull('fobi_checklists.latitude')
                ->whereNotNull('fobi_checklists.longitude')
                ->select(
                    'fobi_checklists.latitude',
                    'fobi_checklists.longitude',
                    DB::raw("CONCAT('fobi_b_', fobi_checklists.id) as id"),
                    'fobi_checklists.created_at',
                    DB::raw("'burungnesia_fobi' as source")
                );

            // Query untuk fobi_checklist_faunasv2 (kupunesia)
            $kupunesiaMarkers = DB::table('fobi_checklists_kupnes')
                ->join('fobi_checklist_faunasv2', 'fobi_checklists_kupnes.id', '=', 'fobi_checklist_faunasv2.checklist_id')
                ->where('fobi_checklist_faunasv2.fauna_id', $taxaData->kupnes_fauna_id)
                ->whereNotNull('fobi_checklists_kupnes.latitude')
                ->whereNotNull('fobi_checklists_kupnes.longitude')
                ->select(
                    'fobi_checklists_kupnes.latitude',
                    'fobi_checklists_kupnes.longitude',
                    DB::raw("CONCAT('fobi_k_', fobi_checklists_kupnes.id) as id"),
                    'fobi_checklists_kupnes.created_at',
                    DB::raw("'kupunesia_fobi' as source")
                );

            // Query untuk taxa
            $taxaMarkers = DB::table('fobi_checklist_taxas')
                ->where('taxa_id', $taxaId)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->select(
                    'latitude',
                    'longitude',
                    DB::raw("CONCAT('fobi_t_', id) as id"),
                    'created_at',
                    DB::raw("'taxa_fobi' as source")
                );

            // Gabungkan semua hasil
            $markers = $burungnesiaMarkers
                ->union($kupunesiaMarkers)
                ->union($taxaMarkers)
                ->get();

            return response()->json($markers);
        } catch (\Exception $e) {
            \Log::error('Error in getMarkersByTaxa:', [
                'error' => $e->getMessage(),
                'taxa_id' => $request->taxa_id
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // Perbaikan method getFobiStats untuk menghitung semua sumber FOBI
    public function getFobiStats()
    {
        try {
            // Hitung observasi dari semua sumber FOBI
            $fobiTaxaCount = DB::table('fobi_checklist_taxas')
                ->whereNull('deleted_at')
                ->count();
            
            $fobiBurungnesiaCount = DB::table('fobi_checklists')
                ->whereNull('deleted_at')
                ->count();
            
            $fobiKupunesiaCount = DB::table('fobi_checklists_kupnes')
                ->whereNull('deleted_at')
                ->count();

            // Hitung kontributor unik dari semua sumber
            $kontributorCount = DB::table('fobi_users as fu')
                ->leftJoin('fobi_checklist_taxas as fct', 'fu.id', '=', 'fct.user_id')
                ->leftJoin('fobi_checklists as fc', 'fu.id', '=', 'fc.user_id')
                ->leftJoin('fobi_checklists_kupnes as fck', 'fu.id', '=', 'fck.user_id')
                ->whereNull('fct.deleted_at')
                ->whereNull('fc.deleted_at')
                ->whereNull('fck.deleted_at')
                ->whereNotNull(DB::raw('COALESCE(fct.id, fc.id, fck.id)'))
                ->distinct('fu.id')
                ->count('fu.id');

            // Hitung spesies unik
            $speciesCount = DB::table(DB::raw('(
                SELECT scientific_name FROM fobi_checklist_taxas WHERE deleted_at IS NULL
                UNION
                SELECT f.nameLat FROM fobi_checklist_faunasv1 fcf1
                JOIN faunas f ON fcf1.fauna_id = f.id
                JOIN fobi_checklists fc ON fcf1.checklist_id = fc.id
                WHERE fc.deleted_at IS NULL
                UNION
                SELECT f.nameLat FROM fobi_checklist_faunasv2 fcf2
                JOIN faunas_kupnes f ON fcf2.fauna_id = f.id
                JOIN fobi_checklists_kupnes fck ON fcf2.checklist_id = fck.id
                WHERE fck.deleted_at IS NULL
            ) as unique_species'))
                ->count();

            $stats = [
                'observasi' => $fobiTaxaCount + $fobiBurungnesiaCount + $fobiKupunesiaCount,
                'kontributor' => $kontributorCount,
                'spesies' => $speciesCount
            ];

            return response()->json($stats);
        } catch (\Exception $e) {
            \Log::error('Error getting FOBI stats:', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
