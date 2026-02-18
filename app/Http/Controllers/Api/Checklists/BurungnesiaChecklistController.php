<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BurungnesiaChecklistController extends Controller
{
    public function update(Request $request, $id)
    {
        try {
            // Cek apakah user bisa memodifikasi checklist
            if (!$this->canModifyChecklist(auth()->user(), $id, 'burungnesia')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mengubah checklist ini'
                ], 403);
            }

            DB::beginTransaction();

            // Hapus 'BN' prefix jika ada
            $actualId = str_starts_with($id, 'BN') ? substr($id, 2) : $id;

            Log::info('Burungnesia update request:', [
                'id' => $id,
                'actualId' => $actualId,
                'data' => $request->all()
            ]);

            // Cek di database second
            $checklist = DB::connection('second')
                ->table('checklists')
                ->where('id', $actualId)
                ->first();

            if (!$checklist) {
                throw new \Exception('Checklist tidak ditemukan');
            }

            // Validate request
            $request->validate([
                'tgl_pengamatan' => 'required|date',
                'start_time' => 'required',
                'end_time' => 'required',
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
                'fauna' => 'array',
                'fauna.*.id' => 'required|integer'
            ]);

            // Update checklist di database second
            DB::connection('second')
                ->table('checklists')
                ->where('id', $actualId)
                ->update([
                    'tgl_pengamatan' => $request->tgl_pengamatan,
                    'start_time' => $request->start_time,
                    'end_time' => $request->end_time,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'additional_note' => $request->additional_note,
                    'updated_at' => now()
                ]);

            // Update fauna
            foreach ($request->fauna as $fauna) {
                DB::connection('second')
                    ->table('checklist_fauna')
                    ->where([
                        'checklist_id' => $actualId,
                        'fauna_id' => $fauna['id']
                    ])
                    ->update([
                        'count' => $fauna['jumlah'] ?? 0,
                        'notes' => $fauna['catatan'] ?? '',
                        'breeding' => $fauna['breeding'] ?? false,
                        'breeding_note' => $fauna['breeding_note'] ?? '',
                        'updated_at' => now()
                    ]);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Checklist berhasil diperbarui'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in Burungnesia update:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getDetail($id)
    {
        try {
            // Hapus 'BN' prefix jika ada
            $actualId = str_starts_with($id, 'BN') ? substr($id, 2) : $id;

            Log::info('Burungnesia detail request:', [
                'id' => $id,
                'actualId' => $actualId
            ]);

            // Cek di database second
            $checklist = DB::connection('second')
                ->table('checklists as fc')
                ->join('users as fu', 'fc.user_id', '=', 'fu.id')
                ->select([
                    DB::raw("CONCAT('BN', fc.id) as id"),
                    'fc.user_id as fobi_user_id',
                    'fc.observer',
                    'fc.latitude',
                    'fc.longitude',
                    'fc.tgl_pengamatan',
                    'fc.start_time',
                    'fc.end_time',
                    'fc.additional_note',
                    'fc.created_at',
                    'fc.updated_at',
                    'fu.uname as username'
                ])
                ->where('fc.id', $actualId)
                ->first();

            if (!$checklist) {
                return response()->json([
                    'success' => false,
                    'message' => 'Checklist tidak ditemukan'
                ], 404);
            }

            // Ambil fauna dari checklist_fauna Burungnesia
            $faunaRaw = DB::connection('second')
                ->table('checklist_fauna as fcf')
                ->select([
                    'fcf.checklist_id',
                    'fcf.fauna_id',
                    'fcf.count as jumlah',
                    'fcf.notes as catatan',
                    'fcf.breeding',
                    'fcf.breeding_note',
                    'fcf.nama_spesies',
                    'fcf.nama_latin'
                ])
                ->where('fcf.checklist_id', $actualId)
                ->whereNull('fcf.deleted_at')
                ->get();

            // Untuk setiap fauna, coba ambil data lengkap
            // PENTING: Ada 2 skenario untuk fauna_id:
            // 1. Data lama: fauna_id merujuk ke tabel faunas Burungnesia
            // 2. Data baru (dari suggestion API Amaturalist): fauna_id adalah taxa.id dari Amaturalist
            // 
            // STRATEGI: Prioritaskan data dari checklist_fauna.nama_latin dulu
            // Jika kosong, cek faunas Burungnesia, lalu taxas Amaturalist
            $fauna = $faunaRaw->map(function($item) {
                $namaLokal = '';
                $namaIlmiah = '';
                $family = '';
                $genus = '';
                $species = '';
                $taxonRank = 'SPECIES';
                $found = false;
                
                // SKENARIO 1: Cek apakah ada nama_latin di checklist_fauna (data baru dari web upload)
                if (!empty($item->nama_latin)) {
                    $namaLokal = $item->nama_spesies ?? '';
                    $namaIlmiah = $item->nama_latin ?? '';
                    
                    // Coba enrichment dari taxas berdasarkan nama ilmiah
                    $taxaByName = DB::table('taxas')
                        ->select([
                            'scientific_name',
                            'cname_species',
                            'Cname',
                            'cname_genus',
                            'cname_family',
                            'family',
                            'genus',
                            'species',
                            'taxon_rank'
                        ])
                        ->where('scientific_name', $namaIlmiah)
                        ->first();
                    
                    // Jika exact match tidak ketemu, coba LIKE
                    if (!$taxaByName) {
                        $taxaByName = DB::table('taxas')
                            ->select([
                                'scientific_name',
                                'cname_species',
                                'Cname',
                                'cname_genus',
                                'cname_family',
                                'family',
                                'genus',
                                'species',
                                'taxon_rank'
                            ])
                            ->where('scientific_name', 'LIKE', $namaIlmiah . '%')
                            ->first();
                    }
                    
                    if ($taxaByName) {
                        $taxonRank = strtoupper($taxaByName->taxon_rank ?? 'SPECIES');
                        
                        // Tentukan nama lokal berdasarkan taxon_rank
                        switch ($taxonRank) {
                            case 'SPECIES':
                            case 'SUBSPECIES':
                                $namaLokal = $taxaByName->cname_species ?? $taxaByName->Cname ?? $namaLokal;
                                break;
                            case 'GENUS':
                                $namaLokal = $taxaByName->cname_genus ?? $taxaByName->Cname ?? $namaLokal;
                                break;
                            case 'FAMILY':
                                $namaLokal = $taxaByName->cname_family ?? $taxaByName->Cname ?? $namaLokal;
                                break;
                            default:
                                $namaLokal = $taxaByName->cname_species ?? $taxaByName->Cname ?? $taxaByName->cname_genus ?? $taxaByName->cname_family ?? $namaLokal;
                        }
                        
                        $namaIlmiah = $taxaByName->scientific_name ?? $namaIlmiah;
                        $family = $taxaByName->family ?? '';
                        $genus = $taxaByName->genus ?? '';
                        $species = $taxaByName->species ?? '';
                    }
                    $found = true;
                }
                
                // SKENARIO 2: Cari di taxas Amaturalist berdasarkan fauna_id DULU
                // PENTING: Prioritaskan taxas karena upload dari web menggunakan taxa.id
                // Ini untuk menghindari ID bentrok dengan faunas Burungnesia
                if (!$found) {
                    $taxa = DB::table('taxas')
                        ->select([
                            'scientific_name',
                            'cname_species',
                            'Cname',
                            'cname_genus',
                            'cname_family',
                            'family',
                            'genus',
                            'species',
                            'taxon_rank'
                        ])
                        ->where('id', $item->fauna_id)
                        ->first();
                    
                    if ($taxa) {
                        $taxonRank = strtoupper($taxa->taxon_rank ?? 'SPECIES');
                        
                        // Tentukan nama lokal berdasarkan taxon_rank
                        switch ($taxonRank) {
                            case 'SPECIES':
                            case 'SUBSPECIES':
                                $namaLokal = $taxa->cname_species ?? $taxa->Cname ?? '';
                                break;
                            case 'GENUS':
                                $namaLokal = $taxa->cname_genus ?? $taxa->Cname ?? '';
                                break;
                            case 'FAMILY':
                                $namaLokal = $taxa->cname_family ?? $taxa->Cname ?? '';
                                break;
                            default:
                                $namaLokal = $taxa->cname_species ?? $taxa->Cname ?? $taxa->cname_genus ?? $taxa->cname_family ?? '';
                        }
                        
                        $namaIlmiah = $taxa->scientific_name ?? '';
                        $family = $taxa->family ?? '';
                        $genus = $taxa->genus ?? '';
                        $species = $taxa->species ?? '';
                        $found = true;
                    }
                }
                
                // SKENARIO 3: Jika TIDAK ketemu di taxas, coba cari di faunas Burungnesia (untuk data lama dari app)
                if (!$found) {
                    $faunaBurungnesia = DB::connection('second')
                        ->table('faunas')
                        ->select(['nameId', 'nameLat', 'family'])
                        ->where('id', $item->fauna_id)
                        ->first();
                    
                    if ($faunaBurungnesia && !empty($faunaBurungnesia->nameLat)) {
                        $namaLokal = $faunaBurungnesia->nameId ?? '';
                        $namaIlmiah = $faunaBurungnesia->nameLat ?? '';
                        $family = $faunaBurungnesia->family ?? '';
                        $found = true;
                        
                        // Coba enrichment dari taxas berdasarkan nama ilmiah
                        if (!empty($namaIlmiah)) {
                            $taxaByName = DB::table('taxas')
                                ->select([
                                    'scientific_name',
                                    'cname_species',
                                    'Cname',
                                    'family',
                                    'genus',
                                    'species',
                                    'taxon_rank'
                                ])
                                ->where('scientific_name', $namaIlmiah)
                                ->first();
                            
                            if (!$taxaByName) {
                                $taxaByName = DB::table('taxas')
                                    ->select([
                                        'scientific_name',
                                        'cname_species',
                                        'Cname',
                                        'family',
                                        'genus',
                                        'species',
                                        'taxon_rank'
                                    ])
                                    ->where('scientific_name', 'LIKE', $namaIlmiah . '%')
                                    ->first();
                            }
                            
                            if ($taxaByName) {
                                $namaLokal = $taxaByName->cname_species ?? $taxaByName->Cname ?? $namaLokal;
                                $namaIlmiah = $taxaByName->scientific_name ?? $namaIlmiah;
                                $family = $taxaByName->family ?? $family;
                                $genus = $taxaByName->genus ?? '';
                                $species = $taxaByName->species ?? '';
                                $taxonRank = strtoupper($taxaByName->taxon_rank ?? 'SPECIES');
                            }
                        }
                    }
                }
                
                // SKENARIO 4: Fallback ke data yang tersimpan di checklist_fauna
                if (empty($namaLokal) && empty($namaIlmiah)) {
                    $namaLokal = $item->nama_spesies ?? '';
                    $namaIlmiah = $item->nama_latin ?? '';
                }
                
                return (object)[
                    'fauna_id' => 'BN' . $item->fauna_id,
                    'checklist_id' => 'BN' . $item->checklist_id,
                    'jumlah' => $item->jumlah,
                    'catatan' => $item->catatan,
                    'breeding' => $item->breeding,
                    'breeding_note' => $item->breeding_note,
                    'nama_lokal' => $namaLokal,
                    'nama_ilmiah' => $namaIlmiah,
                    'family' => $family,
                    'genus' => $genus,
                    'species' => $species,
                    'taxon_rank' => $taxonRank
                ];
            });

            // Hitung total observasi
            $totalObservations = DB::connection('second')
                ->table('checklists')
                ->where('user_id', $checklist->fobi_user_id)
                ->whereNull('deleted_at')
                ->count();

            // Tambahkan informasi canEdit ke response
            $canEdit = $this->canModifyChecklist(auth()->user(), $actualId, 'burungnesia');

            $response = [
                'success' => true,
                'data' => [
                    'checklist' => [
                        'id' => $checklist->id,
                        'username' => $checklist->username,
                        'observer' => $checklist->observer,
                        'latitude' => $checklist->latitude,
                        'longitude' => $checklist->longitude,
                        'tgl_pengamatan' => $checklist->tgl_pengamatan,
                        'start_time' => $checklist->start_time,
                        'end_time' => $checklist->end_time,
                        'additional_note' => $checklist->additional_note,
                        'total_observations' => $totalObservations,
                        'can_edit' => $canEdit
                    ],
                    'fauna' => $fauna->map(function($f) {
                        return [
                            'id' => $f->fauna_id,
                            'checklist_id' => $f->checklist_id,
                            'nama_lokal' => $f->nama_lokal,
                            'nama_ilmiah' => $f->nama_ilmiah,
                            'family' => $f->family,
                            'genus' => $f->genus,
                            'species' => $f->species,
                            'taxon_rank' => $f->taxon_rank,
                            'jumlah' => $f->jumlah,
                            'catatan' => $f->catatan,
                            'breeding' => $f->breeding,
                            'breeding_note' => $f->breeding_note
                        ];
                    }),
                    'media' => [
                        'images' => [],
                        'sounds' => []
                    ]
                ]
            ];

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Error in getDetail Burungnesia:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil detail checklist'
            ], 500);
        }
    }
        /**
     * Check if user can modify checklist
     */
    private function canModifyChecklist($user, $checklistId, $source)
    {
        if (!$user) return false;

        $table = $source === 'burungnesia' ? 'fobi_checklists' : 'fobi_checklists_kupnes';

        $checklist = DB::table($table)
            ->where('id', $checklistId)
            ->first();

        if (!$checklist) return false;

        return $user->id === $checklist->fobi_user_id ||
               in_array($user->level, [3, 4]);
    }

    /**
     * Get media comments by ID
     */
    public function getMediaComments($mediaId)
    {
        try {
            // Deteksi apakah media ada di tabel FOBi
            $mediaExists = DB::table('fobi_checklist_fauna_imgs')
                ->where('id', $mediaId)
                ->exists();
            
            $isAppMedia = false;
            
            // Jika tidak ada di FOBi, maka kemungkinan dari aplikasi Burungnesia
            if (!$mediaExists) {
                $mediaExists = DB::connection('second')
                    ->table('checklist_fauna_imgs')
                    ->where('id', $mediaId)
                    ->exists();
                
                if ($mediaExists) {
                    $isAppMedia = true;
                }
            }
            
            // Ambil komentar dari tabel FOBi
            $comments = DB::table('fobi_checklist_fauna_imgs_comments as c')
                ->join('fobi_users as u', 'c.user_id', '=', 'u.id')
                ->select([
                    'c.id',
                    'c.media_id',
                    'c.comment',
                    'c.created_at',
                    'c.updated_at',
                    'u.uname as username'
                ])
                ->where('c.media_id', $mediaId)
                ->whereNull('c.deleted_at')
                ->orderBy('c.created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $comments
            ]);

        } catch (\Exception $e) {
            Log::error('Error in getMediaComments: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil komentar'
            ], 500);
        }
    }

    /**
     * Add a comment to a specific media
     */
    public function addMediaComment(Request $request, $mediaId)
    {
        try {
            $user = auth()->user();

            // Validasi request
            $request->validate([
                'comment' => 'required|string|max:1000'
            ]);

            // Deteksi apakah media ada di tabel FOBi
            $mediaExists = DB::table('fobi_checklist_fauna_imgs')
                ->where('id', $mediaId)
                ->exists();
            
            $isAppMedia = false;
            
            // Jika tidak ada di FOBi, maka kemungkinan dari aplikasi Burungnesia
            if (!$mediaExists) {
                $mediaExists = DB::connection('second')
                    ->table('checklist_fauna_imgs')
                    ->where('id', $mediaId)
                    ->exists();
                
                if (!$mediaExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Media tidak ditemukan'
                    ], 404);
                }
                
                $isAppMedia = true;
            }
            
            // Insert komentar ke tabel FOBi
            DB::table('fobi_checklist_fauna_imgs_comments')->insert([
                'media_id' => $mediaId,
                'user_id' => $user->id,
                'comment' => $request->comment,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Komentar berhasil ditambahkan'
            ]);

        } catch (\Exception $e) {
            Log::error('Error in addMediaComment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan komentar'
            ], 500);
        }
    }

    /**
     * Get rating for a specific media
     */
    public function getMediaRating($mediaId)
    {
        try {
            $userId = auth()->id();
            
            // Deteksi apakah media ada di tabel FOBi
            $mediaExists = DB::table('fobi_checklist_fauna_imgs')
                ->where('id', $mediaId)
                ->exists();
            
            $isAppMedia = false;
            
            // Jika tidak ada di FOBi, maka kemungkinan dari aplikasi Burungnesia
            if (!$mediaExists) {
                $mediaExists = DB::connection('second')
                    ->table('checklist_fauna_imgs')
                    ->where('id', $mediaId)
                    ->exists();
                
                if ($mediaExists) {
                    $isAppMedia = true;
                }
            }
            
            // Get average rating and count dari tabel FOBi
            $ratingStats = DB::table('fobi_checklist_fauna_imgs_ratings')
                ->select([
                    DB::raw('AVG(rating) as average_rating'),
                    DB::raw('COUNT(*) as total_ratings')
                ])
                ->where('media_id', $mediaId)
                ->whereNull('deleted_at')
                ->first();
            
            // Get user's rating if logged in
            $userRating = null;
            if ($userId) {
                $userRating = DB::table('fobi_checklist_fauna_imgs_ratings')
                    ->where([
                        'media_id' => $mediaId,
                        'user_id' => $userId
                    ])
                    ->whereNull('deleted_at')
                    ->value('rating');
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'average_rating' => $ratingStats->average_rating ?? 0,
                    'total_ratings' => $ratingStats->total_ratings ?? 0,
                    'user_rating' => $userRating,
                    'media_source' => $isAppMedia ? 'app' : 'fobi'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error in getMediaRating: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil rating'
            ], 500);
        }
    }

    /**
     * Rate a specific media
     */
    public function rateMedia(Request $request, $mediaId)
    {
        try {
            DB::beginTransaction();
            
            $user = auth()->user();

            // Validasi request
            $request->validate([
                'rating' => 'required|integer|min:0|max:5'
            ]);

            // Deteksi apakah media ada di tabel FOBi
            $mediaExists = DB::table('fobi_checklist_fauna_imgs')
                ->where('id', $mediaId)
                ->exists();
            
            $isAppMedia = false;
            
            // Jika tidak ada di FOBi, maka kemungkinan dari aplikasi Burungnesia
            if (!$mediaExists) {
                $mediaExists = DB::connection('second')
                    ->table('checklist_fauna_imgs')
                    ->where('id', $mediaId)
                    ->exists();
                
                if (!$mediaExists) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Media tidak ditemukan'
                    ], 404);
                }
                
                $isAppMedia = true;
            }
            
            // Cek apakah user sudah memberikan rating sebelumnya di tabel FOBi
            $existingRating = DB::table('fobi_checklist_fauna_imgs_ratings')
                ->where([
                    'media_id' => $mediaId,
                    'user_id' => $user->id
                ])
                ->first();

            if ($existingRating) {
                if ($request->rating === 0) {
                    // Hapus rating (soft delete)
                    DB::table('fobi_checklist_fauna_imgs_ratings')
                        ->where([
                            'media_id' => $mediaId,
                            'user_id' => $user->id
                        ])
                        ->update([
                            'deleted_at' => now()
                        ]);
                } else {
                    // Update rating
                    DB::table('fobi_checklist_fauna_imgs_ratings')
                        ->where([
                            'media_id' => $mediaId,
                            'user_id' => $user->id
                        ])
                        ->update([
                            'rating' => $request->rating,
                            'updated_at' => now(),
                            'deleted_at' => null // Jika sebelumnya dihapus
                        ]);
                }
            } else if ($request->rating > 0) {
                // Insert rating baru ke tabel FOBi
                DB::table('fobi_checklist_fauna_imgs_ratings')->insert([
                    'media_id' => $mediaId,
                    'user_id' => $user->id,
                    'rating' => $request->rating,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => $request->rating > 0 ? 'Rating berhasil diberikan' : 'Rating berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in rateMedia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memberikan rating'
            ], 500);
        }
    }

}
