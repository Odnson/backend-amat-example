<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManagerStatic as Image;

/**
 * Service untuk menangani upload dan processing media (gambar, audio)
 */
class MediaService
{
    /**
     * Process dan upload gambar
     *
     * @param UploadedFile $file
     * @param string $folder
     * @return array
     */
    public function processImage(UploadedFile $file, string $folder = 'observations'): array
    {
        try {
            $filename = uniqid('img_') . '.' . $file->getClientOriginalExtension();
            $path = "{$folder}/{$filename}";

            // Resize gambar jika terlalu besar
            $image = Image::make($file);
            
            if ($image->width() > 2048 || $image->height() > 2048) {
                $image->resize(2048, 2048, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }

            // Simpan ke storage
            $stored = Storage::disk('public')->put($path, (string) $image->encode());

            if (!$stored) {
                return [
                    'success' => false,
                    'error' => 'Gagal menyimpan gambar'
                ];
            }

            return [
                'success' => true,
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'storage_type' => 'local'
            ];

        } catch (\Exception $e) {
            Log::error('Error processing image:', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process dan upload audio
     *
     * @param UploadedFile $file
     * @param string $folder
     * @return array
     */
    public function processAudio(UploadedFile $file, string $folder = 'sounds'): array
    {
        try {
            $extension = strtolower($file->getClientOriginalExtension());
            $filename = uniqid('audio_') . '.' . $extension;
            $path = "{$folder}/{$filename}";

            // Validasi format audio
            $allowedExtensions = ['mp3', 'wav', 'ogg', 'aac', 'm4a'];
            if (!in_array($extension, $allowedExtensions)) {
                return [
                    'success' => false,
                    'error' => 'Format audio tidak didukung'
                ];
            }

            // Simpan ke storage
            $stored = Storage::disk('public')->putFileAs($folder, $file, $filename);

            if (!$stored) {
                return [
                    'success' => false,
                    'error' => 'Gagal menyimpan audio'
                ];
            }

            return [
                'success' => true,
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'storage_type' => 'local'
            ];

        } catch (\Exception $e) {
            Log::error('Error processing audio:', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Hapus media dari storage
     *
     * @param string $path
     * @param string $disk
     * @return bool
     */
    public function deleteMedia(string $path, string $disk = 'public'): bool
    {
        try {
            return Storage::disk($disk)->delete($path);
        } catch (\Exception $e) {
            Log::error('Error deleting media:', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Validasi file adalah gambar
     *
     * @param UploadedFile $file
     * @return bool
     */
    public function isValidImage(UploadedFile $file): bool
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        return in_array($file->getMimeType(), $allowedMimes);
    }

    /**
     * Validasi file adalah audio
     *
     * @param UploadedFile $file
     * @return bool
     */
    public function isValidAudio(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['mp3', 'wav', 'ogg', 'aac', 'm4a'];
        
        return in_array($extension, $allowedExtensions) 
            || strpos($file->getMimeType(), 'audio') !== false;
    }

    /**
     * Get URL media berdasarkan storage type
     *
     * @param string $path
     * @param string $storageType
     * @return string
     */
    public function getMediaUrl(string $path, string $storageType = 'local'): string
    {
        if ($storageType === 's3') {
            return Storage::disk('s3')->url($path);
        }

        return Storage::disk('public')->url($path);
    }
}
