<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserFavoriteTaxa;
use App\Models\Taxa;
use App\Models\FobiUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FavoriteTaxaController extends Controller
{
    /**
     * Get favorite taxas for a user
     */
    public function index($userId)
    {
        try {
            $favorites = UserFavoriteTaxa::with(['taxa' => function($query) {
                $query->select(
                    'id', 'scientific_name', 'taxon_rank', 'Cname',
                    'kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species',
                    'cname_kingdom', 'cname_phylum', 'cname_class', 'cname_order', 
                    'cname_family', 'cname_genus', 'cname_species'
                );
            }])
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->get();

            // Get photo for each taxa (including from child taxa for parent taxa)
            $favoritesWithPhotos = $favorites->map(function($favorite) {
                $taxa = $favorite->taxa;
                $photoUrl = null;
                $childCount = 0;

                if ($taxa) {
                    $rank = strtolower($taxa->taxon_rank ?? '');
                    
                    // Try to get photo from observations of this taxa
                    $observation = DB::table('fobi_checklist_taxas as fct')
                        ->join('fobi_checklist_media as fcm', 'fct.id', '=', 'fcm.checklist_id')
                        ->where('fct.taxa_id', $taxa->id)
                        ->whereNotNull('fcm.file_path')
                        ->select('fcm.file_path')
                        ->first();

                    if ($observation && $observation->file_path) {
                        $photoUrl = \App\Helpers\MediaStorageHelper::getMediaUrl($observation->file_path, 'local', null);
                    }
                    
                    // If no photo and this is a parent taxa, try to get photo from child taxa
                    if (!$photoUrl && !in_array($rank, ['species', 'subspecies'])) {
                        $childQuery = DB::table('taxas as t')
                            ->join('fobi_checklist_taxas as fct', 't.id', '=', 'fct.taxa_id')
                            ->join('fobi_checklist_media as fcm', 'fct.id', '=', 'fcm.checklist_id')
                            ->whereNotNull('fcm.file_path');
                        
                        // Filter by the appropriate hierarchy field
                        if ($taxa->kingdom && $rank === 'kingdom') {
                            $childQuery->whereRaw('LOWER(t.kingdom) = ?', [strtolower($taxa->kingdom)]);
                        } elseif ($taxa->phylum && $rank === 'phylum') {
                            $childQuery->whereRaw('LOWER(t.phylum) = ?', [strtolower($taxa->phylum)]);
                        } elseif ($taxa->class && $rank === 'class') {
                            $childQuery->whereRaw('LOWER(t.class) = ?', [strtolower($taxa->class)]);
                        } elseif ($taxa->order && $rank === 'order') {
                            $childQuery->whereRaw('LOWER(t.order) = ?', [strtolower($taxa->order)]);
                        } elseif ($taxa->family && $rank === 'family') {
                            $childQuery->whereRaw('LOWER(t.family) = ?', [strtolower($taxa->family)]);
                        } elseif ($taxa->genus && $rank === 'genus') {
                            $childQuery->whereRaw('LOWER(t.genus) = ?', [strtolower($taxa->genus)]);
                        }
                        
                        $childPhoto = $childQuery->select('fcm.file_path')->first();
                        if ($childPhoto && $childPhoto->file_path) {
                            $photoUrl = \App\Helpers\MediaStorageHelper::getMediaUrl($childPhoto->file_path, 'local', null);
                        }
                    }
                    
                    // Count child taxa for parent taxa
                    if (!in_array($rank, ['species', 'subspecies'])) {
                        $countQuery = DB::table('taxas');
                        if ($taxa->kingdom && $rank === 'kingdom') {
                            $countQuery->whereRaw('LOWER(kingdom) = ?', [strtolower($taxa->kingdom)]);
                        } elseif ($taxa->phylum && $rank === 'phylum') {
                            $countQuery->whereRaw('LOWER(phylum) = ?', [strtolower($taxa->phylum)]);
                        } elseif ($taxa->class && $rank === 'class') {
                            $countQuery->whereRaw('LOWER(class) = ?', [strtolower($taxa->class)]);
                        } elseif ($taxa->order && $rank === 'order') {
                            $countQuery->whereRaw('LOWER(order) = ?', [strtolower($taxa->order)]);
                        } elseif ($taxa->family && $rank === 'family') {
                            $countQuery->whereRaw('LOWER(family) = ?', [strtolower($taxa->family)]);
                        } elseif ($taxa->genus && $rank === 'genus') {
                            $countQuery->whereRaw('LOWER(genus) = ?', [strtolower($taxa->genus)]);
                        }
                        $childCount = $countQuery->count();
                    }
                }

                return [
                    'id' => $favorite->id,
                    'taxa_id' => $favorite->taxa_id,
                    'sort_order' => $favorite->sort_order,
                    'created_at' => $favorite->created_at,
                    'taxa' => $taxa ? [
                        'id' => $taxa->id,
                        'scientific_name' => $taxa->scientific_name,
                        'taxon_rank' => $taxa->taxon_rank,
                        'common_name' => $taxa->Cname,
                        'kingdom' => $taxa->kingdom,
                        'phylum' => $taxa->phylum,
                        'class' => $taxa->class,
                        'order' => $taxa->order,
                        'family' => $taxa->family,
                        'genus' => $taxa->genus,
                        'species' => $taxa->species,
                        'photo_url' => $photoUrl,
                        'child_count' => $childCount
                    ] : null
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $favoritesWithPhotos
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching favorite taxas:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data taksa favorit'
            ], 500);
        }
    }

    /**
     * Add a taxa to favorites
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'taxa_id' => 'required|exists:taxas,id'
            ]);

            $userId = auth()->id();
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Check if already exists
            $existing = UserFavoriteTaxa::where('user_id', $userId)
                ->where('taxa_id', $request->taxa_id)
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Taksa sudah ada di daftar favorit'
                ], 400);
            }

            // Get max sort order
            $maxOrder = UserFavoriteTaxa::where('user_id', $userId)->max('sort_order') ?? 0;

            $favorite = UserFavoriteTaxa::create([
                'user_id' => $userId,
                'taxa_id' => $request->taxa_id,
                'sort_order' => $maxOrder + 1
            ]);

            // Load taxa data
            $favorite->load('taxa');

            return response()->json([
                'success' => true,
                'message' => 'Taksa berhasil ditambahkan ke favorit',
                'data' => $favorite
            ]);
        } catch (\Exception $e) {
            Log::error('Error adding favorite taxa:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan taksa favorit: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove a taxa from favorites
     */
    public function destroy($id)
    {
        try {
            $userId = auth()->id();
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $favorite = UserFavoriteTaxa::where('id', $id)
                ->where('user_id', $userId)
                ->first();

            if (!$favorite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Taksa favorit tidak ditemukan'
                ], 404);
            }

            $favorite->delete();

            return response()->json([
                'success' => true,
                'message' => 'Taksa berhasil dihapus dari favorit'
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing favorite taxa:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus taksa favorit'
            ], 500);
        }
    }

    /**
     * Search taxas with hierarchy
     */
    public function searchTaxa(Request $request)
    {
        try {
            $search = $request->query('search', '');
            $rank = $request->query('rank', '');
            $parentRank = $request->query('parent_rank', '');
            $parentValue = $request->query('parent_value', '');
            $limit = $request->query('limit', 50);

            $query = Taxa::query();

            // Filter by rank if specified
            if ($rank) {
                $query->whereRaw('LOWER(taxon_rank) = ?', [strtolower($rank)]);
            }

            // Filter by parent if specified
            if ($parentRank && $parentValue) {
                $query->whereRaw('LOWER(' . $parentRank . ') = ?', [strtolower($parentValue)]);
            }

            // Search by name
            if ($search) {
                $searchLower = strtolower($search);
                $query->where(function($q) use ($searchLower) {
                    $q->whereRaw('LOWER(scientific_name) LIKE ?', ["%{$searchLower}%"])
                      ->orWhereRaw('LOWER(Cname) LIKE ?', ["%{$searchLower}%"]);
                });
            }

            $taxas = $query->select(
                'id', 'scientific_name', 'taxon_rank', 'Cname',
                'superkingdom', 'kingdom', 'subkingdom', 'superphylum', 'phylum', 'subphylum',
                'superclass', 'class', 'subclass', 'infraclass',
                'superorder', 'order', 'suborder', 'infraorder',
                'superfamily', 'family', 'subfamily', 'tribe', 'subtribe',
                'genus', 'subgenus', 'species', 'subspecies'
            )
            ->orderBy('scientific_name')
            ->limit($limit)
            ->get();

            return response()->json([
                'success' => true,
                'data' => $taxas
            ]);
        } catch (\Exception $e) {
            Log::error('Error searching taxa:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencari taksa'
            ], 500);
        }
    }

    /**
     * Get distinct values for a taxonomic rank
     */
    public function getTaxonomicRankValues(Request $request)
    {
        try {
            $rank = $request->query('rank', 'kingdom');
            $search = $request->query('search', '');
            $parentRank = $request->query('parent_rank', '');
            $parentValue = $request->query('parent_value', '');
            $limit = $request->query('limit', 100);

            // Validate rank
            $validRanks = [
                'superkingdom', 'kingdom', 'subkingdom', 'superphylum', 'phylum', 'subphylum',
                'superclass', 'class', 'subclass', 'infraclass',
                'superorder', 'order', 'suborder', 'infraorder',
                'superfamily', 'family', 'subfamily', 'tribe', 'subtribe',
                'genus', 'subgenus', 'species', 'subspecies'
            ];

            if (!in_array(strtolower($rank), $validRanks)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid taxonomic rank'
                ], 400);
            }

            $query = DB::table('taxas')
                ->whereNotNull($rank)
                ->where($rank, '!=', '');

            // Filter by parent if specified
            if ($parentRank && $parentValue) {
                $query->whereRaw('LOWER(' . $parentRank . ') = ?', [strtolower($parentValue)]);
            }

            // Search filter
            if ($search) {
                $searchLower = strtolower($search);
                $query->whereRaw('LOWER(' . $rank . ') LIKE ?', ["%{$searchLower}%"]);
            }

            $values = $query->select($rank)
                ->distinct()
                ->orderBy($rank)
                ->limit($limit)
                ->pluck($rank);

            return response()->json([
                'success' => true,
                'data' => $values
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting taxonomic rank values:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data taksonomi'
            ], 500);
        }
    }

    /**
     * Get taxa by taxonomic hierarchy selection
     */
    public function getTaxaByHierarchy(Request $request)
    {
        try {
            $filters = $request->all();
            $exactRank = $request->query('exact_rank', '');
            $query = Taxa::query();

            // Apply hierarchy filters
            $hierarchyFields = [
                'superkingdom', 'kingdom', 'subkingdom', 'superphylum', 'phylum', 'subphylum',
                'superclass', 'class', 'subclass', 'infraclass',
                'superorder', 'order', 'suborder', 'infraorder',
                'superfamily', 'family', 'subfamily', 'tribe', 'subtribe',
                'genus', 'subgenus', 'species', 'subspecies'
            ];

            foreach ($hierarchyFields as $field) {
                if (!empty($filters[$field])) {
                    $query->whereRaw('LOWER(' . $field . ') = ?', [strtolower($filters[$field])]);
                }
            }

            // If exact_rank is specified, filter by that rank
            if ($exactRank) {
                $query->whereRaw('LOWER(taxon_rank) = ?', [strtolower($exactRank)]);
            }

            // Get the most specific taxa matching the hierarchy
            $taxas = $query->select(
                'id', 'scientific_name', 'taxon_rank', 'Cname',
                'superkingdom', 'kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'
            )
            ->orderByRaw("CASE 
                WHEN taxon_rank = 'SPECIES' THEN 1
                WHEN taxon_rank = 'GENUS' THEN 2
                WHEN taxon_rank = 'FAMILY' THEN 3
                WHEN taxon_rank = 'ORDER' THEN 4
                WHEN taxon_rank = 'CLASS' THEN 5
                WHEN taxon_rank = 'PHYLUM' THEN 6
                WHEN taxon_rank = 'KINGDOM' THEN 7
                ELSE 8
            END")
            ->limit(50)
            ->get();

            return response()->json([
                'success' => true,
                'data' => $taxas
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting taxa by hierarchy:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data taksa'
            ], 500);
        }
    }
}
