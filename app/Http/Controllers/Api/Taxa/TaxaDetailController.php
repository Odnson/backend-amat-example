<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Taxa;
use App\Models\FobiChecklistTaxa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaxaDetailController extends Controller
{
    // Mapping rank hierarchy untuk navigasi
    private $rankHierarchy = [
        'domain' => ['superkingdom', 'kingdom'],
        'superkingdom' => ['kingdom'],
        'kingdom' => ['subkingdom', 'superphylum', 'phylum'],
        'subkingdom' => ['superphylum', 'phylum'],
        'superphylum' => ['phylum'],
        'phylum' => ['subphylum', 'superclass', 'class'],
        'subphylum' => ['superclass', 'class'],
        'superclass' => ['class'],
        'class' => ['subclass', 'infraclass', 'superorder', 'order'],
        'subclass' => ['infraclass', 'superorder', 'order'],
        'infraclass' => ['superorder', 'order'],
        'superorder' => ['order'],
        'order' => ['suborder', 'infraorder', 'superfamily', 'family'],
        'suborder' => ['infraorder', 'superfamily', 'family'],
        'infraorder' => ['superfamily', 'family'],
        'superfamily' => ['family'],
        'family' => ['subfamily', 'supertribe', 'tribe', 'genus'],
        'subfamily' => ['supertribe', 'tribe', 'genus'],
        'supertribe' => ['tribe', 'genus'],
        'tribe' => ['subtribe', 'genus'],
        'subtribe' => ['genus'],
        'genus' => ['subgenus', 'species'],
        'subgenus' => ['species'],
        'species' => ['subspecies', 'variety', 'form'],
        'subspecies' => ['variety', 'form'],
        'variety' => ['form', 'subform'],
        'form' => ['subform']
    ];

    // Mapping untuk nama field common name
    private $commonNameFields = [
        'domain' => 'cname_domain',
        'superkingdom' => 'cname_superkingdom',
        'kingdom' => 'cname_kingdom',
        'subkingdom' => 'cname_subkingdom',
        'superphylum' => 'cname_superphylum',
        'phylum' => 'cname_phylum',
        'subphylum' => 'cname_subphylum',
        'superclass' => 'cname_superclass',
        'class' => 'cname_class',
        'subclass' => 'cname_subclass',
        'infraclass' => 'cname_infraclass',
        'magnorder' => 'cname_magnorder',
        'superorder' => 'cname_superorder',
        'order' => 'cname_order',
        'suborder' => 'cname_suborder',
        'infraorder' => 'cname_infraorder',
        'parvorder' => 'cname_parvorder',
        'superfamily' => 'cname_superfamily',
        'family' => 'cname_family',
        'subfamily' => 'cname_subfamily',
        'supertribe' => 'cname_supertribe',
        'tribe' => 'cname_tribe',
        'subtribe' => 'cname_subtribe',
        'genus' => 'cname_genus',
        'subgenus' => 'cname_subgenus',
        'species' => 'cname_species',
        'subspecies' => 'cname_subspecies',
        'variety' => 'cname_variety',
        'form' => 'cname_form',
        'subform' => 'cname_subform'
    ];

    /**
     * Get taxa detail by rank and ID
     */
    public function show(Request $request, $rank, $id)
    {
        try {
            // Validasi rank
            if (!array_key_exists($rank, $this->rankHierarchy) && !in_array($rank, array_values(array_merge(...array_values($this->rankHierarchy))))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid taxonomic rank'
                ], 400);
            }

            // Ambil data taxa utama
            $taxa = Taxa::where('id', $id)
                ->where('taxon_rank', $rank)
                ->first();

            if (!$taxa) {
                return response()->json([
                    'success' => false,
                    'message' => ucfirst($rank) . ' tidak ditemukan'
                ], 404);
            }

            // Ambil data detail
            $taxaDetail = $this->getTaxaDetail($taxa, $rank);
            
            // Ambil children taxa
            $children = $this->getChildrenTaxa($taxa, $rank);
            
            // Ambil media/observations
            $media = $this->getTaxaMedia($taxa);
            
            // Ambil taxonomy tree
            $taxonomyTree = $this->buildTaxonomyTree($taxa);

            // Ambil statistics
            $stats = $this->getTaxaStatistics($taxa, $rank);
            
            // Ambil synonyms
            $synonyms = $this->getSynonyms($taxa);

            return response()->json([
                'success' => true,
                'data' => [
                    'taxa' => $taxaDetail,
                    'children' => $children,
                    'media' => $media,
                    'taxonomy_tree' => $taxonomyTree,
                    'statistics' => $stats,
                    'synonyms' => $synonyms
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed taxa information
     */
    private function getTaxaDetail($taxa, $rank)
    {
        $commonNameField = $this->commonNameFields[$rank] ?? null;
        
        return [
            'id' => $taxa->id,
            'rank' => $rank,
            'scientific_name' => $taxa->scientific_name,
            'common_name' => $commonNameField ? $taxa->{$commonNameField} : null,
            'author' => $taxa->author,
            'taxonomic_status' => $taxa->taxonomic_status,
            'accepted_scientific_name' => $taxa->accepted_scientific_name,
            'description' => $taxa->description,
            'iucn_red_list_category' => $taxa->iucn_red_list_category ?: 'Tidak ada data',
            'cites_status' => $taxa->cites_status,
            'status_dilindungi' => $taxa->status_dilindungi,
            'hybrid' => $taxa->Hybrid,
            'introduced' => $taxa->Introduced,
            'eksotis' => $taxa->Eksotis,
            'metadata' => $taxa->metadata,
            'created_at' => $taxa->created_at,
            'updated_at' => $taxa->updated_at
        ];
    }

    /**
     * Get synonyms for a taxa based on accepted_scientific_name
     */
    private function getSynonyms($taxa)
    {
        try {
            // If this taxa has an accepted_scientific_name, find other synonyms with the same accepted name
            if ($taxa->accepted_scientific_name) {
                $synonyms = Taxa::where('accepted_scientific_name', $taxa->accepted_scientific_name)
                    ->where('taxonomic_status', 'SYNONYM')
                    ->where('id', '!=', $taxa->id) // Exclude current taxa
                    ->select('id', 'scientific_name', 'author', 'taxon_rank', 'taxonomic_status', 'created_at')
                    ->orderBy('scientific_name')
                    ->get();
                    
                return $synonyms;
            }
            
            // If this taxa is accepted, find all synonyms that reference it
            if ($taxa->taxonomic_status === 'ACCEPTED') {
                $synonyms = Taxa::where('accepted_scientific_name', $taxa->scientific_name)
                    ->where('taxonomic_status', 'SYNONYM')
                    ->select('id', 'scientific_name', 'author', 'taxon_rank', 'taxonomic_status', 'created_at')
                    ->orderBy('scientific_name')
                    ->get();
                    
                return $synonyms;
            }
            
            return collect();
            
        } catch (\Exception $e) {
            \Log::error('Error getting synonyms: ' . $e->getMessage());
            return collect();
        }
    }

    /**
     * Get children taxa for current rank
     */
    private function getChildrenTaxa($taxa, $rank)
    {
        $rank = strtolower($rank);
        
        // Only subform should not have children (end of hierarchy)
        if (in_array($rank, ['subform'])) {
            return [];
        }
        
        $childRanks = $this->rankHierarchy[$rank] ?? [];
        
        if (empty($childRanks)) {
            return [];
        }

        $children = [];
        
        foreach ($childRanks as $childRank) {
            $query = Taxa::where('taxon_rank', $childRank);
            
            // Build where conditions based on parent taxa using improved logic
            $this->addImprovedParentConditions($query, $taxa, $rank);
            
            $commonNameField = $this->commonNameFields[$childRank] ?? null;
            
            // Apply stricter limits based on rank to prevent explosion
            $limit = $this->getChildrenLimit($rank, $childRank);
            
            $childTaxa = $query->select(
                'id as taxa_id',
                $childRank,
                'scientific_name',
                $commonNameField ? $commonNameField . ' as common_name' : DB::raw('NULL as common_name'),
                'taxonomic_status',
                'author'
            )
            ->distinct()
            ->orderBy('scientific_name')
            ->limit($limit)
            ->get();

            if ($childTaxa->isNotEmpty()) {
                $children[$childRank] = $childTaxa;
            }
        }

        return $children;
    }

    /**
     * Add improved parent conditions to query for finding child taxa
     */
    private function addImprovedParentConditions($query, $taxa, $rank)
    {
        switch ($rank) {
            case 'kingdom':
                if ($taxa->kingdom) {
                    $query->where('kingdom', $taxa->kingdom);
                }
                break;
                
            case 'phylum':
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                break;
                
            case 'class':
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                if ($taxa->class) $query->where('class', $taxa->class);
                break;
                
            case 'order':
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                if ($taxa->class) $query->where('class', $taxa->class);
                if ($taxa->order) $query->where('order', $taxa->order);
                break;
                
            case 'family':
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                if ($taxa->class) $query->where('class', $taxa->class);
                if ($taxa->order) $query->where('order', $taxa->order);
                if ($taxa->family) $query->where('family', $taxa->family);
                break;
                
            case 'genus':
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                if ($taxa->class) $query->where('class', $taxa->class);
                if ($taxa->order) $query->where('order', $taxa->order);
                if ($taxa->family) $query->where('family', $taxa->family);
                if ($taxa->genus) $query->where('genus', $taxa->genus);
                break;
                
            case 'species':
                // For species looking for subspecies, use hierarchical matching
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                if ($taxa->class) $query->where('class', $taxa->class);
                if ($taxa->order) $query->where('order', $taxa->order);
                if ($taxa->family) $query->where('family', $taxa->family);
                if ($taxa->genus) $query->where('genus', $taxa->genus);
                if ($taxa->species) $query->where('species', $taxa->species);
                break;
                
            case 'subspecies':
                // For subspecies looking for varieties, use hierarchical matching
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                if ($taxa->class) $query->where('class', $taxa->class);
                if ($taxa->order) $query->where('order', $taxa->order);
                if ($taxa->family) $query->where('family', $taxa->family);
                if ($taxa->genus) $query->where('genus', $taxa->genus);
                if ($taxa->species) $query->where('species', $taxa->species);
                if ($taxa->subspecies) $query->where('subspecies', $taxa->subspecies);
                break;
                
            case 'variety':
                // For varieties looking for forms, use hierarchical matching
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                if ($taxa->class) $query->where('class', $taxa->class);
                if ($taxa->order) $query->where('order', $taxa->order);
                if ($taxa->family) $query->where('family', $taxa->family);
                if ($taxa->genus) $query->where('genus', $taxa->genus);
                if ($taxa->species) $query->where('species', $taxa->species);
                if ($taxa->subspecies) $query->where('subspecies', $taxa->subspecies);
                if ($taxa->variety) $query->where('variety', $taxa->variety);
                break;
                
            case 'form':
                // For forms looking for subforms, use hierarchical matching
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                if ($taxa->class) $query->where('class', $taxa->class);
                if ($taxa->order) $query->where('order', $taxa->order);
                if ($taxa->family) $query->where('family', $taxa->family);
                if ($taxa->genus) $query->where('genus', $taxa->genus);
                if ($taxa->species) $query->where('species', $taxa->species);
                if ($taxa->subspecies) $query->where('subspecies', $taxa->subspecies);
                if ($taxa->variety) $query->where('variety', $taxa->variety);
                if ($taxa->form) $query->where('form', $taxa->form);
                break;
                
            default:
                // For other ranks, use dynamic field matching
                $hierarchyFields = [
                    'domain', 'superkingdom', 'kingdom', 'subkingdom', 'superphylum', 'phylum', 
                    'subphylum', 'superclass', 'class', 'subclass', 'infraclass', 'superorder', 
                    'order', 'suborder', 'infraorder', 'superfamily', 'family', 'subfamily', 
                    'supertribe', 'tribe', 'subtribe', 'genus', 'subgenus'
                ];
                
                $currentRankIndex = array_search($rank, $hierarchyFields);
                
                if ($currentRankIndex !== false) {
                    for ($i = 0; $i <= $currentRankIndex; $i++) {
                        $field = $hierarchyFields[$i];
                        if (isset($taxa->{$field}) && $taxa->{$field}) {
                            $query->where($field, $taxa->{$field});
                        }
                    }
                }
                break;
        }
    }
    
    /**
     * Add parent conditions to query (legacy method for backward compatibility)
     */
    private function addParentConditions($query, $taxa, $rank)
    {
        $this->addImprovedParentConditions($query, $taxa, $rank);
    }

    /**
     * Get media for taxa (including from child taxa for higher ranks)
     */
    private function getTaxaMedia($taxa)
    {
        $rank = strtolower($taxa->taxon_rank);
        
        // For species level, get direct media only
        if ($rank === 'species') {
            return DB::table('fobi_checklist_taxas as fct')
                ->join('fobi_checklist_media as fcm', 'fct.id', '=', 'fcm.checklist_id')
                ->where('fct.taxa_id', $taxa->id)
                ->select(
                    'fcm.id',
                    'fcm.file_path',
                    'fcm.spectrogram',
                    'fcm.storage_type',
                    'fcm.habitat',
                    'fcm.location',
                    'fcm.date',
                    'fcm.description as observation_notes'
                )
                ->limit(20)
                ->get()
                ->map(function($item) {
                    return $this->formatMediaUrl($item);
                });
        }
        
        // For subspecies, variety, form - get direct media only (no children)
        if (in_array($rank, ['subspecies', 'variety', 'form', 'subform'])) {
            return DB::table('fobi_checklist_taxas as fct')
                ->join('fobi_checklist_media as fcm', 'fct.id', '=', 'fcm.checklist_id')
                ->where('fct.taxa_id', $taxa->id)
                ->select(
                    'fcm.id',
                    'fcm.file_path',
                    'fcm.spectrogram',
                    'fcm.storage_type',
                    'fcm.habitat',
                    'fcm.location',
                    'fcm.date',
                    'fcm.description as observation_notes'
                )
                ->limit(20)
                ->get()
                ->map(function($item) {
                    return $this->formatMediaUrl($item);
                });
        }

        // For higher taxonomic ranks, get media from descendant species only
        $descendantSpecies = $this->getDescendantSpecies($taxa, $rank);
        
        if (empty($descendantSpecies)) {
            return collect([]);
        }

        return DB::table('fobi_checklist_taxas as fct')
            ->join('fobi_checklist_media as fcm', 'fct.id', '=', 'fcm.checklist_id')
            ->whereIn('fct.taxa_id', $descendantSpecies)
            ->select(
                'fcm.id',
                'fcm.file_path',
                'fcm.spectrogram',
                'fcm.storage_type',
                'fcm.habitat',
                'fcm.location',
                'fcm.date',
                'fcm.description as observation_notes'
            )
            ->orderBy(DB::raw('RAND()'))
            ->limit(50)
            ->get()
            ->map(function($item) {
                return $this->formatMediaUrl($item);
            });
    }

    /**
     * Format media URLs based on storage type
     */
    private function formatMediaUrl($item)
    {
        $storageType = $item->storage_type ?? 'local';
        $baseUrl = config('app.url');
        $s3Url = config('filesystems.disks.s3.url');
        
        // Format file_path
        if ($item->file_path) {
            if ($storageType === 's3' && $s3Url) {
                // Jika sudah URL lengkap, biarkan
                if (!str_starts_with($item->file_path, 'http')) {
                    $item->file_path = rtrim($s3Url, '/') . '/' . ltrim($item->file_path, '/');
                }
            } else {
                // Local storage
                if (!str_starts_with($item->file_path, 'http')) {
                    $item->file_path = rtrim($baseUrl, '/') . '/storage/' . ltrim($item->file_path, '/');
                }
            }
        }
        
        // Format spectrogram
        if ($item->spectrogram) {
            if ($storageType === 's3' && $s3Url) {
                if (!str_starts_with($item->spectrogram, 'http')) {
                    $item->spectrogram = rtrim($s3Url, '/') . '/' . ltrim($item->spectrogram, '/');
                }
            } else {
                if (!str_starts_with($item->spectrogram, 'http')) {
                    $item->spectrogram = rtrim($baseUrl, '/') . '/storage/' . ltrim($item->spectrogram, '/');
                }
            }
        }
        
        return $item;
    }

    /**
     * Add taxonomic conditions to query based on taxa rank
     */
    private function addTaxaConditions($query, $taxa)
    {
        $rank = $taxa->taxon_rank;
        
        switch ($rank) {
            case 'kingdom':
                if ($taxa->kingdom) {
                    $query->where('t.kingdom', $taxa->kingdom);
                }
                break;
            case 'phylum':
                if ($taxa->kingdom) $query->where('t.kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('t.phylum', $taxa->phylum);
                break;
            case 'class':
                if ($taxa->kingdom) $query->where('t.kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('t.phylum', $taxa->phylum);
                if ($taxa->class) $query->where('t.class', $taxa->class);
                break;
            case 'order':
                if ($taxa->kingdom) $query->where('t.kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('t.phylum', $taxa->phylum);
                if ($taxa->class) $query->where('t.class', $taxa->class);
                if ($taxa->order) $query->where('t.order', $taxa->order);
                break;
            case 'family':
                if ($taxa->kingdom) $query->where('t.kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('t.phylum', $taxa->phylum);
                if ($taxa->class) $query->where('t.class', $taxa->class);
                if ($taxa->order) $query->where('t.order', $taxa->order);
                if ($taxa->family) $query->where('t.family', $taxa->family);
                break;
            case 'genus':
                if ($taxa->kingdom) $query->where('t.kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('t.phylum', $taxa->phylum);
                if ($taxa->class) $query->where('t.class', $taxa->class);
                if ($taxa->order) $query->where('t.order', $taxa->order);
                if ($taxa->family) $query->where('t.family', $taxa->family);
                if ($taxa->genus) $query->where('t.genus', $taxa->genus);
                break;
            default:
                // For other ranks, use dynamic field matching
                $hierarchyFields = [
                    'domain', 'superkingdom', 'kingdom', 'subkingdom', 'superphylum', 'phylum', 
                    'subphylum', 'superclass', 'class', 'subclass', 'infraclass', 'superorder', 
                    'order', 'suborder', 'infraorder', 'superfamily', 'family', 'subfamily', 
                    'supertribe', 'tribe', 'subtribe', 'genus', 'subgenus'
                ];
                
                $currentRankIndex = array_search($rank, $hierarchyFields);
                
                if ($currentRankIndex !== false) {
                    for ($i = 0; $i <= $currentRankIndex; $i++) {
                        $field = $hierarchyFields[$i];
                        if (isset($taxa->{$field}) && $taxa->{$field}) {
                            $query->where("t.{$field}", $taxa->{$field});
                        }
                    }
                }
                break;
        }
    }

    /**
     * Build taxonomy tree for navigation
     */
    private function buildTaxonomyTree($taxa)
    {
        $tree = [];
        $hierarchyFields = [
            'domain', 'superkingdom', 'kingdom', 'subkingdom', 'superphylum', 'phylum', 
            'subphylum', 'superclass', 'class', 'subclass', 'infraclass', 'superorder', 
            'order', 'suborder', 'infraorder', 'superfamily', 'family', 'subfamily', 
            'supertribe', 'tribe', 'subtribe', 'genus', 'subgenus', 'species', 
            'subspecies', 'variety', 'form', 'subform'
        ];

        foreach ($hierarchyFields as $field) {
            if ($taxa->{$field}) {
                $commonNameField = $this->commonNameFields[$field] ?? null;
                $commonName = $commonNameField ? $taxa->{$commonNameField} : null;
                
                // Find taxa ID for this rank with improved specificity
                $rankTaxaData = $this->findTaxaByRankAndHierarchy($taxa, $field, $hierarchyFields);
                
                $tree[] = [
                    'rank' => $field,
                    'name' => $taxa->{$field},
                    'common_name' => $commonName,
                    'taxa_id' => $rankTaxaData ? $rankTaxaData->id : null,
                    'is_current' => $taxa->taxon_rank === $field
                ];
            }
        }

        return $tree;
    }

    /**
     * Find taxa by rank and hierarchy with improved specificity
     */
    private function findTaxaByRankAndHierarchy($taxa, $targetRank, $hierarchyFields)
    {
        $currentIndex = array_search($targetRank, $hierarchyFields);
        
        if ($currentIndex === false) {
            return null;
        }
        
        // Start with basic query
        $query = Taxa::where('taxon_rank', $targetRank)
            ->where($targetRank, $taxa->{$targetRank});
        
        // Add all available parent conditions for maximum specificity
        for ($i = 0; $i < $currentIndex; $i++) {
            $field = $hierarchyFields[$i];
            if ($taxa->{$field}) {
                $query->where($field, $taxa->{$field});
            }
        }
        
        // First try: exact match with all parent conditions
        $result = $query->first();
        
        if ($result) {
            \Log::info("Found taxa with full hierarchy for {$targetRank}: {$taxa->{$targetRank}}", [
                'found_id' => $result->id,
                'rank' => $targetRank
            ]);
            return $result;
        }
        
        // Second try: reduce specificity by removing some parent conditions
        // This handles cases where parent taxa might not be perfectly aligned
        $essentialFields = ['kingdom', 'phylum', 'class', 'order', 'family', 'genus'];
        $query = Taxa::where('taxon_rank', $targetRank)
            ->where($targetRank, $taxa->{$targetRank});
        
        for ($i = 0; $i < $currentIndex; $i++) {
            $field = $hierarchyFields[$i];
            if ($taxa->{$field} && in_array($field, $essentialFields)) {
                $query->where($field, $taxa->{$field});
            }
        }
        
        $result = $query->first();
        
        if ($result) {
            \Log::info("Found taxa with essential hierarchy for {$targetRank}: {$taxa->{$targetRank}}", [
                'found_id' => $result->id,
                'rank' => $targetRank
            ]);
            return $result;
        }
        
        // Third try: just by rank and name (least specific)
        $result = Taxa::where('taxon_rank', $targetRank)
            ->where($targetRank, $taxa->{$targetRank})
            ->first();
        
        if ($result) {
            \Log::info("Found taxa with basic match for {$targetRank}: {$taxa->{$targetRank}}", [
                'found_id' => $result->id,
                'rank' => $targetRank
            ]);
        } else {
            \Log::warning("No taxa found for {$targetRank}: {$taxa->{$targetRank}}");
        }
        
        return $result;
    }
    
    /**
     * Add parent conditions for tree building (deprecated - replaced by findTaxaByRankAndHierarchy)
     */
    private function addParentConditionsForTree($query, $taxa, $currentField, $hierarchyFields)
    {
        $currentIndex = array_search($currentField, $hierarchyFields);
        
        if ($currentIndex !== false) {
            for ($i = 0; $i < $currentIndex; $i++) {
                $field = $hierarchyFields[$i];
                if ($taxa->{$field}) {
                    $query->where($field, $taxa->{$field});
                }
            }
        }
    }

    /**
     * Get statistics for taxa (improved for higher taxonomic ranks)
     */
    private function getTaxaStatistics($taxa, $rank)
    {
        $rank = strtolower($rank);
        $stats = [
            'total_observations' => 0,
            'total_media' => 0,
            'total_children' => 0,
            'conservation_status' => $taxa->iucn_red_list_category ?: 'Tidak ada data'
        ];

        // For species level, count direct observations and media
        if ($rank === 'species') {
            $stats['total_observations'] = FobiChecklistTaxa::where('taxa_id', $taxa->id)->count();
            $stats['total_media'] = DB::table('fobi_checklist_taxas as fct')
                ->join('fobi_checklist_media as fcm', 'fct.id', '=', 'fcm.checklist_id')
                ->where('fct.taxa_id', $taxa->id)
                ->count();
        } elseif (in_array($rank, ['subspecies', 'variety', 'form', 'subform'])) {
            // For subspecies and below, count direct observations and media only
            $stats['total_observations'] = FobiChecklistTaxa::where('taxa_id', $taxa->id)->count();
            $stats['total_media'] = DB::table('fobi_checklist_taxas as fct')
                ->join('fobi_checklist_media as fcm', 'fct.id', '=', 'fcm.checklist_id')
                ->where('fct.taxa_id', $taxa->id)
                ->count();
        } else {
            // For higher ranks, count observations and media from descendant species only
            $descendantSpecies = $this->getDescendantSpecies($taxa, $rank);
            
            if (!empty($descendantSpecies)) {
                $stats['total_observations'] = FobiChecklistTaxa::whereIn('taxa_id', $descendantSpecies)->count();
                $stats['total_media'] = DB::table('fobi_checklist_taxas as fct')
                    ->join('fobi_checklist_media as fcm', 'fct.id', '=', 'fcm.checklist_id')
                    ->whereIn('fct.taxa_id', $descendantSpecies)
                    ->count();
            }
        }

        // Count children taxa using improved method (only if not subform)
        if (!in_array($rank, ['subform'])) {
            $childRanks = $this->rankHierarchy[$rank] ?? [];
            foreach ($childRanks as $childRank) {
                $query = Taxa::where('taxon_rank', $childRank);
                $this->addImprovedParentConditions($query, $taxa, $rank);
                $limit = $this->getChildrenLimit($rank, $childRank);
                $count = $query->limit($limit)->count();
                $stats['total_children'] += $count;
            }
        }

        return $stats;
    }

    /**
     * Search taxa by rank and name
     */
    public function search(Request $request, $rank)
    {
        try {
            $query = $request->get('q', '');
            $limit = min($request->get('limit', 20), 100);
            $page = max($request->get('page', 1), 1);
            $offset = ($page - 1) * $limit;

            $commonNameField = $this->commonNameFields[$rank] ?? null;

            $queryBuilder = Taxa::where('taxon_rank', $rank);

            // If query is provided, add search conditions
            if (strlen($query) >= 2) {
                $queryBuilder->where(function($q) use ($query, $rank, $commonNameField) {
                    $q->where($rank, 'LIKE', "%{$query}%")
                      ->orWhere('scientific_name', 'LIKE', "%{$query}%");
                    
                    if ($commonNameField) {
                        $q->orWhere($commonNameField, 'LIKE', "%{$query}%");
                    }
                });
            } elseif (strlen($query) > 0 && strlen($query) < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Query minimal 2 karakter'
                ], 400);
            }

            $taxa = $queryBuilder
                ->select(
                    'id as taxa_id',
                    $rank,
                    'scientific_name',
                    $commonNameField ? $commonNameField . ' as common_name' : DB::raw('NULL as common_name'),
                    'taxonomic_status',
                    'author'
                )
                ->orderBy('scientific_name')
                ->offset($offset)
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $taxa,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'has_more' => $taxa->count() === $limit
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }
     /**
     * Get taxa distribution data
     */
    public function getDistribution($rank, $id)
    {
        try {
            // Get taxa info first
            $taxa = Taxa::where('id', $id)
                ->where('taxon_rank', $rank)
                ->first();

            if (!$taxa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Taxa tidak ditemukan'
                ], 404);
            }

            // Get distribution based on rank
            $allLocations = collect([]);

            // 1. Query untuk FOBi (database default) - menggunakan fobi_checklist_taxas
            try {
                $fobiLocations = DB::table('fobi_checklist_taxas as fct')
                    ->distinct()
                    ->where(function($query) use ($taxa, $rank) {
                        if ($rank === 'species') {
                            $query->where('fct.taxa_id', $taxa->id)
                                  ->orWhere('fct.scientific_name', 'LIKE', $taxa->scientific_name . '%');
                        } elseif (in_array($rank, ['subspecies', 'variety', 'form', 'subform'])) {
                            // For subspecies and below, show only direct matches
                            $query->where('fct.taxa_id', $taxa->id);
                        } else {
                            // For higher ranks, find species that belong to this specific taxa
                            // Get all descendant species of this taxa
                            $descendantSpecies = DB::table('taxas')
                                ->where($rank, $taxa->$rank)
                                ->where('taxon_rank', 'species')
                                ->whereNotNull($rank)
                                ->pluck('id')
                                ->toArray();
                            
                            if (!empty($descendantSpecies)) {
                                $query->whereIn('fct.taxa_id', $descendantSpecies);
                            } else {
                                // Fallback: no descendant species found
                                $query->where('fct.taxa_id', -1); // This will return no results
                            }
                        }
                    })
                    ->whereNotNull('fct.latitude')
                    ->whereNotNull('fct.longitude')
                    ->select(
                        'fct.latitude',
                        'fct.longitude',
                        'fct.id',
                        'fct.created_at',
                        'fct.scientific_name as matched_name'
                    )
                    ->get()
                    ->map(function($item) {
                        return [
                            'latitude' => (float) $item->latitude,
                            'longitude' => (float) $item->longitude,
                            'id' => 'fobi_' . $item->id,
                            'created_at' => $item->created_at,
                            'source' => 'fobi',
                            'matched_name' => $item->matched_name
                        ];
                    });

                $allLocations = $allLocations->concat($fobiLocations);
                \Log::info('FOBI locations found for taxa:', [
                    'rank' => $rank,
                    'id' => $id,
                    'count' => $fobiLocations->count()
                ]);
            } catch (\Exception $e) {
                \Log::error('FOBi query error:', ['error' => $e->getMessage()]);
            }

            // Get additional data for species-level queries
            $classData = $taxa->class ?? '';
            $burnes_fauna_id = $taxa->burnes_fauna_id ?? null;
            $kupnes_fauna_id = $taxa->kupnes_fauna_id ?? null;
            $genus = $taxa->genus ?? '';
            $species = $taxa->species ?? '';
            
            // Extract species without author name for better matching
            $speciesWithoutAuthor = preg_replace('/\s+\([^)]+\)/', '', $taxa->scientific_name);

            // 2. Query untuk Burungnesia - always try, not just for Aves
            try {
                $burungnesiaLocations = collect([]);
                
                if ($rank === 'species') {
                    // For species, use the same logic as SpeciesGalleryController
                    $burungnesiaQuery = DB::connection('second')
                        ->table('checklist_fauna')
                        ->distinct()
                        ->join('checklists', 'checklist_fauna.checklist_id', '=', 'checklists.id')
                        ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                        ->whereNotNull('checklists.latitude')
                        ->whereNotNull('checklists.longitude')
                        ->whereNull('checklists.deleted_at');

                    // Use fauna ID if available, otherwise use name-based search
                    if (!empty($burnes_fauna_id)) {
                        $burungnesiaQuery->where('checklist_fauna.fauna_id', $burnes_fauna_id);
                        \Log::info('Searching Burungnesia with ID:', ['burnes_fauna_id' => $burnes_fauna_id]);
                    } else {
                        $burungnesiaQuery->where(function($query) use ($speciesWithoutAuthor, $genus, $species) {
                            $query->where('faunas.nameLat', 'LIKE', $speciesWithoutAuthor . '%')
                                  ->orWhere('faunas.nameLat', 'LIKE', $genus . ' ' . $species . '%')
                                  ->orWhere('faunas.nameLat', 'LIKE', $genus . '%');
                        });
                        \Log::info('Searching Burungnesia with names:', [
                            'scientific_name' => $speciesWithoutAuthor,
                            'genus_species' => $genus . ' ' . $species,
                            'genus' => $genus
                        ]);
                    }
                    
                    $burungnesiaLocations = $burungnesiaQuery
                        ->select(
                            'checklists.latitude',
                            'checklists.longitude',
                            'checklists.id',
                            'checklists.created_at',
                            'faunas.nameLat as matched_name'
                        )
                        ->get();
                } elseif (in_array($rank, ['subspecies', 'variety', 'form', 'subform'])) {
                    // For subspecies and below, search by exact scientific name
                    $burungnesiaLocations = DB::connection('second')
                        ->table('checklist_fauna')
                        ->distinct()
                        ->join('checklists', 'checklist_fauna.checklist_id', '=', 'checklists.id')
                        ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                        ->where('faunas.nameLat', 'LIKE', $taxa->scientific_name . '%')
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
                        ->get();
                } else {
                    // For higher ranks, try direct taxonomic name search first (more efficient)
                    $taxonValue = $taxa->$rank;
                    
                    \Log::info('Burungnesia higher rank query:', [
                        'rank' => $rank,
                        'taxa_name' => $taxa->scientific_name,
                        'taxon_value' => $taxonValue
                    ]);
                    
                    // Try direct search by taxonomic name first (like MarkerController approach)
                    try {
                        $burungnesiaLocations = DB::connection('second')
                            ->table('checklist_fauna')
                            ->distinct()
                            ->join('checklists', 'checklist_fauna.checklist_id', '=', 'checklists.id')
                            ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                            ->where('faunas.nameLat', 'LIKE', $taxonValue . '%')
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
                            ->limit(2000) // Reasonable limit for direct search
                            ->get();
                        
                        \Log::info('Burungnesia direct search results:', [
                            'rank' => $rank,
                            'search_term' => $taxonValue,
                            'results_count' => $burungnesiaLocations->count()
                        ]);
                        
                    } catch (\Exception $e) {
                        \Log::error('Burungnesia direct search failed:', [
                            'error' => $e->getMessage(),
                            'rank' => $rank,
                            'search_term' => $taxonValue
                        ]);
                        $burungnesiaLocations = collect([]);
                    }
                    
                    // If direct search yields few results, try descendant species approach
                    if ($burungnesiaLocations->count() < 10) {
                        \Log::info('Direct search yielded few results, trying descendant species approach');
                        
                        $descendantSpeciesQuery = DB::table('taxas')
                            ->where($rank, $taxa->$rank)
                            ->where('taxon_rank', 'species')
                            ->whereNotNull($rank);
                        
                        $totalSpeciesCount = $descendantSpeciesQuery->count();
                        
                        \Log::info('Descendant species count:', [
                            'total_species_count' => $totalSpeciesCount
                        ]);
                        
                        // Skip descendant species query if too many species (performance protection)
                        if ($totalSpeciesCount > 500) {
                            \Log::warning('Skipping Burungnesia query - too many descendant species:', [
                                'rank' => $rank,
                                'taxa_name' => $taxa->scientific_name,
                                'species_count' => $totalSpeciesCount,
                                'limit' => 500
                            ]);
                        } else {
                        $descendantSpecies = $descendantSpeciesQuery
                            ->pluck('scientific_name')
                            ->toArray();
                        
                        if (!empty($descendantSpecies)) {
                            // Process in smaller batches to avoid query timeout
                            $batchSize = 50;
                            $speciesBatches = array_chunk($descendantSpecies, $batchSize);
                            $burungnesiaLocations = collect([]);
                            
                            foreach ($speciesBatches as $batchIndex => $speciesBatch) {
                                try {
                                    $batchLocations = DB::connection('second')
                                        ->table('checklist_fauna')
                                        ->distinct()
                                        ->join('checklists', 'checklist_fauna.checklist_id', '=', 'checklists.id')
                                        ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                                        ->where(function($query) use ($speciesBatch) {
                                            foreach ($speciesBatch as $speciesName) {
                                                $query->orWhere('faunas.nameLat', 'LIKE', $speciesName . '%');
                                            }
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
                                        ->limit(1000) // Limit results per batch
                                        ->get();
                                    
                                    $burungnesiaLocations = $burungnesiaLocations->concat($batchLocations);
                                    
                                    \Log::info('Burungnesia batch processed:', [
                                        'batch' => $batchIndex + 1,
                                        'batch_size' => count($speciesBatch),
                                        'locations_found' => $batchLocations->count(),
                                        'total_locations' => $burungnesiaLocations->count()
                                    ]);
                                    
                                } catch (\Exception $e) {
                                    \Log::error('Burungnesia batch query error:', [
                                        'batch' => $batchIndex + 1,
                                        'error' => $e->getMessage()
                                    ]);
                                    continue; // Skip this batch and continue with next
                                }
                            }
                        }
                        }
                    }
                }
                    
                $burungnesiaLocations = $burungnesiaLocations
                    ->unique('id')
                    ->map(function($item) {
                        return [
                            'latitude' => (float) $item->latitude,
                            'longitude' => (float) $item->longitude,
                            'id' => 'brn_' . $item->id,
                            'created_at' => $item->created_at,
                            'source' => 'burungnesia',
                            'matched_name' => $item->matched_name
                        ];
                    });

                $allLocations = $allLocations->concat($burungnesiaLocations);
                \Log::info('Burungnesia locations found for taxa:', [
                    'rank' => $rank,
                    'id' => $id,
                    'scientific_name' => $taxa->scientific_name,
                    'taxa_name' => $taxa->scientific_name,
                    'count' => $burungnesiaLocations->count(),
                    'matched_names' => $burungnesiaLocations->pluck('matched_name')->unique()->take(5)
                ]);
            } catch (\Exception $e) {
                \Log::error('Burungnesia query error:', [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            }

            // 3. Query untuk Kupunesia - always try, not just for Insecta/Lepidoptera
            try {
                $kupunesiaLocations = collect([]);
                
                if ($rank === 'species') {
                    // For species, use the same logic as SpeciesGalleryController
                    $kupunesiaQuery = DB::connection('third')
                        ->table('checklist_fauna')
                        ->distinct()
                        ->join('checklists', 'checklist_fauna.checklist_id', '=', 'checklists.id')
                        ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                        ->whereNotNull('checklists.latitude')
                        ->whereNotNull('checklists.longitude')
                        ->whereNull('checklists.deleted_at');

                    // Use fauna ID if available, otherwise use name-based search
                    if (!empty($kupnes_fauna_id)) {
                        $kupunesiaQuery->where('checklist_fauna.fauna_id', $kupnes_fauna_id);
                        \Log::info('Searching Kupunesia with ID:', ['kupnes_fauna_id' => $kupnes_fauna_id]);
                    } else {
                        $kupunesiaQuery->where(function($query) use ($speciesWithoutAuthor, $genus, $species) {
                            $query->where('faunas.nameLat', 'LIKE', $speciesWithoutAuthor . '%')
                                  ->orWhere('faunas.nameLat', 'LIKE', $genus . ' ' . $species . '%')
                                  ->orWhere('faunas.nameLat', 'LIKE', $genus . '%');
                        });
                        \Log::info('Searching Kupunesia with names:', [
                            'scientific_name' => $speciesWithoutAuthor,
                            'genus_species' => $genus . ' ' . $species,
                            'genus' => $genus
                        ]);
                    }
                    
                    $kupunesiaLocations = $kupunesiaQuery
                        ->select(
                            'checklists.latitude',
                            'checklists.longitude',
                            'checklists.id',
                            'checklists.created_at',
                            'faunas.nameLat as matched_name'
                        )
                        ->get();
                } elseif (in_array($rank, ['subspecies', 'variety', 'form', 'subform'])) {
                    // For subspecies and below, search by exact scientific name
                    $kupunesiaLocations = DB::connection('third')
                        ->table('checklist_fauna')
                        ->distinct()
                        ->join('checklists', 'checklist_fauna.checklist_id', '=', 'checklists.id')
                        ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                        ->where('faunas.nameLat', 'LIKE', $taxa->scientific_name . '%')
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
                        ->get();
                } else {
                    // For higher ranks, try direct taxonomic name search first (more efficient)
                    $taxonValue = $taxa->$rank;
                    
                    \Log::info('Kupunesia higher rank query:', [
                        'rank' => $rank,
                        'taxa_name' => $taxa->scientific_name,
                        'taxon_value' => $taxonValue
                    ]);
                    
                    // Try direct search by taxonomic name first (like MarkerController approach)
                    try {
                        $kupunesiaLocations = DB::connection('third')
                            ->table('checklist_fauna')
                            ->distinct()
                            ->join('checklists', 'checklist_fauna.checklist_id', '=', 'checklists.id')
                            ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                            ->where('faunas.nameLat', 'LIKE', $taxonValue . '%')
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
                            ->limit(2000) // Reasonable limit for direct search
                            ->get();
                        
                        \Log::info('Kupunesia direct search results:', [
                            'rank' => $rank,
                            'search_term' => $taxonValue,
                            'results_count' => $kupunesiaLocations->count()
                        ]);
                        
                    } catch (\Exception $e) {
                        \Log::error('Kupunesia direct search failed:', [
                            'error' => $e->getMessage(),
                            'rank' => $rank,
                            'search_term' => $taxonValue
                        ]);
                        $kupunesiaLocations = collect([]);
                    }
                    
                    // If direct search yields few results, try descendant species approach
                    if ($kupunesiaLocations->count() < 10) {
                        \Log::info('Direct search yielded few results, trying descendant species approach');
                        
                        $descendantSpeciesQuery = DB::table('taxas')
                            ->where($rank, $taxa->$rank)
                            ->where('taxon_rank', 'species')
                            ->whereNotNull($rank);
                        
                        $totalSpeciesCount = $descendantSpeciesQuery->count();
                        
                        \Log::info('Descendant species count:', [
                            'total_species_count' => $totalSpeciesCount
                        ]);
                        
                        // Skip descendant species query if too many species (performance protection)
                        if ($totalSpeciesCount > 500) {
                            \Log::warning('Skipping Kupunesia query - too many descendant species:', [
                                'rank' => $rank,
                                'taxa_name' => $taxa->scientific_name,
                                'species_count' => $totalSpeciesCount,
                                'limit' => 500
                            ]);
                        } else {
                        $descendantSpecies = $descendantSpeciesQuery
                            ->pluck('scientific_name')
                            ->toArray();
                        
                        if (!empty($descendantSpecies)) {
                            // Process in smaller batches to avoid query timeout
                            $batchSize = 50;
                            $speciesBatches = array_chunk($descendantSpecies, $batchSize);
                            $kupunesiaLocations = collect([]);
                            
                            foreach ($speciesBatches as $batchIndex => $speciesBatch) {
                                try {
                                    $batchLocations = DB::connection('third')
                                        ->table('checklist_fauna')
                                        ->distinct()
                                        ->join('checklists', 'checklist_fauna.checklist_id', '=', 'checklists.id')
                                        ->join('faunas', 'checklist_fauna.fauna_id', '=', 'faunas.id')
                                        ->where(function($query) use ($speciesBatch) {
                                            foreach ($speciesBatch as $speciesName) {
                                                $query->orWhere('faunas.nameLat', 'LIKE', $speciesName . '%');
                                            }
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
                                        ->limit(1000) // Limit results per batch
                                        ->get();
                                    
                                    $kupunesiaLocations = $kupunesiaLocations->concat($batchLocations);
                                    
                                    \Log::info('Kupunesia batch processed:', [
                                        'batch' => $batchIndex + 1,
                                        'batch_size' => count($speciesBatch),
                                        'locations_found' => $batchLocations->count(),
                                        'total_locations' => $kupunesiaLocations->count()
                                    ]);
                                    
                                } catch (\Exception $e) {
                                    \Log::error('Kupunesia batch query error:', [
                                        'batch' => $batchIndex + 1,
                                        'error' => $e->getMessage()
                                    ]);
                                    continue; // Skip this batch and continue with next
                                }
                            }
                        }
                        }
                    }
                }
                    
                $kupunesiaLocations = $kupunesiaLocations
                    ->unique('id')
                    ->map(function($item) {
                        return [
                            'latitude' => (float) $item->latitude,
                            'longitude' => (float) $item->longitude,
                            'id' => 'kpn_' . $item->id,
                            'created_at' => $item->created_at,
                            'source' => 'kupunesia',
                            'matched_name' => $item->matched_name
                        ];
                    });

                $allLocations = $allLocations->concat($kupunesiaLocations);
                \Log::info('Kupunesia locations found for taxa:', [
                    'rank' => $rank,
                    'id' => $id,
                    'scientific_name' => $taxa->scientific_name,
                    'taxa_name' => $taxa->scientific_name,
                    'count' => $kupunesiaLocations->count(),
                    'matched_names' => $kupunesiaLocations->pluck('matched_name')->unique()->take(5)
                ]);
            } catch (\Exception $e) {
                \Log::error('Kupunesia query error:', [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            }

            // Ensure unique locations and filter invalid coordinates
            $uniqueLocations = $allLocations->unique(function ($item) {
                return $item['latitude'] . '_' . $item['longitude'] . '_' . $item['source'];
            })->values();

            $validLocations = $uniqueLocations->filter(function ($item) {
                $lat = $item['latitude']; 
                $lng = $item['longitude'];
                return is_numeric($lat) && is_numeric($lng) &&
                       $lat >= -90 && $lat <= 90 && 
                       $lng >= -180 && $lng <= 180;
            })->values();

            return response()->json([
                'success' => true,
                'data' => $validLocations
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error in getTaxaDistribution:', [
                'rank' => $rank,
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get similar taxa
     */
    public function getSimilar($rank, $id)
    {
        try {
            $taxa = Taxa::where('id', $id)
                ->where('taxon_rank', $rank)
                ->first();

            if (!$taxa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Taxa tidak ditemukan'
                ], 404);
            }

            // Try to get from taxa_similar_identifications first
            $similarFromHistory = DB::table('taxa_similar_identifications as tsi')
                ->join('taxas as t', function($join) use ($id) {
                    $join->on('t.id', '=', 'tsi.similar_taxa_id')
                         ->where('tsi.taxa_id', '=', $id)
                         ->orOn('t.id', '=', 'tsi.taxa_id')
                         ->where('tsi.similar_taxa_id', '=', $id);
                })
                ->where('t.id', '!=', $id)
                ->where('t.taxon_rank', $rank)
                ->select(
                    't.id',
                    't.scientific_name',
                    $this->commonNameFields[$rank] ? 't.' . $this->commonNameFields[$rank] . ' as common_name' : DB::raw('NULL as common_name'),
                    't.family',
                    't.genus',
                    't.taxonomic_status',
                    'tsi.confusion_count',
                    'tsi.similarity_type',
                    'tsi.notes'
                )
                ->orderBy('tsi.confusion_count', 'desc')
                ->limit(6)
                ->get();

            // If data from history is less than 6, add from the same family/genus
            if ($similarFromHistory->count() < 6) {
                $needed = 6 - $similarFromHistory->count();
                
                // Get similar taxa from same family or genus
                $similarFromFamily = DB::table('taxas')
                    ->where(function($query) use ($taxa, $rank) {
                        if ($rank === 'species' && $taxa->genus) {
                            $query->where('genus', $taxa->genus)
                                  ->where('taxon_rank', 'species');
                        } else {
                            $query->where('family', $taxa->family)
                                  ->where('taxon_rank', $rank);
                        }
                    })
                    ->where('id', '!=', $id)
                    ->whereNotIn('id', $similarFromHistory->pluck('id'))
                    ->select(
                        'id',
                        'scientific_name',
                        $this->commonNameFields[$rank] ? $this->commonNameFields[$rank] . ' as common_name' : DB::raw('NULL as common_name'),
                        'family',
                        'genus',
                        'taxonomic_status'
                    )
                    ->limit($needed)
                    ->get()
                    ->map(function($item) use ($rank, $taxa) {
                        $similarity_type = ($rank === 'species' && $taxa->genus === $item->genus) ? 'genus' : 'family';
                        $notes = ($similarity_type === 'genus') ? 'Dalam genus yang sama' : 'Dalam family yang sama';
                        
                        return (object) [
                            'id' => $item->id,
                            'scientific_name' => $item->scientific_name,
                            'common_name' => $item->common_name,
                            'family' => $item->family,
                            'genus' => $item->genus,
                            'taxonomic_status' => $item->taxonomic_status,
                            'confusion_count' => 0,
                            'similarity_type' => $similarity_type,
                            'notes' => $notes
                        ];
                    });

                $similarTaxa = $similarFromHistory->concat($similarFromFamily);
            } else {
                $similarTaxa = $similarFromHistory;
            }

            return response()->json([
                'success' => true,
                'data' => $similarTaxa->map(function($taxa) use ($rank) {
                    // Add media information for each similar taxa
                    $media = DB::table('fobi_checklist_taxas as fct')
                        ->join('fobi_checklist_media as fcm', 'fct.id', '=', 'fcm.checklist_id')
                        ->where('fct.taxa_id', $taxa->id)
                        ->select('fcm.file_path', 'fcm.id')
                        ->limit(1)
                        ->get();

                    $mediaCount = DB::table('fobi_checklist_taxas as fct')
                        ->join('fobi_checklist_media as fcm', 'fct.id', '=', 'fcm.checklist_id')
                        ->where('fct.taxa_id', $taxa->id)
                        ->count();

                    return [
                        'taxa_id' => $taxa->id,
                        'scientific_name' => $taxa->scientific_name,
                        'cname' => $taxa->common_name,
                        'family' => $taxa->family,
                        'genus' => $taxa->genus,
                        'rank' => $rank,
                        'taxonomic_status' => $taxa->taxonomic_status ?? 'ACCEPTED', // Use actual status from database
                        'media' => $media->map(function($item) {
                            return [
                                'id' => $item->id,
                                'file_url' => $item->file_path
                            ];
                        })->toArray(),
                        'media_count' => $mediaCount,
                        'similarity_score' => ($taxa->confusion_count ?? 0) > 0 ? min(1.0, ($taxa->confusion_count ?? 0) / 10) : 0.7,
                        'confusion_count' => $taxa->confusion_count ?? 0,
                        'similarity_type' => $taxa->similarity_type ?? 'family',
                        'notes' => $taxa->notes ?? 'Taxa serupa'
                    ];
                })
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get conservation status (IUCN and CITES) for species
     */
    public function getConservationStatus($rank, $id)
    {
        try {
            // Only allow species level
            if ($rank !== 'species') {
                return response()->json([
                    'success' => false,
                    'message' => 'Conservation status only available for species level'
                ], 400);
            }

            $taxa = Taxa::where('id', $id)
                ->where('taxon_rank', $rank)
                ->first();

            if (!$taxa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Species not found'
                ], 404);
            }

            $speciesName = $taxa->scientific_name;
            
            // Fetch from external APIs
            $iucnResult = $this->getIucnStatus($speciesName);
            $citesResult = $this->getCitesAppendix($speciesName);

            return response()->json([
                'success' => true,
                'data' => [
                    'iucn' => $iucnResult,
                    'cites' => $citesResult,
                    'species_name' => $speciesName
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error fetching conservation status:', [
                'rank' => $rank,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching conservation status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get IUCN status from external API
     */
    private function getIucnStatus(string $speciesName): array
    {
        if (empty($speciesName)) {
            return ['status' => 'error', 'value' => null, 'message' => 'Species name is required.'];
        }

        $nameParts = $this->getGenusAndSpecies($speciesName);
        $genus = $nameParts['genus'];
        $species = $nameParts['species'];

        if (empty($genus) || empty($species)) {
            return ['status' => 'error', 'value' => null, 'message' => 'Invalid species name for IUCN lookup.'];
        }

        $cacheKey = 'iucn_status_v4_' . \Illuminate\Support\Str::slug($genus . ' ' . $species);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addDays(30), function () use ($genus, $species) {
            try {
                $iucnApiKey = config('services.iucn.key');
                if (!$iucnApiKey) {
                    \Illuminate\Support\Facades\Log::error('IUCN API key is not set.');
                    return ['status' => 'error', 'value' => null, 'message' => 'IUCN API key not configured.'];
                }

                $client = new \GuzzleHttp\Client([
                    'timeout' => 15,
                    'connect_timeout' => 7,
                    'http_errors' => false,
                ]);

                $response = $client->request('GET',
                    "https://api.iucnredlist.org/api/v4/taxa/scientific_name?genus_name=".urlencode($genus)."&species_name=".urlencode($species),
                    [
                        'headers' => [
                            'Accept' => 'application/json',
                            'Authorization' => $iucnApiKey
                        ]
                    ]
                );

                $statusCode = $response->getStatusCode();
                $data = json_decode((string) $response->getBody(), true);

                if ($statusCode === 200) {
                    if (!empty($data['assessments'])) {
                        $latestAssessment = null;
                        foreach ($data['assessments'] as $assessment) {
                            if (isset($assessment['latest']) && $assessment['latest'] === true) {
                                $latestAssessment = $assessment['red_list_category_code'];
                                break;
                            }
                        }
                        $category = $latestAssessment ?? ($data['assessments'][0]['red_list_category_code'] ?? null);
                        return ['status' => 'found', 'value' => $category, 'message' => 'IUCN status found.'];
                    } else {
                        return ['status' => 'not_found', 'value' => 'Not Evaluated', 'message' => 'Species found but not evaluated by IUCN.'];
                    }
                }

                if ($statusCode === 404) {
                    return ['status' => 'not_found', 'value' => 'Not Found', 'message' => 'Species not found in IUCN Red List.'];
                }

                \Illuminate\Support\Facades\Log::warning('IUCN API request failed', [
                    'species' => $genus . ' ' . $species,
                    'status' => $statusCode,
                    'response' => (string) $response->getBody()
                ]);
                return ['status' => 'error', 'value' => null, 'message' => 'IUCN API error (Code: ' . $statusCode . ')'];
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Exception fetching IUCN status', ['species' => $genus . ' ' . $species, 'error' => $e->getMessage()]);
                return ['status' => 'error', 'value' => null, 'message' => 'Exception occurred while fetching IUCN status.'];
            }
        });
    }

    /**
     * Get CITES appendix from external API
     */
    private function getCitesAppendix(string $speciesName): array
    {
        if (empty($speciesName)) {
            return ['status' => 'error', 'value' => null, 'message' => 'Species name is required.'];
        }

        $nameParts = $this->getGenusAndSpecies($speciesName);
        $cleanSpeciesName = trim($nameParts['genus'] . ' ' . $nameParts['species']);

        if (empty($cleanSpeciesName)) {
            return ['status' => 'error', 'value' => null, 'message' => 'Invalid species name for CITES lookup.'];
        }

        $cacheKey = 'cites_appendix_' . \Illuminate\Support\Str::slug($cleanSpeciesName);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addDays(30), function () use ($cleanSpeciesName) {
            try {
                $speciesPlusApiKey = config('services.speciesplus.key');
                if (!$speciesPlusApiKey) {
                    \Illuminate\Support\Facades\Log::error('Species+ API key is not set.');
                    return ['status' => 'error', 'value' => null, 'message' => 'Species+ API key not configured.'];
                }

                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'X-Authentication-Token' => $speciesPlusApiKey,
                ])->get('https://api.speciesplus.net/api/v1/taxon_concepts', [
                    'name' => $cleanSpeciesName,
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (empty($data['taxon_concepts'])) {
                        return ['status' => 'not_found', 'value' => 'Not Found', 'message' => 'Species not found in Species+.'];
                    }

                    if (!empty($data['taxon_concepts'][0]['cites_listings'])) {
                        $listings = $data['taxon_concepts'][0]['cites_listings'];
                        $appendices = array_column($listings, 'appendix');
                        $appendix = null;
                        if (in_array('I', $appendices)) $appendix = 'I';
                        elseif (in_array('II', $appendices)) $appendix = 'II';
                        elseif (in_array('III', $appendices)) $appendix = 'III';
                        
                        return ['status' => 'found', 'value' => $appendix, 'message' => 'CITES appendix found.'];
                    } else {
                        return ['status' => 'not_found', 'value' => 'Not Listed', 'message' => 'Species is not listed in CITES appendices.'];
                    }
                }

                \Illuminate\Support\Facades\Log::warning('Species+ API request failed', ['species' => $cleanSpeciesName, 'response' => $response->body()]);
                return ['status' => 'error', 'value' => null, 'message' => 'Species+ API error (Code: ' . $response->status() . ')'];
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Exception fetching CITES appendix', ['species' => $cleanSpeciesName, 'error' => $e->getMessage()]);
                return ['status' => 'error', 'value' => null, 'message' => 'Exception occurred while fetching CITES status.'];
            }
        });
    }

    /**
     * Parse genus and species from scientific name
     */
    private function getGenusAndSpecies(string $scientificName): array
    {
        $parts = explode(' ', trim($scientificName));
        return [
            'genus' => $parts[0] ?? '',
            'species' => $parts[1] ?? ''
        ];
    }

    /**
     * Get appropriate limit for children taxa based on rank
     */
    private function getChildrenLimit($parentRank, $childRank)
    {
        // Set stricter limits to prevent explosion
        $limits = [
            'kingdom' => ['phylum' => 50],
            'phylum' => ['class' => 50],
            'class' => ['order' => 100],
            'order' => ['family' => 100],
            'family' => ['genus' => 100],
            'genus' => ['species' => 50],
        ];
        
        return $limits[$parentRank][$childRank] ?? 50;
    }

/**
 * Get descendant species for higher taxonomic ranks
 */
private function getDescendantSpecies($taxa, $rank)
{
    $rank = strtolower($rank);
    
    // Build query to find descendant species
    $query = DB::table('taxas')
        ->where('taxon_rank', 'species');
        
    // Define complete hierarchy for proper filtering
    $hierarchyFields = [
        'domain', 'superkingdom', 'kingdom', 'subkingdom', 'superphylum', 'phylum', 
        'subphylum', 'superclass', 'class', 'subclass', 'infraclass', 'superorder', 
        'order', 'suborder', 'infraorder', 'superfamily', 'family', 'subfamily', 
        'supertribe', 'tribe', 'subtribe', 'genus', 'subgenus'
    ];
    
    // Find the current rank position in hierarchy
    $currentRankIndex = array_search($rank, $hierarchyFields);
    
    if ($currentRankIndex !== false) {
        // Add conditions for all hierarchy levels up to and including current rank
        for ($i = 0; $i <= $currentRankIndex; $i++) {
            $field = $hierarchyFields[$i];
            if (isset($taxa->{$field}) && $taxa->{$field}) {
                $query->where($field, $taxa->{$field});
            }
        }
    } else {
        // Fallback for major ranks not in hierarchy array
        switch ($rank) {
            case 'kingdom':
                if ($taxa->kingdom) {
                    $query->where('kingdom', $taxa->kingdom);
                }
                break;
            case 'phylum':
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                break;
            case 'class':
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                if ($taxa->class) $query->where('class', $taxa->class);
                break;
            case 'order':
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                if ($taxa->class) $query->where('class', $taxa->class);
                if ($taxa->order) $query->where('order', $taxa->order);
                break;
            case 'family':
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                if ($taxa->class) $query->where('class', $taxa->class);
                if ($taxa->order) $query->where('order', $taxa->order);
                if ($taxa->family) $query->where('family', $taxa->family);
                break;
            case 'genus':
                if ($taxa->kingdom) $query->where('kingdom', $taxa->kingdom);
                if ($taxa->phylum) $query->where('phylum', $taxa->phylum);
                if ($taxa->class) $query->where('class', $taxa->class);
                if ($taxa->order) $query->where('order', $taxa->order);
                if ($taxa->family) $query->where('family', $taxa->family);
                if ($taxa->genus) $query->where('genus', $taxa->genus);
                break;
            default:
                return [];
        }
    }
    
    return $query->pluck('id')->toArray();
}

    /**
     * Validate if taxa exists in database
     */
    public function validateTaxa(Request $request)
    {
        $scientificName = $request->query('scientific_name');
        
        if (!$scientificName) {
            return response()->json([
                'exists' => false,
                'error' => 'Scientific name is required'
            ], 400);
        }

        try {
            // Search for exact match first
            $taxa = Taxa::where('scientific_name', $scientificName)->first();
            
            if ($taxa) {
                return response()->json([
                    'exists' => true,
                    'taxa_id' => $taxa->id,
                    'taxonomic_status' => $taxa->taxonomic_status,
                    'accepted_scientific_name' => $taxa->accepted_scientific_name
                ]);
            }

            // If not found, try to find synonym fallback
            $synonymFallback = $this->findSynonymFallback($scientificName);
            
            return response()->json([
                'exists' => false,
                'synonym_fallback' => $synonymFallback
            ]);

        } catch (\Exception $e) {
            Log::error('Taxa validation error:', [
                'scientific_name' => $scientificName,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'exists' => false,
                'error' => 'Validation failed'
            ], 500);
        }
    }

    /**
     * Find synonym fallback for taxa not found
     */
    private function findSynonymFallback($scientificName)
    {
        try {
            // Try partial matching or similar names
            $similarTaxa = Taxa::where('scientific_name', 'LIKE', '%' . explode(' ', $scientificName)[0] . '%')
                ->where('taxonomic_status', 'ACCEPTED')
                ->limit(3)
                ->get(['id', 'scientific_name', 'taxonomic_status']);

            if ($similarTaxa->isNotEmpty()) {
                return [
                    'suggested_taxa' => $similarTaxa->toArray(),
                    'message' => 'Taksa tidak ditemukan, berikut adalah saran taksa serupa'
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Synonym fallback search error:', [
                'scientific_name' => $scientificName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

}
