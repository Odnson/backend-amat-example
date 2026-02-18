<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SpeciesSuggestionController extends Controller
{
    public function suggest(Request $request)
    {
        try {
            $request->validate([
                'query' => 'required|string|min:1',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'data_source' => 'nullable|array',
                'data_source.*' => 'string|in:fobi,burungnesia,kupunesia'
            ]);

            $query = trim($request->get('query'));
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 20);
            $offset = ($page - 1) * $perPage;
            $dataSources = $request->get('data_source', null); // null = semua sumber

            if (empty($query)) {
                return response()->json([
                    'data' => [],
                    'success' => true,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => 0,
                        'total_pages' => 0
                    ]
                ]);
            }

            // Define all taxonomic fields to search
            $taxonomicFields = [
                // Scientific names
                'scientific_name',
                'domain', 'superkingdom', 'kingdom', 'subkingdom',
                'superphylum', 'phylum', 'subphylum',
                'superclass', 'class', 'subclass', 'infraclass',
                'superorder', 'order', 'suborder', 'infraorder',
                'superfamily', 'family', 'subfamily',
                'supertribe', 'tribe', 'subtribe',
                'genus', 'subgenus',
                'species', 'subspecies', 'variety', 'form',
                // Common names
                'cname_domain', 'cname_superkingdom', 'cname_kingdom', 'cname_subkingdom',
                'cname_superphylum', 'cname_phylum', 'cname_subphylum',
                'cname_superclass', 'cname_class', 'cname_subclass', 'cname_infraclass',
                'cname_superorder', 'cname_order', 'cname_suborder',
                'cname_superfamily', 'cname_family', 'cname_subfamily',
                'cname_supertribe', 'cname_tribe', 'cname_subtribe',
                'cname_genus', 'cname_subgenus',
                'cname_species', 'cname_subspecies', 'cname_variety'
            ];

            // Build optimized search query with smart common name mapping
            // Normalize query for hyphen-insensitive search
            $normalizedQuery = strtolower($query);
            $cleanQuery = str_replace('-', ' ', $query); // Remove hyphens for consistent search
            
            $searchPattern = '%' . $cleanQuery . '%';
            $exactPattern = strtolower($cleanQuery);
            
            $queryBuilder = DB::table('taxas')
                ->select([
                    'id', 'scientific_name', 'taxonomic_status', 'accepted_scientific_name', 'taxon_rank',
                    'domain', 'superkingdom', 'kingdom', 'subkingdom',
                    'superphylum', 'phylum', 'subphylum',
                    'superclass', 'class', 'subclass', 'infraclass',
                    'superorder', 'order', 'suborder', 'infraorder',
                    'superfamily', 'family', 'subfamily',
                    'supertribe', 'tribe', 'subtribe',
                    'genus', 'subgenus', 'species', 'subspecies', 'variety', 'form',
                    'cname_domain', 'cname_superkingdom', 'cname_kingdom', 'cname_subkingdom',
                    'cname_superphylum', 'cname_phylum', 'cname_subphylum',
                    'cname_superclass', 'cname_class', 'cname_subclass', 'cname_infraclass',
                    'cname_superorder', 'cname_order', 'cname_suborder',
                    'cname_superfamily', 'cname_family', 'cname_subfamily',
                    'cname_supertribe', 'cname_tribe', 'cname_subtribe',
                    'cname_genus', 'cname_subgenus', 'cname_species', 'cname_subspecies', 'cname_variety',
                    'Cname', 'Cname_two', 'Cname_three', 'Cname_four', 'Cname_five',
                    'Cname_six', 'Cname_seven', 'Cname_eight', 'Cname_nine', 'Cname_ten'
                ])
                ->whereIn('taxonomic_status', ['ACCEPTED', 'SYNONYM'])
                ->where(function($q) use ($searchPattern, $exactPattern, $cleanQuery) {
                    // First priority: Scientific names (hyphen-insensitive search)
                    foreach (['scientific_name', 'domain', 'superkingdom', 'kingdom', 'subkingdom',
                             'superphylum', 'phylum', 'subphylum', 'superclass', 'class', 'subclass', 'infraclass',
                             'superorder', 'order', 'suborder', 'infraorder', 'superfamily', 'family', 'subfamily',
                             'supertribe', 'tribe', 'subtribe', 'genus', 'subgenus', 'species', 'subspecies', 'variety', 'form'] as $field) {
                        if ($field === 'order') {
                            $q->orWhere(DB::raw("REPLACE(LOWER(`{$field}`), '-', ' ')"), 'LIKE', $searchPattern);
                        } else {
                            $q->orWhere(DB::raw("REPLACE(LOWER({$field}), '-', ' ')"), 'LIKE', $searchPattern);
                        }
                    }
                    
                    // Second priority: Common names that match the taxon's own rank
                    // This ensures "hewan" finds Animalia (kingdom), not all animals
                    $q->orWhere(function($subQ) use ($exactPattern, $searchPattern, $cleanQuery) {
                        $subQ->where(function($rankQ) use ($exactPattern, $searchPattern, $cleanQuery) {
                            // Kingdom level matches (hyphen-insensitive)
                            $rankQ->where('taxon_rank', 'KINGDOM')
                                  ->where(function($cq) use ($exactPattern, $searchPattern, $cleanQuery) {
                                      $cq->where(DB::raw("REPLACE(LOWER(cname_kingdom), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(cname_kingdom), '-', ' ')"), 'LIKE', $searchPattern);
                                  });
                        })
                        ->orWhere(function($rankQ) use ($exactPattern, $searchPattern) {
                            // Phylum level matches (hyphen-insensitive)
                            $rankQ->where('taxon_rank', 'PHYLUM')
                                  ->where(function($cq) use ($exactPattern, $searchPattern) {
                                      $cq->where(DB::raw("REPLACE(LOWER(cname_phylum), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(cname_phylum), '-', ' ')"), 'LIKE', $searchPattern);
                                  });
                        })
                        ->orWhere(function($rankQ) use ($exactPattern, $searchPattern) {
                            // Class level matches (hyphen-insensitive)
                            $rankQ->where('taxon_rank', 'CLASS')
                                  ->where(function($cq) use ($exactPattern, $searchPattern) {
                                      $cq->where(DB::raw("REPLACE(LOWER(cname_class), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(cname_class), '-', ' ')"), 'LIKE', $searchPattern);
                                  });
                        })
                        ->orWhere(function($rankQ) use ($exactPattern, $searchPattern) {
                            // Order level matches (hyphen-insensitive)
                            $rankQ->where('taxon_rank', 'ORDER')
                                  ->where(function($cq) use ($exactPattern, $searchPattern) {
                                      $cq->where(DB::raw("REPLACE(LOWER(cname_order), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(cname_order), '-', ' ')"), 'LIKE', $searchPattern);
                                  });
                        })
                        ->orWhere(function($rankQ) use ($exactPattern, $searchPattern) {
                            // Family level matches (hyphen-insensitive)
                            $rankQ->where('taxon_rank', 'FAMILY')
                                  ->where(function($cq) use ($exactPattern, $searchPattern) {
                                      $cq->where(DB::raw("REPLACE(LOWER(cname_family), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(cname_family), '-', ' ')"), 'LIKE', $searchPattern);
                                  });
                        })
                        ->orWhere(function($rankQ) use ($exactPattern, $searchPattern) {
                            // Genus level matches (hyphen-insensitive)
                            $rankQ->where('taxon_rank', 'GENUS')
                                  ->where(function($cq) use ($exactPattern, $searchPattern) {
                                      $cq->where(DB::raw("REPLACE(LOWER(cname_genus), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(cname_genus), '-', ' ')"), 'LIKE', $searchPattern);
                                  });
                        })
                        ->orWhere(function($rankQ) use ($exactPattern, $searchPattern) {
                            // Species level matches (hyphen-insensitive)
                            $rankQ->where('taxon_rank', 'SPECIES')
                                  ->where(function($cq) use ($exactPattern, $searchPattern) {
                                      $cq->where(DB::raw("REPLACE(LOWER(cname_species), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(cname_species), '-', ' ')"), 'LIKE', $searchPattern)
                                         // Add support for Cname fields in species search (hyphen-insensitive)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname), '-', ' ')"), 'LIKE', $searchPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_two), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_two), '-', ' ')"), 'LIKE', $searchPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_three), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_three), '-', ' ')"), 'LIKE', $searchPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_four), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_four), '-', ' ')"), 'LIKE', $searchPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_five), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_five), '-', ' ')"), 'LIKE', $searchPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_six), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_six), '-', ' ')"), 'LIKE', $searchPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_seven), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_seven), '-', ' ')"), 'LIKE', $searchPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_eight), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_eight), '-', ' ')"), 'LIKE', $searchPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_nine), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_nine), '-', ' ')"), 'LIKE', $searchPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_ten), '-', ' ')"), $exactPattern)
                                         ->orWhere(DB::raw("REPLACE(LOWER(Cname_ten), '-', ' ')"), 'LIKE', $searchPattern);
                                  });
                        });
                    });
                    
                    // Third priority: Partial common name matches (for broader search, hyphen-insensitive)
                    $q->orWhere(function($subQ) use ($searchPattern) {
                        foreach (['cname_domain', 'cname_superkingdom', 'cname_kingdom', 'cname_subkingdom',
                                 'cname_superphylum', 'cname_phylum', 'cname_subphylum', 'cname_superclass', 'cname_class', 'cname_subclass', 'cname_infraclass',
                                 'cname_superorder', 'cname_order', 'cname_suborder', 'cname_superfamily', 'cname_family', 'cname_subfamily',
                                 'cname_supertribe', 'cname_tribe', 'cname_subtribe', 'cname_genus', 'cname_subgenus', 'cname_species', 'cname_subspecies', 'cname_variety',
                                 'Cname', 'Cname_two', 'Cname_three', 'Cname_four', 'Cname_five', 'Cname_six', 'Cname_seven', 'Cname_eight', 'Cname_nine', 'Cname_ten'] as $field) {
                            $subQ->orWhere(DB::raw("REPLACE(LOWER({$field}), '-', ' ')"), 'LIKE', $searchPattern);
                        }
                    });
                });

            // Filter berdasarkan data_source: hanya tampilkan taksa yang punya observasi di sumber aktif
            if (!empty($dataSources) && is_array($dataSources)) {
                $queryBuilder->where(function($sourceQ) use ($dataSources) {
                    foreach ($dataSources as $source) {
                        if ($source === 'fobi') {
                            // fobi_checklist_taxas.taxa_id → taxas.id (relasi langsung)
                            $sourceQ->orWhereExists(function($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('fobi_checklist_taxas')
                                    ->whereColumn('fobi_checklist_taxas.taxa_id', 'taxas.id')
                                    ->limit(1);
                            });
                        }
                        if ($source === 'burungnesia') {
                            // fobi_checklist_faunasv1.fauna_id → taxas.burnes_fauna_id (bukan taxas.id)
                            $sourceQ->orWhereExists(function($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('fobi_checklist_faunasv1')
                                    ->whereColumn('fobi_checklist_faunasv1.fauna_id', 'taxas.burnes_fauna_id')
                                    ->limit(1);
                            });
                        }
                        if ($source === 'kupunesia') {
                            // fobi_checklist_faunasv2.fauna_id → taxas.kupnes_fauna_id (bukan taxas.id)
                            $sourceQ->orWhereExists(function($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('fobi_checklist_faunasv2')
                                    ->whereColumn('fobi_checklist_faunasv2.fauna_id', 'taxas.kupnes_fauna_id')
                                    ->limit(1);
                            });
                        }
                    }
                });
            }

            $queryBuilder->orderByRaw("
                    CASE 
                        -- Highest priority: Exact scientific name matches
                        WHEN LOWER(scientific_name) = ? THEN 1
                        WHEN LOWER(scientific_name) LIKE ? THEN 2
                        
                        -- Second priority: Taxa that match their own rank's common name (exact)
                        WHEN (taxon_rank = 'KINGDOM' AND LOWER(cname_kingdom) = ?) THEN 3
                        WHEN (taxon_rank = 'PHYLUM' AND LOWER(cname_phylum) = ?) THEN 4
                        WHEN (taxon_rank = 'CLASS' AND LOWER(cname_class) = ?) THEN 5
                        WHEN (taxon_rank = 'ORDER' AND LOWER(cname_order) = ?) THEN 6
                        WHEN (taxon_rank = 'FAMILY' AND LOWER(cname_family) = ?) THEN 7
                        WHEN (taxon_rank = 'GENUS' AND LOWER(cname_genus) = ?) THEN 8
                        WHEN (taxon_rank = 'SPECIES' AND LOWER(cname_species) = ?) THEN 9
                        
                        -- High priority for compound common names
                        WHEN (taxon_rank = 'KINGDOM' AND LOWER(cname_kingdom) LIKE ?) THEN 10
                        WHEN (taxon_rank = 'PHYLUM' AND LOWER(cname_phylum) LIKE ?) THEN 11
                        WHEN (taxon_rank = 'CLASS' AND LOWER(cname_class) LIKE ?) THEN 12
                        WHEN (taxon_rank = 'ORDER' AND LOWER(cname_order) LIKE ?) THEN 13
                        WHEN (taxon_rank = 'FAMILY' AND LOWER(cname_family) LIKE ?) THEN 14
                        WHEN (taxon_rank = 'GENUS' AND LOWER(cname_genus) LIKE ?) THEN 15
                        WHEN (taxon_rank = 'SPECIES' AND LOWER(cname_species) LIKE ?) THEN 16
                        
                        -- Third priority: Scientific name partial matches by specificity
                        WHEN LOWER(species) LIKE ? THEN 17
                        WHEN LOWER(genus) LIKE ? THEN 18
                        WHEN LOWER(family) LIKE ? THEN 19
                        WHEN LOWER(`order`) LIKE ? THEN 20
                        WHEN LOWER(class) LIKE ? THEN 21
                        WHEN LOWER(phylum) LIKE ? THEN 22
                        WHEN LOWER(kingdom) LIKE ? THEN 23
                        
                        -- Fourth priority: Common name partial matches
                        WHEN LOWER(cname_species) LIKE ? THEN 24
                        WHEN LOWER(cname_genus) LIKE ? THEN 25
                        WHEN LOWER(cname_family) LIKE ? THEN 26
                        WHEN LOWER(cname_order) LIKE ? THEN 27
                        WHEN LOWER(cname_class) LIKE ? THEN 28
                        WHEN LOWER(cname_phylum) LIKE ? THEN 29
                        WHEN LOWER(cname_kingdom) LIKE ? THEN 30
                        
                        -- Fifth priority: Cname field matches (exact matches first)
                        WHEN LOWER(Cname) = ? THEN 31
                        WHEN LOWER(Cname_two) = ? THEN 32
                        WHEN LOWER(Cname_three) = ? THEN 33
                        WHEN LOWER(Cname_four) = ? THEN 34
                        WHEN LOWER(Cname_five) = ? THEN 35
                        WHEN LOWER(Cname_six) = ? THEN 36
                        WHEN LOWER(Cname_seven) = ? THEN 37
                        WHEN LOWER(Cname_eight) = ? THEN 38
                        WHEN LOWER(Cname_nine) = ? THEN 39
                        WHEN LOWER(Cname_ten) = ? THEN 40
                        
                        -- Sixth priority: Cname field partial matches
                        WHEN LOWER(Cname) LIKE ? THEN 41
                        WHEN LOWER(Cname_two) LIKE ? THEN 42
                        WHEN LOWER(Cname_three) LIKE ? THEN 43
                        WHEN LOWER(Cname_four) LIKE ? THEN 44
                        WHEN LOWER(Cname_five) LIKE ? THEN 45
                        WHEN LOWER(Cname_six) LIKE ? THEN 46
                        WHEN LOWER(Cname_seven) LIKE ? THEN 47
                        WHEN LOWER(Cname_eight) LIKE ? THEN 48
                        WHEN LOWER(Cname_nine) LIKE ? THEN 49
                        WHEN LOWER(Cname_ten) LIKE ? THEN 50
                        
                        ELSE 51
                    END, scientific_name
                ", [
                    $exactPattern, $searchPattern, // scientific name exact and partial
                    $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, // rank-specific cname exact matches
                    $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, // rank-specific cname partial matches (compound names)
                    $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, // scientific name partial matches
                    $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, // cname partial matches
                    $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, $exactPattern, // Cname exact matches
                    $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern // Cname partial matches
                ]);

            // Get total count for pagination
            $total = $queryBuilder->count();

            // Get paginated results
            $results = $queryBuilder->offset($offset)->limit($perPage)->get();

            // Format results with proper rank detection
            $formattedResults = $results->map(function($item) use ($query) {
                // Detect the most specific rank for this item
                $rank = $this->detectTaxonomicRank($item);
                
                // Get the appropriate scientific and common names for the detected rank
                $scientificName = $this->getScientificNameForRank($item, $rank);
                $commonName = $this->getCommonNameForRank($item, $rank);
                
                // Build display name
                $displayName = $scientificName;
                if ($commonName) {
                    $displayName .= " ({$commonName})";
                }
                
                // Add rank and family info for context
                if ($rank && $rank !== 'scientific_name') {
                    $displayName .= " - " . ucfirst($rank);
                }
                
                if ($item->family && !in_array($rank, ['family', 'subfamily'])) {
                    $displayName .= " | Family: {$item->family}";
                    if ($item->cname_family) {
                        $displayName .= " ({$item->cname_family})";
                    }
                }

                return [
                    'id' => $item->id,
                    'rank' => $rank,
                    'scientific_name' => $item->scientific_name,
                    'common_name' => $commonName,
                    'taxonomic_status' => $item->taxonomic_status,
                    'accepted_scientific_name' => $item->accepted_scientific_name,
                    'display_name' => $displayName,
                    'full_data' => $item
                ];
            })
            ->filter()
            ->unique('scientific_name')
            ->values();

            return response()->json([
                'data' => $formattedResults,
                'success' => true,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => ceil($total / $perPage)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error in species suggestion: ' . $e->getMessage());
            return response()->json([
                'data' => [],
                'success' => false,
                'message' => 'Error occurred while searching'
            ], 500);
        }
    }

    private function detectTaxonomicRank($item)
    {
        // Detect the most specific taxonomic rank based on available data
        $ranks = [
            'form', 'variety', 'subspecies', 'species', 'subgenus', 'genus',
            'subtribe', 'tribe', 'supertribe', 'subfamily', 'family', 'superfamily',
            'infraorder', 'suborder', 'order', 'superorder',
            'infraclass', 'subclass', 'class', 'superclass',
            'subphylum', 'phylum', 'superphylum',
            'subkingdom', 'kingdom', 'superkingdom', 'domain'
        ];
        
        foreach ($ranks as $rank) {
            if (!empty($item->{$rank})) {
                return $rank;
            }
        }
        
        return $item->taxon_rank ?: 'unknown';
    }

    private function getScientificNameForRank($item, $rank)
    {
        // Get the scientific name for the detected rank
        if (!empty($item->{$rank})) {
            return $item->{$rank};
        }
        
        return $item->scientific_name;
    }

    private function getCommonNameForRank($item, $rank)
    {
        // Get the common name for the detected rank
        $cnameField = 'cname_' . $rank;
        
        if (property_exists($item, $cnameField) && !empty($item->{$cnameField})) {
            return $item->{$cnameField};
        }
        
        // If no standard cname field, check Cname fields (prioritize Cname first, then Cname_two, etc.)
        $cnameFields = ['Cname', 'Cname_two', 'Cname_three', 'Cname_four', 'Cname_five', 
                       'Cname_six', 'Cname_seven', 'Cname_eight', 'Cname_nine', 'Cname_ten'];
        
        foreach ($cnameFields as $field) {
            if (property_exists($item, $field) && !empty($item->{$field})) {
                return $item->{$field};
            }
        }
        
        return null;
    }



    private function getFobiLocations($taxaId)
    {
        // Lokasi dari fobi_checklists (burungnesia)
        $burungnesiaLocations = DB::table('fobi_checklists')
            ->join('fobi_checklist_faunasv1', 'fobi_checklists.id', '=', 'fobi_checklist_faunasv1.checklist_id')
            ->where('fobi_checklist_faunasv1.fauna_id', $taxaId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('latitude', 'longitude')
            ->get();

        // Lokasi dari fobi_checklists_kupnes (kupunesia)
        $kupunesiaLocations = DB::table('fobi_checklists_kupnes')
            ->join('fobi_checklist_faunasv2', 'fobi_checklists_kupnes.id', '=', 'fobi_checklist_faunasv2.checklist_id')
            ->where('fobi_checklist_faunasv2.fauna_id', $taxaId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('latitude', 'longitude')
            ->get();

        // Lokasi dari fobi_checklist_taxas
        $taxaLocations = DB::table('fobi_checklist_taxas')
            ->where('taxa_id', $taxaId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('latitude', 'longitude')
            ->get();

        return $burungnesiaLocations->concat($kupunesiaLocations)
            ->concat($taxaLocations)
            ->map(function($item) {
                return [
                    'latitude' => (float) $item->latitude,
                    'longitude' => (float) $item->longitude,
                    'source' => 'fobi'
                ];
            });
    }

    private function getBurungnesiaLocations($taxaId)
    {
        return DB::connection('second')
            ->table('checklists')
            ->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
            ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
            ->where('checklist_fauna.fauna_id', $taxaId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('latitude', 'longitude')
            ->get()
            ->map(function($item) {
                return [
                    'latitude' => (float) $item->latitude,
                    'longitude' => (float) $item->longitude,
                    'source' => 'burungnesia'
                ];
            });
    }

    private function getKupunesiaLocations($taxaId)
    {
        return DB::connection('third')
            ->table('checklists')
            ->join('checklist_fauna', 'checklists.id', '=', 'checklist_fauna.checklist_id')
            ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
            ->where('checklist_fauna.fauna_id', $taxaId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('latitude', 'longitude')
            ->get()
            ->map(function($item) {
                return [
                    'latitude' => (float) $item->latitude,
                    'longitude' => (float) $item->longitude,
                    'source' => 'kupunesia'
                ];
            });
    }
}
