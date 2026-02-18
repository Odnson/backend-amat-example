<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class MessageController extends Controller
{
    /**
     * Get profile picture URL dengan support S3
     */
    private function getProfilePictureUrl($path)
    {
        if (empty($path)) {
            return null;
        }

        // Jika sudah URL lengkap (S3 atau URL lainnya), gunakan langsung
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Bersihkan path dari prefix yang tidak perlu
        $cleanPath = preg_replace('/^\/?(storage\/)?/', '', $path);
        
        // Cek apakah S3 credentials tersedia
        $storageDisk = config('filesystems.media_storage_disk', 's3');
        $awsKey = config('filesystems.disks.s3.key');
        $awsBucket = config('filesystems.disks.s3.bucket');
        $s3Available = !empty($awsKey) && !empty($awsBucket);
        
        try {
            if ($storageDisk === 's3' && $s3Available) {
                // Cek apakah file ada di S3
                if (Storage::disk('s3')->exists($cleanPath)) {
                    return Storage::disk('s3')->url($cleanPath);
                }
            }
            
            // Fallback ke local storage URL
            return config('app.url') . '/storage/' . $cleanPath;
            
        } catch (\Exception $e) {
            Log::debug('Error getting profile picture URL:', [
                'path' => $cleanPath,
                'error' => $e->getMessage()
            ]);
            
            return config('app.url') . '/storage/' . $cleanPath;
        }
    }

    /**
     * Send a message to a user
     */
    public function send(Request $request)
    {
        try {
            $currentUser = auth()->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'receiver_id' => 'required|integer|exists:fobi_users,id',
                'message' => 'required|string|max:5000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $receiverId = $request->input('receiver_id');
            $message = $request->input('message');

            // Can't message yourself
            if ($currentUser->id == $receiverId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat mengirim pesan ke diri sendiri'
                ], 400);
            }

            // Create message
            $messageId = DB::table('user_messages')->insertGetId([
                'sender_id' => $currentUser->id,
                'receiver_id' => $receiverId,
                'message' => $message,
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Create notification for receiver (non-blocking)
            try {
                DB::table('social_notifications')->insert([
                    'user_id' => $receiverId,
                    'type' => 'new_message',
                    'message' => "{$currentUser->uname} mengirim pesan kepada Anda",
                    'data' => json_encode([
                        'sender_id' => $currentUser->id,
                        'sender_name' => $currentUser->uname,
                        'sender_picture' => $currentUser->profile_picture,
                        'message_id' => $messageId,
                        'preview' => substr($message, 0, 100)
                    ]),
                    'is_read' => false,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } catch (\Exception $notifError) {
                Log::warning('Failed to create message notification:', ['error' => $notifError->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Pesan berhasil dikirim',
                'data' => [
                    'id' => $messageId,
                    'sender_id' => $currentUser->id,
                    'receiver_id' => $receiverId,
                    'message' => $message,
                    'created_at' => now()->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending message:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim pesan'
            ], 500);
        }
    }

    /**
     * Get inbox (list of conversations)
     */
    public function inbox(Request $request)
    {
        try {
            $currentUser = auth()->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Get unique conversations with last message
            $conversations = DB::select("
                SELECT 
                    CASE 
                        WHEN m.sender_id = ? THEN m.receiver_id 
                        ELSE m.sender_id 
                    END as other_user_id,
                    u.uname,
                    u.fname,
                    u.lname,
                    u.profile_picture,
                    m.message as last_message,
                    m.created_at as last_message_at,
                    m.sender_id as last_sender_id,
                    COALESCE(m.is_admin_message, 0) as is_admin_message,
                    CASE WHEN u.uname = 'amaturalist' THEN 1 ELSE 0 END as is_system_user,
                    (
                        SELECT COUNT(*) 
                        FROM user_messages 
                        WHERE sender_id = CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END
                        AND receiver_id = ?
                        AND is_read = 0
                        AND deleted_at IS NULL
                    ) as unread_count
                FROM user_messages m
                INNER JOIN (
                    SELECT 
                        CASE 
                            WHEN sender_id = ? THEN receiver_id 
                            ELSE sender_id 
                        END as other_id,
                        MAX(created_at) as max_created
                    FROM user_messages
                    WHERE (sender_id = ? OR receiver_id = ?)
                    AND deleted_at IS NULL
                    GROUP BY other_id
                ) latest ON (
                    CASE 
                        WHEN m.sender_id = ? THEN m.receiver_id 
                        ELSE m.sender_id 
                    END = latest.other_id
                    AND m.created_at = latest.max_created
                )
                INNER JOIN fobi_users u ON u.id = CASE 
                    WHEN m.sender_id = ? THEN m.receiver_id 
                    ELSE m.sender_id 
                END
                WHERE (m.sender_id = ? OR m.receiver_id = ?)
                AND m.deleted_at IS NULL
                ORDER BY m.created_at DESC
            ", [
                $currentUser->id, $currentUser->id, $currentUser->id,
                $currentUser->id, $currentUser->id, $currentUser->id,
                $currentUser->id, $currentUser->id, $currentUser->id, $currentUser->id
            ]);

            // Get total unread count
            $totalUnread = DB::table('user_messages')
                ->where('receiver_id', $currentUser->id)
                ->where('is_read', false)
                ->whereNull('deleted_at')
                ->count();

            // Process conversations untuk konversi profile_picture ke URL S3
            $processedConversations = array_map(function($conv) {
                $conv->profile_picture = $this->getProfilePictureUrl($conv->profile_picture);
                return $conv;
            }, $conversations);

            return response()->json([
                'success' => true,
                'data' => [
                    'conversations' => $processedConversations,
                    'total_unread' => $totalUnread
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting inbox:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil inbox'
            ], 500);
        }
    }

    /**
     * Get conversation with a specific user
     */
    public function conversation(Request $request, $userId)
    {
        try {
            $currentUser = auth()->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 50);
            $offset = ($page - 1) * $perPage;

            // Get other user info
            $otherUser = DB::table('fobi_users')
                ->where('id', $userId)
                ->select('id', 'uname', 'fname', 'lname', 'profile_picture')
                ->first();

            if (!$otherUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan'
                ], 404);
            }

            // Get messages
            $messages = DB::table('user_messages')
                ->where(function($query) use ($currentUser, $userId) {
                    $query->where('sender_id', $currentUser->id)
                          ->where('receiver_id', $userId);
                })
                ->orWhere(function($query) use ($currentUser, $userId) {
                    $query->where('sender_id', $userId)
                          ->where('receiver_id', $currentUser->id);
                })
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            // Mark messages as read
            DB::table('user_messages')
                ->where('sender_id', $userId)
                ->where('receiver_id', $currentUser->id)
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now()
                ]);

            // Get total count
            $total = DB::table('user_messages')
                ->where(function($query) use ($currentUser, $userId) {
                    $query->where('sender_id', $currentUser->id)
                          ->where('receiver_id', $userId);
                })
                ->orWhere(function($query) use ($currentUser, $userId) {
                    $query->where('sender_id', $userId)
                          ->where('receiver_id', $currentUser->id);
                })
                ->whereNull('deleted_at')
                ->count();

            // Process other_user untuk konversi profile_picture ke URL S3
            $otherUser->profile_picture = $this->getProfilePictureUrl($otherUser->profile_picture);

            return response()->json([
                'success' => true,
                'data' => [
                    'other_user' => $otherUser,
                    'messages' => $messages->reverse()->values(),
                    'pagination' => [
                        'current_page' => (int)$page,
                        'per_page' => (int)$perPage,
                        'total' => $total,
                        'has_more' => ($offset + $perPage) < $total
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting conversation:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil percakapan'
            ], 500);
        }
    }

    /**
     * Mark messages as read
     */
    public function markAsRead(Request $request, $userId)
    {
        try {
            $currentUser = auth()->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $updated = DB::table('user_messages')
                ->where('sender_id', $userId)
                ->where('receiver_id', $currentUser->id)
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now()
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Pesan ditandai sudah dibaca',
                'data' => [
                    'marked_count' => $updated
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error marking messages as read:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menandai pesan'
            ], 500);
        }
    }

    /**
     * Get unread message count
     */
    public function unreadCount()
    {
        try {
            $currentUser = auth()->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'count' => 0
                    ]
                ]);
            }

            $count = DB::table('user_messages')
                ->where('receiver_id', $currentUser->id)
                ->where('is_read', false)
                ->whereNull('deleted_at')
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'count' => $count
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting unread count:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil jumlah pesan'
            ], 500);
        }
    }

    /**
     * Delete a message (soft delete)
     */
    public function delete(Request $request, $messageId)
    {
        try {
            $currentUser = auth()->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $message = DB::table('user_messages')
                ->where('id', $messageId)
                ->where(function($query) use ($currentUser) {
                    $query->where('sender_id', $currentUser->id)
                          ->orWhere('receiver_id', $currentUser->id);
                })
                ->first();

            if (!$message) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pesan tidak ditemukan'
                ], 404);
            }

            DB::table('user_messages')
                ->where('id', $messageId)
                ->update(['deleted_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Pesan berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting message:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus pesan'
            ], 500);
        }
    }
}
