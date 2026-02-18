<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocationLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LocationLabelController extends Controller
{
    /**
     * Cari location labels berdasarkan query string.
     * Mengembalikan hasil dari database yang cocok.
     */
    public function search(Request $request)
    {
        try {
            $query = $request->input('q', '');
            $lat = $request->input('lat');
            $lng = $request->input('lng');

            $results = LocationLabel::query();

            if (!empty($query)) {
                $results->where('label', 'LIKE', '%' . $query . '%');
            }

            // Jika ada koordinat, prioritaskan lokasi terdekat
            if ($lat && $lng) {
                $results->orderByRaw(
                    "ABS(latitude - ?) + ABS(longitude - ?) ASC",
                    [(float)$lat, (float)$lng]
                );
            }

            $results = $results
                ->orderBy('use_count', 'desc')
                ->orderBy('updated_at', 'desc')
                ->limit(15)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('Error searching location labels: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencari lokasi'
            ], 500);
        }
    }

    /**
     * Simpan atau update location label.
     * Jika lokasi dengan koordinat serupa sudah ada, update use_count.
     * Jika label berbeda dari OSM, simpan sebagai entry baru.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'label' => 'required|string|max:500',
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
                'osm_name' => 'nullable|string|max:500',
            ]);

            $userId = null;
            try {
                $userId = \Tymon\JWTAuth\Facades\JWTAuth::parseToken()->authenticate()->id;
            } catch (\Exception $e) {
                // User tidak terautentikasi, lanjut tanpa user_id
            }

            // Cek apakah label yang sama sudah ada di koordinat yang dekat (~100m)
            $existing = LocationLabel::where('label', $request->label)
                ->whereRaw('ABS(latitude - ?) < 0.001 AND ABS(longitude - ?) < 0.001', [
                    $request->latitude,
                    $request->longitude
                ])
                ->first();

            if ($existing) {
                // Update use_count dan timestamp
                $existing->increment('use_count');
                $existing->touch();

                return response()->json([
                    'success' => true,
                    'data' => $existing,
                    'message' => 'Lokasi sudah ada, use_count diperbarui'
                ]);
            }

            // Buat entry baru
            $locationLabel = LocationLabel::create([
                'label' => $request->label,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'osm_name' => $request->osm_name,
                'created_by' => $userId,
                'use_count' => 1,
            ]);

            return response()->json([
                'success' => true,
                'data' => $locationLabel,
                'message' => 'Lokasi berhasil disimpan'
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error storing location label: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan lokasi'
            ], 500);
        }
    }
}
