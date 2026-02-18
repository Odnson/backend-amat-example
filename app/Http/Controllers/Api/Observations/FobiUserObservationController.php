<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FobiChecklistTaxa;
use App\Models\FobiChecklistMedia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\FobiChecklistBurungnesia;
use App\Models\FobiChecklistKupunesia;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Schema;
use App\Helpers\MediaStorageHelper;

class FobiUserObservationController extends Controller
{
    /**
     * Mendapatkan daftar observasi milik user tertentu
     */
    public function getUserObservations(Request $request)
    {
        try {
            $userId = auth()->user()->id;
            
            // Validasi parameter
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search', '');
            $searchType = $request->input('search_type', 'all');
            $dateFilter = $request->input('date', '');
            $createdDateFilter = $request->input('created_date', '');
            $dateStartFilter = $request->input('date_start', '');
            $dateEndFilter = $request->input('date_end', '');
            $mediaTypeFilter = $request->input('media_type', 'all'); // all | photo | audio | checklist
            $gradeFilter = $request->input('grade', 'all'); // all | needs_id | low_quality | confirmed | research_grade
            $sortBy = $request->input('sort_by', 'date'); // date | created_at | name
            $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
            Log::info('getUserObservations: params', [
                'userId' => $userId,
                'page' => $request->input('page', 1),
                'per_page' => $perPage,
                'search' => $search,
                'searchType' => $searchType,
                'date' => $dateFilter,
                'created_date' => $createdDateFilter,
                'date_start' => $dateStartFilter,
                'date_end' => $dateEndFilter,
                'media_type' => $mediaTypeFilter,
                'grade' => $gradeFilter,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'raw_sort_by' => $request->input('sort_by'),
                'raw_sort_order' => $request->input('sort_order'),
            ]);
            
            // Query dasar untuk FOBI Taxa dengan join ke quality assessment untuk grade
            $query = FobiChecklistTaxa::with(['medias' => function($query) {
                $query->select([
                    'id',
                    'checklist_id',
                    'media_type',
                    'file_path',
                    'spectrogram',
                    'location',
                    'created_at',
                    'updated_at'
                ]);
            }, 'qualityAssessment'])
            ->where('user_id', $userId);
                
            // Filter berdasarkan pencarian
            if (!empty($search)) {
                if ($searchType === 'species') {
                    $query->where(function($q) use ($search) {
                        $q->where('scientific_name', 'like', '%' . $search . '%')
                          ->orWhere('genus', 'like', '%' . $search . '%')
                          ->orWhere('species', 'like', '%' . $search . '%');
                    });
                } elseif ($searchType === 'location') {
                    $query->where(function($q) use ($search) {
                        $q->whereRaw("CONCAT(latitude, ', ', longitude) like ?", ['%' . $search . '%']);
                    });
                } elseif ($searchType === 'date') {
                    $query->whereDate('date', $search);
                } else {
                    // All search
                    $query->where(function($q) use ($search) {
                        $q->where('scientific_name', 'like', '%' . $search . '%')
                          ->orWhere('genus', 'like', '%' . $search . '%')
                          ->orWhere('species', 'like', '%' . $search . '%')
                          ->orWhereRaw("CONCAT(latitude, ', ', longitude) like ?", ['%' . $search . '%']);
                    });
                }
            }
            
            // Filter berdasarkan tanggal observasi
            if (!empty($dateFilter)) {
                $query->whereDate('date', $dateFilter);
            }
            // Filter berdasarkan tanggal dibuat (created_at)
            if (!empty($createdDateFilter)) {
                $query->whereDate('created_at', $createdDateFilter);
            }
            // Filter berdasarkan rentang tanggal observasi
            if (!empty($dateStartFilter) && !empty($dateEndFilter)) {
                $query->whereBetween('date', [$dateStartFilter, $dateEndFilter]);
            } elseif (!empty($dateStartFilter)) {
                $query->whereDate('date', '>=', $dateStartFilter);
            } elseif (!empty($dateEndFilter)) {
                $query->whereDate('date', '<=', $dateEndFilter);
            }
            
            // Ambil data dengan paginasi
            $taxaObservations = $query->orderBy('created_at', 'desc')->get();
            
            // Query untuk Burungnesia
            $burungnesiaQuery = FobiChecklistBurungnesia::with(['faunas', 'medias'])
                ->where('fobi_user_id', $userId);
                
            // Filter berdasarkan pencarian untuk Burungnesia
            if (!empty($search)) {
                if ($searchType === 'species') {
                    $burungnesiaQuery->whereHas('faunas.fauna', function($q) use ($search) {
                        $q->where('nameLat', 'like', '%' . $search . '%')
                          ->orWhere('nameId', 'like', '%' . $search . '%');
                    });
                } elseif ($searchType === 'location') {
                    $burungnesiaQuery->where(function($q) use ($search) {
                        $q->whereRaw("CONCAT(latitude, ', ', longitude) like ?", ['%' . $search . '%']);
                    });
                } elseif ($searchType === 'date') {
                    $burungnesiaQuery->whereDate('tgl_pengamatan', $search);
                } else {
                    // All search
                    $burungnesiaQuery->where(function($q) use ($search) {
                        $q->whereHas('faunas.fauna', function($sq) use ($search) {
                            $sq->where('nameLat', 'like', '%' . $search . '%')
                              ->orWhere('nameId', 'like', '%' . $search . '%');
                        })
                        ->orWhereRaw("CONCAT(latitude, ', ', longitude) like ?", ['%' . $search . '%']);
                    });
                }
            }
            
            // Filter berdasarkan tanggal observasi untuk Burungnesia
            if (!empty($dateFilter)) {
                $burungnesiaQuery->whereDate('tgl_pengamatan', $dateFilter);
            }
            // Filter berdasarkan tanggal dibuat (created_at) untuk Burungnesia
            if (!empty($createdDateFilter)) {
                $burungnesiaQuery->whereDate('created_at', $createdDateFilter);
            }
            // Filter berdasarkan rentang tanggal observasi untuk Burungnesia
            if (!empty($dateStartFilter) && !empty($dateEndFilter)) {
                $burungnesiaQuery->whereBetween('tgl_pengamatan', [$dateStartFilter, $dateEndFilter]);
            } elseif (!empty($dateStartFilter)) {
                $burungnesiaQuery->whereDate('tgl_pengamatan', '>=', $dateStartFilter);
            } elseif (!empty($dateEndFilter)) {
                $burungnesiaQuery->whereDate('tgl_pengamatan', '<=', $dateEndFilter);
            }
            
            $burungnesiaObservations = $burungnesiaQuery->orderBy('created_at', 'desc')->get();
            
            // Query untuk Kupunesia
            $kupunesiaQuery = FobiChecklistKupunesia::with(['faunas', 'medias'])
                ->where('fobi_user_id', $userId);
                
            // Filter berdasarkan pencarian untuk Kupunesia
            if (!empty($search)) {
                if ($searchType === 'species') {
                    $kupunesiaQuery->whereHas('faunas.fauna', function($q) use ($search) {
                        $q->where('nameLat', 'like', '%' . $search . '%')
                          ->orWhere('nameId', 'like', '%' . $search . '%');
                    });
                } elseif ($searchType === 'location') {
                    $kupunesiaQuery->where(function($q) use ($search) {
                        $q->whereRaw("CONCAT(latitude, ', ', longitude) like ?", ['%' . $search . '%']);
                    });
                } elseif ($searchType === 'date') {
                    $kupunesiaQuery->whereDate('tgl_pengamatan', $search);
                } else {
                    // All search
                    $kupunesiaQuery->where(function($q) use ($search) {
                        $q->whereHas('faunas.fauna', function($sq) use ($search) {
                            $sq->where('nameLat', 'like', '%' . $search . '%')
                              ->orWhere('nameId', 'like', '%' . $search . '%');
                        })
                        ->orWhereRaw("CONCAT(latitude, ', ', longitude) like ?", ['%' . $search . '%']);
                    });
                }
            }
            
            // Filter berdasarkan tanggal observasi untuk Kupunesia
            if (!empty($dateFilter)) {
                $kupunesiaQuery->whereDate('tgl_pengamatan', $dateFilter);
            }
            // Filter berdasarkan tanggal dibuat (created_at) untuk Kupunesia
            if (!empty($createdDateFilter)) {
                $kupunesiaQuery->whereDate('created_at', $createdDateFilter);
            }
            // Filter berdasarkan rentang tanggal observasi untuk Kupunesia
            if (!empty($dateStartFilter) && !empty($dateEndFilter)) {
                $kupunesiaQuery->whereBetween('tgl_pengamatan', [$dateStartFilter, $dateEndFilter]);
            } elseif (!empty($dateStartFilter)) {
                $kupunesiaQuery->whereDate('tgl_pengamatan', '>=', $dateStartFilter);
            } elseif (!empty($dateEndFilter)) {
                $kupunesiaQuery->whereDate('tgl_pengamatan', '<=', $dateEndFilter);
            }
            
            $kupunesiaObservations = $kupunesiaQuery->orderBy('created_at', 'desc')->get();
            
            // Gabungkan semua observasi
            $taxaObservations = $taxaObservations->map(function($observation) {
                // Format media
                $media = [
                    'images' => [],
                    'sounds' => []
                ];

                foreach ($observation->medias as $mediaItem) {
                    $mediaUrl = MediaStorageHelper::getMediaUrl(
                        $mediaItem->file_path,
                        $mediaItem->storage_type ?? 'local',
                        $mediaItem->id
                    );
                    
                    if ($mediaItem->media_type === 'photo') {
                        $media['images'][] = [
                            'id' => $mediaItem->id,
                            'url' => $mediaUrl,
                            'thumbnail_url' => $mediaUrl,
                            'type' => 'image'
                        ];
                    } elseif ($mediaItem->media_type === 'audio') {
                        $media['sounds'][] = [
                            'id' => $mediaItem->id,
                            'url' => $mediaUrl,
                            'spectrogram_url' => $mediaItem->spectrogram ? MediaStorageHelper::getMediaUrl(
                                $mediaItem->spectrogram,
                                $mediaItem->storage_type ?? 'local',
                                $mediaItem->id
                            ) : null,
                            'type' => 'audio'
                        ];
                    }
                }

                // Ambil media pertama untuk thumbnail
                $firstMedia = $observation->medias->first();
                if ($firstMedia) {
                    $observation->photo_url = MediaStorageHelper::getMediaUrl(
                        $firstMedia->file_path,
                        $firstMedia->storage_type ?? 'local',
                        $firstMedia->id
                    );
                    $observation->media_type = $firstMedia->media_type;
                }
                
                // Format data tambahan
                $observation->formatted_date = $observation->created_at->format('d F Y');
                // Prefer checklist.location, then first media.location, then lat,lon
                $mediaLocation = isset($firstMedia) ? ($firstMedia->location ?? null) : null;
                $observation->location_name = !empty($observation->location)
                    ? $observation->location
                    : (!empty($mediaLocation)
                        ? $mediaLocation
                        : (isset($observation->latitude, $observation->longitude)
                            ? ($observation->latitude . ', ' . $observation->longitude)
                            : null));
                $observation->media = $media;
                $observation->source = 'taxa';
                $observation->observation_date = $observation->date;
                $observation->quality_assessment = $observation->qualityAssessment;
                
                return $observation;
            });
            
            // Format data Burungnesia
            $burungnesiaObservations = $burungnesiaObservations->map(function($observation) {
                $fauna = $observation->faunas->first();
                $faunaData = $fauna ? $fauna->fauna : null;
                
                // Format media
                $media = [
                    'images' => [],
                    'sounds' => []
                ];
                
                foreach ($observation->medias as $mediaItem) {
                    $media['images'][] = [
                        'id' => $mediaItem->id,
                        'url' => $mediaItem->images,
                        'thumbnail_url' => $mediaItem->images,
                        'type' => 'image'
                    ];
                }
                
                // Ambil media pertama untuk thumbnail
                $firstMedia = $observation->medias->first();
                if ($firstMedia) {
                    $observation->photo_url = $firstMedia->images;
                }
                
                // Format data tambahan
                $observation->scientific_name = $faunaData ? $faunaData->nameLat : 'Unknown Species';
                $observation->genus = $faunaData ? explode(' ', $faunaData->nameLat)[0] : '';
                $observation->species = $faunaData ? (count(explode(' ', $faunaData->nameLat)) > 1 ? explode(' ', $faunaData->nameLat)[1] : '') : '';
                $observation->formatted_date = $observation->tgl_pengamatan ? date('d F Y', strtotime($observation->tgl_pengamatan)) : $observation->created_at->format('d F Y');
                $firstMedia = $observation->medias->first();
                $mediaLocation = $firstMedia->location ?? null;
                $observation->location_name = !empty($observation->location)
                    ? $observation->location
                    : (!empty($mediaLocation)
                        ? $mediaLocation
                        : (isset($observation->latitude, $observation->longitude)
                            ? ($observation->latitude . ', ' . $observation->longitude)
                            : null));
                $observation->media = $media;
                $observation->source = 'burungnesia';
                $observation->observation_date = $observation->tgl_pengamatan;
                // Ensure created_at is properly accessible for sorting
                $observation->created_at = $observation->created_at;
                
                return $observation;
            });
            
            // Format data Kupunesia
            $kupunesiaObservations = $kupunesiaObservations->map(function($observation) {
                $fauna = $observation->faunas->first();
                $faunaData = $fauna ? $fauna->fauna : null;
                
                // Format media
                $media = [
                    'images' => [],
                    'sounds' => []
                ];
                
                foreach ($observation->medias as $mediaItem) {
                    $media['images'][] = [
                        'id' => $mediaItem->id,
                        'url' => $mediaItem->images,
                        'thumbnail_url' => $mediaItem->images,
                        'type' => 'image'
                    ];
                }
                
                // Ambil media pertama untuk thumbnail
                $firstMedia = $observation->medias->first();
                if ($firstMedia) {
                    $observation->photo_url = $firstMedia->images;
                }
                
                // Format data tambahan
                $observation->scientific_name = $faunaData ? $faunaData->nameLat : 'Unknown Species';
                $observation->genus = $faunaData ? explode(' ', $faunaData->nameLat)[0] : '';
                $observation->species = $faunaData ? (count(explode(' ', $faunaData->nameLat)) > 1 ? explode(' ', $faunaData->nameLat)[1] : '') : '';
                $observation->formatted_date = $observation->tgl_pengamatan ? date('d F Y', strtotime($observation->tgl_pengamatan)) : $observation->created_at->format('d F Y');
                $firstMedia = $observation->medias->first();
                $mediaLocation = $firstMedia->location ?? null;
                $observation->location_name = !empty($observation->location)
                    ? $observation->location
                    : (!empty($mediaLocation)
                        ? $mediaLocation
                        : (isset($observation->latitude, $observation->longitude)
                            ? ($observation->latitude . ', ' . $observation->longitude)
                            : null));
                $observation->media = $media;
                $observation->source = 'kupunesia';
                $observation->observation_date = $observation->tgl_pengamatan;
                // Ensure created_at is properly accessible for sorting
                $observation->created_at = $observation->created_at;
                
                return $observation;
            });
            
            // Gabungkan semua observasi
            $allObservations = $taxaObservations
                ->concat($burungnesiaObservations)
                ->concat($kupunesiaObservations);

            // Filter berdasarkan media type
            if ($mediaTypeFilter !== 'all') {
                $allObservations = $allObservations->filter(function($observation) use ($mediaTypeFilter) {
                    if ($mediaTypeFilter === 'checklist') {
                        // Filter untuk Burungnesia dan Kupunesia (checklist)
                        return in_array($observation->source, ['burungnesia', 'kupunesia']);
                    } elseif ($mediaTypeFilter === 'photo') {
                        // Filter untuk observasi yang memiliki foto
                        return $observation->source === 'taxa' && 
                               isset($observation->media['images']) && 
                               count($observation->media['images']) > 0;
                    } elseif ($mediaTypeFilter === 'audio') {
                        // Filter untuk observasi yang memiliki audio
                        return $observation->source === 'taxa' && 
                               isset($observation->media['sounds']) && 
                               count($observation->media['sounds']) > 0;
                    }
                    return true;
                });
            }

            // Filter berdasarkan grade
            if ($gradeFilter !== 'all') {
                $allObservations = $allObservations->filter(function($observation) use ($gradeFilter) {
                    // Map grade filter ke field grade di database
                    $targetGrade = null;
                    switch ($gradeFilter) {
                        case 'needs_id':
                            $targetGrade = 'needs ID';
                            break;
                        case 'low_quality':
                            $targetGrade = 'low quality ID';
                            break;
                        case 'confirmed':
                            $targetGrade = 'confirmed id';
                            break;
                        case 'research_grade':
                            $targetGrade = 'research grade';
                            break;
                        case 'casual':
                            $targetGrade = 'casual';
                            break;
                    }
                    
                    // Filter hanya untuk taxa yang memiliki field grade dari quality assessment
                    if ($observation->source === 'taxa') {
                        return isset($observation->quality_assessment) && 
                               isset($observation->quality_assessment->grade) && 
                               $observation->quality_assessment->grade === $targetGrade;
                    }
                    
                    // Untuk burungnesia dan kupunesia, anggap semua sebagai confirmed jika tidak ada grade
                    if ($gradeFilter === 'casual') {
                        return in_array($observation->source, ['burungnesia', 'kupunesia']);
                    }
                    
                    return false;
                });
            }

            // Log sorting parameters
            Log::info('Sorting parameters', [
                'sortBy' => $sortBy,
                'sortOrder' => $sortOrder,
                'total_observations' => $allObservations->count()
            ]);
            
            // Sample data before sorting
            $sampleBefore = $allObservations->take(3)->map(function($o) {
                return [
                    'id' => $o->id,
                    'source' => $o->source,
                    'scientific_name' => $o->scientific_name,
                    'created_at' => $o->created_at ? $o->created_at->toDateTimeString() : 'null',
                    'observation_date' => $o->observation_date ? (is_string($o->observation_date) ? $o->observation_date : $o->observation_date->toDateString()) : 'null'
                ];
            });
            Log::info('Sample data before sorting', $sampleBefore->toArray());

            // Sorting dinamis
            if ($sortBy === 'name') {
                Log::info('Sorting by name');
                $allObservations = $sortOrder === 'asc'
                    ? $allObservations->sortBy(function ($o) { return mb_strtolower($o->scientific_name ?? ''); })
                    : $allObservations->sortByDesc(function ($o) { return mb_strtolower($o->scientific_name ?? ''); });
            } elseif ($sortBy === 'created_at') {
                Log::info('Sorting by created_at');
                $allObservations = $sortOrder === 'asc'
                    ? $allObservations->sortBy(function ($o) { 
                        $date = $o->created_at;
                        Log::debug('Sorting created_at item', ['id' => $o->id, 'created_at' => $date ? $date->toDateTimeString() : 'null']);
                        return $date; 
                    })
                    : $allObservations->sortByDesc(function ($o) { 
                        $date = $o->created_at;
                        Log::debug('Sorting created_at item', ['id' => $o->id, 'created_at' => $date ? $date->toDateTimeString() : 'null']);
                        return $date; 
                    });
            } else { // default sort by observation date
                Log::info('Sorting by observation_date (default)');
                $allObservations = $sortOrder === 'asc'
                    ? $allObservations->sortBy(function ($o) { return $o->observation_date ?? $o->created_at; })
                    : $allObservations->sortByDesc(function ($o) { return $o->observation_date ?? $o->created_at; });
            }
            
            // Sample data after sorting
            $sampleAfter = $allObservations->take(3)->map(function($o) {
                return [
                    'id' => $o->id,
                    'source' => $o->source,
                    'scientific_name' => $o->scientific_name,
                    'created_at' => $o->created_at ? $o->created_at->toDateTimeString() : 'null',
                    'observation_date' => $o->observation_date ? (is_string($o->observation_date) ? $o->observation_date : $o->observation_date->toDateString()) : 'null'
                ];
            });
            Log::info('Sample data after sorting', $sampleAfter->toArray());
            
            // Paginasi manual
            $page = $request->input('page', 1);
            $total = $allObservations->count();
            $lastPage = ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
            
            $paginatedObservations = $allObservations->slice($offset, $perPage)->values();
            
            $result = [
                'data' => $paginatedObservations,
                'current_page' => (int)$page,
                'per_page' => (int)$perPage,
                'last_page' => $lastPage,
                'total' => $total,
                'from' => $total > 0 ? ($offset + 1) : 0,
                'to' => min($offset + $perPage, $total),
            ];
            // Log ringkas: hitung total dan contoh pertama
            $sample = $paginatedObservations->first();
            Log::info('getUserObservations: result summary', [
                'total' => $total,
                'last_page' => $lastPage,
                'page' => (int)$page,
                'count_on_page' => $paginatedObservations->count(),
                'sample' => $sample ? [
                    'id' => $sample->id ?? null,
                    'source' => $sample->source ?? null,
                    'location' => $sample->location ?? null,
                    'latitude' => $sample->latitude ?? null,
                    'longitude' => $sample->longitude ?? null,
                    'location_name' => $sample->location_name ?? null,
                ] : null,
            ]);
            
            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Daftar observasi berhasil dimuat'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat daftar observasi: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Mendapatkan detail observasi
     */
    public function getObservationDetail($id)
    {
        try {
            $userId = auth()->user()->id;
            Log::info('getObservationDetail: start', ['userId' => $userId, 'id' => $id]);
            
            // Cek format ID untuk menentukan sumber data
            if (strpos($id, 'BN') === 0) {
                // Format BN123 untuk Burungnesia
                $actualId = substr($id, 2);
                $observation = FobiChecklistBurungnesia::with(['faunas.fauna', 'medias', 'user'])
                    ->where('id', $actualId)
                    ->where('fobi_user_id', $userId)
                    ->first();
                    
                if (!$observation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Observasi Burungnesia tidak ditemukan atau Anda tidak memiliki akses'
                    ], 404);
                }
                
                // Format data tambahan
                $observation->source = 'burungnesia';
                $fauna = $observation->faunas->first();
                $faunaData = $fauna ? $fauna->fauna : null;
                
                $observation->scientific_name = $faunaData ? $faunaData->nameLat : 'Unknown Species';
                $observation->genus = $faunaData ? explode(' ', $faunaData->nameLat)[0] : '';
                $observation->species = $faunaData ? (count(explode(' ', $faunaData->nameLat)) > 1 ? explode(' ', $faunaData->nameLat)[1] : '') : '';
                $observation->formatted_date = $observation->tgl_pengamatan ? date('d F Y', strtotime($observation->tgl_pengamatan)) : $observation->created_at->format('d F Y');
                $firstMedia = $observation->medias->first();
                $mediaLocation = $firstMedia->location ?? null;
                $observation->location_name = !empty($observation->location)
                    ? $observation->location
                    : (!empty($mediaLocation)
                        ? $mediaLocation
                        : (isset($observation->latitude, $observation->longitude)
                            ? ($observation->latitude . ', ' . $observation->longitude)
                            : null));
                
                // Format media
                $observation->medias->transform(function ($media) {
                    $media->full_url = $media->images;
                    $media->thumbnail_url = $media->images;
                    $media->media_type = 'photo';
                    return $media;
                });
                
            } elseif (strpos($id, 'KN') === 0) {
                // Format KN123 untuk Kupunesia
                $actualId = substr($id, 2);
                $observation = FobiChecklistKupunesia::with(['faunas.fauna', 'medias', 'user'])
                    ->where('id', $actualId)
                    ->where('fobi_user_id', $userId)
                    ->first();
                    
                if (!$observation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Observasi Kupunesia tidak ditemukan atau Anda tidak memiliki akses'
                    ], 404);
                }
                
                // Format data tambahan
                $observation->source = 'kupunesia';
                $fauna = $observation->faunas->first();
                $faunaData = $fauna ? $fauna->fauna : null;
                
                $observation->scientific_name = $faunaData ? $faunaData->nameLat : 'Unknown Species';
                $observation->genus = $faunaData ? explode(' ', $faunaData->nameLat)[0] : '';
                $observation->species = $faunaData ? (count(explode(' ', $faunaData->nameLat)) > 1 ? explode(' ', $faunaData->nameLat)[1] : '') : '';
                $observation->formatted_date = $observation->tgl_pengamatan ? date('d F Y', strtotime($observation->tgl_pengamatan)) : $observation->created_at->format('d F Y');
                $observation->location_name = !empty($observation->location)
                    ? $observation->location
                    : (isset($observation->latitude, $observation->longitude)
                        ? ($observation->latitude . ', ' . $observation->longitude)
                        : null);
                
                // Format media
                $observation->medias->transform(function ($media) {
                    $media->full_url = $media->images;
                    $media->thumbnail_url = $media->images;
                    $media->media_type = 'photo';
                    return $media;
                });
                
            } else {
                // Format default untuk FobiChecklistTaxa
            $observation = FobiChecklistTaxa::with('medias', 'user', 'qualityAssessment')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->first();
                
            if (!$observation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Observasi tidak ditemukan atau Anda tidak memiliki akses'
                ], 404);
            }
            
            // Format data tambahan
                $observation->source = 'taxa';
            $observation->formatted_date = $observation->created_at->format('d F Y');
            $firstMedia = $observation->medias->first();
            $mediaLocation = $firstMedia->location ?? null;
            $observation->location_name = !empty($observation->location)
                ? $observation->location
                : (!empty($mediaLocation)
                    ? $mediaLocation
                    : (isset($observation->latitude, $observation->longitude)
                        ? ($observation->latitude . ', ' . $observation->longitude)
                        : null));
            Log::info('getObservationDetail: formatted', [
                'id' => $observation->id ?? null,
                'source' => $observation->source ?? null,
                'location' => $observation->location ?? null,
                'latitude' => $observation->latitude ?? null,
                'longitude' => $observation->longitude ?? null,
                'location_name' => $observation->location_name ?? null,
            ]);
            
            // Format media
            $observation->medias->transform(function ($media) {
                // Pastikan URL media bisa diakses
                $media->full_url = MediaStorageHelper::getMediaUrl(
                    $media->file_path,
                    $media->storage_type ?? 'local',
                    $media->id
                );
                $media->thumbnail_url = MediaStorageHelper::getMediaUrl(
                    $media->file_path,
                    $media->storage_type ?? 'local',
                    $media->id
                );
                if ($media->spectrogram) {
                    $media->spectrogram = MediaStorageHelper::getMediaUrl(
                        $media->spectrogram,
                        $media->storage_type ?? 'local',
                        $media->id
                    );
                }
                return $media;
            });
            }
            
            return response()->json([
                'success' => true,
                'data' => $observation,
                'message' => 'Detail observasi berhasil dimuat'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail observasi: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update data observasi
     */
    public function updateObservation(Request $request, $id)
    {
        // Deteksi apakah request hanya untuk operasi media
        $isMediaOnlyOperation = false;
        
        // Cek apakah request hanya berisi new_media
        if ($request->hasFile('new_media') && count($request->allFiles()) === 1 && count($request->except(['new_media'])) === 0) {
            $isMediaOnlyOperation = true;
            $mediaController = app()->make('App\Http\Controllers\Api\FobiMediaController');
            return $mediaController->addMedia($request, $id);
        }
        
        // Cek apakah request hanya berisi media_to_delete
        if ($request->has('media_to_delete') && count($request->all()) === 1) {
            $isMediaOnlyOperation = true;
            $mediaController = app()->make('App\Http\Controllers\Api\FobiMediaController');
            return $mediaController->deleteMedia($request, $id);
        }
        
        try {
            $userId = Auth::id();
            
            // Cek format ID untuk menentukan sumber data
            if (strpos($id, 'BN') === 0) {
                // Format BN123 untuk Burungnesia
                $actualId = substr($id, 2);
                
                // Validasi input untuk Burungnesia
                $validator = Validator::make($request->all(), [
                    'scientific_name' => 'required|string|max:255',
                    'latitude' => 'required|numeric',
                    'longitude' => 'required|numeric',
                    'observation_date' => 'nullable|date',
                    'observation_details' => 'nullable|json',
                    'new_media' => 'nullable|array',
                    'new_media.*' => 'file|mimes:jpeg,png,jpg,gif,mp4,mov,avi,mp3,wav|max:20480',
                    'media_to_delete' => 'nullable|array',
                    'media_to_delete.*' => 'integer'
                ]);
                
                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validasi gagal',
                        'errors' => $validator->errors()
                    ], 422);
                }
                
                // Cari observasi Burungnesia
                $observation = FobiChecklistBurungnesia::where('id', $actualId)
                    ->where('fobi_user_id', $userId)
                    ->first();
                    
                if (!$observation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Observasi Burungnesia tidak ditemukan atau Anda tidak memiliki akses'
                    ], 404);
                }
                
                // Update data observasi Burungnesia
                DB::beginTransaction();
                
                $observation->scientific_name = $request->scientific_name;
                $observation->latitude = $request->latitude;
                $observation->longitude = $request->longitude;
                
                if ($request->has('observation_date')) {
                    $observation->tgl_pengamatan = $request->observation_date;
                }
                
                if ($request->has('observation_details')) {
                    $observation->additional_note = json_decode($request->observation_details, true);
                }
                
                // Simpan perubahan pada observasi
                $observation->updated_at = now();
                $observation->save();
                
                // Hapus media yang dipilih
                if ($request->has('media_to_delete') && is_array($request->media_to_delete)) {
                    foreach ($request->media_to_delete as $mediaId) {
                        $media = DB::table('fobi_checklist_fauna_imgs')
                            ->where('id', $mediaId)
                            ->where('checklist_id', $actualId)
                            ->first();
                            
                        if ($media) {
                            // Hapus file fisik jika ada
                            $path = parse_url($media->images, PHP_URL_PATH);
                            $localPath = str_replace('/storage/', '', $path);
                            if (Storage::disk('public')->exists($localPath)) {
                                Storage::disk('public')->delete($localPath);
                            }
                            
                            // Hapus record dari database
                            DB::table('fobi_checklist_fauna_imgs')
                                ->where('id', $mediaId)
                                ->delete();
                        }
                    }
                }
                
                // Proses media baru
                if ($request->hasFile('new_media')) {
                    foreach ($request->file('new_media') as $file) {
                        // Deteksi tipe media
                        $extension = $file->getClientOriginalExtension();
                        $mediaType = in_array(strtolower($extension), ['mp3', 'wav']) ? 'audio' : 'image';
                        
                        if ($mediaType === 'audio') {
                            $soundPath = $file->store('sounds', 'public');
                            $spectrogramPath = preg_replace('/\.(mp3|wav|ogg)$/i', '.png', $soundPath);
                            
                            // Generate spectrogram
                            $env = [
                                'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                                'PYTHONPATH' => '/var/www/talinara/venv/lib/python3.12/site-packages'
                            ];
                            
                            $command = escapeshellcmd("/var/www/talinara/venv/bin/python " . base_path('/scripts/spectrogram.py') . " " .
                                storage_path('app/public/' . $soundPath) . " " .
                                storage_path('app/public/' . $spectrogramPath));
                            
                            $process = proc_open($command, [
                                0 => ["pipe", "r"],
                                1 => ["pipe", "w"],
                                2 => ["pipe", "w"]
                            ], $pipes, null, $env);
                            
                            if (is_resource($process)) {
                                $stdout = stream_get_contents($pipes[1]);
                                $stderr = stream_get_contents($pipes[2]);
                                fclose($pipes[1]);
                                fclose($pipes[2]);
                                proc_close($process);
                                
                                if (Storage::disk('public')->exists($spectrogramPath)) {
                                    // Simpan record audio
                                    DB::table('fobi_checklist_sounds')->insert([
                                        'checklist_id' => $actualId,
                                        'fauna_id' => $observation->fauna_id ?? null,
                                        'sounds' => asset('storage/' . $soundPath),
                                        'spectrogram' => asset('storage/' . $spectrogramPath),
                                        'created_at' => now(),
                                        'updated_at' => now()
                                    ]);
                                }
                            }
                        } else {
                            // Proses gambar
                            $result = $this->processImageFile($file);
                            
                            if ($result['success']) {
                                DB::table('fobi_checklist_fauna_imgs')->insert([
                                    'checklist_id' => $actualId,
                                    'fauna_id' => $observation->fauna_id ?? null,
                                    'images' => asset('storage/' . $result['imagePath']),
                                    'created_at' => now(),
                                    'updated_at' => now()
                                ]);
                            }
                        }
                    }
                }
                
                DB::commit();
                
                // Ambil data terbaru termasuk media
                $updatedObservation = FobiChecklistBurungnesia::with(['medias', 'sounds'])
                    ->where('id', $actualId)
                    ->first();
                
                return response()->json([
                    'success' => true,
                    'data' => $updatedObservation,
                    'message' => 'Observasi Burungnesia berhasil diperbarui'
                ]);
                
            } elseif (strpos($id, 'KN') === 0) {
                // Format KN123 untuk Kupunesia
                $actualId = substr($id, 2);
                
                // Validasi input untuk Kupunesia
                $validator = Validator::make($request->all(), [
                    'scientific_name' => 'required|string|max:255',
                    'latitude' => 'required|numeric',
                    'longitude' => 'required|numeric',
                    'observation_date' => 'nullable|date',
                    'observation_details' => 'nullable|json',
                    'new_media' => 'nullable|array',
                    'new_media.*' => 'file|mimes:jpeg,png,jpg,gif|max:20480',
                    'media_to_delete' => 'nullable|array',
                    'media_to_delete.*' => 'integer'
                ]);
                
                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validasi gagal',
                        'errors' => $validator->errors()
                    ], 422);
                }
                
                // Cari observasi Kupunesia
                $observation = FobiChecklistKupunesia::where('id', $actualId)
                    ->where('fobi_user_id', $userId)
                    ->first();
                    
                if (!$observation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Observasi Kupunesia tidak ditemukan atau Anda tidak memiliki akses'
                    ], 404);
                }
                
                // Update data observasi Kupunesia
                DB::beginTransaction();
                
                $observation->scientific_name = $request->scientific_name;
                $observation->latitude = $request->latitude;
                $observation->longitude = $request->longitude;
                
                if ($request->has('observation_date')) {
                    $observation->tgl_pengamatan = $request->observation_date;
                }
                
                if ($request->has('observation_details')) {
                    $observation->additional_note = json_decode($request->observation_details, true);
                }
                
                // Simpan perubahan pada observasi
                $observation->updated_at = now();
                $observation->save();
                
                // Hapus media yang dipilih
                if ($request->has('media_to_delete') && is_array($request->media_to_delete)) {
                    foreach ($request->media_to_delete as $mediaId) {
                        $media = DB::table('fobi_checklist_fauna_imgs_kupnes')
                            ->where('id', $mediaId)
                            ->where('checklist_id', $actualId)
                            ->first();
                            
                        if ($media) {
                            // Hapus file fisik jika ada
                            $path = parse_url($media->images, PHP_URL_PATH);
                            $localPath = str_replace('/storage/', '', $path);
                            if (Storage::disk('public')->exists($localPath)) {
                                Storage::disk('public')->delete($localPath);
                            }
                            
                            // Hapus record dari database
                            DB::table('fobi_checklist_fauna_imgs_kupnes')
                                ->where('id', $mediaId)
                                ->delete();
                        }
                    }
                }
                
                // Proses media baru
                if ($request->hasFile('new_media')) {
                    foreach ($request->file('new_media') as $file) {
                        // Proses gambar untuk Kupunesia
                        $result = $this->processImageFile($file);
                        
                        if ($result['success']) {
                            DB::table('fobi_checklist_fauna_imgs_kupnes')->insert([
                                'checklist_id' => $actualId,
                                'fauna_id' => $observation->fauna_id ?? null,
                                'images' => asset('storage/' . $result['imagePath']),
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);
                        }
                    }
                }
                
                DB::commit();
                
                // Ambil data terbaru termasuk media
                $updatedObservation = FobiChecklistKupunesia::with(['medias'])
                    ->where('id', $actualId)
                    ->first();
                
                return response()->json([
                    'success' => true,
                    'data' => $updatedObservation,
                    'message' => 'Observasi Kupunesia berhasil diperbarui'
                ]);
                
            } else {
                // Format default untuk FobiChecklistTaxa
            // Validasi input
            $validator = Validator::make($request->all(), [
                'scientific_name' => 'required|string|max:255',
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
                'observation_details' => 'nullable|json',
                'observation_date' => 'nullable|date',
                'new_media' => 'nullable|array',
                'new_media.*' => 'file|mimes:jpeg,png,jpg,gif,mp4,mov,avi,mp3,wav|max:20480',
                'media_to_delete' => 'nullable|array',
                    'media_to_delete.*' => 'integer',
                    'taxon_id' => 'nullable|string',
                    'kingdom' => 'nullable|string',
                    'phylum' => 'nullable|string',
                    'class' => 'nullable|string',
                    'order' => 'nullable|string',
                    'family' => 'nullable|string',
                    'genus' => 'nullable|string',
                    'species' => 'nullable|string'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Cari observasi
            $observation = FobiChecklistTaxa::where('id', $id)
                ->where('user_id', $userId)
                ->first();
                
            if (!$observation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Observasi tidak ditemukan atau Anda tidak memiliki akses'
                ], 404);
            }
            
            // Update data observasi
            DB::beginTransaction();
            
            $observation->scientific_name = $request->scientific_name;
            $observation->latitude = $request->latitude;
            $observation->longitude = $request->longitude;
                
                if ($request->has('observation_details')) {
                    $observation->observation_details = json_decode($request->observation_details, true);
                }
                
                if ($request->has('observation_date')) {
                    $observation->date = $request->observation_date;
                }
                
                // Tambahkan taxa_id jika ada
                if ($request->has('taxon_id')) {
                    $observation->taxa_id = $request->taxon_id;
                }
                
                // Dapatkan kolom-kolom yang ada di tabel
                $tableColumns = Schema::getColumnListing('fobi_checklist_taxas');
                
                \Log::info('Kolom yang tersedia di tabel fobi_checklist_taxas:', $tableColumns);
                
                // Update kolom taksonomi hanya jika kolom tersebut ada di tabel
                if ($request->has('kingdom') && in_array('kingdom', $tableColumns)) {
            $observation->kingdom = $request->kingdom;
                }
                
                if ($request->has('phylum') && in_array('phylum', $tableColumns)) {
            $observation->phylum = $request->phylum;
                }
                
                if ($request->has('class') && in_array('class', $tableColumns)) {
            $observation->class = $request->class;
                }
                
                if ($request->has('order') && in_array('order', $tableColumns)) {
            $observation->order = $request->order;
                }
                
                if ($request->has('family') && in_array('family', $tableColumns)) {
            $observation->family = $request->family;
                }
            
                if ($request->has('genus') && in_array('genus', $tableColumns)) {
                    $observation->genus = $request->genus;
            }
            
                if ($request->has('species') && in_array('species', $tableColumns)) {
                    $observation->species = $request->species;
            }
            
            // Simpan perubahan pada observasi
            $observation->save();
                
                // Log perubahan taksonomi
                \Log::info("Taksonomi observasi ID {$id} diperbarui", [
                    'scientific_name' => $observation->scientific_name,
                    'kingdom' => $observation->kingdom,
                    'phylum' => $observation->phylum,
                    'class' => $observation->class,
                    'order' => $observation->order,
                    'family' => $observation->family,
                    'genus' => $observation->genus,
                    'species' => $observation->species
                ]);
            
            // Hapus media yang dipilih
            if ($request->has('media_to_delete') && is_array($request->media_to_delete)) {
                foreach ($request->media_to_delete as $mediaId) {
                    $media = FobiChecklistMedia::where('id', $mediaId)
                        ->where('checklist_id', $observation->id)
                        ->first();
                        
                    if ($media) {
                        // Hapus file fisik jika ada
                        if (Storage::exists($media->file_path)) {
                            Storage::delete($media->file_path);
                        }
                        if ($media->spectrogram && Storage::exists($media->spectrogram)) {
                            Storage::delete($media->spectrogram);
                        }
                        
                        // Hapus record dari database
                        $media->delete();
                            \Log::info("Media terkait observasi ID {$id} berhasil dihapus");
                    }
                }
            }
            
            // Proses media baru
            if ($request->hasFile('new_media')) {
                foreach ($request->file('new_media') as $file) {
                    $path = $file->store('public/observations/' . $observation->id);
                    $publicPath = Storage::url($path);
                    
                    // Deteksi tipe media
                    $extension = $file->getClientOriginalExtension();
                    $mediaType = in_array(strtolower($extension), ['mp4', 'mov', 'avi']) ? 'video' : 
                                (in_array(strtolower($extension), ['mp3', 'wav']) ? 'audio' : 'image');
                    
                    // Buat record media baru
                    $media = new FobiChecklistMedia();
                    $media->checklist_id = $observation->id;
                    $media->media_type = $mediaType;
                    $media->file_path = $publicPath;
                    $media->scientific_name = $observation->scientific_name;
                    $media->location = "Lat: {$observation->latitude}, Long: {$observation->longitude}";
                        $media->date = $observation->date ?? now()->toDateString();
                    $media->save();
                    
                    // Jika audio, buat spectrogram
                    if ($mediaType === 'audio') {
                        // Logic untuk membuat spectrogram akan ditambahkan di sini
                    }
                }
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'data' => $observation->fresh(['medias']),
                'message' => 'Observasi berhasil diperbarui'
            ]);
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui observasi: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Hapus observasi
     */
    public function deleteObservation($id)
    {
        try {
            $userId = Auth::id();
            
            // Cek format ID untuk menentukan sumber data
            if (strpos($id, 'BN') === 0) {
                // Format BN123 untuk Burungnesia
                $actualId = substr($id, 2);
                $observation = FobiChecklistBurungnesia::where('id', $actualId)
                    ->where('fobi_user_id', $userId)
                    ->first();
                    
                if (!$observation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Observasi Burungnesia tidak ditemukan atau Anda tidak memiliki akses'
                    ], 404);
                }
                
                DB::beginTransaction();
                
                try {
                    // Hapus semua media terkait
                    $medias = DB::table('fobi_checklist_fauna_imgs')->where('checklist_id', $actualId)->get();
                    foreach ($medias as $media) {
                        // Hapus file fisik jika ada
                        $path = parse_url($media->images, PHP_URL_PATH);
                        $localPath = str_replace('/storage/', '', $path);
                        if (Storage::disk('public')->exists($localPath)) {
                            Storage::disk('public')->delete($localPath);
                        }
                    }
                    DB::table('fobi_checklist_fauna_imgs')->where('checklist_id', $actualId)->delete();
                    
                    // Hapus sounds terkait
                    $sounds = DB::table('fobi_checklist_sounds')->where('checklist_id', $actualId)->get();
                    foreach ($sounds as $sound) {
                        // Hapus file fisik jika ada
                        $path = parse_url($sound->sounds, PHP_URL_PATH);
                        $localPath = str_replace('/storage/', '', $path);
                        if (Storage::disk('public')->exists($localPath)) {
                            Storage::disk('public')->delete($localPath);
                        }
                        
                        if ($sound->spectrogram) {
                            $spectrogramPath = parse_url($sound->spectrogram, PHP_URL_PATH);
                            $localSpectrogramPath = str_replace('/storage/', '', $spectrogramPath);
                            if (Storage::disk('public')->exists($localSpectrogramPath)) {
                                Storage::disk('public')->delete($localSpectrogramPath);
                            }
                        }
                    }
                    DB::table('fobi_checklist_sounds')->where('checklist_id', $actualId)->delete();
                    
                    // Hapus fauna terkait
                    DB::table('fobi_checklist_faunasv1')->where('checklist_id', $actualId)->delete();
                    
                    // Hapus community identifications terkait
                    DB::table('community_identifications')
                        ->where('observation_id', $actualId)
                        ->where('observation_type', 'burungnesia')
                        ->delete();
                    
                    // Hapus verifikasi lokasi terkait
                    DB::table('location_verifications')
                        ->where('observation_id', $actualId)
                        ->where('observation_type', 'burungnesia')
                        ->delete();
                    
                    // Hapus wild status votes terkait
                    DB::table('wild_status_votes')
                        ->where('observation_id', $actualId)
                        ->where('observation_type', 'burungnesia')
                        ->delete();
                    
                    // Hapus quality assessment terkait
                    DB::table('data_quality_assessments')->where('observation_id', $actualId)->delete();
                    
                    // Hapus comments terkait
                    DB::table('observation_comments')
                        ->where('observation_id', $actualId)
                        ->delete();
                    
                    // Hapus observasi
                    $observation->delete();
                    \Log::info("Observasi ID {$id} berhasil dihapus");
                    
                    DB::commit();
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Observasi Burungnesia berhasil dihapus'
                    ]);
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    \Log::error("Gagal menghapus observasi Burungnesia ID {$actualId}: " . $e->getMessage());
                    \Log::error($e->getTraceAsString());
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal menghapus observasi Burungnesia: ' . $e->getMessage()
                    ], 500);
                }
                
            } elseif (strpos($id, 'KN') === 0) {
                // Format KN123 untuk Kupunesia
                $actualId = substr($id, 2);
                $observation = FobiChecklistKupunesia::where('id', $actualId)
                    ->where('fobi_user_id', $userId)
                    ->first();
                    
                if (!$observation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Observasi Kupunesia tidak ditemukan atau Anda tidak memiliki akses'
                    ], 404);
                }
                
                DB::beginTransaction();
                
                try {
                    // Hapus semua media terkait
                    $medias = DB::table('fobi_checklist_fauna_imgs_kupnes')->where('checklist_id', $actualId)->get();
                    foreach ($medias as $media) {
                        // Hapus file fisik jika ada
                        $path = parse_url($media->images, PHP_URL_PATH);
                        $localPath = str_replace('/storage/', '', $path);
                        if (Storage::disk('public')->exists($localPath)) {
                            Storage::disk('public')->delete($localPath);
                        }
                    }
                    DB::table('fobi_checklist_fauna_imgs_kupnes')->where('checklist_id', $actualId)->delete();
                    
                    // Hapus fauna terkait
                    DB::table('fobi_checklist_faunasv2')->where('checklist_id', $actualId)->delete();
                    
                    // Hapus observasi
                    $observation->delete();
                    
                    // Hapus community identifications terkait
                    DB::table('community_identifications')
                        ->where('observation_id', $actualId)
                        ->where('observation_type', 'kupunesia')
                        ->delete();
                    
                    // Hapus verifikasi lokasi terkait
                    DB::table('location_verifications')
                        ->where('observation_id', $actualId)
                        ->where('observation_type', 'kupunesia')
                        ->delete();
                    
                    // Hapus wild status votes terkait
                    DB::table('wild_status_votes')
                        ->where('observation_id', $actualId)
                        ->where('observation_type', 'kupunesia')
                        ->delete();
                    
                    // Hapus quality assessment terkait
                    DB::table('data_quality_assessments_kupnes')->where('observation_id', $actualId)->delete();
                    
                    // Hapus comments terkait
                    DB::table('observation_comments')
                        ->where('observation_id', $actualId)
                        ->delete();
                    
                    DB::commit();
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Observasi Kupunesia berhasil dihapus'
                    ]);
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    \Log::error("Gagal menghapus observasi Kupunesia ID {$actualId}: " . $e->getMessage());
                    \Log::error($e->getTraceAsString());
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal menghapus observasi Kupunesia: ' . $e->getMessage()
                    ], 500);
                }
                
            } else {
                // Format default untuk FobiChecklistTaxa
            $observation = FobiChecklistTaxa::where('id', $id)
                ->where('user_id', $userId)
                ->first();
                
            if (!$observation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Observasi tidak ditemukan atau Anda tidak memiliki akses'
                ], 404);
            }
            
            DB::beginTransaction();
            
            try {
                // Hapus semua media terkait
                $medias = FobiChecklistMedia::where('checklist_id', $observation->id)->get();
                foreach ($medias as $media) {
                    // Hapus file fisik jika ada
                    if (Storage::exists($media->file_path)) {
                        Storage::delete($media->file_path);
                    }
                    if ($media->spectrogram && Storage::exists($media->spectrogram)) {
                        Storage::delete($media->spectrogram);
                    }
                    
                    // Hapus record dari database
                    $media->delete();
                    \Log::info("Media terkait observasi ID {$id} berhasil dihapus");
                }

                // Hapus identifikasi terkait
                DB::table('taxa_identifications')->where('checklist_id', $observation->id)->delete();
                \Log::info("Identifikasi terkait observasi ID {$id} berhasil dihapus");

                // Hapus verifikasi lokasi terkait
                DB::table('taxa_location_verifications')->where('checklist_id', $observation->id)->delete();
                \Log::info("Verifikasi lokasi terkait observasi ID {$id} berhasil dihapus");

                // Hapus wild status votes terkait
                DB::table('taxa_wild_status_votes')->where('checklist_id', $observation->id)->delete();
                \Log::info("Wild status votes terkait observasi ID {$id} berhasil dihapus");

                // Hapus quality assessment terkait
                DB::table('taxa_quality_assessments')->where('taxa_id', $observation->id)->delete();
                \Log::info("Quality assessment terkait observasi ID {$id} berhasil dihapus");

                // Hapus comments terkait
                DB::table('observation_comments')->where('observation_id', $observation->id)->delete();
                \Log::info("Komentar terkait observasi ID {$id} berhasil dihapus");

                // Hapus flags terkait
                DB::table('taxa_flags')->where('checklist_id', $observation->id)->delete();
                \Log::info("Flags terkait observasi ID {$id} berhasil dihapus");
                
                // Hapus notifikasi terkait
                DB::table('taxa_notifications')->where('checklist_id', $observation->id)->delete();
                \Log::info("Notifikasi terkait observasi ID {$id} berhasil dihapus");
                
                // Hapus observasi
                $observation->delete();
                \Log::info("Observasi ID {$id} berhasil dihapus");
                
                DB::commit();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Observasi berhasil dihapus'
                ]);
                
            } catch (\Exception $e) {
                DB::rollBack();
                \Log::error("Gagal menghapus observasi ID {$id}: " . $e->getMessage());
                \Log::error($e->getTraceAsString());
                
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal menghapus observasi: ' . $e->getMessage()
                ], 500);
                }
            }
            
        } catch (\Exception $e) {
            \Log::error("Error saat mengakses observasi ID {$id}: " . $e->getMessage());
            \Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Mendapatkan saran pencarian
     */
    public function getSearchSuggestions(Request $request)
    {
        try {
            $userId = Auth::id();
            $query = $request->get('q', '');
            $type = $request->get('type', 'all');
            
            if (empty($query) || strlen($query) < 2) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }
            
            $suggestions = [];
            
            // Berdasarkan tipe pencarian
            if ($type === 'species' || $type === 'all') {
                // Cari berdasarkan nama spesies
                $speciesSuggestions = FobiChecklistTaxa::where('user_id', $userId)
                    ->where(function($q) use ($query) {
                        $q->where('scientific_name', 'like', '%' . $query . '%')
                          ->orWhere('genus', 'like', '%' . $query . '%')
                          ->orWhere('species', 'like', '%' . $query . '%');
                    })
                    ->select('scientific_name', 'genus', 'species')
                    ->distinct()
                    ->limit(10)
                    ->get()
                    ->map(function($item) {
                        return [
                            'scientific_name' => $item->scientific_name,
                            'type' => 'species'
                        ];
                    });
                    
                $suggestions = array_merge($suggestions, $speciesSuggestions->toArray());
            }
            
            if ($type === 'location' || $type === 'all') {
                // Karena lokasi disimpan sebagai koordinat, kita berikan beberapa contoh lokasi
                // Dalam implementasi nyata, Anda mungkin perlu menggunakan geocoding service
                $locationSuggestions = [
                    ['name' => 'Jakarta', 'type' => 'location'],
                    ['name' => 'Surabaya', 'type' => 'location'],
                    ['name' => 'Bandung', 'type' => 'location'],
                    ['name' => 'Bali', 'type' => 'location'],
                ];
                
                // Filter berdasarkan query
                $locationSuggestions = array_filter($locationSuggestions, function($item) use ($query) {
                    return stripos($item['name'], $query) !== false;
                });
                
                $suggestions = array_merge($suggestions, array_values($locationSuggestions));
            }
            
            // Limit hasil
            $suggestions = array_slice($suggestions, 0, 10);
            
            return response()->json([
                'success' => true,
                'data' => $suggestions
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mendapatkan saran pencarian: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Proses file gambar yang diunggah
     */
    private function processImageFile($file)
    {
        try {
            $uploadPath = storage_path('app/public/uploads');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }

            $image = Image::make($file->getRealPath());

            // Resize gambar dengan mempertahankan aspect ratio
            $image->resize(1200, 1200, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            $fileName = uniqid('img_') . '.jpg';
            $relativePath = 'uploads/' . $fileName;
            $fullPath = storage_path('app/public/' . $relativePath);

            // Simpan gambar dengan kualitas 80%
            $image->save($fullPath, 80);

            return [
                'imagePath' => $relativePath,
                'success' => true
            ];

        } catch (\Exception $e) {
            Log::error('Error processing image file: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Menambahkan media ke observasi
     */
    public function addMedia(Request $request, $id)
    {
        try {
            $userId = Auth::id();
            
            // Validasi input khusus untuk media
            $validator = Validator::make($request->all(), [
                'new_media' => 'required|array',
                'new_media.*' => 'file|mimes:jpeg,png,jpg,gif,mp4,mov,avi,mp3,wav|max:20480'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Cek format ID untuk menentukan sumber data
            if (strpos($id, 'BN') === 0) {
                // Format BN123 untuk Burungnesia
                $actualId = substr($id, 2);
                $observation = FobiChecklistBurungnesia::where('id', $actualId)
                    ->where('fobi_user_id', $userId)
                    ->first();
                    
                if (!$observation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Observasi Burungnesia tidak ditemukan atau Anda tidak memiliki akses'
                    ], 404);
                }
                
                DB::beginTransaction();
                
                // Proses media baru
                if ($request->hasFile('new_media')) {
                    foreach ($request->file('new_media') as $file) {
                        // Deteksi tipe media
                        $extension = $file->getClientOriginalExtension();
                        $mediaType = in_array(strtolower($extension), ['mp3', 'wav']) ? 'audio' : 'image';
                        
                        if ($mediaType === 'audio') {
                            $soundPath = $file->store('sounds', 'public');
                            $spectrogramPath = preg_replace('/\.(mp3|wav|ogg)$/i', '.png', $soundPath);
                            
                            // Generate spectrogram
                            $env = [
                                'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                                'PYTHONPATH' => '/var/www/talinara/venv/lib/python3.12/site-packages'
                            ];
                            
                            $command = escapeshellcmd("/var/www/talinara/venv/bin/python " . base_path('/scripts/spectrogram.py') . " " .
                                storage_path('app/public/' . $soundPath) . " " .
                                storage_path('app/public/' . $spectrogramPath));
                            
                            $process = proc_open($command, [
                                0 => ["pipe", "r"],
                                1 => ["pipe", "w"],
                                2 => ["pipe", "w"]
                            ], $pipes, null, $env);
                            
                            if (is_resource($process)) {
                                $stdout = stream_get_contents($pipes[1]);
                                $stderr = stream_get_contents($pipes[2]);
                                fclose($pipes[1]);
                                fclose($pipes[2]);
                                proc_close($process);
                                
                                if (Storage::disk('public')->exists($spectrogramPath)) {
                                    // Simpan record audio
                                    DB::table('fobi_checklist_sounds')->insert([
                                        'checklist_id' => $actualId,
                                        'fauna_id' => $observation->fauna_id ?? null,
                                        'sounds' => asset('storage/' . $soundPath),
                                        'spectrogram' => asset('storage/' . $spectrogramPath),
                                        'created_at' => now(),
                                        'updated_at' => now()
                                    ]);
                                }
                            }
                        } else {
                            // Proses gambar
                            $result = $this->processImageFile($file);
                            
                            if ($result['success']) {
                                DB::table('fobi_checklist_fauna_imgs')->insert([
                                    'checklist_id' => $actualId,
                                    'fauna_id' => $observation->fauna_id ?? null,
                                    'images' => asset('storage/' . $result['imagePath']),
                                    'created_at' => now(),
                                    'updated_at' => now()
                                ]);
                            }
                        }
                    }
                }
                
                DB::commit();
                
                // Ambil data terbaru termasuk media
                $updatedObservation = FobiChecklistBurungnesia::with(['medias', 'sounds'])
                    ->where('id', $actualId)
                    ->first();
                
                return response()->json([
                    'success' => true,
                    'data' => $updatedObservation,
                    'message' => 'Media berhasil ditambahkan'
                ]);
                
            } elseif (strpos($id, 'KN') === 0) {
                // Format KN123 untuk Kupunesia
                $actualId = substr($id, 2);
                $observation = FobiChecklistKupunesia::where('id', $actualId)
                    ->where('fobi_user_id', $userId)
                    ->first();
                    
                if (!$observation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Observasi Kupunesia tidak ditemukan atau Anda tidak memiliki akses'
                    ], 404);
                }
                
                DB::beginTransaction();
                
                // Proses media baru
                if ($request->hasFile('new_media')) {
                    foreach ($request->file('new_media') as $file) {
                        // Proses gambar untuk Kupunesia
                        $result = $this->processImageFile($file);
                        
                        if ($result['success']) {
                            DB::table('fobi_checklist_fauna_imgs_kupnes')->insert([
                                'checklist_id' => $actualId,
                                'fauna_id' => $observation->fauna_id ?? null,
                                'images' => asset('storage/' . $result['imagePath']),
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);
                        }
                    }
                }
                
                DB::commit();
                
                // Ambil data terbaru termasuk media
                $updatedObservation = FobiChecklistKupunesia::with(['medias'])
                    ->where('id', $actualId)
                    ->first();
                
                return response()->json([
                    'success' => true,
                    'data' => $updatedObservation,
                    'message' => 'Media berhasil ditambahkan'
                ]);
                
            } else {
                // Format default untuk FobiChecklistTaxa
                $observation = FobiChecklistTaxa::where('id', $id)
                    ->where('user_id', $userId)
                    ->first();
                    
                if (!$observation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Observasi tidak ditemukan atau Anda tidak memiliki akses'
                    ], 404);
                }
                
                DB::beginTransaction();
                
                // Proses media baru
                if ($request->hasFile('new_media')) {
                    foreach ($request->file('new_media') as $file) {
                        $path = $file->store('public/observations/' . $observation->id);
                        $publicPath = Storage::url($path);
                        
                        // Deteksi tipe media
                        $extension = $file->getClientOriginalExtension();
                        $mediaType = in_array(strtolower($extension), ['mp4', 'mov', 'avi']) ? 'video' : 
                                    (in_array(strtolower($extension), ['mp3', 'wav']) ? 'audio' : 'image');
                        
                        // Buat record media baru
                        $media = new FobiChecklistMedia();
                        $media->checklist_id = $observation->id;
                        $media->media_type = $mediaType;
                        $media->file_path = $publicPath;
                        $media->scientific_name = $observation->scientific_name;
                        $media->location = "Lat: {$observation->latitude}, Long: {$observation->longitude}";
                        $media->date = $observation->date ?? now()->toDateString();
                        $media->save();
                        
                        // Jika audio, buat spectrogram
                        if ($mediaType === 'audio') {
                            // Logic untuk membuat spectrogram akan ditambahkan di sini
                        }
                    }
                }
                
                DB::commit();
                
                return response()->json([
                    'success' => true,
                    'data' => $observation->fresh(['medias']),
                    'message' => 'Media berhasil ditambahkan'
                ]);
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error adding media: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan media: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Menghapus media dari observasi
     */
    public function deleteMedia(Request $request, $id)
    {
        try {
            $userId = Auth::id();
            
            // Validasi input khusus untuk menghapus media
            $validator = Validator::make($request->all(), [
                'media_to_delete' => 'required|array',
                'media_to_delete.*' => 'integer'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Cek format ID untuk menentukan sumber data
            if (strpos($id, 'BN') === 0) {
                // Format BN123 untuk Burungnesia
                $actualId = substr($id, 2);
                $observation = FobiChecklistBurungnesia::where('id', $actualId)
                    ->where('fobi_user_id', $userId)
                    ->first();
                    
                if (!$observation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Observasi Burungnesia tidak ditemukan atau Anda tidak memiliki akses'
                    ], 404);
                }
                
                DB::beginTransaction();
                
                // Hapus media yang dipilih
                if ($request->has('media_to_delete') && is_array($request->media_to_delete)) {
                    foreach ($request->media_to_delete as $mediaId) {
                        $media = DB::table('fobi_checklist_fauna_imgs')
                            ->where('id', $mediaId)
                            ->where('checklist_id', $actualId)
                            ->first();
                            
                        if ($media) {
                            // Hapus file fisik jika ada
                            $path = parse_url($media->images, PHP_URL_PATH);
                            $localPath = str_replace('/storage/', '', $path);
                            if (Storage::disk('public')->exists($localPath)) {
                                Storage::disk('public')->delete($localPath);
                            }
                            
                            // Hapus record dari database
                            DB::table('fobi_checklist_fauna_imgs')
                                ->where('id', $mediaId)
                                ->delete();
                        }
                    }
                }
                
                DB::commit();
                
                // Ambil data terbaru termasuk media
                $updatedObservation = FobiChecklistBurungnesia::with(['medias', 'sounds'])
                    ->where('id', $actualId)
                    ->first();
                
                return response()->json([
                    'success' => true,
                    'data' => $updatedObservation,
                    'message' => 'Media berhasil dihapus'
                ]);
                
            } elseif (strpos($id, 'KN') === 0) {
                // Format KN123 untuk Kupunesia
                $actualId = substr($id, 2);
                $observation = FobiChecklistKupunesia::where('id', $actualId)
                    ->where('fobi_user_id', $userId)
                    ->first();
                    
                if (!$observation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Observasi Kupunesia tidak ditemukan atau Anda tidak memiliki akses'
                    ], 404);
                }
                
                DB::beginTransaction();
                
                // Hapus media yang dipilih
                if ($request->has('media_to_delete') && is_array($request->media_to_delete)) {
                    foreach ($request->media_to_delete as $mediaId) {
                        $media = DB::table('fobi_checklist_fauna_imgs_kupnes')
                            ->where('id', $mediaId)
                            ->where('checklist_id', $actualId)
                            ->first();
                            
                        if ($media) {
                            // Hapus file fisik jika ada
                            $path = parse_url($media->images, PHP_URL_PATH);
                            $localPath = str_replace('/storage/', '', $path);
                            if (Storage::disk('public')->exists($localPath)) {
                                Storage::disk('public')->delete($localPath);
                            }
                            
                            // Hapus record dari database
                            DB::table('fobi_checklist_fauna_imgs_kupnes')
                                ->where('id', $mediaId)
                                ->delete();
                        }
                    }
                }
                
                DB::commit();
                
                // Ambil data terbaru termasuk media
                $updatedObservation = FobiChecklistKupunesia::with(['medias'])
                    ->where('id', $actualId)
                    ->first();
                
                return response()->json([
                    'success' => true,
                    'data' => $updatedObservation,
                    'message' => 'Media berhasil dihapus'
                ]);
                
            } else {
                // Format default untuk FobiChecklistTaxa
                $observation = FobiChecklistTaxa::where('id', $id)
                    ->where('user_id', $userId)
                    ->first();
                    
                if (!$observation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Observasi tidak ditemukan atau Anda tidak memiliki akses'
                    ], 404);
                }
                
                DB::beginTransaction();
                
                // Hapus media yang dipilih
                if ($request->has('media_to_delete') && is_array($request->media_to_delete)) {
                    foreach ($request->media_to_delete as $mediaId) {
                        $media = FobiChecklistMedia::where('id', $mediaId)
                            ->where('checklist_id', $observation->id)
                            ->first();
                            
                        if ($media) {
                            // Hapus file fisik jika ada
                            if (Storage::exists($media->file_path)) {
                                Storage::delete($media->file_path);
                            }
                            if ($media->spectrogram && Storage::exists($media->spectrogram)) {
                                Storage::delete($media->spectrogram);
                            }
                            
                            // Hapus record dari database
                            $media->delete();
                            \Log::info("Media terkait observasi ID {$id} berhasil dihapus");
                        }
                    }
                }
                
                DB::commit();
                
                return response()->json([
                    'success' => true,
                    'data' => $observation->fresh(['medias']),
                    'message' => 'Media berhasil dihapus'
                ]);
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error deleting media: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus media: ' . $e->getMessage()
            ], 500);
        }
    }
} 
