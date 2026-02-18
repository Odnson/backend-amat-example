<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\OrderFauna;
use App\Models\Fauna;
use App\Models\Taxontest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class HomeController extends Controller
{
    public function getOrderFaunas()
    {
        $orderFaunas = OrderFauna::orderBy('ordo_order')->orderBy('famili_order')->get()->keyBy('famili');
        return response()->json($orderFaunas);
    }

    public function getChecklists()
    {
        $checklistsAka = DB::connection('second')->table('checklists')
            ->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
            ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
            ->select('checklists.latitude', 'checklists.longitude', 'checklists.id', 'checklists.created_at', DB::raw("'burungnesia' as source"))
            ->groupBy('checklists.latitude', 'checklists.longitude', 'checklists.id', 'checklists.created_at')
            ->get();

        $checklistsKupnes = DB::connection('third')->table('checklists')
            ->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
            ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
            ->select('checklists.latitude', 'checklists.longitude', 'checklists.id', 'checklists.created_at', DB::raw("'kupunesia' as source"))
            ->groupBy('checklists.latitude', 'checklists.longitude', 'checklists.id', 'checklists.created_at')
            ->get();

        $checklists = $checklistsAka->merge($checklistsKupnes);
        return response()->json($checklists);
    }

    public function getFamilies()
    {
        $families = Fauna::select('family')->distinct()->get();
        $orderFaunas = OrderFauna::orderBy('ordo_order')->orderBy('famili_order')->get()->keyBy('famili');

        $families = $families->map(function ($family) use ($orderFaunas) {
            $family->ordo = $orderFaunas->get($family->family)->ordo ?? null;
            return $family;
        });

        return response()->json($families);
    }

    public function getOrdos()
    {
        $ordos = OrderFauna::select('ordo')->distinct()->get();
        return response()->json($ordos);
    }

    public function getFaunas()
    {
        $faunas = Fauna::all();
        return response()->json($faunas);
    }

    public function getTaxontest()
    {
        $taxontest = Taxontest::all();
        return response()->json($taxontest);
    }

    private function applyCommonFilters($query, Request $request)
    {
        // Filter berdasarkan lokasi dan radius
        if ($request->has(['latitude', 'longitude'])) {
            $lat = floatval($request->latitude);
            $lon = floatval($request->longitude);
            $radius = floatval($request->radius ?? 10); // Default 10km

            // Validasi koordinat
            $lat = max(-90, min(90, $lat));
            $lon = (($lon + 180) % 360) - 180; // Normalisasi longitude ke range -180 sampai 180

            // Gunakan Haversine formula sebagai alternatif ST_Distance_Sphere
            $haversine = "(6371 * acos(cos(radians($lat)) * 
                          cos(radians(latitude)) * 
                          cos(radians(longitude) - radians($lon)) + 
                          sin(radians($lat)) * sin(radians(latitude))))";

            $query->whereRaw("{$haversine} <= ?", [$radius]);
        }

        // Filter berdasarkan tanggal
        if ($request->has('start_date')) {
            $query->where('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('created_at', '<=', $request->end_date);
        }

        // Filter berdasarkan grade
        if ($request->has('grade') && !empty($request->grade)) {
            $grades = is_array($request->grade) ? $request->grade : explode(',', $request->grade);
            $query->whereIn('grade', $grades);
        }

        // Filter berdasarkan media
        if ($request->has('has_media') && $request->has_media) {
            $query->whereNotNull('media_url');
        }
        if ($request->has('media_type')) {
            $query->where('media_type', $request->media_type);
        }

        return $query;
    }

    public function getBurungnesiaCount(Request $request)
    {
        try {
            $query = DB::connection('second')->table('checklists');
            
            // Validasi koordinat sebelum menerapkan filter
            if ($request->has(['latitude', 'longitude'])) {
                $lat = floatval($request->latitude);
                $lon = floatval($request->longitude);
                
                if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                    \Log::warning('Invalid coordinates in getBurungnesiaCount:', ['lat' => $lat, 'lon' => $lon]);
                }
            }
            
            $query = $this->applyCommonFilters($query, $request);
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
                      ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                      ->where(function($q) use ($search) {
                          $q->where('faunas.nameLat', 'like', "%{$search}%")
                            ->orWhere('faunas.nameId', 'like', "%{$search}%");
                      });
            }
            
            $burungnesiaCount = $query->count();
            return response()->json(['burungnesiaCount' => $burungnesiaCount]);
        } catch (\Exception $e) {
            \Log::error('Error in getBurungnesiaCount: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getKupunesiaCount(Request $request)
    {
        try {
            $query = DB::connection('third')->table('checklists');
            
            // Validasi koordinat sebelum menerapkan filter
            if ($request->has(['latitude', 'longitude'])) {
                $lat = floatval($request->latitude);
                $lon = floatval($request->longitude);
                
                if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                    \Log::warning('Invalid coordinates in getKupunesiaCount:', ['lat' => $lat, 'lon' => $lon]);
                }
            }
            
            $query = $this->applyCommonFilters($query, $request);
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
                      ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                      ->where(function($q) use ($search) {
                          $q->where('faunas.nameLat', 'like', "%{$search}%")
                            ->orWhere('faunas.nameId', 'like', "%{$search}%");
                      });
            }
            
            $kupunesiaCount = $query->count();
            return response()->json(['kupunesiaCount' => $kupunesiaCount]);
        } catch (\Exception $e) {
            \Log::error('Error in getKupunesiaCount: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getFobiCount(Request $request)
    {
        try {
            $query = DB::table('fobi_checklist_taxas')
                ->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id');

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('taxas.accepted_scientific_name', 'like', "%{$search}%")
                      ->orWhere('taxas.scientific_name', 'like', "%{$search}%")
                      ->orWhere('taxas.cname_species', 'like', "%{$search}%");
                });
            }

            $fobiCount = $query->distinct('fobi_checklist_taxas.id')->count('fobi_checklist_taxas.id');
            return response()->json(['fobiCount' => $fobiCount]);
        } catch (\Exception $e) {
            \Log::error('Error in getFobiCount: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getUserBurungnesiaCount($userId)
    {
        $userBurungnesiaCount = 0;
        $fobiUser = DB::table('fobi_users')->where('id', $userId)->first();
        if ($fobiUser) {
            $secondCount = DB::connection('second')->table('checklists')
                ->where('user_id', $fobiUser->burungnesia_user_id)
                ->count();

            $fobiCount = DB::table('fobi_checklists')
                ->where('fobi_user_id', $userId)
                ->count();

            $userBurungnesiaCount = $secondCount + $fobiCount;
        }
        return response()->json(['userBurungnesiaCount' => $userBurungnesiaCount]);
    }

    public function getUserKupunesiaCount($userId)
    {
        $userKupunesiaCount = 0;
        $fobiUser = DB::table('fobi_users')->where('id', $userId)->first();
        if ($fobiUser) {
            $thirdCount = DB::connection('third')->table('checklists')
                ->where('user_id', $fobiUser->kupunesia_user_id)
                ->count();

            $fobiKupnesCount = DB::table('fobi_checklists_kupnes')
                ->where('fobi_user_id', $userId)
                ->count();

            $userKupunesiaCount = $thirdCount + $fobiKupnesCount;
        }
        return response()->json(['userKupunesiaCount' => $userKupunesiaCount]);
    }

    public function getUserTotalObservations($userId)
    {
        $cacheKey = "user_total_observations_{$userId}";
        $cacheDuration = 30; // Cache selama 30 detik karena tidak ada polling

        return Cache::remember($cacheKey, $cacheDuration, function() use ($userId) {
            $userBurungnesiaCount = 0;
            $userKupunesiaCount = 0;
            $fobiCount = 0;

            $fobiUser = DB::table('fobi_users')->where('id', $userId)->first();

            if ($fobiUser) {
                $secondCount = DB::connection('second')
                    ->table('checklists')
                    ->where('user_id', $fobiUser->burungnesia_user_id)
                    ->count();

                $fobiBirdCount = DB::table('fobi_checklists')
                    ->where('fobi_user_id', $userId)
                    ->count();

                $userBurungnesiaCount = $secondCount + $fobiBirdCount;

                $thirdCount = DB::connection('third')
                    ->table('checklists')
                    ->where('user_id', $fobiUser->kupunesia_user_id)
                    ->count();

                $fobiKupnesCount = DB::table('fobi_checklists_kupnes')
                    ->where('fobi_user_id', $userId)
                    ->count();

                $userKupunesiaCount = $thirdCount + $fobiKupnesCount;
            }

            $fobiCount = DB::table('fobi_checklist_taxas')
                ->where('user_id', $userId)
                ->count();

            $total = $userBurungnesiaCount + $userKupunesiaCount + $fobiCount;

            return response()->json([
                'userTotalObservations' => $total,
                'timestamp' => now()->timestamp
            ]);
        });
    }

    public function getTotalSpecies(Request $request)
    {
        try {
            $query = DB::table('taxas')->where('taxon_rank', 'species');

            // Filter by species_id if provided
            if ($request->has('species_id')) {
                $query->where('id', $request->species_id);
            }

            // Filter by search if provided
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('accepted_scientific_name', 'like', "%{$search}%")
                      ->orWhere('scientific_name', 'like', "%{$search}%")
                      ->orWhere('cname_species', 'like', "%{$search}%");
                });
            }

            $totalSpecies = $query->count();
            return response()->json(['totalSpecies' => $totalSpecies]);
        } catch (\Exception $e) {
            \Log::error('Error in getTotalSpecies: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getTotalContributors(Request $request)
    {
        try {
            // Base query untuk fobi users
            $fobiUsersQuery = DB::table('fobi_users');
            
            // Query untuk users di database utama
            $mainUsersQuery = DB::table('users')
                ->whereNull('users.deleted_at');
                
            // Query untuk users di database second (Burungnesia)
            $burungnesiaUsersQuery = DB::connection('second')
                ->table('users')
                ->whereNull('users.deleted_at');
                
            // Query untuk users di database third (Kupunesia)
            $kupunesiaUsersQuery = DB::connection('third')
                ->table('users')
                ->whereNull('users.deleted_at');
            
            // Jika ada parameter search, terapkan filter
            if ($request->has('search')) {
                $search = $request->search;
                
                // Filter FOBI users
                $fobiUsersQuery->join('fobi_checklist_taxas', 'fobi_users.id', '=', 'fobi_checklist_taxas.user_id')
                    ->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id')
                    ->where(function($q) use ($search) {
                        $q->where('taxas.accepted_scientific_name', 'like', "%{$search}%")
                          ->orWhere('taxas.scientific_name', 'like', "%{$search}%")
                          ->orWhere('taxas.cname_species', 'like', "%{$search}%");
                    })
                    ->distinct('fobi_users.id');
                    
                // Filter main users
                $mainUsersQuery->join('checklists', 'users.id', '=', 'checklists.user_id')
                    ->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                    ->where(function($q) use ($search) {
                        $q->where('faunas.nameLat', 'like', "%{$search}%")
                          ->orWhere('faunas.nameId', 'like', "%{$search}%");
                    })
                    ->distinct('users.id');
                    
                // Filter Burungnesia users
                $burungnesiaUsersQuery->join('checklists', 'users.id', '=', 'checklists.user_id')
                    ->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                    ->where(function($q) use ($search) {
                        $q->where('faunas.nameLat', 'like', "%{$search}%")
                          ->orWhere('faunas.nameId', 'like', "%{$search}%");
                    })
                    ->distinct('users.id');
                    
                // Filter Kupunesia users
                $kupunesiaUsersQuery->join('checklists', 'users.id', '=', 'checklists.user_id')
                    ->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                    ->where(function($q) use ($search) {
                        $q->where('faunas.nameLat', 'like', "%{$search}%")
                          ->orWhere('faunas.nameId', 'like', "%{$search}%");
                    })
                    ->distinct('users.id');
            }
            
            // Jika ada parameter shape, terapkan filter geografis
            if ($request->has('shape')) {
                $shape = $request->shape;
                
                // Filter FOBI users berdasarkan lokasi
                $fobiUsersQuery->join('fobi_checklists', 'fobi_users.id', '=', 'fobi_checklists.user_id')
                    ->whereNotNull('fobi_checklists.latitude')
                    ->whereNotNull('fobi_checklists.longitude');
                    
                if ($shape['type'] === 'Polygon') {
                    $coordinates = $shape['coordinates'][0];
                    $polygonWKT = 'POLYGON((' . implode(',', array_map(function($point) {
                        return $point[0] . ' ' . $point[1];
                    }, $coordinates)) . '))';
                    
                    $fobiUsersQuery->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(fobi_checklists.longitude, fobi_checklists.latitude))', [$polygonWKT]);
                } 
                else if ($shape['type'] === 'Circle') {
                    $center = $shape['center'];
                    $radius = $shape['radius'];
                    
                    $fobiUsersQuery->whereRaw("
                        ST_Distance_Sphere(
                            point(fobi_checklists.longitude, fobi_checklists.latitude),
                            point(?, ?)
                        ) <= ?
                    ", [$center[0], $center[1], $radius]);
                }
                
                // Filter main users berdasarkan lokasi
                $mainUsersQuery->join('checklists', 'users.id', '=', 'checklists.user_id')
                    ->whereNotNull('checklists.latitude')
                    ->whereNotNull('checklists.longitude');
                    
                if ($shape['type'] === 'Polygon') {
                    $mainUsersQuery->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(checklists.longitude, checklists.latitude))', [$polygonWKT]);
                } 
                else if ($shape['type'] === 'Circle') {
                    $mainUsersQuery->whereRaw("
                        ST_Distance_Sphere(
                            point(checklists.longitude, checklists.latitude),
                            point(?, ?)
                        ) <= ?
                    ", [$center[0], $center[1], $radius]);
                }
                
                // Filter Burungnesia users berdasarkan lokasi
                $burungnesiaUsersQuery->join('checklists', 'users.id', '=', 'checklists.user_id')
                    ->whereNotNull('checklists.latitude')
                    ->whereNotNull('checklists.longitude');
                    
                if ($shape['type'] === 'Polygon') {
                    $burungnesiaUsersQuery->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(checklists.longitude, checklists.latitude))', [$polygonWKT]);
                } 
                else if ($shape['type'] === 'Circle') {
                    $burungnesiaUsersQuery->whereRaw("
                        ST_Distance_Sphere(
                            point(checklists.longitude, checklists.latitude),
                            point(?, ?)
                        ) <= ?
                    ", [$center[0], $center[1], $radius]);
                }
                
                // Filter Kupunesia users berdasarkan lokasi
                $kupunesiaUsersQuery->join('checklists', 'users.id', '=', 'checklists.user_id')
                    ->whereNotNull('checklists.latitude')
                    ->whereNotNull('checklists.longitude');
                    
                if ($shape['type'] === 'Polygon') {
                    $kupunesiaUsersQuery->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(checklists.longitude, checklists.latitude))', [$polygonWKT]);
                } 
                else if ($shape['type'] === 'Circle') {
                    $kupunesiaUsersQuery->whereRaw("
                        ST_Distance_Sphere(
                            point(checklists.longitude, checklists.latitude),
                            point(?, ?)
                        ) <= ?
                    ", [$center[0], $center[1], $radius]);
                }
            }
            
            // Ambil ID dari semua query
            $fobiUserIds = $fobiUsersQuery->pluck('fobi_users.id')->toArray();
            $mainUserIds = $mainUsersQuery->pluck('users.id')->toArray();
            $burungnesiaUserIds = $burungnesiaUsersQuery->pluck('users.id')->toArray();
            $kupunesiaUserIds = $kupunesiaUsersQuery->pluck('users.id')->toArray();
            
            // Ambil mapping ID untuk menghindari duplikasi
            $burungnesiaMapping = DB::table('fobi_users')
                ->whereNotNull('burungnesia_user_id')
                ->pluck('burungnesia_user_id')
                ->toArray();
                
            $kupunesiaMapping = DB::table('fobi_users')
                ->whereNotNull('kupunesia_user_id')
                ->pluck('kupunesia_user_id')
                ->toArray();
            
            // Filter ID yang sudah terhitung di FOBI
            $filteredBurungnesiaIds = array_diff($burungnesiaUserIds, $burungnesiaMapping);
            $filteredKupunesiaIds = array_diff($kupunesiaUserIds, $kupunesiaMapping);
            
            // Hitung total kontributor unik
            $totalContributors = count(array_unique(array_merge(
                $fobiUserIds,
                $mainUserIds,
                $filteredBurungnesiaIds,
                $filteredKupunesiaIds
            )));
            
            return response()->json(['totalContributors' => $totalContributors]);
        } catch (\Exception $e) {
            \Log::error('Error in getTotalContributors: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getGridContributors(Request $request) 
    {
        try {
            $checklistIds = $request->input('checklistIds', []);
            
            if (empty($checklistIds)) {
                return response()->json([
                    'status' => 'success',
                    'totalContributors' => 0
                ]);
            }

            \Log::info('Received checklist IDs:', $checklistIds);

            // Filter dan pisahkan ID berdasarkan prefix
            $burungnesiaIds = [];
            $kupunesiaIds = [];
            $fobiTaxaIds = [];
            $fobiChecklistIds = [];
            $fobiKupnesIds = [];
            
            foreach ($checklistIds as $id) {
                if (strpos($id, 'brn_') === 0) {
                    $burungnesiaIds[] = (int)str_replace('brn_', '', $id);
                } elseif (strpos($id, 'kpn_') === 0) {
                    $kupunesiaIds[] = (int)str_replace('kpn_', '', $id);
                } elseif (strpos($id, 'fob_fobi_t_') === 0) {
                    // Extract ID for fobi_checklist_taxas
                    $fobiTaxaIds[] = (int)str_replace('fob_fobi_t_', '', $id);
                } elseif (strpos($id, 'fob_fobi_c_') === 0) {
                    // Extract ID for fobi_checklists
                    $fobiChecklistIds[] = (int)str_replace('fob_fobi_c_', '', $id);
                } elseif (strpos($id, 'fob_fobi_k_') === 0) {
                    // Extract ID for fobi_checklists_kupnes
                    $fobiKupnesIds[] = (int)str_replace('fob_fobi_k_', '', $id);
                }
            }

            \Log::info('Filtered IDs:', [
                'burungnesia' => $burungnesiaIds,
                'kupunesia' => $kupunesiaIds,
                'fobi_taxa' => $fobiTaxaIds,
                'fobi_checklist' => $fobiChecklistIds,
                'fobi_kupnes' => $fobiKupnesIds
            ]);

            $allContributors = collect();

            // Get Burungnesia contributors
            if (!empty($burungnesiaIds)) {
                $secondContributors = DB::connection('second')
                    ->table('checklists')
                    ->whereIn('id', $burungnesiaIds)
                    ->distinct()
                    ->pluck('user_id');
                $allContributors = $allContributors->merge($secondContributors);
                \Log::info('Burungnesia contributors:', $secondContributors->toArray());
            }

            // Get Kupunesia contributors
            if (!empty($kupunesiaIds)) {
                $thirdContributors = DB::connection('third')
                    ->table('checklists')
                    ->whereIn('id', $kupunesiaIds)
                    ->distinct()
                    ->pluck('user_id');
                $allContributors = $allContributors->merge($thirdContributors);
                \Log::info('Kupunesia contributors:', $thirdContributors->toArray());
            }

            // Get FOBI taxa contributors
            if (!empty($fobiTaxaIds)) {
                $fobiTaxaContributors = DB::table('fobi_checklist_taxas')
                    ->whereIn('id', $fobiTaxaIds)
                    ->distinct()
                    ->pluck('user_id');
                $allContributors = $allContributors->merge($fobiTaxaContributors);
                \Log::info('FOBI taxa contributors:', $fobiTaxaContributors->toArray());
            }

            // Get FOBI checklist contributors
            if (!empty($fobiChecklistIds)) {
                $fobiChecklistContributors = DB::table('fobi_checklists')
                    ->whereIn('id', $fobiChecklistIds)
                    ->distinct()
                    ->pluck('fobi_user_id');
                $allContributors = $allContributors->merge($fobiChecklistContributors);
                \Log::info('FOBI checklist contributors:', $fobiChecklistContributors->toArray());
            }

            // Get FOBI kupnes contributors
            if (!empty($fobiKupnesIds)) {
                $fobiKupnesContributors = DB::table('fobi_checklists_kupnes')
                    ->whereIn('id', $fobiKupnesIds)
                    ->distinct()
                    ->pluck('fobi_user_id');
                $allContributors = $allContributors->merge($fobiKupnesContributors);
                \Log::info('FOBI kupnes contributors:', $fobiKupnesContributors->toArray());
            }

            $uniqueContributors = $allContributors->unique()->values();
            \Log::info('Total unique contributors:', ['count' => $uniqueContributors->count()]);

            return response()->json([
                'status' => 'success',
                'totalContributors' => $uniqueContributors->count()
            ]);

        } catch (\Exception $e) {
            \Log::error('Error in getGridContributors: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getFilteredStats(Request $request)
    {
        try {
            $searchTerm = $request->input('search', '');
            $dataSources = $request->input('data_source', ['fobi', 'burungnesia', 'kupunesia']);
            if (is_string($dataSources)) {
                $dataSources = explode(',', $dataSources);
            }
            $taxonomyRank = $request->input('taxonomy_rank', '');
            $taxonomyValue = $request->input('taxonomy_value', '');
            $locationName = $request->input('location_name', '');

            // === OBSERVASI COUNT ===
            $obsCount = 0;
            $fobiObsCount = 0;
            $burungnesiaObsCount = 0;
            $kupunesiaObsCount = 0;

            // FOBI observations
            if (in_array('fobi', $dataSources)) {
                $fobiQuery = DB::table('fobi_checklist_taxas')
                    ->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id');

                if ($searchTerm) {
                    $fobiQuery->where(function($q) use ($searchTerm) {
                        $q->where('taxas.accepted_scientific_name', 'like', "%{$searchTerm}%")
                          ->orWhere('taxas.scientific_name', 'like', "%{$searchTerm}%")
                          ->orWhere('taxas.cname_species', 'like', "%{$searchTerm}%");
                    });
                }

                if ($taxonomyValue) {
                    if ($taxonomyRank && in_array($taxonomyRank, ['kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'])) {
                        $fobiQuery->where("taxas.{$taxonomyRank}", $taxonomyValue);
                    } else {
                        $fobiQuery->where('taxas.scientific_name', 'like', "%{$taxonomyValue}%");
                    }
                }

                if ($locationName) {
                    $fobiQuery->whereExists(function($q) use ($locationName) {
                        $q->select(DB::raw(1))
                          ->from('fobi_checklist_media')
                          ->whereColumn('fobi_checklist_media.checklist_id', 'fobi_checklist_taxas.id')
                          ->where('fobi_checklist_media.location', 'LIKE', "%{$locationName}%");
                    });
                }

                $this->applyPolygonFilter($fobiQuery, $request, 'fobi_checklist_taxas');

                $fobiObsCount = $fobiQuery->distinct('fobi_checklist_taxas.id')->count('fobi_checklist_taxas.id');
            }

            // Burungnesia observations
            if (in_array('burungnesia', $dataSources)) {
                $brnQuery = DB::connection('second')->table('checklists')
                    ->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id');

                if ($searchTerm) {
                    $brnQuery->where(function($q) use ($searchTerm) {
                        $q->where('faunas.nameLat', 'like', "%{$searchTerm}%")
                          ->orWhere('faunas.nameId', 'like', "%{$searchTerm}%");
                    });
                }

                if ($taxonomyValue) {
                    if ($taxonomyRank === 'family') {
                        $brnQuery->where('faunas.family', $taxonomyValue);
                    } elseif ($taxonomyRank === 'class' && $taxonomyValue !== 'Aves') {
                        $brnQuery->whereRaw('1 = 0');
                    } else {
                        $brnQuery->where('faunas.nameLat', 'like', "%{$taxonomyValue}%");
                    }
                }

                if ($locationName) {
                    $brnQuery->whereExists(function($q) use ($locationName) {
                        $q->select(DB::raw(1))
                          ->from('checklisttr')
                          ->whereColumn('checklisttr.checklist_id', 'checklists.id')
                          ->where('checklisttr.label', 'LIKE', "%{$locationName}%");
                    });
                }

                $this->applyPolygonFilter($brnQuery, $request, 'checklists');

                $burungnesiaObsCount = $brnQuery->distinct('checklists.id')->count('checklists.id');
            }

            // Kupunesia observations
            if (in_array('kupunesia', $dataSources)) {
                $kupQuery = DB::connection('third')->table('checklists')
                    ->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id');

                if ($searchTerm) {
                    $kupQuery->where(function($q) use ($searchTerm) {
                        $q->where('faunas.nameLat', 'like', "%{$searchTerm}%")
                          ->orWhere('faunas.nameId', 'like', "%{$searchTerm}%");
                    });
                }

                if ($taxonomyValue) {
                    if ($taxonomyRank === 'family') {
                        $kupQuery->where('faunas.family', $taxonomyValue);
                    } elseif ($taxonomyRank === 'order' && $taxonomyValue !== 'Lepidoptera') {
                        $kupQuery->whereRaw('1 = 0');
                    } else {
                        $kupQuery->where('faunas.nameLat', 'like', "%{$taxonomyValue}%");
                    }
                }

                if ($locationName) {
                    $kupQuery->whereExists(function($q) use ($locationName) {
                        $q->select(DB::raw(1))
                          ->from('checklisttr')
                          ->whereColumn('checklisttr.checklist_id', 'checklists.id')
                          ->where('checklisttr.label', 'LIKE', "%{$locationName}%");
                    });
                }

                $this->applyPolygonFilter($kupQuery, $request, 'checklists');

                $kupunesiaObsCount = $kupQuery->distinct('checklists.id')->count('checklists.id');
            }

            $obsCount = $fobiObsCount + $burungnesiaObsCount + $kupunesiaObsCount;

            // === TAKSA COUNT ===
            // Taksa dihitung dari tabel taxas:
            // - FOBI/Amaturalist: distinct taxa_id dari fobi_checklist_taxas
            // - Burungnesia: distinct fauna_id dari checklist_fauna DB second, map ke taxas via burnes_fauna_id
            // - Kupunesia: distinct fauna_id dari checklist_fauna DB third, map ke taxas via kupnes_fauna_id
            $taxaIds = collect();

            // Taksa dari FOBI (dari tabel taxas via fobi_checklist_taxas)
            if (in_array('fobi', $dataSources)) {
                $fobiTaxaQuery = DB::table('fobi_checklist_taxas')
                    ->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id');

                if ($searchTerm) {
                    $fobiTaxaQuery->where(function($q) use ($searchTerm) {
                        $q->where('taxas.accepted_scientific_name', 'like', "%{$searchTerm}%")
                          ->orWhere('taxas.scientific_name', 'like', "%{$searchTerm}%")
                          ->orWhere('taxas.cname_species', 'like', "%{$searchTerm}%");
                    });
                }

                if ($taxonomyValue) {
                    if ($taxonomyRank && in_array($taxonomyRank, ['kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'])) {
                        $fobiTaxaQuery->where("taxas.{$taxonomyRank}", $taxonomyValue);
                    } else {
                        $fobiTaxaQuery->where('taxas.scientific_name', 'like', "%{$taxonomyValue}%");
                    }
                }

                if ($locationName) {
                    $fobiTaxaQuery->whereExists(function($q) use ($locationName) {
                        $q->select(DB::raw(1))
                          ->from('fobi_checklist_media')
                          ->whereColumn('fobi_checklist_media.checklist_id', 'fobi_checklist_taxas.id')
                          ->where('fobi_checklist_media.location', 'LIKE', "%{$locationName}%");
                    });
                }

                $this->applyPolygonFilter($fobiTaxaQuery, $request, 'fobi_checklist_taxas');

                $fobiTaxaIds = $fobiTaxaQuery->distinct()->pluck('fobi_checklist_taxas.taxa_id');
                $taxaIds = $taxaIds->merge($fobiTaxaIds);
            }

            // Taksa dari Burungnesia: distinct fauna_id dari checklist_fauna, lalu map ke taxas via burnes_fauna_id
            if (in_array('burungnesia', $dataSources)) {
                $brnFaunaQuery = DB::connection('second')->table('checklist_fauna')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id');

                if ($searchTerm) {
                    $brnFaunaQuery->where(function($q) use ($searchTerm) {
                        $q->where('faunas.nameLat', 'like', "%{$searchTerm}%")
                          ->orWhere('faunas.nameId', 'like', "%{$searchTerm}%");
                    });
                }

                if ($taxonomyValue) {
                    if ($taxonomyRank === 'family') {
                        $brnFaunaQuery->where('faunas.family', $taxonomyValue);
                    } elseif ($taxonomyRank === 'class' && $taxonomyValue !== 'Aves') {
                        $brnFaunaQuery->whereRaw('1 = 0');
                    } else {
                        $brnFaunaQuery->where('faunas.nameLat', 'like', "%{$taxonomyValue}%");
                    }
                }

                $brnFaunaIds = $brnFaunaQuery->distinct()->pluck('checklist_fauna.fauna_id');

                if ($brnFaunaIds->isNotEmpty()) {
                    $brnTaxaIds = DB::table('taxas')
                        ->whereIn('burnes_fauna_id', $brnFaunaIds)
                        ->pluck('id');
                    $taxaIds = $taxaIds->merge($brnTaxaIds);
                }
            }

            // Taksa dari Kupunesia: distinct fauna_id dari checklist_fauna, lalu map ke taxas via kupnes_fauna_id
            if (in_array('kupunesia', $dataSources)) {
                $kupFaunaQuery = DB::connection('third')->table('checklist_fauna')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id');

                if ($searchTerm) {
                    $kupFaunaQuery->where(function($q) use ($searchTerm) {
                        $q->where('faunas.nameLat', 'like', "%{$searchTerm}%")
                          ->orWhere('faunas.nameId', 'like', "%{$searchTerm}%");
                    });
                }

                if ($taxonomyValue) {
                    if ($taxonomyRank === 'family') {
                        $kupFaunaQuery->where('faunas.family', $taxonomyValue);
                    } elseif ($taxonomyRank === 'order' && $taxonomyValue !== 'Lepidoptera') {
                        $kupFaunaQuery->whereRaw('1 = 0');
                    } else {
                        $kupFaunaQuery->where('faunas.nameLat', 'like', "%{$taxonomyValue}%");
                    }
                }

                $kupFaunaIds = $kupFaunaQuery->distinct()->pluck('checklist_fauna.fauna_id');

                if ($kupFaunaIds->isNotEmpty()) {
                    $kupTaxaIds = DB::table('taxas')
                        ->whereIn('kupnes_fauna_id', $kupFaunaIds)
                        ->pluck('id');
                    $taxaIds = $taxaIds->merge($kupTaxaIds);
                }
            }

            $taxaCount = $taxaIds->unique()->count();

            // === MEDIA COUNT ===
            $mediaCount = 0;

            // Media FOBI (fobi_checklist_media)
            if (in_array('fobi', $dataSources)) {
                $fobiMediaQuery = DB::table('fobi_checklist_media')
                    ->join('fobi_checklist_taxas', 'fobi_checklist_media.checklist_id', '=', 'fobi_checklist_taxas.id')
                    ->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id');

                if ($searchTerm) {
                    $fobiMediaQuery->where(function($q) use ($searchTerm) {
                        $q->where('taxas.accepted_scientific_name', 'like', "%{$searchTerm}%")
                          ->orWhere('taxas.scientific_name', 'like', "%{$searchTerm}%")
                          ->orWhere('taxas.cname_species', 'like', "%{$searchTerm}%");
                    });
                }

                if ($taxonomyValue) {
                    if ($taxonomyRank && in_array($taxonomyRank, ['kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'])) {
                        $fobiMediaQuery->where("taxas.{$taxonomyRank}", $taxonomyValue);
                    } else {
                        $fobiMediaQuery->where('taxas.scientific_name', 'like', "%{$taxonomyValue}%");
                    }
                }

                if ($locationName) {
                    $fobiMediaQuery->where('fobi_checklist_media.location', 'LIKE', "%{$locationName}%");
                }

                $this->applyPolygonFilter($fobiMediaQuery, $request, 'fobi_checklist_taxas');

                $mediaCount += $fobiMediaQuery->count();
            }

            // Media Burungnesia (fobi_checklist_fauna_imgs + fobi_checklist_sounds)
            if (in_array('burungnesia', $dataSources)) {
                $brnImgQuery = DB::table('fobi_checklist_fauna_imgs')
                    ->join('fobi_checklists', 'fobi_checklist_fauna_imgs.checklist_id', '=', 'fobi_checklists.id');

                if ($searchTerm || $taxonomyValue) {
                    $brnImgQuery->whereExists(function($q) use ($searchTerm, $taxonomyRank, $taxonomyValue) {
                        $q->select(DB::raw(1))
                          ->from('fobi_checklist_faunasv2')
                          ->join('faunas', 'fobi_checklist_faunasv2.fauna_id', '=', 'faunas.id')
                          ->whereColumn('fobi_checklist_faunasv2.checklist_id', 'fobi_checklists.id');
                        if ($searchTerm) {
                            $q->where(function($sq) use ($searchTerm) {
                                $sq->where('faunas.nameLat', 'like', "%{$searchTerm}%")
                                   ->orWhere('faunas.nameId', 'like', "%{$searchTerm}%");
                            });
                        }
                        if ($taxonomyValue) {
                            if ($taxonomyRank === 'family') {
                                $q->where('faunas.family', $taxonomyValue);
                            } else {
                                $q->where('faunas.nameLat', 'like', "%{$taxonomyValue}%");
                            }
                        }
                    });
                }

                $mediaCount += $brnImgQuery->count();

                // Sounds Burungnesia
                $brnSndQuery = DB::table('fobi_checklist_sounds')
                    ->join('fobi_checklists', 'fobi_checklist_sounds.checklist_id', '=', 'fobi_checklists.id');

                if ($searchTerm || $taxonomyValue) {
                    $brnSndQuery->whereExists(function($q) use ($searchTerm, $taxonomyRank, $taxonomyValue) {
                        $q->select(DB::raw(1))
                          ->from('fobi_checklist_faunasv2')
                          ->join('faunas', 'fobi_checklist_faunasv2.fauna_id', '=', 'faunas.id')
                          ->whereColumn('fobi_checklist_faunasv2.checklist_id', 'fobi_checklists.id');
                        if ($searchTerm) {
                            $q->where(function($sq) use ($searchTerm) {
                                $sq->where('faunas.nameLat', 'like', "%{$searchTerm}%")
                                   ->orWhere('faunas.nameId', 'like', "%{$searchTerm}%");
                            });
                        }
                        if ($taxonomyValue) {
                            if ($taxonomyRank === 'family') {
                                $q->where('faunas.family', $taxonomyValue);
                            } else {
                                $q->where('faunas.nameLat', 'like', "%{$taxonomyValue}%");
                            }
                        }
                    });
                }

                $mediaCount += $brnSndQuery->count();
            }

            // Media Kupunesia (dari DB kupunesia tabel checklist_fauna_imgs)
            if (in_array('kupunesia', $dataSources)) {
                $kupImgQuery = DB::connection('third')->table('checklist_fauna_imgs')
                    ->whereNull('checklist_fauna_imgs.deleted_at');

                if ($searchTerm || $taxonomyValue) {
                    $kupImgQuery->whereExists(function($q) use ($searchTerm, $taxonomyRank, $taxonomyValue) {
                        $q->select(DB::raw(1))
                          ->from('checklist_fauna')
                          ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                          ->whereColumn('checklist_fauna.checklist_id', 'checklist_fauna_imgs.checklist_id');
                        if ($searchTerm) {
                            $q->where(function($sq) use ($searchTerm) {
                                $sq->where('faunas.nameLat', 'like', "%{$searchTerm}%")
                                   ->orWhere('faunas.nameId', 'like', "%{$searchTerm}%");
                            });
                        }
                        if ($taxonomyValue) {
                            if ($taxonomyRank === 'family') {
                                $q->where('faunas.family', $taxonomyValue);
                            } elseif ($taxonomyRank === 'order' && $taxonomyValue !== 'Lepidoptera') {
                                $q->whereRaw('1 = 0');
                            } else {
                                $q->where('faunas.nameLat', 'like', "%{$taxonomyValue}%");
                            }
                        }
                    });
                }

                $mediaCount += $kupImgQuery->count();
            }

            $stats = [
                'observasi' => $obsCount,
                'taksa' => $taxaCount,
                'media' => $mediaCount,
            ];

            return response()->json([
                'success' => true,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getFilteredStats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving filtered statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper: apply polygon/circle filter to a query
     */
    private function applyPolygonFilter($query, Request $request, $table)
    {
        if (!$request->has('polygon') || empty($request->polygon)) return;

        try {
            $polygon = json_decode($request->polygon, true);
            if (!isset($polygon['type'])) return;

            $latCol = "{$table}.latitude";
            $lonCol = "{$table}.longitude";

            if ($polygon['type'] === 'Polygon' && isset($polygon['coordinates'][0])) {
                $coordinates = $polygon['coordinates'][0];
                if (count($coordinates) >= 4) {
                    $polygonWKT = 'POLYGON((' . implode(',', array_map(function($point) {
                        return $point[0] . ' ' . $point[1];
                    }, $coordinates)) . '))';
                    $query->whereRaw("ST_Contains(ST_GeomFromText(?), POINT({$lonCol}, {$latCol}))", [$polygonWKT]);
                }
            } elseif ($polygon['type'] === 'Circle' && isset($polygon['center'], $polygon['radius'])) {
                $center = $polygon['center'];
                $radius = $polygon['radius'];
                $query->whereRaw("ST_Distance_Sphere(point({$lonCol}, {$latCol}), point(?, ?)) <= ?", [$center[0], $center[1], $radius]);
            }
        } catch (\Exception $e) {
            \Log::error('Error applying polygon filter: ' . $e->getMessage());
        }
    }

    public function getPolygonStats(Request $request)
    {
        try {
            $shape = $request->input('shape', []);
            
            \Log::info('Received shape data: ' . json_encode($shape));
            
            if (empty($shape) || !isset($shape['type'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shape data tidak valid'
                ], 400);
            }
            
            // Validasi format data
            if ($shape['type'] === 'Polygon' && (!isset($shape['coordinates']) || !is_array($shape['coordinates']) || empty($shape['coordinates'][0]))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Format polygon tidak valid'
                ], 400);
            }
            
            if ($shape['type'] === 'Circle' && (!isset($shape['center']) || !isset($shape['radius']))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Format circle tidak valid'
                ], 400);
            }
            
            // Ambil filter data_source (support multiple)
            $dataSources = $request->input('data_source', ['fobi', 'burungnesia', 'kupunesia']);
            if (!is_array($dataSources)) {
                $dataSources = [$dataSources];
            }
            
            $includeBurungnesia = in_array('burungnesia', $dataSources);
            $includeKupunesia = in_array('kupunesia', $dataSources);
            $includeFobi = in_array('fobi', $dataSources);
            
            // Query untuk Burungnesia
            $burungnesiaQuery = $includeBurungnesia ? DB::connection('second')->table('checklists')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude') : null;
                
            // Query untuk Kupunesia
            $kupunesiaQuery = $includeKupunesia ? DB::connection('third')->table('checklists')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude') : null;
                
            // Query untuk FOBI
            $fobiQuery = $includeFobi ? DB::table('fobi_checklist_taxas')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude') : null;
            
            // Terapkan filter polygon
            if ($shape['type'] === 'Polygon') {
                $coordinates = $shape['coordinates'][0];
                $polygonWKT = 'POLYGON((' . implode(',', array_map(function($point) {
                    return $point[0] . ' ' . $point[1];
                }, $coordinates)) . '))';
                
                if ($burungnesiaQuery) $burungnesiaQuery->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(longitude, latitude))', [$polygonWKT]);
                if ($kupunesiaQuery) $kupunesiaQuery->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(longitude, latitude))', [$polygonWKT]);
                if ($fobiQuery) $fobiQuery->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(longitude, latitude))', [$polygonWKT]);
            } 
            else if ($shape['type'] === 'Circle') {
                $center = $shape['center'];
                $radius = $shape['radius'];
                
                if ($burungnesiaQuery) $burungnesiaQuery->whereRaw("ST_Distance_Sphere(point(longitude, latitude), point(?, ?)) <= ?", [$center[0], $center[1], $radius]);
                if ($kupunesiaQuery) $kupunesiaQuery->whereRaw("ST_Distance_Sphere(point(longitude, latitude), point(?, ?)) <= ?", [$center[0], $center[1], $radius]);
                if ($fobiQuery) $fobiQuery->whereRaw("ST_Distance_Sphere(point(longitude, latitude), point(?, ?)) <= ?", [$center[0], $center[1], $radius]);
            }
            
            // Filter berdasarkan search jika ada
            if ($request->has('search') && !empty($request->search)) {
                $searchTerm = $request->search;
                
                if ($burungnesiaQuery) {
                    $burungnesiaQuery->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
                        ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                        ->where(function($q) use ($searchTerm) {
                            $q->where('faunas.nameLat', 'like', "%{$searchTerm}%")
                              ->orWhere('faunas.nameId', 'like', "%{$searchTerm}%");
                        });
                }
                    
                if ($kupunesiaQuery) {
                    $kupunesiaQuery->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
                        ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                        ->where(function($q) use ($searchTerm) {
                            $q->where('faunas.nameLat', 'like', "%{$searchTerm}%")
                              ->orWhere('faunas.nameId', 'like', "%{$searchTerm}%");
                        });
                }
                    
                if ($fobiQuery) {
                    $fobiQuery->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id')
                        ->where(function($q) use ($searchTerm) {
                            $q->where('taxas.accepted_scientific_name', 'like', "%{$searchTerm}%")
                              ->orWhere('taxas.scientific_name', 'like', "%{$searchTerm}%")
                              ->orWhere('taxas.cname_species', 'like', "%{$searchTerm}%");
                        });
                }
            } else {
                if ($fobiQuery) $fobiQuery->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id');
            }
            
            // Hitung jumlah observasi untuk masing-masing sumber data
            $burungnesiaCount = $burungnesiaQuery ? $burungnesiaQuery->distinct('checklists.id')->count('checklists.id') : 0;
            $kupunesiaCount = $kupunesiaQuery ? $kupunesiaQuery->distinct('checklists.id')->count('checklists.id') : 0;
            $fobiCount = $fobiQuery ? $fobiQuery->distinct('fobi_checklist_taxas.id')->count('fobi_checklist_taxas.id') : 0;
            
            // Perbaiki perhitungan total spesies dalam polygon
            $allSpeciesIds = collect();

            // 1. Spesies dari FOBI
            if ($includeFobi) {
                $fobiSpeciesQuery = DB::table('taxas')
                    ->where('taxon_rank', 'species')
                    ->whereExists(function ($query) use ($shape) {
                        $query->from('fobi_checklist_taxas')
                            ->whereRaw('fobi_checklist_taxas.taxa_id = taxas.id')
                            ->whereNotNull('fobi_checklist_taxas.latitude')
                            ->whereNotNull('fobi_checklist_taxas.longitude');
                        
                        if ($shape['type'] === 'Polygon') {
                            $coordinates = $shape['coordinates'][0];
                            $polygonWKT = 'POLYGON((' . implode(',', array_map(function($point) {
                                return $point[0] . ' ' . $point[1];
                            }, $coordinates)) . '))';
                            $query->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(fobi_checklist_taxas.longitude, fobi_checklist_taxas.latitude))', [$polygonWKT]);
                        } else if ($shape['type'] === 'Circle') {
                            $center = $shape['center'];
                            $radius = $shape['radius'];
                            $query->whereRaw("ST_Distance_Sphere(point(fobi_checklist_taxas.longitude, fobi_checklist_taxas.latitude), point(?, ?)) <= ?", [$center[0], $center[1], $radius]);
                        }
                    });
                $allSpeciesIds = $allSpeciesIds->concat($fobiSpeciesQuery->pluck('id'));
            }

            // 2. Spesies dari Burungnesia
            if ($includeBurungnesia) {
                $burungnesiaSpeciesQuery = DB::connection('second')
                    ->table('checklist_fauna')
                    ->join('checklists', 'checklist_fauna.checklist_id', '=', 'checklists.id')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                    ->whereNotNull('checklists.latitude')
                    ->whereNotNull('checklists.longitude')
                    ->select('faunas.id', 'faunas.nameLat');

                if ($shape['type'] === 'Polygon') {
                    $coordinates = $shape['coordinates'][0];
                    $polygonWKT = 'POLYGON((' . implode(',', array_map(function($point) {
                        return $point[0] . ' ' . $point[1];
                    }, $coordinates)) . '))';
                    $burungnesiaSpeciesQuery->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(checklists.longitude, checklists.latitude))', [$polygonWKT]);
                } else if ($shape['type'] === 'Circle') {
                    $center = $shape['center'];
                    $radius = $shape['radius'];
                    $burungnesiaSpeciesQuery->whereRaw("ST_Distance_Sphere(point(checklists.longitude, checklists.latitude), point(?, ?)) <= ?", [$center[0], $center[1], $radius]);
                }
                $allSpeciesIds = $allSpeciesIds->concat($burungnesiaSpeciesQuery->pluck('id'));
            }

            // 3. Spesies dari Kupunesia
            if ($includeKupunesia) {
                $kupunesiaSpeciesQuery = DB::connection('third')
                    ->table('checklist_fauna')
                    ->join('checklists', 'checklist_fauna.checklist_id', '=', 'checklists.id')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                    ->whereNotNull('checklists.latitude')
                    ->whereNotNull('checklists.longitude')
                    ->select('faunas.id', 'faunas.nameLat');

                if ($shape['type'] === 'Polygon') {
                    $coordinates = $shape['coordinates'][0];
                    $polygonWKT = 'POLYGON((' . implode(',', array_map(function($point) {
                        return $point[0] . ' ' . $point[1];
                    }, $coordinates)) . '))';
                    $kupunesiaSpeciesQuery->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(checklists.longitude, checklists.latitude))', [$polygonWKT]);
                } else if ($shape['type'] === 'Circle') {
                    $center = $shape['center'];
                    $radius = $shape['radius'];
                    $kupunesiaSpeciesQuery->whereRaw("ST_Distance_Sphere(point(checklists.longitude, checklists.latitude), point(?, ?)) <= ?", [$center[0], $center[1], $radius]);
                }
                $allSpeciesIds = $allSpeciesIds->concat($kupunesiaSpeciesQuery->pluck('id'));
            }

            // Hitung total spesies unik
            $totalSpecies = $allSpeciesIds->unique()->count();
            
            // Hitung total kontributor dalam polygon
            // Helper untuk apply spatial filter
            $applySpatialFilter = function($query, $shape, $latCol, $lngCol) {
                if ($shape['type'] === 'Polygon') {
                    $coordinates = $shape['coordinates'][0];
                    $polygonWKT = 'POLYGON((' . implode(',', array_map(function($point) {
                        return $point[0] . ' ' . $point[1];
                    }, $coordinates)) . '))';
                    $query->whereRaw("ST_Contains(ST_GeomFromText(?), POINT({$lngCol}, {$latCol}))", [$polygonWKT]);
                } else if ($shape['type'] === 'Circle') {
                    $center = $shape['center'];
                    $radius = $shape['radius'];
                    $query->whereRaw("ST_Distance_Sphere(point({$lngCol}, {$latCol}), point(?, ?)) <= ?", [$center[0], $center[1], $radius]);
                }
                return $query;
            };
            
            $allContributorIds = [];
            
            // 1. Kontributor FOBI
            if ($includeFobi) {
                $fobiContributors = DB::table('fobi_users')
                    ->select('fobi_users.id')
                    ->join('fobi_checklist_taxas', 'fobi_users.id', '=', 'fobi_checklist_taxas.user_id')
                    ->whereNotNull('fobi_checklist_taxas.latitude')
                    ->whereNotNull('fobi_checklist_taxas.longitude');
                $applySpatialFilter($fobiContributors, $shape, 'fobi_checklist_taxas.latitude', 'fobi_checklist_taxas.longitude');
                $allContributorIds = array_merge($allContributorIds, $fobiContributors->pluck('id')->toArray());
                
                // Kontributor FOBI via database utama
                $mainContributors = DB::table('users')
                    ->select('users.id')
                    ->join('checklists', 'users.id', '=', 'checklists.user_id')
                    ->whereNull('users.deleted_at')
                    ->whereNotNull('checklists.latitude')
                    ->whereNotNull('checklists.longitude');
                $applySpatialFilter($mainContributors, $shape, 'checklists.latitude', 'checklists.longitude');
                $allContributorIds = array_merge($allContributorIds, $mainContributors->pluck('id')->toArray());
            }
            
            // 2. Kontributor Burungnesia
            if ($includeBurungnesia) {
                $burungnesiaContributors = DB::connection('second')
                    ->table('users')
                    ->select('users.id')
                    ->join('checklists', 'users.id', '=', 'checklists.user_id')
                    ->whereNull('users.deleted_at')
                    ->whereNotNull('checklists.latitude')
                    ->whereNotNull('checklists.longitude');
                $applySpatialFilter($burungnesiaContributors, $shape, 'checklists.latitude', 'checklists.longitude');
                
                // Filter yang sudah terhitung di FOBI
                $burungnesiaMapping = DB::table('fobi_users')->whereNotNull('burungnesia_user_id')->pluck('burungnesia_user_id')->toArray();
                $burungnesiaUserIds = $burungnesiaContributors->pluck('id')->toArray();
                $allContributorIds = array_merge($allContributorIds, array_diff($burungnesiaUserIds, $burungnesiaMapping));
                
                // FOBI users terhubung Burungnesia
                if ($includeFobi) {
                    $fobiBrnContrib = DB::table('fobi_users')->select('fobi_users.id');
                    $fobiBrnContrib->whereExists(function($query) use ($shape, $applySpatialFilter) {
                        $query->select(DB::raw(1))
                            ->from(DB::connection('second')->getDatabaseName() . '.checklists')
                            ->whereRaw('fobi_users.burungnesia_user_id = checklists.user_id')
                            ->whereNotNull('checklists.latitude')
                            ->whereNotNull('checklists.longitude');
                        $applySpatialFilter($query, $shape, 'checklists.latitude', 'checklists.longitude');
                    });
                    $allContributorIds = array_merge($allContributorIds, $fobiBrnContrib->pluck('id')->toArray());
                }
            }
            
            // 3. Kontributor Kupunesia
            if ($includeKupunesia) {
                $kupunesiaContributors = DB::connection('third')
                    ->table('users')
                    ->select('users.id')
                    ->join('checklists', 'users.id', '=', 'checklists.user_id')
                    ->whereNull('users.deleted_at')
                    ->whereNotNull('checklists.latitude')
                    ->whereNotNull('checklists.longitude');
                $applySpatialFilter($kupunesiaContributors, $shape, 'checklists.latitude', 'checklists.longitude');
                
                // Filter yang sudah terhitung di FOBI
                $kupunesiaMapping = DB::table('fobi_users')->whereNotNull('kupunesia_user_id')->pluck('kupunesia_user_id')->toArray();
                $kupunesiaUserIds = $kupunesiaContributors->pluck('id')->toArray();
                $allContributorIds = array_merge($allContributorIds, array_diff($kupunesiaUserIds, $kupunesiaMapping));
                
                // FOBI users terhubung Kupunesia
                if ($includeFobi) {
                    $fobiKpnContrib = DB::table('fobi_users')->select('fobi_users.id');
                    $fobiKpnContrib->whereExists(function($query) use ($shape, $applySpatialFilter) {
                        $query->select(DB::raw(1))
                            ->from(DB::connection('third')->getDatabaseName() . '.checklists')
                            ->whereRaw('fobi_users.kupunesia_user_id = checklists.user_id')
                            ->whereNotNull('checklists.latitude')
                            ->whereNotNull('checklists.longitude');
                        $applySpatialFilter($query, $shape, 'checklists.latitude', 'checklists.longitude');
                    });
                    $allContributorIds = array_merge($allContributorIds, $fobiKpnContrib->pluck('id')->toArray());
                }
            }

            // Hitung total kontributor unik
            $totalContributors = count(array_unique($allContributorIds));
            
            // Siapkan data statistik
            $stats = [
                'burungnesia' => $burungnesiaCount,
                'kupunesia' => $kupunesiaCount,
                'fobi' => $fobiCount,
                'observasi' => $burungnesiaCount + $kupunesiaCount + $fobiCount,
                'spesies' => $totalSpecies,
                'kontributor' => $totalContributors
            ];
            
            return response()->json([
                'success' => true,
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error in getPolygonStats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getGridSpeciesCount(Request $request)
    {
        try {
            $checklistIds = $request->input('checklistIds', []);
            
            if (empty($checklistIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No checklist IDs provided'
                ]);
            }
            
            $burungnesiaIds = [];
            $kupunesiaIds = [];
            $fobiIds = [];
            
            // Pisahkan ID berdasarkan prefix
            foreach ($checklistIds as $id) {
                if (strpos($id, 'brn_') === 0) {
                    $burungnesiaIds[] = str_replace('brn_', '', $id);
                } elseif (strpos($id, 'kpn_') === 0) {
                    $kupunesiaIds[] = str_replace('kpn_', '', $id);
                } elseif (strpos($id, 'fob_') === 0) {
                    $fobiIds[] = str_replace('fob_', '', $id);
                }
            }
            
            // Ambil spesies dari Burungnesia
            $burungnesiaSpecies = collect();
            if (!empty($burungnesiaIds)) {
                $burungnesiaSpecies = DB::connection('second')
                    ->table('checklist_fauna')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                    ->whereIn('checklist_fauna.checklist_id', $burungnesiaIds)
                    ->select('faunas.nameLat')
                    ->distinct()
                    ->get()
                    ->pluck('nameLat');
            }
            
            // Ambil spesies dari Kupunesia
            $kupunesiaSpecies = collect();
            if (!empty($kupunesiaIds)) {
                $kupunesiaSpecies = DB::connection('third')
                    ->table('checklist_fauna')
                    ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                    ->whereIn('checklist_fauna.checklist_id', $kupunesiaIds)
                    ->select('faunas.nameLat')
                    ->distinct()
                    ->get()
                    ->pluck('nameLat');
            }
            
            // Ambil spesies dari FOBI
            $fobiSpecies = collect();
            if (!empty($fobiIds)) {
                $fobiSpecies = DB::table('fobi_checklist_taxas')
                    ->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id')
                    ->whereIn('fobi_checklist_taxas.id', $fobiIds)
                    ->select('taxas.scientific_name as nameLat')
                    ->distinct()
                    ->get()
                    ->pluck('nameLat');
            }
            
            // Gabungkan semua spesies dan hitung yang unik
            $allSpecies = $burungnesiaSpecies->concat($kupunesiaSpecies)->concat($fobiSpecies);
            $uniqueSpeciesCount = $allSpecies->unique()->count();
            
            return response()->json([
                'status' => 'success',
                'totalSpecies' => $uniqueSpeciesCount
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error in getGridSpeciesCount: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getGridsInPolygon(Request $request)
    {
        try {
            $shape = $request->input('shape');
            if (!$shape) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Shape data is required'
                ], 400);
            }
            
            // Ambil filter data_source (support multiple)
            $dataSources = $request->input('data_source', ['fobi', 'burungnesia', 'kupunesia']);
            if (!is_array($dataSources)) {
                $dataSources = [$dataSources];
            }
            
            // Tentukan sumber mana yang aktif
            $includeBurungnesia = in_array('burungnesia', $dataSources);
            $includeKupunesia = in_array('kupunesia', $dataSources);
            $includeFobi = in_array('fobi', $dataSources);
            
            // Ambil semua grid yang berada dalam polygon
            $grids = [];
            $allPoints = collect();
            
            // Jika polygon
            if ($shape['type'] === 'Polygon') {
                $coordinates = $shape['coordinates'][0];
                $polygonWKT = 'POLYGON((' . implode(',', array_map(function($point) {
                    return $point[0] . ' ' . $point[1];
                }, $coordinates)) . '))';
                
                if ($includeBurungnesia) {
                    $burungnesiaPoints = DB::connection('second')
                        ->table('checklists')
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(longitude, latitude))', [$polygonWKT])
                        ->select('id', 'latitude', 'longitude')
                        ->get();
                    $allPoints = $allPoints->merge($burungnesiaPoints->map(function($item) {
                        return ['id' => 'brn_' . $item->id, 'lat' => $item->latitude, 'lng' => $item->longitude];
                    }));
                }
                
                if ($includeKupunesia) {
                    $kupunesiaPoints = DB::connection('third')
                        ->table('checklists')
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(longitude, latitude))', [$polygonWKT])
                        ->select('id', 'latitude', 'longitude')
                        ->get();
                    $allPoints = $allPoints->merge($kupunesiaPoints->map(function($item) {
                        return ['id' => 'kpn_' . $item->id, 'lat' => $item->latitude, 'lng' => $item->longitude];
                    }));
                }
                
                if ($includeFobi) {
                    $fobiPoints = DB::table('fobi_checklists')
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(longitude, latitude))', [$polygonWKT])
                        ->select('id', 'latitude', 'longitude')
                        ->get();
                    $allPoints = $allPoints->merge($fobiPoints->map(function($item) {
                        return ['id' => 'fob_' . $item->id, 'lat' => $item->latitude, 'lng' => $item->longitude];
                    }));
                    
                    $fobiTaxaPoints = DB::table('fobi_checklist_taxas')
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->whereRaw('ST_Contains(ST_GeomFromText(?), POINT(longitude, latitude))', [$polygonWKT])
                        ->select('id', 'latitude', 'longitude')
                        ->get();
                    $allPoints = $allPoints->merge($fobiTaxaPoints->map(function($item) {
                        return ['id' => 'fobt_' . $item->id, 'lat' => $item->latitude, 'lng' => $item->longitude];
                    }));
                }
                
            } 
            // Jika circle
            else if ($shape['type'] === 'Circle') {
                $center = $shape['center'];
                $radius = $shape['radius']; // dalam meter
                
                if ($includeBurungnesia) {
                    $burungnesiaPoints = DB::connection('second')
                        ->table('checklists')
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->whereRaw("ST_Distance_Sphere(point(longitude, latitude), point(?, ?)) <= ?", [$center[0], $center[1], $radius])
                        ->select('id', 'latitude', 'longitude')
                        ->get();
                    $allPoints = $allPoints->merge($burungnesiaPoints->map(function($item) {
                        return ['id' => 'brn_' . $item->id, 'lat' => $item->latitude, 'lng' => $item->longitude];
                    }));
                }
                
                if ($includeKupunesia) {
                    $kupunesiaPoints = DB::connection('third')
                        ->table('checklists')
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->whereRaw("ST_Distance_Sphere(point(longitude, latitude), point(?, ?)) <= ?", [$center[0], $center[1], $radius])
                        ->select('id', 'latitude', 'longitude')
                        ->get();
                    $allPoints = $allPoints->merge($kupunesiaPoints->map(function($item) {
                        return ['id' => 'kpn_' . $item->id, 'lat' => $item->latitude, 'lng' => $item->longitude];
                    }));
                }
                
                if ($includeFobi) {
                    $fobiPoints = DB::table('fobi_checklists')
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->whereRaw("ST_Distance_Sphere(point(longitude, latitude), point(?, ?)) <= ?", [$center[0], $center[1], $radius])
                        ->select('id', 'latitude', 'longitude')
                        ->get();
                    $allPoints = $allPoints->merge($fobiPoints->map(function($item) {
                        return ['id' => 'fob_' . $item->id, 'lat' => $item->latitude, 'lng' => $item->longitude];
                    }));
                    
                    $fobiTaxaPoints = DB::table('fobi_checklist_taxas')
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->whereRaw("ST_Distance_Sphere(point(longitude, latitude), point(?, ?)) <= ?", [$center[0], $center[1], $radius])
                        ->select('id', 'latitude', 'longitude')
                        ->get();
                    $allPoints = $allPoints->merge($fobiTaxaPoints->map(function($item) {
                        return ['id' => 'fobt_' . $item->id, 'lat' => $item->latitude, 'lng' => $item->longitude];
                    }));
                }
            }
            
            // Kelompokkan titik-titik ke dalam grid
            $gridSize = 0.1;
            $gridPoints = [];
            
            foreach ($allPoints as $point) {
                $gridLat = floor($point['lat'] / $gridSize) * $gridSize;
                $gridLng = floor($point['lng'] / $gridSize) * $gridSize;
                $gridId = $gridLat . '_' . $gridLng;
                
                if (!isset($gridPoints[$gridId])) {
                    $gridPoints[$gridId] = [
                        'id' => $gridId,
                        'center' => [$gridLng + ($gridSize/2), $gridLat + ($gridSize/2)],
                        'points' => []
                    ];
                }
                
                $gridPoints[$gridId]['points'][] = $point['id'];
            }
            
            $grids = array_values($gridPoints);
            
            return response()->json([
                'status' => 'success',
                'gridsInPolygon' => $grids,
                'data_source' => $dataSources
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getGridsInPolygon: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getGridData(Request $request, $gridId)
    {
        try {
            // Parse grid ID untuk mendapatkan koordinat
            $parts = explode('_', $gridId);
            if (count($parts) !== 2) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid grid ID format'
                ], 400);
            }
            
            $gridLat = (float)$parts[0];
            $gridLng = (float)$parts[1];
            $gridSize = 0.1; // Ukuran grid dalam derajat
            
            // Hitung batas grid
            $minLat = $gridLat;
            $maxLat = $gridLat + $gridSize;
            $minLng = $gridLng;
            $maxLng = $gridLng + $gridSize;
            
            // Ambil filter data_source (support multiple)
            $dataSources = $request->input('data_source', ['fobi', 'burungnesia', 'kupunesia']);
            if (!is_array($dataSources)) {
                $dataSources = [$dataSources];
            }
            
            $includeBurungnesia = in_array('burungnesia', $dataSources);
            $includeKupunesia = in_array('kupunesia', $dataSources);
            $includeFobi = in_array('fobi', $dataSources);
            
            $processedData = [];
            
            // Ambil data dari Burungnesia (tanpa media)
            if ($includeBurungnesia) {
                $burungnesiaData = DB::connection('second')
                    ->table('checklists')
                    ->join('users', 'checklists.user_id', '=', 'users.id')
                    ->leftJoin('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
                    ->leftJoin('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                    ->whereNotNull('checklists.latitude')
                    ->whereNotNull('checklists.longitude')
                    ->whereBetween('checklists.latitude', [$minLat, $maxLat])
                    ->whereBetween('checklists.longitude', [$minLng, $maxLng])
                    ->select(
                        'checklists.id',
                        'faunas.nameLat as species',
                        'faunas.nameId as local_name',
                        'checklists.created_at as date',
                        'users.uname as observer',
                        DB::raw("(SELECT label FROM checklisttr WHERE checklisttr.checklist_id = checklists.id LIMIT 1) as location"),
                        DB::raw("'burungnesia' as source")
                    )
                    ->get();
                    
                foreach ($burungnesiaData as $item) {
                    $id = 'brn_' . $item->id;
                    $processedData[$id] = [
                        'id' => $id,
                        'species' => $item->species,
                        'local_name' => $item->local_name,
                        'date' => $item->date,
                        'observer' => $item->observer,
                        'location' => $item->location,
                        'source' => $item->source,
                        'media' => []
                    ];
                }
            }
                
            // Ambil data dari Kupunesia (tanpa media)
            if ($includeKupunesia) {
                $kupunesiaData = DB::connection('third')
                    ->table('checklists')
                    ->join('users', 'checklists.user_id', '=', 'users.id')
                    ->leftJoin('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
                    ->leftJoin('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                    ->whereNotNull('checklists.latitude')
                    ->whereNotNull('checklists.longitude')
                    ->whereBetween('checklists.latitude', [$minLat, $maxLat])
                    ->whereBetween('checklists.longitude', [$minLng, $maxLng])
                    ->select(
                        'checklists.id',
                        'faunas.nameLat as species',
                        'faunas.nameId as local_name',
                        'checklists.created_at as date',
                        'users.uname as observer',
                        DB::raw("(SELECT label FROM checklisttr WHERE checklisttr.checklist_id = checklists.id LIMIT 1) as location"),
                        DB::raw("'kupunesia' as source")
                    )
                    ->get();
                    
                foreach ($kupunesiaData as $item) {
                    $id = 'kpn_' . $item->id;
                    $processedData[$id] = [
                        'id' => $id,
                        'species' => $item->species,
                        'local_name' => $item->local_name,
                        'date' => $item->date,
                        'observer' => $item->observer,
                        'location' => $item->location,
                        'source' => $item->source,
                        'media' => []
                    ];
                }
            }
                
            // Ambil data dari FOBI Checklists dan FOBI Checklist Taxas
            if ($includeFobi) {
                $fobiData = DB::table('fobi_checklists')
                    ->join('fobi_users', 'fobi_checklists.fobi_user_id', '=', 'fobi_users.id')
                    ->whereNotNull('fobi_checklists.latitude')
                    ->whereNotNull('fobi_checklists.longitude')
                    ->whereBetween('fobi_checklists.latitude', [$minLat, $maxLat])
                    ->whereBetween('fobi_checklists.longitude', [$minLng, $maxLng])
                    ->select(
                        'fobi_checklists.id',
                        DB::raw("'Unknown Species' as species"),
                        DB::raw("'Spesies Tidak Diketahui' as local_name"),
                        'fobi_checklists.created_at as date',
                        'fobi_users.uname as observer',
                        DB::raw("'fobi' as source")
                    )
                    ->get();
                    
                foreach ($fobiData as $item) {
                    $id = 'fob_' . $item->id;
                    $processedData[$id] = [
                        'id' => $id,
                        'species' => $item->species,
                        'local_name' => $item->local_name,
                        'date' => $item->date,
                        'observer' => $item->observer,
                        'source' => $item->source,
                        'media' => []
                    ];
                }
                    
                // Ambil data dari FOBI Checklist Taxas
                $fobiTaxaData = DB::table('fobi_checklist_taxas')
                    ->join('fobi_users', 'fobi_checklist_taxas.user_id', '=', 'fobi_users.id')
                    ->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id')
                    ->whereNotNull('fobi_checklist_taxas.latitude')
                    ->whereNotNull('fobi_checklist_taxas.longitude')
                    ->whereBetween('fobi_checklist_taxas.latitude', [$minLat, $maxLat])
                    ->whereBetween('fobi_checklist_taxas.longitude', [$minLng, $maxLng])
                    ->select(
                        'fobi_checklist_taxas.id',
                        'taxas.scientific_name as species',
                        'taxas.cname_species as local_name',
                        'fobi_checklist_taxas.created_at as date',
                        'fobi_users.uname as observer',
                        DB::raw("'fobi' as source")
                    )
                    ->get();
                    
                // Ambil media untuk FOBI Checklist Taxas
                $fobiTaxaIds = $fobiTaxaData->pluck('id')->toArray();
                $fobiMediaData = [];
                
                if (!empty($fobiTaxaIds)) {
                    $fobiMediaData = DB::table('fobi_checklist_media')
                        ->whereIn('checklist_id', $fobiTaxaIds)
                        ->select('checklist_id', 'file_path', 'media_type as type', 'storage_type', 'id')
                        ->get()
                        ->groupBy('checklist_id')
                        ->toArray();
                }
                
                foreach ($fobiTaxaData as $item) {
                    $id = 'fobt_' . $item->id;
                    $processedData[$id] = [
                        'id' => $id,
                        'species' => $item->species,
                        'local_name' => $item->local_name,
                        'date' => $item->date,
                        'observer' => $item->observer,
                        'source' => $item->source,
                        'media' => []
                    ];
                    
                    // Tambahkan media jika ada — resolve URL via MediaStorageHelper
                    if (isset($fobiMediaData[$item->id])) {
                        foreach ($fobiMediaData[$item->id] as $media) {
                            $processedData[$id]['media'][] = [
                                'url' => \App\Helpers\MediaStorageHelper::getMediaUrl(
                                    $media->file_path,
                                    $media->storage_type ?? 'local',
                                    $media->id
                                ),
                                'type' => $media->type
                            ];
                        }
                    }
                }
            }
            
            return response()->json([
                'status' => 'success',
                'data' => array_values($processedData)
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getGridData: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function searchUsers(Request $request)
    {
        try {
            $query = $request->input('q', '');
            $limit = $request->input('limit', 20);

            if (strlen($query) < 2) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $users = DB::table('fobi_users')
                ->where('uname', 'LIKE', "%{$query}%")
                ->select('id', 'uname', 'profile_picture')
                ->orderBy('uname')
                ->limit($limit)
                ->get();

            return response()->json(['success' => true, 'data' => $users]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function searchLocations(Request $request)
    {
        try {
            $query = $request->input('q', '');
            $limit = $request->input('limit', 20);
            $source = $request->input('source', 'all'); // all, fobi, burungnesia, kupunesia

            if (strlen($query) < 2) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $locations = collect();

            // FOBi (Amaturnaturalist) - dari fobi_checklist_media.location
            if ($source === 'all' || $source === 'fobi') {
                $fobiLocations = DB::table('fobi_checklist_media')
                    ->whereNotNull('location')
                    ->where('location', '!=', '')
                    ->where('location', 'LIKE', "%{$query}%")
                    ->select('location')
                    ->distinct()
                    ->limit($limit)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'name' => $item->location,
                            'source' => 'fobi'
                        ];
                    });
                $locations = $locations->merge($fobiLocations);
            }

            // Burungnesia - dari checklisttr.label (second connection)
            if ($source === 'all' || $source === 'burungnesia') {
                try {
                    $burungnesiaLocations = DB::connection('second')
                        ->table('checklisttr')
                        ->whereNotNull('label')
                        ->where('label', '!=', '')
                        ->where('label', 'LIKE', "%{$query}%")
                        ->select('label')
                        ->distinct()
                        ->limit($limit)
                        ->get()
                        ->map(function ($item) {
                            return [
                                'name' => $item->label,
                                'source' => 'burungnesia'
                            ];
                        });
                    $locations = $locations->merge($burungnesiaLocations);
                } catch (\Exception $e) {
                    // Skip if connection fails
                }
            }

            // Kupunesia - dari checklisttr.label (third connection)
            if ($source === 'all' || $source === 'kupunesia') {
                try {
                    $kupunesiaLocations = DB::connection('third')
                        ->table('checklisttr')
                        ->whereNotNull('label')
                        ->where('label', '!=', '')
                        ->where('label', 'LIKE', "%{$query}%")
                        ->select('label')
                        ->distinct()
                        ->limit($limit)
                        ->get()
                        ->map(function ($item) {
                            return [
                                'name' => $item->label,
                                'source' => 'kupunesia'
                            ];
                        });
                    $locations = $locations->merge($kupunesiaLocations);
                } catch (\Exception $e) {
                    // Skip if connection fails
                }
            }

            // Remove duplicates and limit
            $uniqueLocations = $locations->unique('name')->take($limit)->values();

            return response()->json(['success' => true, 'data' => $uniqueLocations]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
