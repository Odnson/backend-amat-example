<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class MarkerController extends Controller
{
    public function getMarkers(Request $request)
    {
        try {
            $checklistsAka = DB::connection('second')->table('checklists');
            $checklistsKupnes = DB::connection('third')->table('checklists');

            // Handle spatial search
            if ($request->has('shape')) {
                $shape = json_decode($request->shape, true);
                
                if ($shape['type'] === 'Polygon') {
                    $coordinates = $shape['coordinates'][0];
                    $polygonWKT = 'POLYGON((' . implode(',', array_map(function($point) {
                        return $point[0] . ' ' . $point[1];
                    }, $coordinates)) . '))';
                    
                    $checklistsAka->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(longitude, latitude))', [$polygonWKT]);
                    $checklistsKupnes->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(longitude, latitude))', [$polygonWKT]);
                } 
                else if ($shape['type'] === 'Circle') {
                    $center = $shape['center'];
                    $radius = $shape['radius']; // in meters
                    
                    $haversine = "(6371000 * acos(cos(radians(?)) 
                        * cos(radians(latitude)) 
                        * cos(radians(longitude) - radians(?)) 
                        + sin(radians(?)) 
                        * sin(radians(latitude))))";
                    
                    $checklistsAka->whereRaw("{$haversine} <= ?", [$center[1], $center[0], $center[1], $radius]);
                    $checklistsKupnes->whereRaw("{$haversine} <= ?", [$center[1], $center[0], $center[1], $radius]);
                }
            }

            // Filter berdasarkan tanggal dengan date_type support
            $hasActiveFilter = false;
            $dateColumn = $request->input('date_type', 'created_at') === 'observation_date' ? 'tgl_pengamatan' : 'created_at';
            if ($request->has('start_date') && $request->start_date) {
                $hasActiveFilter = true;
                $checklistsAka->where("checklists.{$dateColumn}", '>=', $request->start_date);
                $checklistsKupnes->where("checklists.{$dateColumn}", '>=', $request->start_date);
            }
            if ($request->has('end_date') && $request->end_date) {
                $hasActiveFilter = true;
                $checklistsAka->where("checklists.{$dateColumn}", '<=', $request->end_date);
                $checklistsKupnes->where("checklists.{$dateColumn}", '<=', $request->end_date);
            }

            // Grade filter tidak berlaku untuk burungnesia/kupunesia
            // karena tabel checklists tidak punya kolom grade (grade hanya untuk fobi/amaturalist)
            // Abaikan saja — biarkan client-side filtering yang handle

            // Filter berdasarkan media - pakai checklist_fauna_imgs
            $hasMediaType = $request->has('media_type') && $request->media_type;
            // has_media filter hanya jika media_type tidak di-set (media_type sudah imply has_media)
            if (!$hasMediaType && $request->has('has_media') && $request->has_media) {
                $hasActiveFilter = true;
                $checklistsAka->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('checklist_fauna_imgs')
                      ->whereColumn('checklist_fauna_imgs.checklist_id', 'checklists.id')
                      ->whereNotNull('checklist_fauna_imgs.images');
                });
                $checklistsKupnes->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('checklist_fauna_imgs')
                      ->whereColumn('checklist_fauna_imgs.checklist_id', 'checklists.id')
                      ->whereNotNull('checklist_fauna_imgs.images');
                });
            }
            // media_type filter: burungnesia/kupunesia hanya punya foto di checklist_fauna_imgs
            if ($hasMediaType) {
                $hasActiveFilter = true;
                if ($request->media_type === 'photo') {
                    $checklistsAka->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                          ->from('checklist_fauna_imgs')
                          ->whereColumn('checklist_fauna_imgs.checklist_id', 'checklists.id')
                          ->whereNotNull('checklist_fauna_imgs.images');
                    });
                    $checklistsKupnes->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                          ->from('checklist_fauna_imgs')
                          ->whereColumn('checklist_fauna_imgs.checklist_id', 'checklists.id')
                          ->whereNotNull('checklist_fauna_imgs.images');
                    });
                } else if ($request->media_type === 'audio') {
                    // Burungnesia/kupunesia tidak punya audio, return empty
                    $checklistsAka->whereRaw('1 = 0');
                    $checklistsKupnes->whereRaw('1 = 0');
                }
            }

            // Filter user_id
            if ($request->has('user_id') && $request->user_id) {
                $hasActiveFilter = true;
                $fobiUser = DB::table('fobi_users')->where('id', $request->user_id)->first();
                if ($fobiUser && $fobiUser->burungnesia_user_id) {
                    $checklistsAka->where('checklists.user_id', $fobiUser->burungnesia_user_id);
                } else {
                    $checklistsAka->whereRaw('1 = 0');
                }
                if ($fobiUser && $fobiUser->kupunesia_user_id) {
                    $checklistsKupnes->where('checklists.user_id', $fobiUser->kupunesia_user_id);
                } else {
                    $checklistsKupnes->whereRaw('1 = 0');
                }
            }

            // Filter taxonomy
            if ($request->has('taxonomy_value') && $request->taxonomy_value) {
                $hasActiveFilter = true;
                $taxonomyRank = $request->input('taxonomy_rank', '');
                $taxonomyValue = $request->taxonomy_value;

                $checklistsAka->whereExists(function($q) use ($taxonomyRank, $taxonomyValue) {
                    $q->select(DB::raw(1))
                      ->from('checklist_fauna')
                      ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                      ->whereColumn('checklist_fauna.checklist_id', 'checklists.id');
                    if ($taxonomyRank === 'family') {
                        $q->where('faunas.family', $taxonomyValue);
                    } else {
                        $q->where('faunas.nameLat', 'LIKE', "%{$taxonomyValue}%");
                    }
                });

                $checklistsKupnes->whereExists(function($q) use ($taxonomyRank, $taxonomyValue) {
                    $q->select(DB::raw(1))
                      ->from('checklist_fauna')
                      ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                      ->whereColumn('checklist_fauna.checklist_id', 'checklists.id');
                    if ($taxonomyRank === 'family') {
                        $q->where('faunas.family', $taxonomyValue);
                    } else {
                        $q->where('faunas.nameLat', 'LIKE', "%{$taxonomyValue}%");
                    }
                });
            }

            // Filter location_name - cari berdasarkan label di tabel checklisttr
            // CATATAN: Di database second/third, kolom label ada di tabel checklisttr (bukan checklists)
            if ($request->has('location_name') && $request->location_name) {
                $hasActiveFilter = true;
                $locationName = $request->location_name;
                
                // Burungnesia - filter berdasarkan checklisttr.label
                $checklistsAka->whereExists(function($q) use ($locationName) {
                    $q->select(DB::raw(1))
                      ->from('checklisttr')
                      ->whereColumn('checklisttr.checklist_id', 'checklists.id')
                      ->where('checklisttr.label', 'LIKE', "%{$locationName}%");
                });
                
                // Kupunesia - filter berdasarkan checklisttr.label
                $checklistsKupnes->whereExists(function($q) use ($locationName) {
                    $q->select(DB::raw(1))
                      ->from('checklisttr')
                      ->whereColumn('checklisttr.checklist_id', 'checklists.id')
                      ->where('checklisttr.label', 'LIKE', "%{$locationName}%");
                });
            }

            $checklistsAka = $checklistsAka->select(
                'checklists.latitude',
                'checklists.longitude',
                DB::raw("CONCAT('brn_', checklists.id) as id"),
                'checklists.created_at',
                DB::raw("'burungnesia' as source")
            )
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('checklist_fauna')
                    ->whereColumn('checklist_fauna.checklist_id', 'checklists.id')
                    ->limit(1);
            })
            ->get();

            $checklistsKupnes = $checklistsKupnes->select(
                'checklists.latitude',
                'checklists.longitude',
                DB::raw("CONCAT('kpn_', checklists.id) as id"),
                'checklists.created_at',
                DB::raw("'kupunesia' as source")
            )
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('checklist_fauna')
                    ->whereColumn('checklist_fauna.checklist_id', 'checklists.id')
                    ->limit(1);
            })
            ->get();

            // Bypass cache saat ada filter aktif
            if ($hasActiveFilter) {
                $markers = $checklistsAka->merge($checklistsKupnes);
            } else {
                $markers = cache()->remember('markers', 3600, function () use ($checklistsAka, $checklistsKupnes) {
                    return $checklistsAka->merge($checklistsKupnes);
                });
            }

            return response()->json($markers);
        } catch (\Exception $e) {
            \Log::error('Error in MarkerController:', ['error' => $e->getMessage()]);
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

            // Dapatkan data taxa
            $taxaData = DB::table('taxas')
                ->where('id', $taxaId)
                ->select('id', 'burnes_fauna_id', 'kupnes_fauna_id', 'scientific_name')
                ->first();

            if (!$taxaData) {
                return response()->json([]);
            }

            // Extract genus dan species tanpa author name
            $scientificParts = explode(' ', $taxaData->scientific_name);
            $genus = $scientificParts[0] ?? null;
            // Jika ada author name dalam kurung, hilangkan
            $speciesWithoutAuthor = preg_replace('/\s+\([^)]+\)/', '', $taxaData->scientific_name);

            \Log::info('Taxa Data:', [
                'id' => $taxaData->id,
                'scientific_name' => $taxaData->scientific_name,
                'scientific_without_author' => $speciesWithoutAuthor,
                'genus' => $genus,
                'burnes_fauna_id' => $taxaData->burnes_fauna_id,
                'kupnes_fauna_id' => $taxaData->kupnes_fauna_id
            ]);

            $allMarkers = collect([]);

            // 1. Query untuk Kupunesia (database third)
            try {
                $kupunesiaMarkers = DB::connection('third')
                    ->table('checklist_fauna')
                    ->distinct()
                    ->join('checklists', 'checklist_fauna.checklist_id', '=', 'checklists.id')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                    ->where(function($query) use ($genus, $speciesWithoutAuthor) {
                        $query->where('faunas.nameLat', 'LIKE', $speciesWithoutAuthor . '%')
                              ->orWhere('faunas.nameLat', 'LIKE', $genus . '%');
                    })
                    ->whereNotNull('checklists.latitude')
                    ->whereNotNull('checklists.longitude')
                    ->whereNull('checklists.deleted_at')
                    ->select(
                        'checklists.latitude',
                        'checklists.longitude',
                        'checklists.id',
                        'checklists.created_at',
                        'faunas.nameLat as matched_name'
                    )
                    ->get()
                    ->unique('id')
                    ->map(function($item) {
                        return [
                            'latitude' => $item->latitude,
                            'longitude' => $item->longitude,
                            'id' => 'kpn_' . $item->id,
                            'created_at' => $item->created_at,
                            'source' => 'kupunesia',
                            'matched_name' => $item->matched_name
                        ];
                    });

                $allMarkers = $allMarkers->concat($kupunesiaMarkers);
                \Log::info('Kupunesia markers found:', [
                    'count' => $kupunesiaMarkers->count(),
                    'matched_names' => $kupunesiaMarkers->pluck('matched_name')->unique()
                ]);
            } catch (\Exception $e) {
                \Log::error('Kupunesia query error:', ['error' => $e->getMessage()]);
            }

            // 2. Query untuk Burungnesia (database second)
            try {
                $burungnesiaMarkers = DB::connection('second')
                    ->table('checklist_fauna')
                    ->distinct()
                    ->join('checklists', 'checklist_fauna.checklist_id', '=', 'checklists.id')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                    ->where(function($query) use ($genus, $speciesWithoutAuthor) {
                        $query->where('faunas.nameLat', 'LIKE', $speciesWithoutAuthor . '%')
                              ->orWhere('faunas.nameLat', 'LIKE', $genus . '%');
                    })
                    ->whereNotNull('checklists.latitude')
                    ->whereNotNull('checklists.longitude')
                    ->whereNull('checklists.deleted_at')
                    ->select(
                        'checklists.latitude',
                        'checklists.longitude',
                        'checklists.id',
                        'checklists.created_at',
                        'faunas.nameLat as matched_name'
                    )
                    ->get()
                    ->unique('id')
                    ->map(function($item) {
                        return [
                            'latitude' => $item->latitude,
                            'longitude' => $item->longitude,
                            'id' => 'brn_' . $item->id,
                            'created_at' => $item->created_at,
                            'source' => 'burungnesia',
                            'matched_name' => $item->matched_name
                        ];
                    });

                $allMarkers = $allMarkers->concat($burungnesiaMarkers);
                \Log::info('Burungnesia markers found:', [
                    'count' => $burungnesiaMarkers->count(),
                    'matched_names' => $burungnesiaMarkers->pluck('matched_name')->unique()
                ]);
            } catch (\Exception $e) {
                \Log::error('Burungnesia query error:', ['error' => $e->getMessage()]);
            }

            // 3. Query untuk FOBI Taxa
            try {
                $taxaMarkers = DB::table('fobi_checklist_taxas')
                    ->distinct()
                    ->where(function($query) use ($taxaData, $genus, $speciesWithoutAuthor) {
                        $query->where('taxa_id', $taxaData->id)
                            ->orWhere('scientific_name', 'LIKE', $speciesWithoutAuthor . '%')
                            ->orWhere('genus', $genus);
                    })
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->whereNull('deleted_at')
                    ->select(
                        'latitude',
                        'longitude',
                        'id',
                        'created_at',
                        'scientific_name as matched_name'
                    )
                    ->get()
                    ->unique('id')
                    ->map(function($item) {
                        // Tambahkan timestamp ke ID untuk memastikan keunikan
                        return [
                            'latitude' => $item->latitude,
                            'longitude' => $item->longitude,
                            'id' => 'fobi_t_' . $item->id . '_' . strtotime($item->created_at),
                            'created_at' => $item->created_at,
                            'source' => 'taxa_fobi',
                            'matched_name' => $item->matched_name
                        ];
                    });

                $allMarkers = $allMarkers->concat($taxaMarkers);
                \Log::info('Taxa markers found:', [
                    'count' => $taxaMarkers->count(),
                    'matched_names' => $taxaMarkers->pluck('matched_name')->unique()
                ]);
            } catch (\Exception $e) {
                \Log::error('Taxa query error:', ['error' => $e->getMessage()]);
            }

            \Log::info('Total markers found:', ['count' => $allMarkers->count()]);

            // Sebelum return, pastikan semua marker unik berdasarkan kombinasi latitude, longitude, source dan id
            $allMarkers = $allMarkers->unique(function ($item) {
                return $item['latitude'] . '_' . $item['longitude'] . '_' . $item['source'] . '_' . $item['id'];
            })->values();

            \Log::info('Final markers after unique:', [
                'count' => $allMarkers->count(),
                'markers' => $allMarkers->map(function($item) {
                    return [
                        'id' => $item['id'],
                        'source' => $item['source'],
                        'matched_name' => $item['matched_name'] ?? null
                    ];
                })
            ]);

            return response()->json($allMarkers);

        } catch (\Exception $e) {
            \Log::error('Error in getMarkersByTaxa:', [
                'error' => $e->getMessage(),
                'taxa_id' => $request->taxa_id
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
