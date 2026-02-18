<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowController extends Controller
{
    /**
     * Follow a user
     */
    public function follow(Request $request, $userId)
    {
        try {
            $currentUser = auth()->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Can't follow yourself
            if ($currentUser->id == $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat mengikuti diri sendiri'
                ], 400);
            }

            // Check if target user exists
            $targetUser = DB::table('fobi_users')->where('id', $userId)->first();
            if (!$targetUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan'
                ], 404);
            }

            // Check if already following
            $existing = DB::table('user_followers')
                ->where('user_id', $userId)
                ->where('follower_id', $currentUser->id)
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sudah mengikuti user ini'
                ], 400);
            }

            // Create follow relationship
            DB::table('user_followers')->insert([
                'user_id' => $userId,
                'follower_id' => $currentUser->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Create notification for the followed user (non-blocking)
            try {
                DB::table('social_notifications')->insert([
                    'user_id' => $userId,
                    'type' => 'new_follower',
                    'message' => "{$currentUser->uname} mulai mengikuti Anda",
                    'data' => json_encode([
                        'follower_id' => $currentUser->id,
                        'follower_name' => $currentUser->uname,
                        'follower_picture' => $currentUser->profile_picture
                    ]),
                    'is_read' => false,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } catch (\Exception $notifError) {
                Log::warning('Failed to create follow notification:', ['error' => $notifError->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Berhasil mengikuti user',
                'data' => [
                    'is_following' => true
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error following user:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengikuti user'
            ], 500);
        }
    }

    /**
     * Unfollow a user
     */
    public function unfollow(Request $request, $userId)
    {
        try {
            $currentUser = auth()->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Delete follow relationship
            $deleted = DB::table('user_followers')
                ->where('user_id', $userId)
                ->where('follower_id', $currentUser->id)
                ->delete();

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak mengikuti user ini'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Berhasil berhenti mengikuti user',
                'data' => [
                    'is_following' => false
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error unfollowing user:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal berhenti mengikuti user'
            ], 500);
        }
    }

    /**
     * Check if current user is following a specific user
     */
    public function checkFollowStatus($userId)
    {
        try {
            $currentUser = auth()->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'is_following' => false
                    ]
                ]);
            }

            $isFollowing = DB::table('user_followers')
                ->where('user_id', $userId)
                ->where('follower_id', $currentUser->id)
                ->exists();

            return response()->json([
                'success' => true,
                'data' => [
                    'is_following' => $isFollowing
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error checking follow status:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memeriksa status follow'
            ], 500);
        }
    }

    /**
     * Get followers of a user
     */
    public function getFollowers($userId)
    {
        try {
            $followers = DB::table('user_followers')
                ->join('fobi_users', 'user_followers.follower_id', '=', 'fobi_users.id')
                ->leftJoin(DB::raw('(SELECT user_id, COUNT(*) as observations_count FROM fobi_checklist_taxas GROUP BY user_id) as obs'), 'fobi_users.id', '=', 'obs.user_id')
                ->where('user_followers.user_id', $userId)
                ->select(
                    'fobi_users.id',
                    'fobi_users.uname',
                    'fobi_users.fname',
                    'fobi_users.lname',
                    'fobi_users.profile_picture',
                    'user_followers.created_at as followed_at',
                    DB::raw('COALESCE(obs.observations_count, 0) as observations_count')
                )
                ->orderBy('user_followers.created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $followers
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting followers:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data followers'
            ], 500);
        }
    }

    /**
     * Get users that a user is following
     */
    public function getFollowing($userId)
    {
        try {
            $following = DB::table('user_followers')
                ->join('fobi_users', 'user_followers.user_id', '=', 'fobi_users.id')
                ->leftJoin(DB::raw('(SELECT user_id, COUNT(*) as observations_count FROM fobi_checklist_taxas GROUP BY user_id) as obs'), 'fobi_users.id', '=', 'obs.user_id')
                ->where('user_followers.follower_id', $userId)
                ->select(
                    'fobi_users.id',
                    'fobi_users.uname',
                    'fobi_users.fname',
                    'fobi_users.lname',
                    'fobi_users.profile_picture',
                    'user_followers.created_at as followed_at',
                    DB::raw('COALESCE(obs.observations_count, 0) as observations_count')
                )
                ->orderBy('user_followers.created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $following
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting following:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data following'
            ], 500);
        }
    }
}
