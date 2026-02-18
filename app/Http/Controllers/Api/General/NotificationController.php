<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class NotificationController extends Controller
{
    public function index()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan'
                ], 401);
            }

            // Get taxa notifications
            $taxaNotifications = DB::table('taxa_notifications')
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get()
                ->map(function($notification) {
                    $notification->source = 'taxa';
                    $notification->created_at = \Carbon\Carbon::parse($notification->created_at)
                        ->setTimezone('Asia/Jakarta');
                    return $notification;
                });

            // Get social notifications
            $socialNotifications = collect([]);
            try {
                $socialNotifications = DB::table('social_notifications')
                    ->where('user_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->limit(20)
                    ->get()
                    ->map(function($notification) {
                        $notification->source = 'social';
                        $notification->checklist_id = null; // Social notifications don't have checklist_id
                        $notification->created_at = \Carbon\Carbon::parse($notification->created_at)
                            ->setTimezone('Asia/Jakarta');
                        return $notification;
                    });
            } catch (\Exception $e) {
                // Table might not exist yet
            }

            // Merge and sort by created_at desc
            $notifications = $taxaNotifications->merge($socialNotifications)
                ->sortByDesc('created_at')
                ->take(30)
                ->values();

            return response()->json([
                'success' => true,
                'data' => $notifications
            ]);
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token telah kadaluarsa'
            ], 401);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token tidak valid'
            ], 401);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token tidak ditemukan'
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil notifikasi'
            ], 500);
        }
    }

    public function markAsRead($id)
    {
        try {
            $userId = JWTAuth::user()->id;

            // Try taxa_notifications first
            $updated = DB::table('taxa_notifications')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->update([
                    'is_read' => true,
                    'updated_at' => now()
                ]);

            // If not found in taxa, try social_notifications
            if (!$updated) {
                try {
                    $updated = DB::table('social_notifications')
                        ->where('id', $id)
                        ->where('user_id', $userId)
                        ->update([
                            'is_read' => true,
                            'updated_at' => now()
                        ]);
                } catch (\Exception $e) {
                    // Table might not exist
                }
            }

            if (!$updated) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notifikasi tidak ditemukan'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Notifikasi telah ditandai sebagai dibaca'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menandai notifikasi sebagai dibaca'
            ], 500);
        }
    }

    public function markAllAsRead()
    {
        try {
            $userId = JWTAuth::user()->id;

            // Mark all taxa notifications as read
            DB::table('taxa_notifications')
                ->where('user_id', $userId)
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'updated_at' => now()
                ]);

            // Mark all social notifications as read
            try {
                DB::table('social_notifications')
                    ->where('user_id', $userId)
                    ->where('is_read', false)
                    ->update([
                        'is_read' => true,
                        'updated_at' => now()
                    ]);
            } catch (\Exception $e) {
                // Table might not exist
            }

            return response()->json([
                'success' => true,
                'message' => 'Semua notifikasi telah ditandai sebagai dibaca'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menandai semua notifikasi sebagai dibaca'
            ], 500);
        }
    }

    public function getUnreadCount()
    {
        try {
            $userId = JWTAuth::user()->id;

            $taxaCount = DB::table('taxa_notifications')
                ->where('user_id', $userId)
                ->where('is_read', false)
                ->count();

            $socialCount = 0;
            try {
                $socialCount = DB::table('social_notifications')
                    ->where('user_id', $userId)
                    ->where('is_read', false)
                    ->count();
            } catch (\Exception $e) {
                // Table might not exist
            }

            $count = $taxaCount + $socialCount;

            return response()->json([
                'success' => true,
                'count' => $count
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil jumlah notifikasi yang belum dibaca'
            ], 500);
        }
    }
}
