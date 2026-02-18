<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserReportController extends Controller
{
    /**
     * Submit a user report
     */
    public function store(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $request->validate([
                'reported_user_id' => 'required|integer|exists:fobi_users,id',
                'reason' => 'required|string|in:spam,harassment,inappropriate,fake_account,other',
                'description' => 'nullable|string|max:1000'
            ]);

            $reportedUserId = $request->reported_user_id;

            // Tidak bisa melaporkan diri sendiri
            if ($user->id == $reportedUserId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak dapat melaporkan diri sendiri'
                ], 400);
            }

            // Cek apakah sudah pernah melaporkan user ini dalam 24 jam terakhir
            $existingReport = DB::table('user_reports')
                ->where('reporter_id', $user->id)
                ->where('reported_user_id', $reportedUserId)
                ->where('created_at', '>=', now()->subDay())
                ->first();

            if ($existingReport) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda sudah melaporkan pengguna ini dalam 24 jam terakhir'
                ], 400);
            }

            // Simpan laporan
            $reportId = DB::table('user_reports')->insertGetId([
                'reporter_id' => $user->id,
                'reported_user_id' => $reportedUserId,
                'reason' => $request->reason,
                'description' => $request->description,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            Log::info('User report submitted:', [
                'report_id' => $reportId,
                'reporter_id' => $user->id,
                'reported_user_id' => $reportedUserId,
                'reason' => $request->reason
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Laporan berhasil dikirim. Tim kami akan meninjau laporan Anda.',
                'data' => [
                    'report_id' => $reportId
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error submitting user report:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim laporan'
            ], 500);
        }
    }

    /**
     * Check if current user has reported a specific user
     */
    public function checkReport($userId)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $hasReported = DB::table('user_reports')
                ->where('reporter_id', $user->id)
                ->where('reported_user_id', $userId)
                ->where('created_at', '>=', now()->subDay())
                ->exists();

            return response()->json([
                'success' => true,
                'data' => [
                    'has_reported' => $hasReported
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memeriksa status laporan'
            ], 500);
        }
    }
}
