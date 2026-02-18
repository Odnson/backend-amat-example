<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KupunesiaChecklistController extends Controller
{
    public function update(Request $request, $id)
    {
        try {
            // Cek apakah user bisa memodifikasi checklist
            if (!$this->canModifyChecklist(auth()->user(), $id, 'kupunesia')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mengubah checklist ini'
                ], 403);
            }

            DB::beginTransaction();

            Log::info('Kupunesia update request:', [
                'id' => $id,
                'data' => $request->all()
            ]);

            // Cek di database third
            $checklist = DB::connection('third')
                ->table('checklists')
                ->where('id', $id)
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

            // Update checklist di database third
            DB::connection('third')
                ->table('checklists')
                ->where('id', $id)
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
                DB::connection('third')
                    ->table('checklist_fauna')
                    ->where([
                        'checklist_id' => $id,
                        'fauna_id' => $fauna['id']
                    ])
                    ->update([
                        'count' => $fauna['count'] ?? 0,
                        'notes' => $fauna['notes'] ?? '',
                        'breeding' => $fauna['breeding'] ?? false,
                        'breeding_note' => $fauna['breeding_note'] ?? '',
                        'breeding_type_id' => $fauna['breeding_type_id'] ?? null,
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
            Log::error('Error in Kupunesia update:', [
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
            // Hapus 'KP' prefix jika ada
            $actualId = str_starts_with($id, 'KP') ? substr($id, 2) : $id;

            Log::info('Kupunesia detail request:', [
                'id' => $id,
                'actualId' => $actualId
            ]);

            // Cek di database third
            $checklist = DB::connection('third')
                ->table('checklists as fc')
                ->join('users as fu', 'fc.user_id', '=', 'fu.id')
                ->select([
                    DB::raw("CONCAT('KP', fc.id) as id"),
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

            // Ambil breeding types
            $breedingTypes = DB::connection('third')
                ->table('breeding_type')
                ->select(['id', 'type as name'])
                ->whereNull('deleted_at')
                ->pluck('name', 'id');

            // Ambil fauna dari checklist_fauna Kupunesia
            $faunaRaw = DB::connection('third')
                ->table('checklist_fauna as fcf')
                ->select([
                    'fcf.checklist_id',
                    'fcf.fauna_id',
                    'fcf.count as jumlah',
                    'fcf.notes as catatan',
                    'fcf.breeding',
                    'fcf.breeding_note',
                    'fcf.breeding_type_id',
                    'fcf.nama_spesies',
                    'fcf.nama_latin'
                ])
                ->where('fcf.checklist_id', $actualId)
                ->whereNull('fcf.deleted_at')
                ->get();

            // Untuk setiap fauna, coba ambil data lengkap
            // PENTING: Ada 2 skenario untuk fauna_id:
            // 1. Data lama: fauna_id merujuk ke tabel faunas Kupunesia
            // 2. Data baru (dari suggestion API Amaturalist): fauna_id adalah taxa.id dari Amaturalist
            // 
            // STRATEGI: Prioritaskan faunas Kupunesia dulu (karena data lama lebih banyak)
            // Jika tidak ketemu di faunas, baru cari di taxas Amaturalist
            $fauna = $faunaRaw->map(function($item) use ($breedingTypes) {
                $item->breeding_type_name = $item->breeding_type_id ?
                    $breedingTypes->get($item->breeding_type_id) : null;
                
                $namaLokal = '';
                $namaIlmiah = '';
                $family = '';
                $genus = '';
                $species = '';
                $taxonRank = 'SPECIES';
                $found = false;
                
                // SKENARIO 1: Coba cari di faunas Kupunesia DULU (untuk data lama - mayoritas data)
                $faunaKupunesia = DB::connection('third')
                    ->table('faunas')
                    ->select(['nameId', 'nameLat', 'family'])
                    ->where('id', $item->fauna_id)
                    ->first();
                
                if ($faunaKupunesia) {
                    $namaLokal = $faunaKupunesia->nameId ?? '';
                    $namaIlmiah = $faunaKupunesia->nameLat ?? '';
                    $family = $faunaKupunesia->family ?? '';
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
                        
                        // Jika exact match tidak ketemu, coba LIKE
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
                            $taxonRank = $taxaByName->taxon_rank ?? 'SPECIES';
                        }
                    }
                }
                
                // SKENARIO 2: Jika TIDAK ketemu di faunas Kupunesia, coba cari di taxas Amaturalist
                // (untuk data baru dari suggestion API)
                if (!$found) {
                    $taxa = DB::table('taxas')
                        ->select([
                            'scientific_name',
                            'cname_species',
                            'Cname',
                            'family',
                            'genus',
                            'species',
                            'taxon_rank'
                        ])
                        ->where('id', $item->fauna_id)
                        ->first();
                    
                    if ($taxa) {
                        $namaLokal = $taxa->cname_species ?? $taxa->Cname ?? '';
                        $namaIlmiah = $taxa->scientific_name ?? '';
                        $family = $taxa->family ?? '';
                        $genus = $taxa->genus ?? '';
                        $species = $taxa->species ?? '';
                        $taxonRank = $taxa->taxon_rank ?? 'SPECIES';
                        $found = true;
                    }
                }
                
                // SKENARIO 3: Fallback ke data yang tersimpan di checklist_fauna
                if (empty($namaLokal) && empty($namaIlmiah)) {
                    $namaLokal = $item->nama_spesies ?? '';
                    $namaIlmiah = $item->nama_latin ?? '';
                }
                
                $item->nama_lokal = $namaLokal;
                $item->nama_ilmiah = $namaIlmiah;
                $item->family = $family;
                $item->genus = $genus;
                $item->species = $species;
                $item->taxon_rank = $taxonRank;
                
                return $item;
            });

            // Ambil images
            $images = DB::connection('third')
                ->table('checklist_fauna_imgs')
                ->select([
                    'id',
                    'images as url',
                    'checklist_id',
                    'fauna_id'
                ])
                ->where('checklist_id', $actualId)
                ->whereNull('deleted_at')
                ->get();

            // Hitung total observasi
            $totalObservations = DB::connection('third')
                ->table('checklists')
                ->where('user_id', $checklist->fobi_user_id)
                ->whereNull('deleted_at')
                ->count();

            // Tambahkan informasi canEdit ke response
            $canEdit = $this->canModifyChecklist(auth()->user(), $actualId, 'kupunesia');

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
                            'checklist_id' => 'KP' . $f->checklist_id,
                            'nama_lokal' => $f->nama_lokal,
                            'nama_ilmiah' => $f->nama_ilmiah,
                            'family' => $f->family ?? '',
                            'genus' => $f->genus ?? '',
                            'species' => $f->species ?? '',
                            'taxon_rank' => $f->taxon_rank ?? 'SPECIES',
                            'jumlah' => $f->jumlah,
                            'catatan' => $f->catatan,
                            'breeding' => $f->breeding,
                            'breeding_note' => $f->breeding_note,
                            'breeding_type_name' => $f->breeding_type_name
                        ];
                    }),
                    'media' => [
                        'images' => $images->map(function($img) {
                            // Buat full URL untuk images dari Kupunesia storage
                            $imageUrl = '';
                            if (!empty($img->url)) {
                                // URL ke Kupunesia storage
                                $imageUrl = config('app.kupunesia_url', 'https://kupunesia.id') . '/storage/images_burung/' . $img->url;
                            }
                            return [
                                'id' => $img->id,
                                'url' => $imageUrl,
                                'filename' => $img->url,
                                'checklist_id' => $img->checklist_id,
                                'fauna_id' => $img->fauna_id
                            ];
                        }),
                        'sounds' => []
                    ]
                ]
            ];

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Error in getDetail Kupunesia:', [
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

        $table = $source === 'kupunesia' ? 'fobi_checklists_kupnes' : 'fobi_checklists';

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
            $mediaExists = DB::table('fobi_checklist_fauna_imgs_kupnes')
                ->where('id', $mediaId)
                ->exists();
            
            $isAppMedia = false;
            
            // Jika tidak ada di FOBi, maka kemungkinan dari aplikasi Kupunesia
            if (!$mediaExists) {
                $mediaExists = DB::connection('third')
                    ->table('checklist_fauna_imgs')
                    ->where('id', $mediaId)
                    ->exists();
                
                if ($mediaExists) {
                    $isAppMedia = true;
                }
            }
            
            // Ambil komentar dari tabel FOBi
            $comments = DB::table('fobi_checklist_fauna_imgs_kupnes_comments as c')
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
            $mediaExists = DB::table('fobi_checklist_fauna_imgs_kupnes')
                ->where('id', $mediaId)
                ->exists();
            
            $isAppMedia = false;
            
            // Jika tidak ada di FOBi, maka kemungkinan dari aplikasi Kupunesia
            if (!$mediaExists) {
                $mediaExists = DB::connection('third')
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
            DB::table('fobi_checklist_fauna_imgs_kupnes_comments')->insert([
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
            $mediaExists = DB::table('fobi_checklist_fauna_imgs_kupnes')
                ->where('id', $mediaId)
                ->exists();
            
            $isAppMedia = false;
            
            // Jika tidak ada di FOBi, maka kemungkinan dari aplikasi Kupunesia
            if (!$mediaExists) {
                $mediaExists = DB::connection('third')
                    ->table('checklist_fauna_imgs')
                    ->where('id', $mediaId)
                    ->exists();
                
                if ($mediaExists) {
                    $isAppMedia = true;
                }
            }
            
            // Get average rating and count dari tabel FOBi
            $ratingStats = DB::table('fobi_checklist_fauna_imgs_kupnes_ratings')
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
                $userRating = DB::table('fobi_checklist_fauna_imgs_kupnes_ratings')
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
            $mediaExists = DB::table('fobi_checklist_fauna_imgs_kupnes')
                ->where('id', $mediaId)
                ->exists();
            
            $isAppMedia = false;
            
            // Jika tidak ada di FOBi, maka kemungkinan dari aplikasi Kupunesia
            if (!$mediaExists) {
                $mediaExists = DB::connection('third')
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
            $existingRating = DB::table('fobi_checklist_fauna_imgs_kupnes_ratings')
                ->where([
                    'media_id' => $mediaId,
                    'user_id' => $user->id
                ])
                ->first();

            if ($existingRating) {
                if ($request->rating === 0) {
                    // Hapus rating (soft delete)
                    DB::table('fobi_checklist_fauna_imgs_kupnes_ratings')
                        ->where([
                            'media_id' => $mediaId,
                            'user_id' => $user->id
                        ])
                        ->update([
                            'deleted_at' => now()
                        ]);
                } else {
                    // Update rating
                    DB::table('fobi_checklist_fauna_imgs_kupnes_ratings')
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
                DB::table('fobi_checklist_fauna_imgs_kupnes_ratings')->insert([
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
