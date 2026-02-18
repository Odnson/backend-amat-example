<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\BurungnesiaIdentification;
use App\Traits\QualityAssessmentTrait;
use Intervention\Image\ImageManagerStatic as Image;
use App\Http\Controllers\Api\ChecklistQualityAssessmentController;

class BurungnesiaObservationApiController extends Controller
{
    use QualityAssessmentTrait;

    protected $qualityAssessmentController;

    public function __construct(ChecklistQualityAssessmentController $qualityAssessmentController)
    {
        $this->qualityAssessmentController = $qualityAssessmentController;
    }

    /**
     * Process dan simpan image ke folder Burungnesia storage (images_burung)
     * Agar konsisten dengan upload dari Kotlin app
     */
    private function processImageFile($file)
    {
        try {
            // Path ke folder Burungnesia storage
            $burungnesiaStoragePath = base_path('../burungnesia/storage/app/public/images_burung/');
            
            // Pastikan folder exists
            if (!file_exists($burungnesiaStoragePath)) {
                mkdir($burungnesiaStoragePath, 0755, true);
                Log::info('Created Burungnesia images directory', ['path' => $burungnesiaStoragePath]);
            }
            
            // Generate nama file dengan ekstensi WebP
            $filename = 'checklist_fauna_' . uniqid() . '_' . time() . '.webp';
            $fullPath = $burungnesiaStoragePath . $filename;
            
            // Buat image dari file upload
            $image = Image::make($file->getRealPath());
            
            // Resize jika terlalu besar (max 1000px)
            $maxDimension = 1000;
            $width = $image->width();
            $height = $image->height();
            
            if ($height > $width && $height > $maxDimension) {
                $image->resize(null, $maxDimension, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            } else if ($width > $maxDimension) {
                $image->resize($maxDimension, null, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }
            
            // Orientasi EXIF
            $image->orientate();
            
            // Encode ke WebP dengan kualitas 80%
            $image->encode('webp', 80)->save($fullPath);
            
            Log::info('Image saved to Burungnesia storage as WebP', [
                'path' => $fullPath,
                'filename' => $filename
            ]);
            
            return [
                'success' => true,
                'filename' => $filename,
                'path' => $fullPath
            ];
            
        } catch (\Exception $e) {
            Log::error('Error processing image for Burungnesia: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Store checklist dan fauna untuk Burungnesia
     * Upload via web amaturalist, simpan langsung ke database Burungnesia
     */
    public function storeChecklistAndFauna(Request $request)
    {
        try {
            $request->validate([
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
                'tujuan_pengamatan' => 'required|integer',
                'fauna_id' => 'required|array',
                'fauna_id.*' => 'required|integer',
                'count' => 'required|array',
                'count.*' => 'required|integer',
                'notes.*' => 'nullable|string',
                'breeding.*' => 'nullable|integer',
                'breeding_type_id.*' => 'nullable|array',
                'breeding_type_id.*.*' => 'nullable|integer',
                'breeding_note.*' => 'nullable|string',
                'observer' => 'nullable|string',
                'completed' => 'nullable|integer',
                'start_time' => 'nullable',
                'end_time' => 'nullable',
                'active' => 'nullable|integer',
                'additional_note' => 'nullable|string',
                'tgl_pengamatan' => 'nullable|date',
                'label' => 'nullable|string',
                'habitat' => 'nullable|string',
                'images.*.*' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp|max:10048',
            ]);

            Log::info('Request data burung dengan breeding:', $request->all());

            $userId = JWTAuth::parseToken()->authenticate()->id;
            $fobiUser = DB::table('fobi_users')->where('id', $userId)->first();

            if (!$fobiUser) {
                return response()->json(['error' => 'User tidak ditemukan.'], 404);
            }

            $burungnesiaUserId = $fobiUser->burungnesia_user_id;
            $checklistId = null;
            
            // Validasi burungnesiaUserId harus ada untuk sinkronisasi ke Burungnesia
            if (!$burungnesiaUserId) {
                return response()->json([
                    'error' => 'Akun Burungnesia belum tertaut. Silakan tautkan akun Burungnesia Anda terlebih dahulu di halaman Sinkronisasi Akun.',
                    'code' => 'BURUNGNESIA_NOT_LINKED'
                ], 400);
            }
            
            Log::info('Burungnesia checklist upload started', [
                'fobi_user_id' => $userId,
                'burungnesia_user_id' => $burungnesiaUserId
            ]);

            DB::transaction(function () use ($request, $userId, $burungnesiaUserId, &$checklistId) {
                // Simpan fauna data
                $faunaIds = is_array($request->fauna_id) ? $request->fauna_id : [$request->fauna_id];
                $counts = is_array($request->count) ? $request->count : [$request->count];
                $notes = is_array($request->notes) ? $request->notes : [$request->notes];
                $breedings = is_array($request->breeding) ? $request->breeding : [$request->breeding];
                $breedingNotes = is_array($request->breeding_note) ? $request->breeding_note : [$request->breeding_note];
                $breedingTypeIds = is_array($request->breeding_type_id) ? $request->breeding_type_id : [$request->breeding_type_id];

                // Simpan langsung ke database Burungnesia (connection second)
                $checklistId = DB::connection('second')->table('checklists')->insertGetId([
                    'user_id' => $burungnesiaUserId,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'tujuan_pengamatan' => $request->tujuan_pengamatan,
                    'observer' => $request->observer,
                    'additional_note' => $request->additional_note,
                    'active' => 1, // Pastikan active = 1 agar tampil
                    'can_edit' => 0,
                    'tgl_pengamatan' => $request->tgl_pengamatan,
                    'start_time' => $request->start_time,
                    'end_time' => $request->end_time,
                    'completed' => $request->completed ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                Log::info('Burungnesia checklist saved', [
                    'burungnesia_checklist_id' => $checklistId,
                    'burungnesia_user_id' => $burungnesiaUserId
                ]);
                
                // Simpan checklisttr (lokasi & habitat) ke Burungnesia
                DB::connection('second')->table('checklisttr')->insert([
                    'checklist_id' => $checklistId,
                    'lang_code' => 'in_ID',
                    'label' => $request->label ?? '',
                    'habitat' => $request->habitat ?? '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($faunaIds as $index => $faunaId) {
                    // Jika breeding = 0, pastikan breeding_type_id = null
                    $breedingTypeId = isset($breedings[$index]) && $breedings[$index] == 1
                        ? (isset($breedingTypeIds[$index]) ? json_encode($breedingTypeIds[$index]) : '[]') 
                        : '[]';
                    
                    $namaSpesies = null;
                    $namaLatin = null;
                    
                    // PENTING: Untuk upload dari web Amaturalist, fauna_id adalah taxa.id dari Amaturalist
                    // Jadi PRIORITASKAN pencarian di taxas Amaturalist DULU, bukan di faunas Burungnesia
                    // Ini untuk menghindari ID bentrok (misal: Gallus di taxas ID 12345 vs Borniella di faunas ID 12345)
                    
                    // SKENARIO 1: Cari di taxas Amaturalist DULU (untuk data baru dari web upload)
                    $taxa = DB::table('taxas')
                        ->select('scientific_name', 'cname_species', 'Cname', 'cname_genus', 'cname_family', 'genus', 'family', 'taxon_rank')
                        ->where('id', $faunaId)
                        ->first();
                    
                    if ($taxa) {
                        // Gunakan scientific_name untuk nama_latin (termasuk genus, family, dll)
                        $namaLatin = $taxa->scientific_name ?? $taxa->genus ?? $taxa->family ?? null;
                        
                        // Tentukan nama_spesies berdasarkan taxon_rank
                        $taxonRank = strtoupper($taxa->taxon_rank ?? 'SPECIES');
                        switch ($taxonRank) {
                            case 'SPECIES':
                            case 'SUBSPECIES':
                                $namaSpesies = $taxa->cname_species ?? $taxa->Cname ?? null;
                                break;
                            case 'GENUS':
                                $namaSpesies = $taxa->cname_genus ?? $taxa->Cname ?? null;
                                break;
                            case 'FAMILY':
                                $namaSpesies = $taxa->cname_family ?? $taxa->Cname ?? null;
                                break;
                            default:
                                $namaSpesies = $taxa->cname_species ?? $taxa->Cname ?? $taxa->cname_genus ?? $taxa->cname_family ?? null;
                        }
                        
                        Log::info('Taxa found from Amaturalist taxas table', [
                            'fauna_id' => $faunaId,
                            'scientific_name' => $taxa->scientific_name,
                            'taxon_rank' => $taxonRank,
                            'nama_latin' => $namaLatin,
                            'nama_spesies' => $namaSpesies
                        ]);
                    }
                    
                    // SKENARIO 2: Jika tidak ditemukan di taxas, coba cari di faunas Burungnesia (untuk data lama)
                    if (empty($namaLatin)) {
                        $fauna = DB::connection('second')->table('faunas')
                            ->select('nameId', 'nameLat')
                            ->where('id', $faunaId)
                            ->first();
                        
                        if ($fauna && !empty($fauna->nameLat)) {
                            $namaSpesies = $fauna->nameId ?? null;
                            $namaLatin = $fauna->nameLat ?? null;
                            
                            Log::info('Fauna found from Burungnesia faunas table', [
                                'fauna_id' => $faunaId,
                                'nama_latin' => $namaLatin,
                                'nama_spesies' => $namaSpesies
                            ]);
                        }
                    }
                        
                    DB::connection('second')->table('checklist_fauna')->insert([
                        'checklist_id' => $checklistId,
                        'fauna_id' => $faunaId,
                        'nama_spesies' => $namaSpesies,
                        'nama_latin' => $namaLatin,
                        'count' => $counts[$index] ?? 0,
                        'notes' => $notes[$index] ?? '',
                        'breeding' => $breedings[$index] ?? 0,
                        'breeding_type_id' => $breedingTypeId,
                        'breeding_note' => isset($breedingNotes[$index]) ? $breedingNotes[$index] : '',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    
                    // Simpan gambar ke database Burungnesia
                    if ($request->hasFile("images.$index")) {
                        foreach ($request->file("images.$index") as $imageFile) {
                            $result = $this->processImageFile($imageFile);
                            
                            if ($result['success']) {
                                DB::connection('second')->table('checklist_fauna_imgs')->insert([
                                    'checklist_id' => $checklistId,
                                    'fauna_id' => $faunaId,
                                    'images' => $result['filename'],
                                    'status' => 0,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diunggah ke Burungnesia!',
                'checklist_id' => $checklistId
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error: ' . json_encode($e->errors()));
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error uploading data: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Terjadi kesalahan saat mengunggah data: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Add community identification untuk Burungnesia
     */
    public function addIdentification(Request $request, $id)
    {
        try {
            Log::info('Burungnesia Identification request data:', $request->all());

            $validated = $request->validate([
                'taxon_id' => 'required|integer|exists:taxas,id',
                'identification_level' => 'required|string|in:species,genus,family,order,class,phylum,kingdom',
                'comment' => 'nullable|string|max:1000',
                'photo' => 'nullable|image|max:5120'
            ]);

            DB::beginTransaction();

            try {
                // Handle file upload jika ada
                $photoPath = null;
                if ($request->hasFile('photo')) {
                    $photoPath = $request->file('photo')->store('identification-photos', 'public');
                }

                $identification = BurungnesiaIdentification::create([
                    'observation_id' => $id,
                    'observation_type' => 'burungnesia',
                    'user_id' => auth()->id(),
                    'taxon_id' => $validated['taxon_id'],
                    'identification_level' => $validated['identification_level'],
                    'comment' => $validated['comment'] ?? null,
                    'photo_path' => $photoPath,
                ]);
                
                // Cek dan buat persetujuan implisit jika ada identifikasi taksa yang sama sebelumnya
                $this->qualityAssessmentController->createImplicitAgreements(
                    $id,
                    $identification->id,
                    $validated['taxon_id'],
                    auth()->id()
                );

                // Update quality assessment
                $this->qualityAssessmentController->updateQualityAssessment($id, 'burungnesia');

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Identifikasi berhasil ditambahkan',
                    'data' => $identification
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Database error while adding identification: ' . $e->getMessage());
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error: ' . json_encode($e->errors()));
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error adding identification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan identifikasi'
            ], 500);
        }
    }

    /**
     * Agree with an identification
     */
    public function agreeWithIdentification(Request $request, $checklistId, $identificationId)
    {
        try {
            $userId = auth()->id();
            
            // Cek apakah identifikasi ada
            $identification = BurungnesiaIdentification::find($identificationId);
            if (!$identification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifikasi tidak ditemukan'
                ], 404);
            }

            // Cek apakah user sudah setuju sebelumnya
            $existingAgreement = DB::table('burungnesia_identification_agreements')
                ->where('identification_id', $identificationId)
                ->where('user_id', $userId)
                ->first();

            if ($existingAgreement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda sudah menyetujui identifikasi ini'
                ], 400);
            }

            DB::table('burungnesia_identification_agreements')->insert([
                'identification_id' => $identificationId,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Update quality assessment
            $this->qualityAssessmentController->updateQualityAssessment($checklistId, 'burungnesia');

            return response()->json([
                'success' => true,
                'message' => 'Berhasil menyetujui identifikasi'
            ]);

        } catch (\Exception $e) {
            Log::error('Error agreeing with identification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan'
            ], 500);
        }
    }

    /**
     * Disagree with an identification
     */
    public function disagreeWithIdentification(Request $request, $checklistId, $identificationId)
    {
        try {
            $userId = auth()->id();
            
            $request->validate([
                'reason' => 'nullable|string|max:500'
            ]);

            // Cek apakah identifikasi ada
            $identification = BurungnesiaIdentification::find($identificationId);
            if (!$identification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifikasi tidak ditemukan'
                ], 404);
            }

            // Cek apakah user sudah tidak setuju sebelumnya
            $existingDisagreement = DB::table('burungnesia_identification_disagreements')
                ->where('identification_id', $identificationId)
                ->where('user_id', $userId)
                ->first();

            if ($existingDisagreement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda sudah tidak menyetujui identifikasi ini'
                ], 400);
            }

            DB::table('burungnesia_identification_disagreements')->insert([
                'identification_id' => $identificationId,
                'user_id' => $userId,
                'reason' => $request->reason,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Update quality assessment
            $this->qualityAssessmentController->updateQualityAssessment($checklistId, 'burungnesia');

            return response()->json([
                'success' => true,
                'message' => 'Berhasil menolak identifikasi'
            ]);

        } catch (\Exception $e) {
            Log::error('Error disagreeing with identification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan'
            ], 500);
        }
    }

    /**
     * Withdraw an identification
     */
    public function withdrawIdentification(Request $request, $checklistId, $identificationId)
    {
        try {
            $userId = auth()->id();
            
            // Cek apakah identifikasi ada dan milik user
            $identification = BurungnesiaIdentification::where('id', $identificationId)
                ->where('user_id', $userId)
                ->first();

            if (!$identification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifikasi tidak ditemukan atau bukan milik Anda'
                ], 404);
            }

            // Soft delete identification
            $identification->delete();

            // Update quality assessment
            $this->qualityAssessmentController->updateQualityAssessment($checklistId, 'burungnesia');

            return response()->json([
                'success' => true,
                'message' => 'Identifikasi berhasil ditarik'
            ]);

        } catch (\Exception $e) {
            Log::error('Error withdrawing identification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan'
            ], 500);
        }
    }

    /**
     * Get observations list for Burungnesia
     */
    public function getObservations(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);
            $search = $request->get('search', '');
            $userId = $request->get('user_id');
            
            $query = DB::connection('second')
                ->table('checklists as c')
                ->join('users as u', 'c.user_id', '=', 'u.id')
                ->leftJoin('checklist_fauna as cf', 'c.id', '=', 'cf.checklist_id')
                ->leftJoin('faunas as f', 'cf.fauna_id', '=', 'f.id')
                ->select([
                    'c.id',
                    'c.latitude',
                    'c.longitude',
                    'c.tgl_pengamatan',
                    'c.observer',
                    'c.additional_note',
                    'c.created_at',
                    'u.uname as username',
                    DB::raw('COUNT(DISTINCT cf.fauna_id) as species_count'),
                    DB::raw('GROUP_CONCAT(DISTINCT f.nameId SEPARATOR ", ") as species_names')
                ])
                ->where('c.active', 1)
                ->whereNull('c.deleted_at')
                ->groupBy('c.id', 'c.latitude', 'c.longitude', 'c.tgl_pengamatan', 'c.observer', 'c.additional_note', 'c.created_at', 'u.uname');

            if ($userId) {
                $query->where('c.user_id', $userId);
            }

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('f.nameId', 'like', "%{$search}%")
                      ->orWhere('f.nameLat', 'like', "%{$search}%")
                      ->orWhere('c.observer', 'like', "%{$search}%");
                });
            }

            $observations = $query->orderBy('c.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => $observations
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting Burungnesia observations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan'
            ], 500);
        }
    }

    /**
     * Get observation detail
     */
    public function getObservationDetail($id)
    {
        try {
            // Hapus 'BN' prefix jika ada
            $actualId = str_starts_with($id, 'BN') ? substr($id, 2) : $id;

            $checklist = DB::connection('second')
                ->table('checklists as c')
                ->join('users as u', 'c.user_id', '=', 'u.id')
                ->select([
                    'c.*',
                    'u.uname as username',
                    'u.email'
                ])
                ->where('c.id', $actualId)
                ->first();

            if (!$checklist) {
                return response()->json([
                    'success' => false,
                    'message' => 'Observasi tidak ditemukan'
                ], 404);
            }

            // Get fauna
            $fauna = DB::connection('second')
                ->table('checklist_fauna as cf')
                ->leftJoin('faunas as f', 'cf.fauna_id', '=', 'f.id')
                ->select([
                    'cf.*',
                    'f.nameId as nama_lokal',
                    'f.nameLat as nama_ilmiah',
                    'f.family'
                ])
                ->where('cf.checklist_id', $actualId)
                ->whereNull('cf.deleted_at')
                ->get();

            // Get images
            $images = DB::connection('second')
                ->table('checklist_fauna_imgs')
                ->where('checklist_id', $actualId)
                ->whereNull('deleted_at')
                ->get();

            // Get identifications
            $identifications = BurungnesiaIdentification::with(['user', 'taxon'])
                ->where('observation_id', $actualId)
                ->where('observation_type', 'burungnesia')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'checklist' => $checklist,
                    'fauna' => $fauna,
                    'images' => $images,
                    'identifications' => $identifications
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting observation detail: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan'
            ], 500);
        }
    }
}
