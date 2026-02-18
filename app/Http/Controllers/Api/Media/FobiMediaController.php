<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FobiChecklistTaxa;
use App\Models\FobiChecklistMedia;
use App\Models\FobiChecklistBurungnesia;
use App\Models\FobiChecklistKupunesia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;

class FobiMediaController extends Controller
{
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
                        $publicPath = str_replace('public/', '', $path);
                        
                        // Deteksi tipe media
                        $extension = $file->getClientOriginalExtension();
                        $mediaType = in_array(strtolower($extension), ['mp4', 'mov', 'avi']) ? 'video' : 
                                    (in_array(strtolower($extension), ['mp3', 'wav']) ? 'audio' : 'photo');
                        
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
                
                // Log operasi berhasil
                Log::info("Media berhasil ditambahkan ke observasi ID {$id}", [
                    'user_id' => $userId,
                    'observation_id' => $id,
                    'media_count' => count($request->file('new_media'))
                ]);
                
                return response()->json([
                    'success' => true,
                    'data' => $observation->fresh(['medias']),
                    'message' => 'Media berhasil ditambahkan'
                ]);
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding media: ' . $e->getMessage(), [
                'observation_id' => $id,
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);
            
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
                            Log::info("Media terkait observasi ID {$id} berhasil dihapus");
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
            Log::error('Error deleting media: ' . $e->getMessage(), [
                'observation_id' => $id,
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus media: ' . $e->getMessage()
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
} 
