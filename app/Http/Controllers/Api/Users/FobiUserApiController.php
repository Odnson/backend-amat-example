<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FobiUser;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class FobiUserApiController extends Controller
{
    // Mendapatkan daftar semua pengguna (hanya untuk admin level 3,4)
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            
            // Log akses untuk audit trail
            Log::info('Admin accessing user list', [
                'admin_id' => $user->id ?? 'unknown',
                'admin_name' => $user->fname ?? 'unknown',
                'ip' => $request->ip()
            ]);
            
            $users = FobiUser::select([
                'id', 'fname', 'lname', 'uname', 'email', 'phone',
                'organization', 'level', 'is_approved', 'profile_picture',
                'created_at', 'updated_at'
            ])->paginate(50);
            
            return response()->json($users);
        } catch (\Exception $e) {
            Log::error('Error fetching user list: ' . $e->getMessage());
            return response()->json(['error' => 'Gagal mengambil data pengguna'], 500);
        }
    }

    // Mendapatkan detail pengguna berdasarkan ID (hanya untuk admin level 3,4)
    public function show(Request $request, $id)
    {
        try {
            $admin = auth()->user();
            
            // Log akses untuk audit trail
            Log::info('Admin accessing user detail', [
                'admin_id' => $admin->id ?? 'unknown',
                'target_user_id' => $id,
                'ip' => $request->ip()
            ]);
            
            $user = FobiUser::select([
                'id', 'fname', 'lname', 'uname', 'email', 'phone',
                'organization', 'level', 'is_approved', 'profile_picture',
                'bio', 'created_at', 'updated_at'
            ])->find($id);
            
            if (!$user) {
                return response()->json(['error' => 'Pengguna tidak ditemukan'], 404);
            }
            
            return response()->json($user);
        } catch (\Exception $e) {
            Log::error('Error fetching user detail: ' . $e->getMessage());
            return response()->json(['error' => 'Gagal mengambil data pengguna'], 500);
        }
    }

    // Membuat pengguna baru (hanya untuk admin level 3,4)
    public function store(Request $request)
    {
        try {
            $admin = auth()->user();
            
            $validatedData = $request->validate([
                'fname' => 'required|max:20',
                'lname' => 'required|max:20',
                'email' => 'required|email|max:50|unique:fobi_users',
                'uname' => 'required|max:50|unique:fobi_users',
                'password' => 'required|min:6',
                'phone' => 'required|max:14',
                'organization' => 'required|max:50',
            ]);

            $user = FobiUser::create([
                'fname' => $request->fname,
                'lname' => $request->lname,
                'email' => $request->email,
                'uname' => $request->uname,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'organization' => $request->organization,
                'level' => 1,
                'is_approved' => 0,
            ]);

            // Log aksi untuk audit trail
            Log::info('Admin created new user', [
                'admin_id' => $admin->id ?? 'unknown',
                'new_user_id' => $user->id,
                'new_user_email' => $user->email,
                'ip' => $request->ip()
            ]);

            // Jangan return password dalam response
            $user->makeHidden(['password', 'remember_token']);
            
            return response()->json(['success' => 'Pengguna berhasil dibuat', 'user' => $user], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => 'Validasi gagal', 'messages' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error creating user: ' . $e->getMessage());
            return response()->json(['error' => 'Gagal membuat pengguna'], 500);
        }
    }

    // Memperbarui pengguna (hanya untuk admin level 3,4)
    public function update(Request $request, $id)
    {
        try {
            $admin = auth()->user();
            
            $user = FobiUser::find($id);
            if (!$user) {
                return response()->json(['error' => 'Pengguna tidak ditemukan'], 404);
            }

            $validatedData = $request->validate([
                'fname' => 'sometimes|required|max:20',
                'lname' => 'sometimes|required|max:20',
                'email' => 'sometimes|required|email|max:50|unique:fobi_users,email,' . $id,
                'uname' => 'sometimes|required|max:50|unique:fobi_users,uname,' . $id,
                'password' => 'sometimes|required|min:6',
                'phone' => 'sometimes|required|max:14',
                'organization' => 'sometimes|required|max:50',
            ]);

            // Simpan data lama untuk audit
            $oldData = $user->only(['fname', 'lname', 'email', 'uname', 'phone', 'organization', 'level']);

            $user->update($request->only(['fname', 'lname', 'email', 'uname', 'phone', 'organization']));

            if ($request->has('password')) {
                $user->password = Hash::make($request->password);
                $user->save();
            }

            // Log aksi untuk audit trail
            Log::info('Admin updated user', [
                'admin_id' => $admin->id ?? 'unknown',
                'target_user_id' => $id,
                'old_data' => $oldData,
                'new_data' => $request->only(['fname', 'lname', 'email', 'uname', 'phone', 'organization']),
                'ip' => $request->ip()
            ]);

            // Jangan return password dalam response
            $user->makeHidden(['password', 'remember_token']);
            
            return response()->json(['success' => 'Pengguna berhasil diperbarui', 'user' => $user]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => 'Validasi gagal', 'messages' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error updating user: ' . $e->getMessage());
            return response()->json(['error' => 'Gagal memperbarui pengguna'], 500);
        }
    }

    // Menghapus pengguna (hanya untuk admin level 3,4)
    public function destroy(Request $request, $id)
    {
        try {
            $admin = auth()->user();
            
            // Cegah admin menghapus dirinya sendiri
            if ($admin && $admin->id == $id) {
                return response()->json(['error' => 'Tidak dapat menghapus akun sendiri'], 403);
            }
            
            $user = FobiUser::find($id);
            if (!$user) {
                return response()->json(['error' => 'Pengguna tidak ditemukan'], 404);
            }

            // Simpan data untuk audit sebelum dihapus
            $deletedUserData = [
                'id' => $user->id,
                'email' => $user->email,
                'uname' => $user->uname,
                'fname' => $user->fname,
                'lname' => $user->lname
            ];

            $user->delete();
            
            // Log aksi untuk audit trail
            Log::warning('Admin deleted user', [
                'admin_id' => $admin->id ?? 'unknown',
                'deleted_user' => $deletedUserData,
                'ip' => $request->ip()
            ]);
            
            return response()->json(['success' => 'Pengguna berhasil dihapus']);
        } catch (\Exception $e) {
            Log::error('Error deleting user: ' . $e->getMessage());
            return response()->json(['error' => 'Gagal menghapus pengguna'], 500);
        }
    }
}
