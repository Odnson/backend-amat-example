<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * Service untuk menangani operasi lokasi
 * Termasuk reverse geocoding menggunakan Nominatim
 */
class LocationService
{
    /**
     * Mendapatkan nama lokasi dari koordinat latitude/longitude
     * menggunakan Nominatim OpenStreetMap API
     *
     * @param float|null $latitude
     * @param float|null $longitude
     * @return string
     */
    public function getLocationName(?float $latitude, ?float $longitude): string
    {
        try {
            if (!$latitude || !$longitude) {
                return 'Unknown Location';
            }

            $response = Http::withHeaders([
                'User-Agent' => 'FOBi Application'
            ])->get("https://nominatim.openstreetmap.org/reverse", [
                'format' => 'json',
                'lat' => $latitude,
                'lon' => $longitude,
                'addressdetails' => 1
            ]);

            if (!$response->successful()) {
                return 'Unknown Location';
            }

            $data = $response->json();

            if (isset($data['address'])) {
                return $this->formatAddress($data['address']);
            }

            return 'Unknown Location';

        } catch (\Exception $e) {
            Log::error('Error getting location name:', [
                'error' => $e->getMessage(),
                'latitude' => $latitude,
                'longitude' => $longitude
            ]);
            return 'Unknown Location';
        }
    }

    /**
     * Format alamat dari response Nominatim
     *
     * @param array $address
     * @return string
     */
    private function formatAddress(array $address): string
    {
        $parts = [];

        // City/Town/Municipality
        $city = $address['city'] ?? $address['town'] ?? $address['municipality'] ?? null;
        if ($city) {
            $parts[] = $city;
        }

        // County/Regency
        $county = $address['county'] ?? $address['regency'] ?? null;
        if ($county) {
            $parts[] = $county;
        }

        // State (Province)
        if (isset($address['state'])) {
            $parts[] = $address['state'];
        }

        // Country
        if (isset($address['country'])) {
            $parts[] = $address['country'];
        }

        return !empty($parts) ? implode(', ', $parts) : 'Unknown Location';
    }

    /**
     * Validasi koordinat
     *
     * @param float|null $latitude
     * @param float|null $longitude
     * @return bool
     */
    public function validateCoordinates(?float $latitude, ?float $longitude): bool
    {
        if ($latitude === null || $longitude === null) {
            return false;
        }

        return $latitude >= -90 && $latitude <= 90 
            && $longitude >= -180 && $longitude <= 180;
    }
}
