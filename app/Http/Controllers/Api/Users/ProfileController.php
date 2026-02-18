<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FobiUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Helpers\MediaStorageHelper;
use App\Traits\S3MediaHandlerTrait;

class ProfileController extends Controller
{
    use S3MediaHandlerTrait;
    public function getHomeProfile($id)
    {
        try {
            // Tambahkan log untuk debugging
            \Log::info('Getting home profile for user:', ['id' => $id]);

            // Ambil data user dengan path untuk profile_picture
            // SECURED: Tidak expose email dan phone untuk endpoint publik
            $user = FobiUser::select(
                'id',
                'fname',
                'lname',
                'uname',
                // 'email', // REMOVED: Data sensitif - tidak expose di endpoint publik
                // 'phone', // REMOVED: Data sensitif - tidak expose di endpoint publik
                'organization',
                'bio',
                'license',
                // include per-type license defaults
                'license_observation',
                'license_photo',
                'license_audio',
                'level',
                'profile_picture',
                'burungnesia_user_id',
                'kupunesia_user_id',
                'created_at'
            )->where('id', $id)->first();

            // Format profile_picture URL untuk frontend dengan fallback S3 -> local
            if ($user && $user->profile_picture) {
                $user->profile_picture = $this->getProfilePictureUrl($user->profile_picture);
            }

            // Log data user untuk debugging
            \Log::info('User data:', ['user' => $user]);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengguna tidak ditemukan'
                ], 404);
            }

            // Hitung total observasi FOBI (Amaturalist)
            $fobiObsCount = DB::table('fobi_checklist_taxas')
                ->where('user_id', $id)
                ->count();

            // Debug logging untuk getHomeProfile
            \Log::info('getHomeProfile Debug:', [
                'user_id' => $id,
                'burungnesia_user_id' => $user->burungnesia_user_id ?? null,
                'kupunesia_user_id' => $user->kupunesia_user_id ?? null,
                'fobi_count' => $fobiObsCount
            ]);

            // Hitung total observasi Burungnesia dari database second
            $birdObsCount = 0;
            $birdSpeciesCount = 0;
            if ($user->burungnesia_user_id) {
                try {
                    if (DB::connection('second')->getDatabaseName()) {
                        \Log::info('getHomeProfile: Querying Burungnesia DB second for user_id: ' . $user->burungnesia_user_id);
                        // Count checklist langsung (bukan join ke checklist_fauna)
                        $birdObsCount = DB::connection('second')
                            ->table('checklists')
                            ->where('user_id', $user->burungnesia_user_id)
                            ->where('active', 1)
                            ->count();
                        // Count distinct fauna_id dari checklist_fauna
                        $birdSpeciesCount = DB::connection('second')
                            ->table('checklist_fauna as cf')
                            ->join('checklists as c', 'c.id', '=', 'cf.checklist_id')
                            ->where('c.user_id', $user->burungnesia_user_id)
                            ->where('c.active', 1)
                            ->whereNull('cf.deleted_at')
                            ->distinct()
                            ->count('cf.fauna_id');
                        \Log::info('getHomeProfile: Burungnesia count result - obs: ' . $birdObsCount . ', species: ' . $birdSpeciesCount);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Error counting bird observations: ' . $e->getMessage());
                }
            } else {
                \Log::info('getHomeProfile: User has no burungnesia_user_id');
            }

            // Hitung total observasi Kupunesia dari database third
            $butterflyObsCount = 0;
            $butterflySpeciesCount = 0;
            if ($user->kupunesia_user_id) {
                try {
                    if (DB::connection('third')->getDatabaseName()) {
                        \Log::info('getHomeProfile: Querying Kupunesia DB third for user_id: ' . $user->kupunesia_user_id);
                        // Count checklist langsung (bukan join ke checklist_fauna)
                        $butterflyObsCount = DB::connection('third')
                            ->table('checklists')
                            ->where('user_id', $user->kupunesia_user_id)
                            ->where('active', 1)
                            ->count();
                        // Count distinct fauna_id dari checklist_fauna
                        $butterflySpeciesCount = DB::connection('third')
                            ->table('checklist_fauna as cf')
                            ->join('checklists as c', 'c.id', '=', 'cf.checklist_id')
                            ->where('c.user_id', $user->kupunesia_user_id)
                            ->where('c.active', 1)
                            ->whereNull('cf.deleted_at')
                            ->distinct()
                            ->count('cf.fauna_id');
                        \Log::info('getHomeProfile: Kupunesia count result - obs: ' . $butterflyObsCount . ', species: ' . $butterflySpeciesCount);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Error counting butterfly observations: ' . $e->getMessage());
                }
            } else {
                \Log::info('getHomeProfile: User has no kupunesia_user_id');
            }

            // Total semua observasi
            $observationCount = $fobiObsCount + $birdObsCount + $butterflyObsCount;
            \Log::info('getHomeProfile: Total stats - fobi: ' . $fobiObsCount . ', bird: ' . $birdObsCount . ', butterfly: ' . $butterflyObsCount);

            // Hitung total spesies - observasi yang sudah research grade dan taxa-nya rank species
            // Debug: cek data yang ada
            $debugObsWithTaxaId = DB::table('fobi_checklist_taxas')
                ->where('user_id', $id)
                ->whereNotNull('taxa_id')
                ->count();
            
            $debugObsWithQA = DB::table('fobi_checklist_taxas as fct')
                ->join('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                ->where('fct.user_id', $id)
                ->count();
            
            $debugResearchGrade = DB::table('fobi_checklist_taxas as fct')
                ->join('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                ->where('fct.user_id', $id)
                ->where('tqa.grade', 'research grade')
                ->count();
            
            \Log::info('Species Debug for user ' . $id . ':', [
                'total_obs' => $observationCount,
                'obs_with_taxa_id' => $debugObsWithTaxaId,
                'obs_with_qa' => $debugObsWithQA,
                'obs_research_grade' => $debugResearchGrade
            ]);
            
            // Query utama: hitung taxa_id unik yang research grade dan rank species
            $speciesCount = DB::table('fobi_checklist_taxas as fct')
                ->join('taxas as t', 'fct.taxa_id', '=', 't.id')
                ->join('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                ->where('fct.user_id', $id)
                ->whereIn('t.taxon_rank', ['species', 'subspecies', 'variety', 'form'])
                ->where('tqa.grade', 'research grade')
                ->distinct()
                ->count('fct.taxa_id');
            
            // Fallback 1: jika 0, coba tanpa filter taxon_rank tapi tetap research grade
            if ($speciesCount == 0) {
                $speciesCount = DB::table('fobi_checklist_taxas as fct')
                    ->join('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                    ->where('fct.user_id', $id)
                    ->where('tqa.grade', 'research grade')
                    ->whereNotNull('fct.taxa_id')
                    ->distinct()
                    ->count('fct.taxa_id');
            }
            
            // Fallback 2: jika masih 0, hitung taxa_id unik dari semua observasi user
            if ($speciesCount == 0) {
                $speciesCount = DB::table('fobi_checklist_taxas')
                    ->where('user_id', $id)
                    ->whereNotNull('taxa_id')
                    ->distinct()
                    ->count('taxa_id');
            }
            
            \Log::info('Final species count for user ' . $id . ': ' . $speciesCount);

            // Hitung total identifikasi (exclude deleted, withdrawn, dan kalah suara berdasarkan taxon_id)
            // Identifikasi dihitung jika:
            // 1. Tidak deleted dan tidak withdrawn
            // 2. taxon_id user memiliki jumlah suara (count) >= taxon_id lain di checklist yang sama
            //    Suara = jumlah identifikasi dengan taxon_id yang sama di checklist yang sama
            $identificationCount = DB::table('taxa_identifications as ti')
                ->where('ti.user_id', $id)
                ->whereNull('ti.deleted_at')
                ->where(function($q) {
                    $q->whereNull('ti.is_withdrawn')
                      ->orWhere('ti.is_withdrawn', 0);
                })
                ->whereNotExists(function($subquery) {
                    // Cek apakah ada taxon_id lain di checklist yang sama dengan jumlah suara lebih banyak
                    // Subquery: hitung jumlah identifikasi per taxon_id di checklist yang sama
                    $subquery->select(DB::raw(1))
                        ->from('taxa_identifications as ti2')
                        ->whereColumn('ti2.checklist_id', 'ti.checklist_id')
                        ->whereColumn('ti2.taxon_id', '!=', 'ti.taxon_id')
                        ->whereNull('ti2.deleted_at')
                        ->where(function($q2) {
                            $q2->whereNull('ti2.is_withdrawn')
                               ->orWhere('ti2.is_withdrawn', 0);
                        })
                        ->havingRaw('COUNT(ti2.id) > (
                            SELECT COUNT(ti3.id) 
                            FROM taxa_identifications ti3 
                            WHERE ti3.checklist_id = ti.checklist_id 
                            AND ti3.taxon_id = ti.taxon_id 
                            AND ti3.deleted_at IS NULL 
                            AND (ti3.is_withdrawn IS NULL OR ti3.is_withdrawn = 0)
                        )')
                        ->groupBy('ti2.taxon_id');
                })
                ->count();

            // Hitung identifikasi perdana (first identifications yang mencapai research grade/confirmed id)
            // Logika: User adalah yang pertama mengidentifikasi (is_first=true) DAN observasi mencapai research grade
            // ATAU user adalah pemilik observasi yang mencapai research grade
            
            // 1. Hitung observasi milik user sendiri yang mencapai research grade
            $ownObservationsResearchGrade = DB::table('fobi_checklist_taxas as fct')
                ->join('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                ->where('fct.user_id', $id)
                ->whereIn('tqa.grade', ['research grade', 'confirmed id'])
                ->count();
            
            // 2. Hitung identifikasi pertama user di observasi orang lain yang mencapai research grade
            $firstIdentificationsOthers = DB::table('taxa_identifications as ti')
                ->join('fobi_checklist_taxas as fct', 'ti.checklist_id', '=', 'fct.id')
                ->join('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                ->where('ti.user_id', $id)
                ->where('ti.is_first', true)
                ->where('ti.is_withdrawn', false)
                ->where('fct.user_id', '!=', $id) // Observasi orang lain
                ->whereIn('tqa.grade', ['research grade', 'confirmed id'])
                ->count();
            
            $totalFirstIdentifications = $ownObservationsResearchGrade + $firstIdentificationsOthers;
            
            \Log::info('Identifikasi Perdana Debug for user ' . $id . ':', [
                'own_obs_research_grade' => $ownObservationsResearchGrade,
                'first_ident_others' => $firstIdentificationsOthers,
                'total_first_ident' => $totalFirstIdentifications,
                'total_identifications' => $identificationCount
            ]);

            // Ambil data followers dengan observations count
            // fobi_checklist_taxas menggunakan user_id
            $followers = DB::table('fobi_users')
                ->leftJoin(DB::raw('(SELECT user_id, COUNT(*) as observations_count FROM fobi_checklist_taxas GROUP BY user_id) as obs'), 'fobi_users.id', '=', 'obs.user_id')
                ->whereIn('fobi_users.id', function($query) use ($id) {
                    $query->select('follower_id')
                        ->from('user_followers')
                        ->where('user_id', $id);
                })
                ->select(
                    'fobi_users.id',
                    'fobi_users.uname',
                    'fobi_users.profile_picture',
                    DB::raw('COALESCE(obs.observations_count, 0) as observations_count')
                )
                ->get()
                ->map(function($follower) {
                    // Format profile_picture URL untuk S3/local
                    if ($follower->profile_picture) {
                        $follower->profile_picture = $this->getProfilePictureUrl($follower->profile_picture);
                    }
                    return $follower;
                });

            // Ambil data following dengan observations count
            // fobi_checklist_taxas menggunakan user_id
            $following = DB::table('fobi_users')
                ->leftJoin(DB::raw('(SELECT user_id, COUNT(*) as observations_count FROM fobi_checklist_taxas GROUP BY user_id) as obs'), 'fobi_users.id', '=', 'obs.user_id')
                ->whereIn('fobi_users.id', function($query) use ($id) {
                    $query->select('user_id')
                        ->from('user_followers')
                        ->where('follower_id', $id);
                })
                ->select(
                    'fobi_users.id',
                    'fobi_users.uname',
                    'fobi_users.profile_picture',
                    DB::raw('COALESCE(obs.observations_count, 0) as observations_count')
                )
                ->get()
                ->map(function($following) {
                    // Format profile_picture URL untuk S3/local
                    if ($following->profile_picture) {
                        $following->profile_picture = $this->getProfilePictureUrl($following->profile_picture);
                    }
                    return $following;
                });

            // Jangan expose burungnesia_user_id dan kupunesia_user_id ke frontend
            $userResponse = $user->toArray();
            unset($userResponse['burungnesia_user_id'], $userResponse['kupunesia_user_id']);

            return response()->json([
               'success' => true,
               'data' => [
                   'user' => $userResponse,
                   'stats' => [
                       'observasi' => number_format($observationCount),
                       'spesies' => number_format($speciesCount),
                       'identifikasi' => number_format($identificationCount),
                       'totalObservations' => $observationCount,
                       'totalSpecies' => $speciesCount,
                       'totalIdentPerdana' => $totalFirstIdentifications,
                       'totalIdentifications' => $identificationCount,
                       'fopiObservations' => $fobiObsCount,
                       'birdObservations' => $birdObsCount,
                       'butterflyObservations' => $butterflyObsCount,
                       'birdSpecies' => $birdSpeciesCount,
                       'butterflySpecies' => $butterflySpeciesCount,
                       'hasBurungnesia' => !empty($user->burungnesia_user_id),
                       'hasKupunesia' => !empty($user->kupunesia_user_id)
                   ],
                   'social' => [
                       'followers' => $followers,
                       'following' => $following,
                       'followerCount' => DB::table('user_followers')->where('user_id', $id)->count(),
                       'followingCount' => DB::table('user_followers')->where('follower_id', $id)->count()
                   ]
               ]
           ]);
        } catch (\Exception $e) {
           \Log::error('Error in getHomeProfile:', [
               'id' => $id,
               'error' => $e->getMessage()
           ]);
            return response()->json([
               'success' => false,
               'message' => 'Terjadi kesalahan saat mengambil data profil'
           ], 500);
       }
   }
   public function getObservations($id, Request $request)
{
    try {
        \Log::info('Received request for observations:', [
            'id' => $id,
            'page' => $request->query('page'),
            'search' => $request->query('search')
        ]);

        $user = DB::table('fobi_users')->find($id);
        if (!$user) {
            \Log::error('User tidak ditemukan:', ['id' => $id]);
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        // Ambil observasi dari fobi_checklist_taxas
        $fobiObservations = DB::table('fobi_checklist_taxas as fct')
            ->join('taxas as t', 't.id', '=', 'fct.taxa_id')
            ->where('fct.user_id', $id)
            ->select(
                'fct.id',
                'fct.photo_url',
                't.nama_latin',
                't.nama_umum',
                'fct.date',
                'fct.created_at',
                DB::raw("'fobi' as source")
            );

        // Ambil observasi burung
        $birdObservations = DB::table('fobi_checklists as fc')
            ->join('burungnesia.fauna as bf', 'bf.id', '=', 'fc.fauna_id')
            ->where('fc.fobi_user_id', $id)
            ->select(
                'fc.id',
                'fc.photo_url',
                'bf.nama_latin',
                'bf.nama_umum',
                'fc.created_at',
                DB::raw("'burung' as source")
            );

        // Ambil observasi kupu-kupu
        $butterflyObservations = DB::table('fobi_checklists_kupnes as fck')
            ->join('kupunesia.fauna as kf', 'kf.id', '=', 'fck.fauna_id')
            ->where('fck.fobi_user_id', $id)
            ->select(
                'fck.id',
                'fck.photo_url',
                'kf.nama_latin',
                'kf.nama_umum',
                'fck.created_at',
                DB::raw("'kupu-kupu' as source")
            );

        // Gabungkan semua observasi dan urutkan berdasarkan tanggal
        $allObservations = $fobiObservations
            ->union($birdObservations)
            ->union($butterflyObservations)
            ->orderBy('created_at', 'desc')
            ->paginate(12);
        return response()->json([
           'success' => true,
           'data' => $allObservations
       ]);
    } catch (\Exception $e) {
       return response()->json([
           'success' => false,
           'message' => 'Gagal mengambil data observasi'
       ], 500);
   }
}

public function updateBio(Request $request)
{
    try {
        $user = auth()->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'bio' => 'nullable|string|max:5000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        // Sanitize HTML content
        $bio = $request->input('bio', '');
        
        // Update bio
        FobiUser::where('id', $user->id)->update([
            'bio' => $bio
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Bio berhasil diperbarui',
            'data' => [
                'bio' => $bio
            ]
        ]);

    } catch (\Exception $e) {
        \Log::error('Error updating bio:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Gagal memperbarui bio'
        ], 500);
    }
}

public function getIdentifications($id, Request $request)
{
    try {
        $perPage = $request->query('per_page', 12);
        $page = $request->query('page', 1);
        
        // Get identifications made by this user
        $identifications = DB::table('taxa_identifications as ti')
            ->join('taxas as t', 't.id', '=', 'ti.taxon_id')
            ->join('fobi_checklist_taxas as fct', 'fct.id', '=', 'ti.checklist_id')
            ->join('fobi_users as observer', 'observer.id', '=', 'fct.user_id')
            ->leftJoin('fobi_checklist_media as fcm', function($join) {
                $join->on('fct.id', '=', 'fcm.checklist_id')
                     ->whereRaw('fcm.id = (SELECT MIN(id) FROM fobi_checklist_media WHERE checklist_id = fct.id)');
            })
            ->where('ti.user_id', $id)
            ->whereNull('ti.deleted_at')
            ->where(function($q) {
                $q->whereNull('ti.is_withdrawn')
                  ->orWhere('ti.is_withdrawn', 0);
            })
            ->leftJoin('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
            ->select(
                'ti.id',
                'ti.checklist_id',
                'ti.taxon_id',
                'ti.comment',
                'ti.created_at',
                'ti.is_first',
                'ti.confidence_level',
                't.scientific_name',
                't.Cname as common_name',
                't.taxon_rank',
                'fct.user_id as observer_id',
                'fct.date as observation_date',
                'observer.uname as observer_username',
                'fcm.file_path as photo_path',
                'fcm.spectrogram',
                'fcm.media_type',
                'fcm.storage_type',
                'fcm.location',
                'tqa.grade'
            )
            ->orderBy('ti.created_at', 'desc')
            ->paginate($perPage);
        
        // Get total identification count per checklist for each identification
        $checklistIds = collect($identifications->items())->pluck('checklist_id')->unique()->toArray();
        $identCountsPerChecklist = DB::table('taxa_identifications')
            ->whereIn('checklist_id', $checklistIds)
            ->whereNull('deleted_at')
            ->where(function($q) {
                $q->whereNull('is_withdrawn')
                  ->orWhere('is_withdrawn', 0);
            })
            ->select('checklist_id', DB::raw('COUNT(*) as total_idents'))
            ->groupBy('checklist_id')
            ->pluck('total_idents', 'checklist_id')
            ->toArray();
        
        // Format data
        $formattedData = collect($identifications->items())->map(function($item) use ($identCountsPerChecklist) {
            // Get photo URL
            $photoUrl = null;
            if ($item->photo_path) {
                $photoUrl = MediaStorageHelper::getMediaUrl($item->photo_path, $item->storage_type ?? 'local', null);
            }
            
            // Get spectrogram URL
            $spectrogramUrl = null;
            if ($item->spectrogram) {
                $spectrogramUrl = MediaStorageHelper::getMediaUrl($item->spectrogram, $item->storage_type ?? 'local', null);
            }
            
            // Get audio URL (if media_type is audio, the photo_path is actually the audio file)
            $audioUrl = null;
            if ($item->media_type === 'audio' && $item->photo_path) {
                $audioUrl = $photoUrl;
            }
            
            return [
                'id' => $item->id,
                'checklist_id' => $item->checklist_id,
                'taxon_id' => $item->taxon_id,
                'scientific_name' => $item->scientific_name,
                'common_name' => $item->common_name,
                'taxon_rank' => $item->taxon_rank,
                'comment' => $item->comment,
                'created_at' => $item->created_at,
                'is_first' => $item->is_first,
                'confidence_level' => $item->confidence_level,
                'observer_id' => $item->observer_id,
                'observer_username' => $item->observer_username,
                'observation_date' => $item->observation_date,
                'location' => $item->location,
                'photo_url' => $photoUrl,
                'spectrogram_url' => $spectrogramUrl,
                'audio_url' => $audioUrl,
                'total_idents' => $identCountsPerChecklist[$item->checklist_id] ?? 1,
                'grade' => $item->grade
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => [
                'data' => $formattedData,
                'current_page' => $identifications->currentPage(),
                'last_page' => $identifications->lastPage(),
                'per_page' => $identifications->perPage(),
                'total' => $identifications->total()
            ]
        ]);
    } catch (\Exception $e) {
        \Log::error('Error fetching identifications: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil data identifikasi: ' . $e->getMessage()
        ], 500);
    }
}

public function getSpecies($id, Request $request)
{
    try {
        $user = FobiUser::findOrFail($id);
        
        // Parameters
        $search = strtolower($request->query('search', ''));
        $sourceFilter = $request->query('source', 'all');
        $perPage = $request->query('per_page', 20);
        $page = $request->query('page', 1);
        
        $allSpeciesData = collect();
        
        // Ambil spesies dari FOBI
        if ($sourceFilter === 'all' || $sourceFilter === 'fobi') {
            $fobiSpecies = DB::table('fobi_checklist_taxas as fct')
                ->join('taxas as t', 't.id', '=', 'fct.taxa_id')
                ->leftJoin('fobi_checklist_media as fcm', function($join) {
                    $join->on('fct.id', '=', 'fcm.checklist_id')
                         ->whereRaw('fcm.id = (SELECT MIN(id) FROM fobi_checklist_media WHERE checklist_id = fct.id)');
                })
                ->where('fct.user_id', $id)
                ->when($search, function($query) use ($search) {
                    $query->where(function($q) use ($search) {
                        $q->whereRaw('LOWER(t.scientific_name) LIKE ?', ["%{$search}%"])
                          ->orWhereRaw('LOWER(t.Cname) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->select(
                    't.id as taxa_id',
                    't.scientific_name',
                    't.Cname as common_name',
                    DB::raw('COUNT(DISTINCT fct.id) as observation_count'),
                    DB::raw("'fobi' as source"),
                    DB::raw('MIN(fcm.file_path) as photo_path')
                )
                ->groupBy('t.id', 't.scientific_name', 't.Cname')
                ->get();
            
            // Format photo URL
            foreach ($fobiSpecies as $species) {
                if ($species->photo_path) {
                    $species->photo_url = MediaStorageHelper::getMediaUrl($species->photo_path, 'local', null);
                } else {
                    $species->photo_url = null;
                }
                unset($species->photo_path);
            }
            
            $allSpeciesData = $allSpeciesData->concat($fobiSpecies);
        }
        
        // Ambil spesies burung
        if ($sourceFilter === 'all' || $sourceFilter === 'burung') {
            $birdSpecies = DB::table('fobi_checklists as fc')
                ->join('burungnesia.fauna as bf', 'bf.id', '=', 'fc.fauna_id')
                ->leftJoin('burungnesia.checklist_fauna as cf', 'fc.checklist_fauna_id', '=', 'cf.id')
                ->leftJoin('burungnesia.checklist_fauna_images as cfi', function($join) {
                    $join->on('cf.id', '=', 'cfi.checklist_fauna_id')
                         ->whereRaw('cfi.id = (SELECT MIN(id) FROM burungnesia.checklist_fauna_images WHERE checklist_fauna_id = cf.id)');
                })
                ->where('fc.fobi_user_id', $id)
                ->when($search, function($query) use ($search) {
                    $query->where(function($q) use ($search) {
                        $q->whereRaw('LOWER(bf.nameLat) LIKE ?', ["%{$search}%"])
                          ->orWhereRaw('LOWER(bf.nameId) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->select(
                    'bf.id as taxa_id',
                    'bf.nameLat as scientific_name',
                    'bf.nameId as common_name',
                    DB::raw('COUNT(DISTINCT fc.id) as observation_count'),
                    DB::raw("'burung' as source"),
                    'cfi.images as photo_url'
                )
                ->groupBy('bf.id', 'bf.nameLat', 'bf.nameId', 'cfi.images')
                ->get();
            
            $allSpeciesData = $allSpeciesData->concat($birdSpecies);
        }
        
        // Ambil spesies kupu-kupu
        if ($sourceFilter === 'all' || $sourceFilter === 'kupu-kupu') {
            $butterflySpecies = DB::table('fobi_checklists_kupnes as fck')
                ->join('kupunesia.fauna as kf', 'kf.id', '=', 'fck.fauna_id')
                ->leftJoin('kupunesia.checklist_fauna as cf', 'fck.checklist_fauna_id', '=', 'cf.id')
                ->leftJoin('kupunesia.checklist_fauna_images as cfi', function($join) {
                    $join->on('cf.id', '=', 'cfi.checklist_fauna_id')
                         ->whereRaw('cfi.id = (SELECT MIN(id) FROM kupunesia.checklist_fauna_images WHERE checklist_fauna_id = cf.id)');
                })
                ->where('fck.fobi_user_id', $id)
                ->when($search, function($query) use ($search) {
                    $query->where(function($q) use ($search) {
                        $q->whereRaw('LOWER(kf.nameLat) LIKE ?', ["%{$search}%"])
                          ->orWhereRaw('LOWER(kf.nameId) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->select(
                    'kf.id as taxa_id',
                    'kf.nameLat as scientific_name',
                    'kf.nameId as common_name',
                    DB::raw('COUNT(DISTINCT fck.id) as observation_count'),
                    DB::raw("'kupu-kupu' as source"),
                    'cfi.images as photo_url'
                )
                ->groupBy('kf.id', 'kf.nameLat', 'kf.nameId', 'cfi.images')
                ->get();
            
            $allSpeciesData = $allSpeciesData->concat($butterflySpecies);
        }
        
        // Sort by observation count
        $allSpeciesData = $allSpeciesData->sortByDesc('observation_count')->values();
        
        // Manual pagination
        $total = $allSpeciesData->count();
        $lastPage = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedData = $allSpeciesData->slice($offset, $perPage)->values();
        
        return response()->json([
            'success' => true,
            'data' => $paginatedData,
            'meta' => [
                'total' => $total,
                'current_page' => (int) $page,
                'per_page' => (int) $perPage,
                'last_page' => (int) $lastPage
            ]
        ]);
    } catch (\Exception $e) {
        \Log::error('Error in getSpecies:', ['error' => $e->getMessage()]);
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil data spesies: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Get life list (taxonomy tree) for a user
 * Returns hierarchical taxonomy data for all user observations
 */
public function getLifeList($id)
{
    try {
        $user = FobiUser::findOrFail($id);
        
        // Linnaean ranks only (strict hierarchy)
        $linnaeanRanks = ['kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species', 'subspecies', 'variety', 'form'];
        
        // Excluded phylogenetic groups
        $excludedNames = [
            'Eukaryota', 'Deuterostomia', 'Ecdysozoa', 'Eumetazoa', 'Vertebrata',
            'Bilateria', 'Protostomia', 'Lophotrochozoa', 'Spiralia', 'Gnathostomata',
            'Tetrapoda', 'Amniota', 'Sauropsida', 'Diapsida', 'Archosauria',
            'Dinosauria', 'Theropoda', 'Metazoa', 'Opisthokonta', 'Holozoa'
        ];
        
        // Get all taxa from user's observations with full hierarchy
        $taxaData = DB::table('fobi_checklist_taxas as fct')
            ->join('taxas as t', 't.id', '=', 'fct.taxa_id')
            ->where('fct.user_id', $id)
            ->select(
                't.id as taxa_id',
                't.scientific_name',
                't.species as species_name',
                't.Cname as common_name',
                't.taxon_rank',
                't.kingdom',
                't.phylum',
                't.class',
                't.order',
                't.family',
                't.genus',
                DB::raw('COUNT(DISTINCT fct.id) as observation_count')
            )
            ->groupBy('t.id', 't.scientific_name', 't.species', 't.Cname', 't.taxon_rank', 
                      't.kingdom', 't.phylum', 't.class', 't.order', 't.family', 't.genus')
            ->get();
        
        // Build hierarchical tree structure
        $tree = $this->buildTaxonomyTree($taxaData, $linnaeanRanks, $excludedNames);
        
        return response()->json([
            'success' => true,
            'data' => $tree
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Error in getLifeList:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil daftar hayati: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Build taxonomy tree from flat taxa data (optimized - no individual queries)
 */
private function buildTaxonomyTree($taxaData, $linnaeanRanks, $excludedNames)
{
    if ($taxaData->isEmpty()) {
        return [];
    }
    
    // Collect all unique taxa names for batch lookup
    $allNames = [];
    foreach ($taxaData as $taxa) {
        if ($taxa->kingdom) $allNames[] = $taxa->kingdom;
        if ($taxa->phylum) $allNames[] = $taxa->phylum;
        if ($taxa->class) $allNames[] = $taxa->class;
        if ($taxa->order) $allNames[] = $taxa->order;
        if ($taxa->family) $allNames[] = $taxa->family;
        if ($taxa->genus) $allNames[] = $taxa->genus;
    }
    $allNames = array_unique($allNames);
    
    // Batch fetch all taxa info in ONE query
    $taxaLookup = [];
    if (!empty($allNames)) {
        $taxaInfo = DB::table('taxas')
            ->whereIn('scientific_name', $allNames)
            ->select('id', 'scientific_name', 'Cname', 'taxon_rank')
            ->get();
        
        foreach ($taxaInfo as $t) {
            $key = strtolower($t->scientific_name) . '-' . strtolower($t->taxon_rank ?? '');
            $taxaLookup[$key] = [
                'id' => $t->id,
                'common_name' => $t->Cname ?? ''
            ];
        }
    }
    
    // Helper to get taxa info from cache
    $getTaxaInfo = function($name, $rank) use ($taxaLookup) {
        $key = strtolower($name) . '-' . strtolower($rank);
        return $taxaLookup[$key] ?? ['id' => null, 'common_name' => ''];
    };
    
    // Group observations by each rank level
    $kingdomCounts = [];
    $phylumCounts = [];
    $classCounts = [];
    $orderCounts = [];
    $familyCounts = [];
    $genusCounts = [];
    $speciesCounts = [];
    
    foreach ($taxaData as $taxa) {
        $count = $taxa->observation_count;
        $rank = strtolower($taxa->taxon_rank ?? '');
        
        // Skip excluded names
        if (in_array($taxa->scientific_name, $excludedNames)) continue;
        
        // Accumulate counts at each level
        if ($taxa->kingdom && !in_array($taxa->kingdom, $excludedNames)) {
            $kingdomCounts[$taxa->kingdom] = ($kingdomCounts[$taxa->kingdom] ?? 0) + $count;
        }
        if ($taxa->phylum && !in_array($taxa->phylum, $excludedNames)) {
            $key = $taxa->kingdom . '|' . $taxa->phylum;
            $phylumCounts[$key] = ($phylumCounts[$key] ?? 0) + $count;
        }
        if ($taxa->class && !in_array($taxa->class, $excludedNames)) {
            $key = $taxa->kingdom . '|' . $taxa->phylum . '|' . $taxa->class;
            $classCounts[$key] = ($classCounts[$key] ?? 0) + $count;
        }
        if ($taxa->order && !in_array($taxa->order, $excludedNames)) {
            $key = $taxa->kingdom . '|' . $taxa->phylum . '|' . $taxa->class . '|' . $taxa->order;
            $orderCounts[$key] = ($orderCounts[$key] ?? 0) + $count;
        }
        if ($taxa->family && !in_array($taxa->family, $excludedNames)) {
            $key = $taxa->kingdom . '|' . $taxa->phylum . '|' . $taxa->class . '|' . $taxa->order . '|' . $taxa->family;
            $familyCounts[$key] = ($familyCounts[$key] ?? 0) + $count;
        }
        if ($taxa->genus && !in_array($taxa->genus, $excludedNames)) {
            $key = $taxa->kingdom . '|' . $taxa->phylum . '|' . $taxa->class . '|' . $taxa->order . '|' . $taxa->family . '|' . $taxa->genus;
            $genusCounts[$key] = ($genusCounts[$key] ?? 0) + $count;
        }
        
        // Species level
        if (in_array($rank, ['species', 'subspecies', 'variety', 'form'])) {
            $key = $taxa->kingdom . '|' . $taxa->phylum . '|' . $taxa->class . '|' . $taxa->order . '|' . $taxa->family . '|' . $taxa->genus . '|' . $taxa->scientific_name;
            $speciesCounts[$key] = [
                'count' => $count,
                'taxa_id' => $taxa->taxa_id,
                'common_name' => $taxa->common_name,
                'species_name' => $taxa->species_name,
                'rank' => $rank
            ];
        }
    }
    
    // Build nested tree structure
    $result = [];
    
    foreach ($kingdomCounts as $kingdom => $kingdomCount) {
        $kingdomInfo = $getTaxaInfo($kingdom, 'kingdom');
        $kingdomNode = [
            'id' => 'kingdom-' . $kingdom,
            'name' => $kingdom,
            'common_name' => $kingdomInfo['common_name'],
            'rank' => 'kingdom',
            'count' => $kingdomCount,
            'taxa_id' => $kingdomInfo['id'],
            'children' => []
        ];
        
        // Add phyla
        foreach ($phylumCounts as $phylumKey => $phylumCount) {
            $parts = explode('|', $phylumKey);
            if ($parts[0] !== $kingdom) continue;
            $phylum = $parts[1];
            $phylumInfo = $getTaxaInfo($phylum, 'phylum');
            
            $phylumNode = [
                'id' => 'phylum-' . $phylum,
                'name' => $phylum,
                'common_name' => $phylumInfo['common_name'],
                'rank' => 'phylum',
                'count' => $phylumCount,
                'taxa_id' => $phylumInfo['id'],
                'children' => []
            ];
            
            // Add classes
            foreach ($classCounts as $classKey => $classCount) {
                $classParts = explode('|', $classKey);
                if ($classParts[0] !== $kingdom || $classParts[1] !== $phylum) continue;
                $class = $classParts[2];
                $classInfo = $getTaxaInfo($class, 'class');
                
                $classNode = [
                    'id' => 'class-' . $class,
                    'name' => $class,
                    'common_name' => $classInfo['common_name'],
                    'rank' => 'class',
                    'count' => $classCount,
                    'taxa_id' => $classInfo['id'],
                    'children' => []
                ];
                
                // Add orders
                foreach ($orderCounts as $orderKey => $orderCount) {
                    $orderParts = explode('|', $orderKey);
                    if ($orderParts[0] !== $kingdom || $orderParts[1] !== $phylum || $orderParts[2] !== $class) continue;
                    $order = $orderParts[3];
                    $orderInfo = $getTaxaInfo($order, 'order');
                    
                    $orderNode = [
                        'id' => 'order-' . $order,
                        'name' => $order,
                        'common_name' => $orderInfo['common_name'],
                        'rank' => 'order',
                        'count' => $orderCount,
                        'taxa_id' => $orderInfo['id'],
                        'children' => []
                    ];
                    
                    // Add families
                    foreach ($familyCounts as $familyKey => $familyCount) {
                        $familyParts = explode('|', $familyKey);
                        if ($familyParts[0] !== $kingdom || $familyParts[1] !== $phylum || 
                            $familyParts[2] !== $class || $familyParts[3] !== $order) continue;
                        $family = $familyParts[4];
                        $familyInfo = $getTaxaInfo($family, 'family');
                        
                        $familyNode = [
                            'id' => 'family-' . $family,
                            'name' => $family,
                            'common_name' => $familyInfo['common_name'],
                            'rank' => 'family',
                            'count' => $familyCount,
                            'taxa_id' => $familyInfo['id'],
                            'children' => []
                        ];
                        
                        // Add genera
                        foreach ($genusCounts as $genusKey => $genusCount) {
                            $genusParts = explode('|', $genusKey);
                            if ($genusParts[0] !== $kingdom || $genusParts[1] !== $phylum || 
                                $genusParts[2] !== $class || $genusParts[3] !== $order || 
                                $genusParts[4] !== $family) continue;
                            $genus = $genusParts[5];
                            $genusInfo = $getTaxaInfo($genus, 'genus');
                            
                            $genusNode = [
                                'id' => 'genus-' . $genus,
                                'name' => $genus,
                                'common_name' => $genusInfo['common_name'],
                                'rank' => 'genus',
                                'count' => $genusCount,
                                'taxa_id' => $genusInfo['id'],
                                'children' => []
                            ];
                            
                            // Add species
                            foreach ($speciesCounts as $speciesKey => $speciesData) {
                                $speciesParts = explode('|', $speciesKey);
                                if ($speciesParts[0] !== $kingdom || $speciesParts[1] !== $phylum || 
                                    $speciesParts[2] !== $class || $speciesParts[3] !== $order || 
                                    $speciesParts[4] !== $family || $speciesParts[5] !== $genus) continue;
                                
                                $genusNode['children'][] = [
                                    'id' => 'species-' . $speciesData['taxa_id'],
                                    'name' => $speciesData['species_name'] ?? $speciesParts[6],
                                    'common_name' => $speciesData['common_name'] ?? '',
                                    'rank' => $speciesData['rank'],
                                    'count' => $speciesData['count'],
                                    'taxa_id' => $speciesData['taxa_id'],
                                    'children' => []
                                ];
                            }
                            
                            if (!empty($genusNode['children']) || $genusCount > 0) {
                                $familyNode['children'][] = $genusNode;
                            }
                        }
                        
                        if (!empty($familyNode['children']) || $familyCount > 0) {
                            $orderNode['children'][] = $familyNode;
                        }
                    }
                    
                    if (!empty($orderNode['children']) || $orderCount > 0) {
                        $classNode['children'][] = $orderNode;
                    }
                }
                
                if (!empty($classNode['children']) || $classCount > 0) {
                    $phylumNode['children'][] = $classNode;
                }
            }
            
            if (!empty($phylumNode['children']) || $phylumCount > 0) {
                $kingdomNode['children'][] = $phylumNode;
            }
        }
        
        $result[] = $kingdomNode;
    }
    
    return $result;
}

/**
 * Get taxa ID by scientific name and rank
 */
private function getTaxaIdByName($name, $rank)
{
    $taxa = DB::table('taxas')
        ->whereRaw('LOWER(scientific_name) = ?', [strtolower($name)])
        ->whereRaw('LOWER(taxon_rank) = ?', [strtolower($rank)])
        ->first();
    
    return $taxa ? $taxa->id : null;
}

/**
 * Get common name for a taxa
 */
private function getCommonName($name, $rank)
{
    $taxa = DB::table('taxas')
        ->whereRaw('LOWER(scientific_name) = ?', [strtolower($name)])
        ->whereRaw('LOWER(taxon_rank) = ?', [strtolower($rank)])
        ->first();
    
    return $taxa ? ($taxa->Cname ?? '') : '';
}

private function processProfileImage($file)
{
    try {
        $uploadPath = storage_path('app/public/profile_pictures');
        if (!file_exists($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        $image = Image::make($file->getRealPath());

        // Resize gambar dengan mempertahankan aspek ratio
        $image->resize(800, 800, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });

        $fileName = time() . '_' . uniqid() . '.jpg';
        $relativePath = 'profile_pictures/' . $fileName;
        $fullPath = storage_path('app/public/' . $relativePath);

        // Simpan gambar dengan kualitas 80%
        $image->save($fullPath, 80);

        return [
            'success' => true,
            'path' => '/storage/' . $relativePath
        ];

    } catch (\Exception $e) {
        \Log::error('Error memproses foto profil: ' . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

public function update(Request $request)
{
    try {
        \Log::info('Profile update request:', $request->all());

        $user = auth()->user();

        // Update fields biasa
        $user->fname = $request->fname;
        $user->lname = $request->lname;
        $user->uname = $request->uname;
        $user->organization = $request->organization;
        $user->phone = $request->phone;
        $user->bio = $request->bio;
        $user->license = $request->license;
        // Persist per-type license defaults if provided
        if ($request->has('license_observation')) {
            $user->license_observation = $request->license_observation;
        }
        if ($request->has('license_photo')) {
            $user->license_photo = $request->license_photo;
        }
        if ($request->has('license_audio')) {
            $user->license_audio = $request->license_audio;
        }

        // Handle profile picture jika ada
        if ($request->hasFile('profile_picture')) {
            $file = $request->file('profile_picture');
            $oldPath = $user->getOriginal('profile_picture');

            // Upload ke S3 menggunakan processAndUploadImage dari S3MediaHandlerTrait
            $uploadResult = $this->processAndUploadImage($file, 'profile_pictures');
            
            if ($uploadResult['success']) {
                // Update database dengan path baru
                $user->profile_picture = $uploadResult['imagePath'];
                
                \Log::info('Profile picture uploaded to primary storage:', [
                    'path' => $uploadResult['imagePath'],
                    'storage_type' => $uploadResult['storage_type'],
                    'url' => $uploadResult['url']
                ]);

                // DUAL STORAGE: Simpan juga ke local storage sebagai backup/fallback
                try {
                    $localPath = storage_path('app/public/' . $uploadResult['imagePath']);
                    $localDir = dirname($localPath);
                    
                    if (!file_exists($localDir)) {
                        mkdir($localDir, 0777, true);
                    }
                    
                    // Jika primary storage adalah S3, copy file ke local juga
                    if ($uploadResult['storage_type'] === 's3') {
                        // Ambil konten dari S3 dan simpan ke local
                        $s3Content = Storage::disk('s3')->get($uploadResult['imagePath']);
                        Storage::disk('public')->put($uploadResult['imagePath'], $s3Content);
                        
                        \Log::info('Profile picture also saved to local storage (backup):', [
                            'path' => $uploadResult['imagePath']
                        ]);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Failed to save backup to local storage:', [
                        'path' => $uploadResult['imagePath'],
                        'error' => $e->getMessage()
                    ]);
                }

                // Hapus file lama jika ada (dari S3 dan local)
                if ($oldPath) {
                    try {
                        // Hapus dari S3
                        if (Storage::disk('s3')->exists($oldPath)) {
                            Storage::disk('s3')->delete($oldPath);
                            \Log::info('Old profile picture deleted from S3:', ['path' => $oldPath]);
                        }
                    } catch (\Exception $e) {
                        \Log::warning('Failed to delete old profile picture from S3:', ['path' => $oldPath, 'error' => $e->getMessage()]);
                    }
                    
                    try {
                        // Hapus dari local
                        if (Storage::disk('public')->exists($oldPath)) {
                            Storage::disk('public')->delete($oldPath);
                            \Log::info('Old profile picture deleted from local:', ['path' => $oldPath]);
                        }
                    } catch (\Exception $e) {
                        \Log::warning('Failed to delete old profile picture from local:', ['path' => $oldPath, 'error' => $e->getMessage()]);
                    }
                }
            } else {
                \Log::error('Failed to upload profile picture:', ['error' => $uploadResult['error'] ?? 'Unknown error']);
            }
        }

        $user->save();

        \Log::info('Profile updated successfully for user:', ['id' => $user->id]);

        // Format profile_picture URL untuk response dengan fallback S3 -> local
        $responseData = $user->toArray();
        if (isset($responseData['profile_picture']) && $responseData['profile_picture']) {
            $responseData['profile_picture'] = $this->getProfilePictureUrl($responseData['profile_picture']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diupdate',
            'data' => $responseData
        ]);

    } catch (\Exception $e) {
        \Log::error('Error updating profile:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Gagal mengupdate profil',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

public function getUserActivities($id, Request $request)
{
    try {
        $period = $request->query('period', 'year');

        // Set rentang waktu berdasarkan periode
        $endDate = now();
        $startDate = match($period) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'year' => now()->subYear(),
            default => now()->subYear(),
        };

        // Get user untuk cek burungnesia_user_id dan kupunesia_user_id
        $user = DB::table('fobi_users')
            ->select('id', 'burungnesia_user_id', 'kupunesia_user_id')
            ->where('id', $id)
            ->first();

        // Aktivitas FOBI dengan error handling
        $fobiActivities = DB::table('fobi_checklist_taxas')
            ->where('user_id', $id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count'),
                DB::raw("'fobi' as source")
            )
            ->groupBy(DB::raw('DATE(created_at)'));

        // Aktivitas Identifikasi dengan error handling
        $identificationActivities = DB::table('taxa_identifications')
            ->where('user_id', $id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count'),
                DB::raw("'identification' as source")
            )
            ->groupBy(DB::raw('DATE(created_at)'));

        // Gabungkan semua data dengan error handling
        $allActivities = collect();

        try {
            $allActivities = $allActivities->concat($fobiActivities->get());
        } catch (\Exception $e) {
            \Log::error('Error getting FOBI activities: ' . $e->getMessage());
        }

        // Aktivitas Burungnesia dari DB second - hitung checklist langsung
        if ($user && $user->burungnesia_user_id) {
            try {
                if (DB::connection('second')->getDatabaseName()) {
                    $birdActivities = DB::connection('second')
                        ->table('checklists')
                        ->where('user_id', $user->burungnesia_user_id)
                        ->where('active', 1)
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->select(
                            DB::raw('DATE(created_at) as date'),
                            DB::raw('COUNT(*) as count'),
                            DB::raw("'bird' as source")
                        )
                        ->groupBy(DB::raw('DATE(created_at)'))
                        ->get();
                    $allActivities = $allActivities->concat($birdActivities);
                }
            } catch (\Exception $e) {
                \Log::error('Error getting bird activities from DB second: ' . $e->getMessage());
            }
        }

        // Aktivitas Kupunesia dari DB third - hitung checklist langsung
        if ($user && $user->kupunesia_user_id) {
            try {
                if (DB::connection('third')->getDatabaseName()) {
                    $butterflyActivities = DB::connection('third')
                        ->table('checklists')
                        ->where('user_id', $user->kupunesia_user_id)
                        ->where('active', 1)
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->select(
                            DB::raw('DATE(created_at) as date'),
                            DB::raw('COUNT(*) as count'),
                            DB::raw("'butterfly' as source")
                        )
                        ->groupBy(DB::raw('DATE(created_at)'))
                        ->get();
                    $allActivities = $allActivities->concat($butterflyActivities);
                }
            } catch (\Exception $e) {
                \Log::error('Error getting butterfly activities from DB third: ' . $e->getMessage());
            }
        }

        try {
            $allActivities = $allActivities->concat($identificationActivities->get());
        } catch (\Exception $e) {
            \Log::error('Error getting identification activities: ' . $e->getMessage());
        }

        // Format data untuk grafik dengan pengecekan null
        $formattedData = $allActivities
            ->groupBy('date')
            ->map(function ($items) {
                return [
                    'date' => $items->first()->date,
                    'sources' => [
                        'fobi' => $items->where('source', 'fobi')->sum('count') ?? 0,
                        'bird' => $items->where('source', 'bird')->sum('count') ?? 0,
                        'butterfly' => $items->where('source', 'butterfly')->sum('count') ?? 0,
                        'identification' => $items->where('source', 'identification')->sum('count') ?? 0
                    ]
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $formattedData
        ]);

    } catch (\Exception $e) {
        \Log::error('Error in getUserActivities: ' . $e->getMessage(), [
            'id' => $id,
            'period' => $period ?? 'unknown',
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil data aktivitas',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

public function getTopTaxa($id)
{
    try {
        // Top 5 observasi - Optimasi query
        $topObservations = DB::table('fobi_checklist_taxas as fct')
            ->select(
                'fct.id as checklist_id',
                'taxas.id',
                'taxas.scientific_name',
                'taxas.genus',
                'taxas.family',
                'fu.uname as observer',
                'fu.id as observer_id',
                DB::raw('COUNT(DISTINCT fct.id) as count'),
                DB::raw("'fobi' as source")
            )
            ->join('taxas', 'fct.taxa_id', '=', 'taxas.id')
            ->join('fobi_users as fu', 'fct.user_id', '=', 'fu.id')
            ->where('fct.user_id', $id)
            ->groupBy('fct.id', 'taxas.id', 'taxas.scientific_name', 'taxas.genus', 'taxas.family', 'fu.uname', 'fu.id')
            ->orderByDesc('count')
            ->limit(5);

        // Ambil media untuk setiap observasi
        $observations = $topObservations->get()->map(function($observation) {
            // Ambil media untuk observasi ini
            $media = DB::table('fobi_checklist_media')
                ->where('checklist_id', $observation->checklist_id)
                ->where('status', 0)
                ->select(
                    'id',
                    'file_path',
                    'spectrogram',
                    'storage_type',
                    'media_type'
                )
                ->get();

            // Tambahkan URL media
            $observation->media = $media->map(function($item) {
                return [
                    'id' => $item->id,
                    'url' => MediaStorageHelper::getMediaUrl(
                        $item->file_path,
                        $item->storage_type ?? 'local',
                        $item->id
                    ),
                    'spectrogram_url' => $item->spectrogram ? MediaStorageHelper::getMediaUrl(
                        $item->spectrogram,
                        $item->storage_type ?? 'local',
                        $item->id
                    ) : null,
                    'type' => $item->media_type
                ];
            });

            return $observation;
        });

        // Top 5 Identifikasi - Optimasi query
        $topIdentifications = DB::table('taxa_identifications as ti')
            ->select(
                DB::raw('COALESCE(ti.checklist_id, ti.burnes_checklist_id, ti.kupnes_checklist_id) as checklist_id'),
                'taxas.id',
                'taxas.scientific_name',
                'taxas.genus',
                'taxas.family',
                'fu.uname as observer',
                'fu.id as observer_id',
                DB::raw('COUNT(DISTINCT ti.id) as count'),
                DB::raw("CASE
                    WHEN ti.burnes_checklist_id IS NOT NULL THEN 'burungnesia'
                    WHEN ti.kupnes_checklist_id IS NOT NULL THEN 'kupunesia'
                    ELSE 'fobi'
                END as source")
            )
            ->join('taxas', 'ti.taxon_id', '=', 'taxas.id')
            ->join('fobi_users as fu', 'ti.user_id', '=', 'fu.id')
            ->where('ti.user_id', $id)
            ->groupBy(
                'ti.checklist_id',
                'ti.burnes_checklist_id',
                'ti.kupnes_checklist_id',
                'taxas.id',
                'taxas.scientific_name',
                'taxas.genus',
                'taxas.family',
                'fu.uname',
                'fu.id'
            )
            ->orderByDesc('count')
            ->limit(5);

        // Ambil media untuk setiap identifikasi
        $identifications = $topIdentifications->get()->map(function($identification) {
            if ($identification->source === 'fobi') {
                $media = DB::table('fobi_checklist_media')
                    ->where('checklist_id', $identification->checklist_id)
                    ->where('status', 0)
                    ->select(
                        'id',
                        'file_path',
                        'spectrogram',
                        'storage_type',
                        'media_type'
                    )
                    ->get();

                $identification->media = $media->map(function($item) {
                    return [
                        'id' => $item->id,
                        'url' => MediaStorageHelper::getMediaUrl(
                            $item->file_path,
                            $item->storage_type ?? 'local',
                            $item->id
                        ),
                        'spectrogram_url' => $item->spectrogram ? MediaStorageHelper::getMediaUrl(
                            $item->spectrogram,
                            $item->storage_type ?? 'local',
                            $item->id
                        ) : null,
                        'type' => $item->media_type
                    ];
                });
            }
            return $identification;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'observations' => $observations,
                'identifications' => $identifications
            ]
        ]);

    } catch (\Exception $e) {
        \Log::error('Error in getTopTaxa: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil data taksa teratas',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

public function getUserObservations($id, Request $request)
{
    try {
        $user = DB::table('fobi_users')
            ->select('id', 'uname', 'burungnesia_user_id', 'kupunesia_user_id')
            ->where('id', $id)
            ->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User tidak ditemukan'], 404);
        }

        $search = strtolower($request->query('search', ''));
        $searchType = $request->query('search_type', 'all');
        $dateFilter = $request->query('date', '');
        $perPage = $request->query('per_page', 20);
        $page = $request->query('page', 1);
        $isMapRequest = $request->query('map', false);
        $sourceFilter = $request->query('source', 'all'); // all, fobi, burungnesia, kupunesia
        $gradeFilter = $request->query('grade', 'all'); // all, research_grade, confirmed_id, needs_id, low_quality_id

        // Query untuk observasi FOBI
        $fobiObservations = DB::table('fobi_checklist_taxas as fct')
            ->select([
                DB::raw('DISTINCT fct.id'),
                'fct.scientific_name as nama_latin',
                't.cname_species as nama_umum',
                'fct.latitude',
                'fct.longitude',
                'fct.date as observation_date',
                DB::raw('(SELECT file_path FROM fobi_checklist_media WHERE checklist_id = fct.id ORDER BY id ASC LIMIT 1) as photo_url'),
                DB::raw('(SELECT storage_type FROM fobi_checklist_media WHERE checklist_id = fct.id ORDER BY id ASC LIMIT 1) as storage_type'),
                DB::raw('(SELECT spectrogram FROM fobi_checklist_media WHERE checklist_id = fct.id AND spectrogram IS NOT NULL ORDER BY id ASC LIMIT 1) as spectrogram'),
                DB::raw('(SELECT media_type FROM fobi_checklist_media WHERE checklist_id = fct.id ORDER BY id ASC LIMIT 1) as media_type'),
                DB::raw('(SELECT grade FROM taxa_quality_assessments WHERE taxa_id = fct.id LIMIT 1) as grade'),
                DB::raw('(SELECT COUNT(*) FROM taxa_identifications WHERE checklist_id = fct.id AND burnes_checklist_id IS NULL AND kupnes_checklist_id IS NULL AND (is_agreed = 1 OR is_agreed IS NULL) AND (is_withdrawn = 0 OR is_withdrawn IS NULL)) as identifications_count'),
                'u.uname as observer_name',
                'fct.family',
                'fct.order as ordo',
                'fct.created_at',
                DB::raw("'fobi' as source")
            ])
            ->leftJoin('taxas as t', 't.id', '=', 'fct.taxa_id')
            ->leftJoin('fobi_users as u', 'u.id', '=', 'fct.user_id')
            ->where('fct.user_id', $id);

        $allObservations = collect($fobiObservations->get());

        // Debug logging
        \Log::info('getUserObservations Debug:', [
            'user_id' => $id,
            'burungnesia_user_id' => $user->burungnesia_user_id ?? null,
            'kupunesia_user_id' => $user->kupunesia_user_id ?? null,
            'fobi_count' => $allObservations->count()
        ]);

        // Cek dan tambahkan data Burungnesia dari database second
        if ($user->burungnesia_user_id) {
            try {
                if (DB::connection('second')->getDatabaseName()) {
                    \Log::info('Querying Burungnesia DB second for user_id: ' . $user->burungnesia_user_id);
                    
                    // Query checklist dengan LEFT JOIN ke checklist_fauna
                    // Prioritaskan nama dari checklist_fauna (dari amaturalist), fallback ke taxas FOBi
                    // Label diambil dari tabel checklisttr
                    $birdObservations = DB::connection('second')
                        ->table('checklists as c')
                        ->select([
                            'c.id',
                            'c.latitude',
                            'c.longitude',
                            DB::raw('(SELECT label FROM checklisttr WHERE checklist_id = c.id LIMIT 1) as location_name'),
                            'c.tgl_pengamatan as observation_date',
                            DB::raw('(SELECT images FROM checklist_fauna_imgs WHERE checklist_id = c.id ORDER BY id ASC LIMIT 1) as photo_url'),
                            DB::raw('0 as identifications_count'),
                            DB::raw('(SELECT uname FROM users WHERE id = c.user_id LIMIT 1) as observer_name'),
                            'c.created_at',
                            DB::raw("'bird' as source"),
                            // Ambil fauna_id dan nama dari checklist_fauna pertama
                            DB::raw('(SELECT fauna_id FROM checklist_fauna WHERE checklist_id = c.id AND deleted_at IS NULL LIMIT 1) as fauna_id'),
                            DB::raw('(SELECT nama_latin FROM checklist_fauna WHERE checklist_id = c.id AND deleted_at IS NULL LIMIT 1) as nama_latin'),
                            DB::raw('(SELECT nama_spesies FROM checklist_fauna WHERE checklist_id = c.id AND deleted_at IS NULL LIMIT 1) as nama_umum'),
                            DB::raw('(SELECT COUNT(*) FROM checklist_fauna WHERE checklist_id = c.id AND deleted_at IS NULL) as species_count')
                        ])
                        ->where('c.user_id', $user->burungnesia_user_id)
                        ->where('c.active', 1);

                    $birdData = $birdObservations->get();
                    
                    // Lookup ke FOBi taxas untuk data yang kosong
                    foreach ($birdData as $obs) {
                        if (empty($obs->nama_latin) && !empty($obs->fauna_id)) {
                            $taxa = DB::table('taxas')
                                ->select('scientific_name', 'cname_species', 'family')
                                ->where('id', $obs->fauna_id)
                                ->first();
                            if ($taxa) {
                                $obs->nama_latin = $taxa->scientific_name;
                                $obs->nama_umum = $taxa->cname_species ?? $taxa->scientific_name;
                                $obs->family = $taxa->family;
                            }
                        }
                        // Set default jika masih kosong
                        if (empty($obs->nama_latin)) {
                            $obs->nama_latin = 'Unknown';
                            $obs->nama_umum = 'Unknown';
                        }
                        $obs->family = $obs->family ?? null;
                        $obs->ordo = null;
                    }
                    \Log::info('Burungnesia query result count: ' . count($birdData));
                    $allObservations = $allObservations->concat($birdData);
                }
            } catch (\Exception $e) {
                \Log::warning('Error fetching bird observations: ' . $e->getMessage());
            }
        } else {
            \Log::info('User has no burungnesia_user_id');
        }

        // Cek dan tambahkan data Kupunesia dari database third
        if ($user->kupunesia_user_id) {
            try {
                if (DB::connection('third')->getDatabaseName()) {
                    \Log::info('Querying Kupunesia DB third for user_id: ' . $user->kupunesia_user_id);
                    
                    // Query checklist langsung, ambil fauna_id dari checklist_fauna
                    // Label diambil dari tabel checklisttr
                    $butterflyObservations = DB::connection('third')
                        ->table('checklists as c')
                        ->select([
                            'c.id',
                            'c.latitude',
                            'c.longitude',
                            DB::raw('(SELECT label FROM checklisttr WHERE checklist_id = c.id LIMIT 1) as location_name'),
                            'c.tgl_pengamatan as observation_date',
                            DB::raw('(SELECT images FROM checklist_fauna_imgs WHERE checklist_id = c.id ORDER BY id ASC LIMIT 1) as photo_url'),
                            DB::raw('0 as identifications_count'),
                            DB::raw('(SELECT uname FROM users WHERE id = c.user_id LIMIT 1) as observer_name'),
                            'c.created_at',
                            DB::raw("'butterfly' as source"),
                            // Ambil fauna_id dan nama dari checklist_fauna pertama
                            DB::raw('(SELECT fauna_id FROM checklist_fauna WHERE checklist_id = c.id AND deleted_at IS NULL LIMIT 1) as fauna_id'),
                            DB::raw('(SELECT nama_latin FROM checklist_fauna WHERE checklist_id = c.id AND deleted_at IS NULL LIMIT 1) as cf_nama_latin'),
                            DB::raw('(SELECT nama_spesies FROM checklist_fauna WHERE checklist_id = c.id AND deleted_at IS NULL LIMIT 1) as cf_nama_umum'),
                            DB::raw('(SELECT COUNT(*) FROM checklist_fauna WHERE checklist_id = c.id AND deleted_at IS NULL) as species_count')
                        ])
                        ->where('c.user_id', $user->kupunesia_user_id)
                        ->where('c.active', 1);

                    $butterflyData = $butterflyObservations->get();
                    
                    // Prioritaskan nama dari checklist_fauna, fallback ke FOBi taxas
                    foreach ($butterflyData as $obs) {
                        // Gunakan nama dari checklist_fauna jika ada
                        $obs->nama_latin = $obs->cf_nama_latin ?? null;
                        $obs->nama_umum = $obs->cf_nama_umum ?? null;
                        $obs->family = null;
                        
                        // Jika kosong, lookup ke FOBi taxas
                        if (empty($obs->nama_latin) && !empty($obs->fauna_id)) {
                            $taxa = DB::table('taxas')
                                ->select('scientific_name', 'cname_species', 'family')
                                ->where('id', $obs->fauna_id)
                                ->first();
                            if ($taxa) {
                                $obs->nama_latin = $taxa->scientific_name;
                                $obs->nama_umum = $taxa->cname_species ?? $taxa->scientific_name;
                                $obs->family = $taxa->family;
                            }
                        }
                        // Set default jika masih kosong
                        if (empty($obs->nama_latin)) {
                            $obs->nama_latin = 'Unknown';
                            $obs->nama_umum = 'Unknown';
                        }
                        $obs->ordo = null;
                        // Hapus field temporary
                        unset($obs->cf_nama_latin, $obs->cf_nama_umum);
                    }
                    
                    \Log::info('Kupunesia query result count: ' . count($butterflyData));
                    $allObservations = $allObservations->concat($butterflyData);
                }
            } catch (\Exception $e) {
                \Log::warning('Error fetching butterfly observations: ' . $e->getMessage());
            }
        } else {
            \Log::info('User has no kupunesia_user_id');
        }

        // Log total setelah merge
        \Log::info('Total observations after merge: ' . $allObservations->count());

        // Filter berdasarkan source jika bukan request map
        if (!$isMapRequest && $sourceFilter !== 'all') {
            $allObservations = $allObservations->filter(function($item) use ($sourceFilter) {
                if ($sourceFilter === 'fobi') {
                    return $item->source === 'fobi';
                } elseif ($sourceFilter === 'burungnesia') {
                    return $item->source === 'bird';
                } elseif ($sourceFilter === 'kupunesia') {
                    return $item->source === 'butterfly';
                }
                return true;
            });
        }

        // Filter berdasarkan grade jika bukan request map
        if (!$isMapRequest && $gradeFilter !== 'all') {
            $allObservations = $allObservations->filter(function($item) use ($gradeFilter) {
                $grade = strtolower($item->grade ?? '');
                switch ($gradeFilter) {
                    case 'research_grade':
                        return $grade === 'research grade';
                    case 'confirmed_id':
                        return $grade === 'confirmed id';
                    case 'needs_id':
                        return $grade === 'needs id';
                    case 'low_quality_id':
                        return $grade === 'low quality id';
                    default:
                        return true;
                }
            });
        }

        // Filter berdasarkan pencarian jika bukan request map
        if (!$isMapRequest && ($search || $dateFilter)) {
            $allObservations = $allObservations->filter(function($item) use ($search, $searchType, $dateFilter) {
                // Filter tanggal
                if ($dateFilter) {
                    $itemDate = substr($item->observation_date, 0, 10); // Ambil hanya bagian YYYY-MM-DD
                    if ($itemDate !== $dateFilter) {
                        return false;
                    }
                }

                // Filter pencarian teks
                if ($search) {
                    $namaLatin = strtolower($item->nama_latin ?? '');
                    $namaUmum = strtolower($item->nama_umum ?? '');
                    $family = strtolower($item->family ?? '');
                    $locationName = strtolower($item->location_name ?? '');
                    
                    switch ($searchType) {
                        case 'species':
                            return str_contains($namaLatin, $search) ||
                                   str_contains($namaUmum, $search);
                        case 'location':
                            return str_contains($locationName, $search);
                        case 'date':
                            return str_contains(strtolower($item->observation_date ?? ''), $search);
                        default:
                            // Pencarian default: cari di semua field
                            return str_contains($namaLatin, $search) ||
                                   str_contains($namaUmum, $search) ||
                                   str_contains($family, $search) ||
                                   str_contains($locationName, $search);
                    }
                }

                return true;
            });
        }

        // Urutkan berdasarkan waktu terbaru
        // Gunakan created_at sebagai primary sort karena memiliki jam/menit/detik yang akurat
        // observation_date seringkali hanya menyimpan tanggal tanpa waktu
        $allObservations = $allObservations->sortByDesc(function($item) {
            // Gunakan created_at sebagai primary karena memiliki timestamp lengkap
            $createdAt = $item->created_at ?? '1970-01-01 00:00:00';
            return strtotime($createdAt);
        });
        
        // Debug: log 5 observasi pertama setelah sorting
        $first5 = $allObservations->take(5)->map(function($item) {
            return [
                'source' => $item->source,
                'observation_date' => $item->observation_date ?? null,
                'created_at' => $item->created_at ?? null,
                'nama_latin' => $item->nama_latin ?? null
            ];
        });
        \Log::info('First 5 observations after sorting:', $first5->toArray());
        
        // Transform photo_url dan spectrogram untuk FOBI observations agar menjadi URL yang valid
        $allObservations = $allObservations->map(function($obs) {
            // Konversi ke array untuk modifikasi
            $obsArray = (array) $obs;
            
            // Proses photo_url untuk source fobi
            if ($obsArray['source'] === 'fobi' && !empty($obsArray['photo_url'])) {
                $filePath = $obsArray['photo_url'];
                $storageType = $obsArray['storage_type'] ?? 'local';
                
                // Jika sudah URL lengkap, biarkan
                if (filter_var($filePath, FILTER_VALIDATE_URL)) {
                    // Already a valid URL
                } elseif ($storageType === 's3') {
                    // S3 storage
                    $obsArray['photo_url'] = config('filesystems.disks.s3.url') . '/' . $filePath;
                } else {
                    // Local storage
                    if (strpos($filePath, 'storage/') === 0) {
                        $obsArray['photo_url'] = asset($filePath);
                    } elseif (strpos($filePath, '/storage/') === 0) {
                        $obsArray['photo_url'] = url($filePath);
                    } else {
                        $obsArray['photo_url'] = asset('storage/' . $filePath);
                    }
                }
            }
            
            // Proses spectrogram URL untuk source fobi
            if ($obsArray['source'] === 'fobi' && !empty($obsArray['spectrogram'])) {
                $spectrogramPath = $obsArray['spectrogram'];
                $storageType = $obsArray['storage_type'] ?? 'local';
                
                if (filter_var($spectrogramPath, FILTER_VALIDATE_URL)) {
                    $obsArray['spectrogram_url'] = $spectrogramPath;
                } elseif ($storageType === 's3') {
                    $obsArray['spectrogram_url'] = config('filesystems.disks.s3.url') . '/' . $spectrogramPath;
                } else {
                    if (strpos($spectrogramPath, 'storage/') === 0) {
                        $obsArray['spectrogram_url'] = asset($spectrogramPath);
                    } elseif (strpos($spectrogramPath, '/storage/') === 0) {
                        $obsArray['spectrogram_url'] = url($spectrogramPath);
                    } else {
                        $obsArray['spectrogram_url'] = asset('storage/' . $spectrogramPath);
                    }
                }
                
                // Jika media_type adalah audio, set audio_url
                if (($obsArray['media_type'] ?? '') === 'audio' && !empty($obsArray['photo_url'])) {
                    $obsArray['audio_url'] = $obsArray['photo_url'];
                }
            }
            
            return (object) $obsArray;
        });

        if ($isMapRequest) {
            // Jika request untuk map, kembalikan semua data
            return response()->json([
                'success' => true,
                'data' => $allObservations->values()
            ]);
        }

        // Jika bukan request map, lakukan paginasi untuk tabel
        $total = $allObservations->count();
        $lastPage = $total > 0 ? ceil($total / $perPage) : 1;
        $paginatedObservations = $allObservations->forPage($page, $perPage);

        return response()->json([
            'success' => true,
            'data' => $paginatedObservations->values(),
            'meta' => [
                'total' => $total,
                'current_page' => (int) $page,
                'per_page' => (int) $perPage,
                'last_page' => (int) $lastPage
            ]
        ]);

    } catch (\Exception $e) {
        \Log::error('Error in getUserObservations:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil data observasi'
        ], 500);
    }
}

private function getLocationName($latitude, $longitude)
{
    try {
        $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$latitude}&lon={$longitude}&zoom=18&addressdetails=1";
        $response = file_get_contents($url, false, stream_context_create([
            'http' => [
                'header' => 'User-Agent: FOBI/1.0'
            ]
        ]));

        $data = json_decode($response);
        return $data->display_name ?? null;
    } catch (\Exception $e) {
        return null;
    }
}

public function getSearchSuggestions(Request $request)
{
    try {
        $query = $request->get('query', '');
        $type = $request->get('type', 'species');

        if (empty($query)) {
            return response()->json([
                'success' => true,
                'data' => []
            ]);
        }

        if ($type === 'species') {
            // Cari di tabel taxas
            $taxaSuggestions = DB::table('taxas')
                ->where(function($q) use ($query) {
                    $q->where('scientific_name', 'LIKE', "%{$query}%")
                      ->orWhere('cname_species', 'LIKE', "%{$query}%");
                })
                ->select(
                    'id',
                    'scientific_name',
                    'cname_species as common_name',
                    DB::raw("'taxa' as type")
                )
                ->take(5);

            // Cari di database burungnesia (second)
            try {
                $birdSuggestions = DB::connection('second')
                    ->table('faunas')
                    ->where(function($q) use ($query) {
                        $q->where('nameLat', 'LIKE', "%{$query}%")
                          ->orWhere('nameId', 'LIKE', "%{$query}%");
                    })
                    ->select(
                        'id',
                        'nameLat as scientific_name',
                        'nameId as common_name',
                        DB::raw("'bird' as type")
                    )
                    ->take(5);

                $taxaSuggestions->union($birdSuggestions);
            } catch (\Exception $e) {
                \Log::error('Error querying bird database: ' . $e->getMessage());
            }

            // Cari di database kupunesia (third)
            try {
                $butterflySuggestions = DB::connection('third')
                    ->table('faunas')
                    ->where(function($q) use ($query) {
                        $q->where('nameLat', 'LIKE', "%{$query}%")
                          ->orWhere('nameId', 'LIKE', "%{$query}%");
                    })
                    ->select(
                        'id',
                        'nameLat as scientific_name',
                        'nameId as common_name',
                        DB::raw("'butterfly' as type")
                    )
                    ->take(5);

                $taxaSuggestions->union($butterflySuggestions);
            } catch (\Exception $e) {
                \Log::error('Error querying butterfly database: ' . $e->getMessage());
            }

            $suggestions = $taxaSuggestions->get();
        } else {
            // Suggestions lokasi dari Nominatim
            $suggestions = $this->getLocationSuggestions($query);
        }

        return response()->json([
            'success' => true,
            'data' => $suggestions
        ]);

    } catch (\Exception $e) {
        \Log::error('Error in getSearchSuggestions: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal mendapatkan saran pencarian'
        ], 500);
    }
}

private function getLocationSuggestions($query)
{
    try {
        $url = "https://nominatim.openstreetmap.org/search?format=json&q=" . urlencode($query) . "&limit=5";
        $response = file_get_contents($url, false, stream_context_create([
            'http' => ['header' => 'User-Agent: FOBI/1.0']
        ]));

        return collect(json_decode($response))->map(function($item) {
            return [
                'id' => $item->place_id,
                'name' => $item->display_name,
                'lat' => $item->lat,
                'lon' => $item->lon
            ];
        });
    } catch (\Exception $e) {
        return collect([]);
    }
}

public function getGridObservations($id, Request $request)
{
    try {
        $zoom = $request->get('zoom', 5);
        $bounds = json_decode($request->get('bounds'), true);
        $search = strtolower($request->get('search', ''));
        $dateFilter = $request->get('date', '');
        $searchType = $request->get('search_type', 'all');

        // Tentukan ukuran grid berdasarkan zoom level
        $gridSize = match(true) {
            $zoom >= 12 => 0.01, // ~1km
            $zoom >= 10 => 0.05, // ~5km
            $zoom >= 8 => 0.1,   // ~10km
            default => 0.5,      // ~50km
        };

        // Query untuk FOBI
        $fobiObservations = DB::table('fobi_checklist_taxas as fct')
            ->select([
                'fct.id',
                'fct.scientific_name as nama_latin',
                't.cname_species as nama_umum',
                'fct.latitude',
                'fct.longitude',
                'fct.date as observation_date',
                'fct.family',
                DB::raw('(SELECT file_path FROM fobi_checklist_media WHERE checklist_id = fct.id LIMIT 1) as photo_url'),
                DB::raw("'fobi' as source"),
                'l.name as location_name'
            ])
            ->join('taxas as t', 't.id', '=', 'fct.taxa_id')
            ->leftJoin('locations as l', 'l.id', '=', 'fct.location_id')
            ->where('fct.user_id', $id);

        // Query untuk Burungnesia
        $birdObservations = DB::table('bird_observations as bo')
            ->select([
                'bo.id',
                'bo.scientific_name as nama_latin',
                'bo.common_name as nama_umum',
                'bo.latitude',
                'bo.longitude',
                'bo.observation_date',
                'bo.family',
                'bo.image_url as photo_url',
                DB::raw("'bird' as source"),
                'bo.location_name'
            ])
            ->where('bo.user_id', $id);

        // Query untuk Kupunesia
        $butterflyObservations = DB::table('butterfly_observations as bfo')
            ->select([
                'bfo.id',
                'bfo.scientific_name as nama_latin',
                'bfo.common_name as nama_umum',
                'bfo.latitude',
                'bfo.longitude',
                'bfo.observation_date',
                'bfo.family',
                'bfo.image_url as photo_url',
                DB::raw("'butterfly' as source"),
                'bfo.location_name'
            ])
            ->where('bfo.user_id', $id);

        // Gabungkan semua query
        $observations = $fobiObservations
            ->union($birdObservations)
            ->union($butterflyObservations);

        // Filter bounds jika ada
        if ($bounds) {
            $observations = $observations->whereBetween('latitude',
                [$bounds['_southWest']['lat'], $bounds['_northEast']['lat']])
                ->whereBetween('longitude',
                    [$bounds['_southWest']['lng'], $bounds['_northEast']['lng']]);
        }

        $observations = $observations->get();

        // Filter hasil
        if ($search || $dateFilter) {
            $observations = $observations->filter(function($obs) use ($search, $searchType, $dateFilter) {
                // Filter tanggal
                if ($dateFilter) {
                    $obsDate = substr($obs->observation_date, 0, 10);
                    if ($obsDate !== $dateFilter) {
                        return false;
                    }
                }

                // Filter berdasarkan tipe pencarian
                if ($search) {
                    switch ($searchType) {
                        case 'species':
                            return str_contains(strtolower($obs->nama_latin), $search) ||
                                   str_contains(strtolower($obs->nama_umum), $search);

                        case 'family':
                            return str_contains(strtolower($obs->family ?? ''), $search);

                        case 'location':
                            return str_contains(strtolower($obs->location_name ?? ''), $search);

                        case 'source':
                            $sourceName = match($obs->source) {
                                'bird' => 'burungnesia',
                                'butterfly' => 'kupunesia',
                                'fobi' => 'fobi',
                                default => strtolower($obs->source)
                            };
                            return str_contains($sourceName, $search);

                        case 'date':
                            return str_contains($obs->observation_date, $search);

                        case 'all':
                        default:
                            return str_contains(strtolower($obs->nama_latin), $search) ||
                                   str_contains(strtolower($obs->nama_umum), $search) ||
                                   str_contains(strtolower($obs->family ?? ''), $search) ||
                                   str_contains(strtolower($obs->location_name ?? ''), $search) ||
                                   str_contains($obs->observation_date, $search) ||
                                   str_contains(strtolower($obs->source), $search);
                    }
                }

                return true;
            });
        }

        // Buat grid
        $grid = [];
        foreach ($observations as $obs) {
            $latGrid = floor($obs->latitude / $gridSize) * $gridSize;
            $lonGrid = floor($obs->longitude / $gridSize) * $gridSize;
            $gridKey = "{$latGrid},{$lonGrid}";

            if (!isset($grid[$gridKey])) {
                $grid[$gridKey] = [
                    'center' => [
                        'lat' => $latGrid + ($gridSize/2),
                        'lng' => $lonGrid + ($gridSize/2)
                    ],
                    'count' => 0,
                    'species' => [],
                    'observations' => [],
                    'size' => $gridSize
                ];
            }

            $grid[$gridKey]['count']++;
            $grid[$gridKey]['species'][] = $obs->nama_latin;
            $grid[$gridKey]['species'] = array_unique($grid[$gridKey]['species']);
            $grid[$gridKey]['observations'][] = [
                'id' => $obs->id,
                'nama_latin' => $obs->nama_latin,
                'nama_umum' => $obs->nama_umum,
                'source' => $obs->source,
                'date' => $obs->observation_date,
                'family' => $obs->family ?? null,
                'photo_url' => $obs->photo_url ?? null,
                'location_name' => $obs->location_name ?? null,
                'latitude' => $obs->latitude,
                'longitude' => $obs->longitude
            ];
        }

        return response()->json([
            'success' => true,
            'data' => array_values($grid)
        ]);

    } catch (\Exception $e) {
        \Log::error('Error in getGridObservations:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Gagal mendapatkan data grid'
        ], 500);
    }
}

public function syncPlatformEmail(Request $request, $platform)
{
    try {
        \Log::info('Received sync request:', [
            'platform' => $platform,
            'request_data' => $request->all()
        ]);

        // Validasi input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
            'recaptcha_token' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verifikasi reCAPTCHA
        $recaptchaSecret = config('services.recaptcha.secret_key');
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $recaptchaSecret,
            'response' => $request->recaptcha_token,
        ]);

        $recaptchaResult = $response->json();
        if (!$recaptchaResult['success'] || $recaptchaResult['score'] < 0.5) {
            return response()->json([
                'success' => false,
                'message' => 'Verifikasi keamanan gagal'
            ], 400);
        }

        $user = auth()->user();

        // Cek dan dapatkan user ID dari platform tujuan
        $platformUserId = null;
        if ($platform === 'burungnesia') {
            $platformUser = DB::connection('second')
                ->table('users')
                ->where('email', $request->email)
                ->first();
            $platformUserId = $platformUser ? $platformUser->id : null;
        } else {
            $platformUser = DB::connection('third')
                ->table('users')
                ->where('email', $request->email)
                ->first();
            $platformUserId = $platformUser ? $platformUser->id : null;
        }

        if (!$platformUserId) {
            return response()->json([
                'success' => false,
                'message' => 'Email belum terdaftar di platform tujuan'
            ], 400);
        }

        // Update user dengan email dan token baru
        $verificationToken = Str::random(60);
        $user->{$platform.'_email'} = $request->email;
        $user->{$platform.'_email_verification_token'} = $verificationToken;
        $user->{$platform.'_user_id'} = $platformUserId;
        $user->save();

        // Kirim email verifikasi
        Mail::to($request->email)->send(new VerifyEmail($user, $platform.'_email_verification_token'));

        return response()->json([
            'success' => true,
            'message' => 'Link verifikasi telah dikirim ke email Anda'
        ]);

    } catch (\Exception $e) {
        \Log::error('Error in syncPlatformEmail:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Terjadi kesalahan saat menghubungkan akun',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

public function getUserProfile()
{
    try {
        $user = auth()->user();

        // Tambahkan log untuk debugging
        \Log::info('Getting user profile for:', ['user_id' => $user->id]);

        // Ambil data user dengan profile_picture
        $userData = FobiUser::select(
            'id',
            'fname',
            'lname',
            'uname',
            'email',
            'phone',
            'organization',
            'bio',
            'profile_picture',
            'burungnesia_email',
            'burungnesia_email_verified_at',
            'burungnesia_user_id',
            'kupunesia_email',
            'kupunesia_email_verified_at',
            'kupunesia_user_id',
            'created_at'
        )->where('id', $user->id)->first();

        // Log data user untuk debugging
        \Log::info('User data retrieved:', ['data' => $userData]);

        if (!$userData) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        // Format profile_picture URL untuk frontend dengan fallback S3 -> local
        if ($userData->profile_picture) {
            $userData->profile_picture = $this->getProfilePictureUrl($userData->profile_picture);
        }

        // Log untuk debugging
        \Log::info('User data retrieved:', ['data' => $userData->toArray()]);

        return response()->json([
            'success' => true,
            'data' => $userData
        ]);

    } catch (\Exception $e) {
        \Log::error('Error in getUserProfile:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil data profil',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

public function resendPlatformVerification(Request $request, $platform)
{
    try {
        $user = auth()->user();

        // Validasi platform
        if (!in_array($platform, ['burungnesia', 'kupunesia'])) {
            return response()->json([
                'success' => false,
                'message' => 'Platform tidak valid'
            ], 400);
        }

        // Cek apakah email sudah ada
        $platformEmail = $user->{$platform.'_email'};
        if (!$platformEmail) {
            return response()->json([
                'success' => false,
                'message' => 'Email belum terdaftar'
            ], 400);
        }

        // Cek apakah sudah terverifikasi
        if ($user->{$platform.'_email_verified_at'}) {
            return response()->json([
                'success' => false,
                'message' => 'Email sudah terverifikasi'
            ], 400);
        }

        // Generate token baru
        $verificationToken = Str::random(60);
        $user->{$platform.'_email_verification_token'} = $verificationToken;
        $user->save();

        // Kirim email verifikasi
        Mail::to($platformEmail)->send(new VerifyEmail($user, $platform.'_email_verification_token'));

        return response()->json([
            'success' => true,
            'message' => 'Email verifikasi telah dikirim ulang'
        ]);

    } catch (\Exception $e) {
        \Log::error('Error in resendPlatformVerification:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Gagal mengirim ulang email verifikasi',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

public function unlinkPlatformAccount(Request $request, $platform)
{
    try {
        // Validasi platform
        if (!in_array($platform, ['burungnesia', 'kupunesia'])) {
            return response()->json([
                'success' => false,
                'message' => 'Platform tidak valid'
            ], 400);
        }

        $user = auth()->user();

        // Reset semua field terkait platform
        $user->{$platform.'_email'} = null;
        $user->{$platform.'_email_verified_at'} = null;
        $user->{$platform.'_email_verification_token'} = null;
        $user->{$platform.'_user_id'} = null;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil dilepaskan'
        ]);

    } catch (\Exception $e) {
        \Log::error('Error in unlinkPlatformAccount:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Gagal melepaskan akun',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

public function updateEmail(Request $request)
{
    try {
        // Validasi input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:fobi_users,email,' . auth()->id(),
            'password' => 'required|string',
            'recaptcha_token' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        // Dapatkan user yang sedang login
        $user = auth()->user();

        // Pastikan user ditemukan
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        // Verifikasi reCAPTCHA
        $recaptchaSecret = config('services.recaptcha.secret_key');
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $recaptchaSecret,
            'response' => $request->recaptcha_token,
        ]);

        if (!$response->json()['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Verifikasi keamanan gagal'
            ], 400);
        }

        // Verifikasi password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password salah'
            ], 400);
        }

        // Generate token verifikasi
        $verificationToken = Str::random(60);

        // Update email dan token
        $user->email = $request->email;
        $user->email_verified_at = null;
        $user->email_verification_token = $verificationToken;
        $user->save();

        // Kirim email verifikasi
        Mail::to($request->email)->send(new VerifyEmail($user, 'email_verification_token'));

        return response()->json([
            'success' => true,
            'message' => 'Email berhasil diupdate. Silakan verifikasi email baru Anda.',
            'data' => [
                'email' => $request->email,
                'redirectTo' => '/verification-pending',
                'state' => [
                    'email' => $request->email,
                    'hasBurungnesia' => !is_null($user->burungnesia_email),
                    'hasKupunesia' => !is_null($user->kupunesia_email)
                ]
            ]
        ]);

    } catch (\Exception $e) {
        \Log::error('Error updating email:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Gagal mengupdate email'
        ], 500);
    }
}

/**
 * Get user statistics including observations and first identifications
 * 
 * Identifikasi Perdana: Identifikasi pertama yang dibuat user pada observasi
 * yang kemudian mencapai research grade atau confirmed id
 * 
 * @param int $id User ID
 * @return \Illuminate\Http\JsonResponse
 */
public function getUserStats($id)
{
    try {
        $user = DB::table('fobi_users')->find($id);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        // Hitung total observasi dari fobi_checklist_taxas
        $totalObservations = DB::table('fobi_checklist_taxas')
            ->where('user_id', $id)
            ->count();

        // Hitung identifikasi perdana
        // Logika: (a) observasi milik user sendiri yang mencapai research grade
        //         (b) identifikasi pertama user di observasi orang lain yang mencapai research grade
        
        // 1. Hitung observasi milik user sendiri yang mencapai research grade (FOBi)
        $ownObservationsResearchGrade = DB::table('fobi_checklist_taxas as fct')
            ->join('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
            ->where('fct.user_id', $id)
            ->whereIn('tqa.grade', ['research grade', 'confirmed id'])
            ->count();
        
        // 2. Hitung identifikasi pertama user di observasi orang lain yang mencapai research grade
        $firstIdentificationsOthers = DB::table('taxa_identifications as ti')
            ->join('fobi_checklist_taxas as fct', 'ti.checklist_id', '=', 'fct.id')
            ->join('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
            ->where('ti.user_id', $id)
            ->where('ti.is_first', true)
            ->where('ti.is_withdrawn', false)
            ->where('fct.user_id', '!=', $id) // Observasi orang lain
            ->whereIn('tqa.grade', ['research grade', 'confirmed id'])
            ->count();
        
        $firstIdentifications = $ownObservationsResearchGrade + $firstIdentificationsOthers;

        // Identifikasi perdana hanya dari fobi_checklist_taxas
        $totalFirstIdentifications = $firstIdentifications;

        // Hitung total spesies unik
        $totalSpecies = DB::table('fobi_checklist_taxas')
            ->where('user_id', $id)
            ->distinct('taxa_id')
            ->count('taxa_id');

        // Hitung total identifikasi (exclude deleted, withdrawn, dan kalah suara berdasarkan taxon_id)
        // Identifikasi dihitung jika:
        // 1. Tidak deleted dan tidak withdrawn
        // 2. taxon_id user memiliki jumlah suara (count) >= taxon_id lain di checklist yang sama
        //    Suara = jumlah identifikasi dengan taxon_id yang sama di checklist yang sama
        $totalIdentifications = DB::table('taxa_identifications as ti')
            ->where('ti.user_id', $id)
            ->whereNull('ti.deleted_at')
            ->where(function($q) {
                $q->whereNull('ti.is_withdrawn')
                  ->orWhere('ti.is_withdrawn', 0);
            })
            ->whereNotExists(function($subquery) {
                // Cek apakah ada taxon_id lain di checklist yang sama dengan jumlah suara lebih banyak
                $subquery->select(DB::raw(1))
                    ->from('taxa_identifications as ti2')
                    ->whereColumn('ti2.checklist_id', 'ti.checklist_id')
                    ->whereColumn('ti2.taxon_id', '!=', 'ti.taxon_id')
                    ->whereNull('ti2.deleted_at')
                    ->where(function($q2) {
                        $q2->whereNull('ti2.is_withdrawn')
                           ->orWhere('ti2.is_withdrawn', 0);
                    })
                    ->havingRaw('COUNT(ti2.id) > (
                        SELECT COUNT(ti3.id) 
                        FROM taxa_identifications ti3 
                        WHERE ti3.checklist_id = ti.checklist_id 
                        AND ti3.taxon_id = ti.taxon_id 
                        AND ti3.deleted_at IS NULL 
                        AND (ti3.is_withdrawn IS NULL OR ti3.is_withdrawn = 0)
                    )')
                    ->groupBy('ti2.taxon_id');
            })
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'totalObservations' => $totalObservations,
                'totalFirstIdentifications' => $totalFirstIdentifications, // Identifikasi Perdana
                'totalSpecies' => $totalSpecies,
                'totalIdentifications' => $totalIdentifications,
                'breakdown' => [
                    'firstIdentifications' => [
                        'fobi' => $firstIdentifications
                    ]
                ]
            ]
        ]);

    } catch (\Exception $e) {
        \Log::error('Error in getUserStats:', [
            'id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil statistik user'
        ], 500);
    }
}

public function deleteAccount(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
            'recaptcha_token' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verifikasi reCAPTCHA
        $recaptchaSecret = config('services.recaptcha.secret_key');
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $recaptchaSecret,
            'response' => $request->recaptcha_token,
        ]);

        if (!$response->json()['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Verifikasi keamanan gagal'
            ], 400);
        }

        $user = auth()->user();

        // Verifikasi password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password salah'
            ], 400);
        }

        // Hapus foto profil jika ada
        if ($user->profile_picture) {
            Storage::delete('public/' . $user->profile_picture);
        }

        // Hapus user
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil dihapus'
        ]);

    } catch (\Exception $e) {
        \Log::error('Error deleting account:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Gagal menghapus akun'
        ], 500);
    }
}

/**
 * Get profile picture URL dengan fallback S3 -> local
 * OPTIMIZED: Tidak melakukan exists() check untuk menghindari delay
 * 
 * @param string $path Path foto profil dari database
 * @return string URL lengkap foto profil
 */
private function getProfilePictureUrl($path)
{
    if (empty($path)) {
        return null;
    }

    // Jika sudah berupa URL lengkap, kembalikan langsung
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    // Bersihkan path dari prefix yang tidak perlu
    $cleanPath = preg_replace('/^\/?(storage\/)?/', '', $path);
    
    // Cek apakah S3 credentials tersedia
    $awsKey = config('filesystems.disks.s3.key');
    $awsBucket = config('filesystems.disks.s3.bucket');
    $s3Available = !empty($awsKey) && !empty($awsBucket);
    
    // Gunakan config yang sama dengan S3MediaHandlerTrait
    $disk = config('filesystems.media_storage_disk', 's3');
    
    try {
        // OPTIMIZED: Langsung generate URL tanpa check exists() untuk menghindari delay
        // File existence sudah divalidasi saat upload
        if ($disk === 's3' && $s3Available) {
            return Storage::disk('s3')->url($cleanPath);
        }
        
        // Local storage
        return asset('storage/' . $cleanPath);
        
    } catch (\Exception $e) {
        \Log::error('Error getting profile picture URL:', [
            'path' => $cleanPath,
            'error' => $e->getMessage()
        ]);
        
        // Fallback ke local URL
        return asset('storage/' . $cleanPath);
    }
}

    /**
     * Get dashboard timeline for user (owner only)
     * Menampilkan aktivitas terbaru berdasarkan:
     * 1. Observasi saya
     * 2. Observasi baru untuk taksa favorit
     * 3. Mention dalam komentar
     * 4. Balasan komentar saya
     * 5. Diskusi di taksa favorit
     * 6. Perubahan grade observasi
     * 7. Komentar di observasi saya
     */
    public function getDashboard($id)
    {
        try {
            $perPage = request('per_page', 15);
            
            // Get user's favorite taxa IDs (including descendants)
            $favoriteTaxaIds = DB::table('user_favorite_taxas')
                ->where('user_id', $id)
                ->pluck('taxa_id')
                ->toArray();
            
            // Get all descendant taxa IDs from favorites using taxonomic hierarchy
            $allFavoriteTaxaIds = $favoriteTaxaIds;
            if (!empty($favoriteTaxaIds)) {
                // Get favorite taxa details to find descendants based on hierarchy
                $favoriteTaxas = DB::table('taxas')
                    ->whereIn('id', $favoriteTaxaIds)
                    ->select('id', 'scientific_name', 'taxon_rank', 'kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species')
                    ->get();
                
                foreach ($favoriteTaxas as $taxa) {
                    $rank = strtolower($taxa->taxon_rank ?? '');
                    $query = DB::table('taxas')->where('id', '!=', $taxa->id);
                    
                    // Find descendants based on the rank of favorite taxa
                    switch ($rank) {
                        case 'kingdom':
                            $query->where('kingdom', $taxa->kingdom);
                            break;
                        case 'phylum':
                            $query->where('phylum', $taxa->phylum);
                            break;
                        case 'class':
                            $query->where('class', $taxa->class);
                            break;
                        case 'order':
                            $query->where('order', $taxa->order);
                            break;
                        case 'family':
                            $query->where('family', $taxa->family);
                            break;
                        case 'genus':
                            $query->where('genus', $taxa->genus);
                            break;
                        case 'species':
                            // For species, include subspecies/varieties with same species name
                            $query->where('species', $taxa->species)
                                  ->where('genus', $taxa->genus);
                            break;
                        default:
                            continue 2; // Skip if rank not recognized
                    }
                    
                    // Limit descendants to prevent performance issues
                    $descendants = $query->limit(500)->pluck('id')->toArray();
                    $allFavoriteTaxaIds = array_merge($allFavoriteTaxaIds, $descendants);
                }
                
                $allFavoriteTaxaIds = array_unique($allFavoriteTaxaIds);
            }
            
            $activities = collect();
            
            // 1. Observasi saya (my_observation)
            $myObservations = DB::table('fobi_checklist_taxas as fct')
                ->leftJoin('taxas as t', 't.id', '=', 'fct.taxa_id')
                ->leftJoin('fobi_users as fu', 'fu.id', '=', 'fct.user_id')
                ->leftJoin('fobi_checklist_media as fcm', function($join) {
                    $join->on('fct.id', '=', 'fcm.checklist_id')
                         ->whereRaw('fcm.id = (SELECT MIN(id) FROM fobi_checklist_media WHERE checklist_id = fct.id)');
                })
                ->leftJoin('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                ->where('fct.user_id', $id)
                ->select(
                    'fct.id as checklist_id',
                    't.scientific_name',
                    't.Cname as common_name',
                    'fu.id as observer_id',
                    'fu.uname as observer_username',
                    'fu.profile_picture as observer_profile_picture',
                    'fct.date as observation_date',
                    'fcm.location',
                    'fcm.file_path as photo_path',
                    'fcm.spectrogram',
                    'fcm.media_type',
                    'fcm.storage_type',
                    'tqa.grade',
                    'fct.created_at',
                    DB::raw("'my_observation' as type"),
                    DB::raw('(SELECT COUNT(*) FROM taxa_identifications WHERE checklist_id = fct.id AND deleted_at IS NULL) as total_identifications')
                )
                ->orderBy('fct.created_at', 'desc')
                ->limit(50)
                ->get();
            
            foreach ($myObservations as $obs) {
                $obs->photo_url = $this->getMediaUrl($obs->photo_path, $obs->storage_type);
                $obs->observer_profile_picture = $this->getProfilePictureUrl($obs->observer_profile_picture);
                $obs->spectrogram_url = $obs->spectrogram ? $this->getMediaUrl($obs->spectrogram, $obs->storage_type) : null;
                $obs->audio_url = ($obs->media_type === 'audio' && $obs->photo_path) ? $this->getMediaUrl($obs->photo_path, $obs->storage_type) : null;
                $activities->push($obs);
            }
            
            // 2. Observasi baru untuk taksa favorit (favorite_taxa_observation)
            if (!empty($allFavoriteTaxaIds)) {
                $favoriteTaxaObservations = DB::table('fobi_checklist_taxas as fct')
                    ->leftJoin('taxas as t', 't.id', '=', 'fct.taxa_id')
                    ->leftJoin('fobi_users as fu', 'fu.id', '=', 'fct.user_id')
                    ->leftJoin('fobi_checklist_media as fcm', function($join) {
                        $join->on('fct.id', '=', 'fcm.checklist_id')
                             ->whereRaw('fcm.id = (SELECT MIN(id) FROM fobi_checklist_media WHERE checklist_id = fct.id)');
                    })
                    ->leftJoin('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                    ->whereIn('fct.taxa_id', $allFavoriteTaxaIds)
                    ->where('fct.user_id', '!=', $id) // Exclude own observations
                    ->select(
                        'fct.id as checklist_id',
                        't.scientific_name',
                        't.Cname as common_name',
                        'fu.id as observer_id',
                        'fu.uname as observer_username',
                        'fu.profile_picture as observer_profile_picture',
                        'fct.date as observation_date',
                        'fcm.location',
                        'fcm.file_path as photo_path',
                        'fcm.spectrogram',
                        'fcm.media_type',
                        'fcm.storage_type',
                        'tqa.grade',
                        'fct.created_at',
                        DB::raw("'favorite_taxa_observation' as type"),
                        DB::raw('(SELECT COUNT(*) FROM taxa_identifications WHERE checklist_id = fct.id AND deleted_at IS NULL) as total_identifications')
                    )
                    ->orderBy('fct.created_at', 'desc')
                    ->limit(30)
                    ->get();
                
                foreach ($favoriteTaxaObservations as $obs) {
                    $obs->photo_url = $this->getMediaUrl($obs->photo_path, $obs->storage_type);
                    $obs->observer_profile_picture = $this->getProfilePictureUrl($obs->observer_profile_picture);
                    $obs->spectrogram_url = $obs->spectrogram ? $this->getMediaUrl($obs->spectrogram, $obs->storage_type) : null;
                    $obs->audio_url = ($obs->media_type === 'audio' && $obs->photo_path) ? $this->getMediaUrl($obs->photo_path, $obs->storage_type) : null;
                    $activities->push($obs);
                }
            }
            
            // 3. Observasi dari user yang difollow (followed_user_observation)
            $followedUserIds = DB::table('user_followers')
                ->where('follower_id', $id)
                ->pluck('user_id')
                ->toArray();
            
            if (!empty($followedUserIds)) {
                $followedUserObservations = DB::table('fobi_checklist_taxas as fct')
                    ->leftJoin('taxas as t', 't.id', '=', 'fct.taxa_id')
                    ->leftJoin('fobi_users as fu', 'fu.id', '=', 'fct.user_id')
                    ->leftJoin('fobi_checklist_media as fcm', function($join) {
                        $join->on('fct.id', '=', 'fcm.checklist_id')
                             ->whereRaw('fcm.id = (SELECT MIN(id) FROM fobi_checklist_media WHERE checklist_id = fct.id)');
                    })
                    ->leftJoin('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                    ->whereIn('fct.user_id', $followedUserIds)
                    ->select(
                        'fct.id as checklist_id',
                        't.scientific_name',
                        't.Cname as common_name',
                        'fu.id as observer_id',
                        'fu.uname as observer_username',
                        'fu.profile_picture as observer_profile_picture',
                        'fct.date as observation_date',
                        'fcm.location',
                        'fcm.file_path as photo_path',
                        'fcm.spectrogram',
                        'fcm.media_type',
                        'fcm.storage_type',
                        'tqa.grade',
                        'fct.created_at',
                        DB::raw("'followed_user_observation' as type"),
                        DB::raw('(SELECT COUNT(*) FROM taxa_identifications WHERE checklist_id = fct.id AND deleted_at IS NULL) as total_identifications')
                    )
                    ->orderBy('fct.created_at', 'desc')
                    ->limit(50)
                    ->get();
                
                foreach ($followedUserObservations as $obs) {
                    $obs->photo_url = $this->getMediaUrl($obs->photo_path, $obs->storage_type);
                    $obs->observer_profile_picture = $this->getProfilePictureUrl($obs->observer_profile_picture);
                    $obs->spectrogram_url = $obs->spectrogram ? $this->getMediaUrl($obs->spectrogram, $obs->storage_type) : null;
                    $obs->audio_url = ($obs->media_type === 'audio' && $obs->photo_path) ? $this->getMediaUrl($obs->photo_path, $obs->storage_type) : null;
                    $activities->push($obs);
                }
            }
            
            // 4. Komentar di observasi saya (observation_comment)
            // Menggunakan tabel observation_comments dengan kolom observation_id
            $observationComments = DB::table('observation_comments as oc')
                ->join('fobi_checklist_taxas as fct', 'fct.id', '=', 'oc.observation_id')
                ->leftJoin('taxas as t', 't.id', '=', 'fct.taxa_id')
                ->leftJoin('fobi_users as fu', 'fu.id', '=', 'fct.user_id')
                ->leftJoin('fobi_users as commenter', 'commenter.id', '=', 'oc.user_id')
                ->leftJoin('fobi_checklist_media as fcm', function($join) {
                    $join->on('fct.id', '=', 'fcm.checklist_id')
                         ->whereRaw('fcm.id = (SELECT MIN(id) FROM fobi_checklist_media WHERE checklist_id = fct.id)');
                })
                ->leftJoin('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                ->where('fct.user_id', $id)
                ->where('oc.user_id', '!=', $id) // Exclude own comments
                ->where('oc.source', 'fobi') // Only FOBI comments
                ->whereNull('oc.deleted_at')
                ->select(
                    'fct.id as checklist_id',
                    't.scientific_name',
                    't.Cname as common_name',
                    'fu.id as observer_id',
                    'fu.uname as observer_username',
                    'fu.profile_picture as observer_profile_picture',
                    'fct.date as observation_date',
                    'fcm.location',
                    'fcm.file_path as photo_path',
                    'fcm.spectrogram',
                    'fcm.media_type',
                    'fcm.storage_type',
                    'tqa.grade',
                    'oc.created_at',
                    'oc.comment',
                    'commenter.uname as commenter_username',
                    'commenter.profile_picture as commenter_profile_picture',
                    DB::raw("'observation_comment' as type"),
                    DB::raw('(SELECT COUNT(*) FROM taxa_identifications WHERE checklist_id = fct.id AND deleted_at IS NULL) as total_identifications')
                )
                ->orderBy('oc.created_at', 'desc')
                ->limit(30)
                ->get();
            
            foreach ($observationComments as $comment) {
                $comment->photo_url = $this->getMediaUrl($comment->photo_path, $comment->storage_type);
                $comment->observer_profile_picture = $this->getProfilePictureUrl($comment->observer_profile_picture);
                $comment->commenter_profile_picture = $this->getProfilePictureUrl($comment->commenter_profile_picture);
                $comment->spectrogram_url = $comment->spectrogram ? $this->getMediaUrl($comment->spectrogram, $comment->storage_type) : null;
                $comment->audio_url = ($comment->media_type === 'audio' && $comment->photo_path) ? $this->getMediaUrl($comment->photo_path, $comment->storage_type) : null;
                $activities->push($comment);
            }
            
            // 4. Diskusi di taksa favorit (favorite_taxa_comment)
            // Note: observation_comments tidak memiliki parent_id, jadi skip comment_reply untuk sementara
            if (!empty($allFavoriteTaxaIds)) {
                $favoriteTaxaComments = DB::table('observation_comments as oc')
                    ->join('fobi_checklist_taxas as fct', 'fct.id', '=', 'oc.observation_id')
                    ->leftJoin('taxas as t', 't.id', '=', 'fct.taxa_id')
                    ->leftJoin('fobi_users as fu', 'fu.id', '=', 'fct.user_id')
                    ->leftJoin('fobi_users as commenter', 'commenter.id', '=', 'oc.user_id')
                    ->leftJoin('fobi_checklist_media as fcm', function($join) {
                        $join->on('fct.id', '=', 'fcm.checklist_id')
                             ->whereRaw('fcm.id = (SELECT MIN(id) FROM fobi_checklist_media WHERE checklist_id = fct.id)');
                    })
                    ->leftJoin('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                    ->whereIn('fct.taxa_id', $allFavoriteTaxaIds)
                    ->where('fct.user_id', '!=', $id) // Exclude own observations
                    ->where('oc.user_id', '!=', $id) // Exclude own comments
                    ->where('oc.source', 'fobi') // Only FOBI comments
                    ->whereNull('oc.deleted_at')
                    ->select(
                        'fct.id as checklist_id',
                        't.scientific_name',
                        't.Cname as common_name',
                        'fu.id as observer_id',
                        'fu.uname as observer_username',
                        'fu.profile_picture as observer_profile_picture',
                        'fct.date as observation_date',
                        'fcm.location',
                        'fcm.file_path as photo_path',
                        'fcm.spectrogram',
                        'fcm.media_type',
                        'fcm.storage_type',
                        'tqa.grade',
                        'oc.created_at',
                        'oc.comment',
                        'commenter.uname as commenter_username',
                        'commenter.profile_picture as commenter_profile_picture',
                        DB::raw("'favorite_taxa_comment' as type"),
                        DB::raw('(SELECT COUNT(*) FROM taxa_identifications WHERE checklist_id = fct.id AND deleted_at IS NULL) as total_identifications')
                    )
                    ->orderBy('oc.created_at', 'desc')
                    ->limit(30)
                    ->get();
                
                foreach ($favoriteTaxaComments as $comment) {
                    $comment->photo_url = $this->getMediaUrl($comment->photo_path, $comment->storage_type);
                    $comment->observer_profile_picture = $this->getProfilePictureUrl($comment->observer_profile_picture);
                    $comment->commenter_profile_picture = $this->getProfilePictureUrl($comment->commenter_profile_picture);
                    $comment->spectrogram_url = $comment->spectrogram ? $this->getMediaUrl($comment->spectrogram, $comment->storage_type) : null;
                    $comment->audio_url = ($comment->media_type === 'audio' && $comment->photo_path) ? $this->getMediaUrl($comment->photo_path, $comment->storage_type) : null;
                    $activities->push($comment);
                }
            }
            
            // 7. Perubahan grade observasi sendiri (grade_change - my observations)
            $myGradeChanges = DB::table('taxa_quality_assessments as tqa')
                ->join('fobi_checklist_taxas as fct', 'fct.id', '=', 'tqa.taxa_id')
                ->leftJoin('taxas as t', 't.id', '=', 'fct.taxa_id')
                ->leftJoin('fobi_users as fu', 'fu.id', '=', 'fct.user_id')
                ->leftJoin('fobi_checklist_media as fcm', function($join) {
                    $join->on('fct.id', '=', 'fcm.checklist_id')
                         ->whereRaw('fcm.id = (SELECT MIN(id) FROM fobi_checklist_media WHERE checklist_id = fct.id)');
                })
                ->where('fct.user_id', $id)
                ->whereRaw('tqa.updated_at > tqa.created_at') // Grade was updated (changed)
                ->whereRaw('tqa.updated_at > DATE_SUB(NOW(), INTERVAL 30 DAY)') // Within last 30 days
                ->select(
                    'fct.id as checklist_id',
                    't.scientific_name',
                    't.Cname as common_name',
                    'fu.id as observer_id',
                    'fu.uname as observer_username',
                    'fu.profile_picture as observer_profile_picture',
                    'fct.date as observation_date',
                    'fcm.location',
                    'fcm.file_path as photo_path',
                    'fcm.spectrogram',
                    'fcm.media_type',
                    'fcm.storage_type',
                    'tqa.grade',
                    'tqa.updated_at as created_at',
                    DB::raw("'grade_change' as type"),
                    DB::raw('(SELECT COUNT(*) FROM taxa_identifications WHERE checklist_id = fct.id AND deleted_at IS NULL) as total_identifications')
                )
                ->orderBy('tqa.updated_at', 'desc')
                ->limit(30)
                ->get();
            
            foreach ($myGradeChanges as $change) {
                $change->photo_url = $this->getMediaUrl($change->photo_path, $change->storage_type);
                $change->observer_profile_picture = $this->getProfilePictureUrl($change->observer_profile_picture);
                $change->spectrogram_url = $change->spectrogram ? $this->getMediaUrl($change->spectrogram, $change->storage_type) : null;
                $change->audio_url = ($change->media_type === 'audio' && $change->photo_path) ? $this->getMediaUrl($change->photo_path, $change->storage_type) : null;
                $activities->push($change);
            }
            
            // 8. Perubahan grade observasi user yang difollow (grade_change - followed users)
            if (!empty($followedUserIds)) {
                $followedGradeChanges = DB::table('taxa_quality_assessments as tqa')
                    ->join('fobi_checklist_taxas as fct', 'fct.id', '=', 'tqa.taxa_id')
                    ->leftJoin('taxas as t', 't.id', '=', 'fct.taxa_id')
                    ->leftJoin('fobi_users as fu', 'fu.id', '=', 'fct.user_id')
                    ->leftJoin('fobi_checklist_media as fcm', function($join) {
                        $join->on('fct.id', '=', 'fcm.checklist_id')
                             ->whereRaw('fcm.id = (SELECT MIN(id) FROM fobi_checklist_media WHERE checklist_id = fct.id)');
                    })
                    ->whereIn('fct.user_id', $followedUserIds)
                    ->whereRaw('tqa.updated_at > tqa.created_at') // Grade was updated (changed)
                    ->whereRaw('tqa.updated_at > DATE_SUB(NOW(), INTERVAL 30 DAY)') // Within last 30 days
                    ->select(
                        'fct.id as checklist_id',
                        't.scientific_name',
                        't.Cname as common_name',
                        'fu.id as observer_id',
                        'fu.uname as observer_username',
                        'fu.profile_picture as observer_profile_picture',
                        'fct.date as observation_date',
                        'fcm.location',
                        'fcm.file_path as photo_path',
                        'fcm.spectrogram',
                        'fcm.media_type',
                        'fcm.storage_type',
                        'tqa.grade',
                        'tqa.updated_at as created_at',
                        DB::raw("'grade_change' as type"),
                        DB::raw('(SELECT COUNT(*) FROM taxa_identifications WHERE checklist_id = fct.id AND deleted_at IS NULL) as total_identifications')
                    )
                    ->orderBy('tqa.updated_at', 'desc')
                    ->limit(30)
                    ->get();
                
                foreach ($followedGradeChanges as $change) {
                    $change->photo_url = $this->getMediaUrl($change->photo_path, $change->storage_type);
                    $change->observer_profile_picture = $this->getProfilePictureUrl($change->observer_profile_picture);
                    $change->spectrogram_url = $change->spectrogram ? $this->getMediaUrl($change->spectrogram, $change->storage_type) : null;
                    $change->audio_url = ($change->media_type === 'audio' && $change->photo_path) ? $this->getMediaUrl($change->photo_path, $change->storage_type) : null;
                    $activities->push($change);
                }
            }
            
            // Sort all activities by created_at descending
            $sortedActivities = $activities->sortByDesc('created_at')->values();
            
            // Paginate manually
            $page = request('page', 1);
            $total = $sortedActivities->count();
            $paginatedActivities = $sortedActivities->forPage($page, $perPage)->values();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'data' => $paginatedActivities,
                    'current_page' => (int)$page,
                    'per_page' => (int)$perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage)
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error in getDashboard:', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data dashboard: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Helper untuk mendapatkan URL media
     */
    private function getMediaUrl($filePath, $storageType = null)
    {
        if (!$filePath) return null;
        
        // Jika sudah URL lengkap
        if (str_starts_with($filePath, 'http')) {
            return $filePath;
        }
        
        // Cek storage type
        if ($storageType === 's3' || config('filesystems.media_storage_disk') === 's3') {
            try {
                return Storage::disk('s3')->url($filePath);
            } catch (\Exception $e) {
                // Fallback ke local
            }
        }
        
        // Local storage
        $cleanPath = ltrim($filePath, '/');
        return asset('storage/' . $cleanPath);
    }
}
