<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthMediaController extends Controller
{
    /**
     * Get active auth media by type
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getByType(Request $request)
    {
        try {
            $type = $request->query('type', 'login'); // default to login
            
            // Validate type
            if (!in_array($type, ['login', 'register'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid media type. Must be "login" or "register".',
                ], 400);
            }

            // Get active media for the specified type
            $media = AuthMedia::active()
                             ->byType($type)
                             ->orderBy('display_order', 'asc')
                             ->first();

            if (!$media) {
                // Return default/fallback response
                return response()->json([
                    'success' => true,
                    'data' => null,
                    'message' => 'No active media found for this type.',
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $media->id,
                    'media_type' => $media->media_type,
                    'media_url' => $media->full_media_url,
                    'title' => $media->title,
                    'description' => $media->description,
                    'photographer_name' => $media->photographer_name,
                    'display_order' => $media->display_order,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching auth media: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch auth media.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all active auth media
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAll()
    {
        try {
            $media = AuthMedia::active()
                             ->orderBy('media_type', 'asc')
                             ->orderBy('display_order', 'asc')
                             ->get()
                             ->map(function ($item) {
                                 return [
                                     'id' => $item->id,
                                     'media_type' => $item->media_type,
                                     'media_url' => $item->full_media_url,
                                     'title' => $item->title,
                                     'description' => $item->description,
                                     'photographer_name' => $item->photographer_name,
                                     'display_order' => $item->display_order,
                                 ];
                             });

            return response()->json([
                'success' => true,
                'data' => [
                    'login' => $media->where('media_type', 'login')->first(),
                    'register' => $media->where('media_type', 'register')->first(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching all auth media: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch auth media.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
