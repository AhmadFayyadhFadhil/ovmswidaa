<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nik'      => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        if (!Auth::attempt($validated)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'NIK atau password salah',
            ], 401);
        }

        $user  = Auth::user();
        if (!$user->is_active) {
            Auth::logout();
            return response()->json([
                'status'  => 'error',
                'message' => 'Akun Anda belum aktif. Silakan hubungi GA Koordinator atau Administrator untuk aktivasi.',
            ], 403);
        }
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'status'  => 'success',
            'message' => 'Login berhasil',
            'data'    => [
                'user' => [
                    'id'                 => $user->id,
                    'nik'                => $user->nik,
                    'name'               => $user->name,
                    'email'              => $user->email,
                    'department_id'      => $user->department_id,
                    'department_name'    => $user->department?->name,
                    'is_department_head' => $user->is_department_head,
                    'roles'              => $user->getRoleNames(),
                    'availability_status' => $user->availability_status,
                    'avatar_url'         => $user->avatar ? url('storage/' . $user->avatar) : null,
                ],
                'token'      => $token,
                'token_type' => 'Bearer',
            ],
        ], 200);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nik'      => 'required|string|unique:users,nik',
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ], [
            'nik.required'       => 'NIK harus diisi',
            'nik.unique'         => 'NIK sudah terdaftar',
            'name.required'      => 'Nama harus diisi',
            'email.required'     => 'Email harus diisi',
            'email.email'        => 'Format email tidak valid',
            'email.unique'       => 'Email sudah terdaftar',
            'password.required'  => 'Password harus diisi',
            'password.min'       => 'Password minimal 6 karakter',
            'password.confirmed' => 'Konfirmasi password tidak cocok',
        ]);

        // Plain text - cast 'hashed' di User model yang akan hash
        $user = User::create([
            'nik'      => $validated['nik'],
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => $validated['password'],
            'department_id' => $validated['department_id'] ?? null,
        ]);

        // Ensure the Employee role exists for the sanctum guard before assigning (avoid runtime errors)
        if (!Role::where('name', 'Employee')->where('guard_name', 'sanctum')->exists()) {
            Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'sanctum']);
        }
        $user->assignRole('Employee');

        return response()->json([
            'status' => 'success',
            'message' => 'Pendaftaran berhasil. Akun Anda sedang menunggu aktivasi dari GA Koordinator.',
            'data'   => [
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                ]
            ],
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Logout berhasil',
        ], 200);
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'id'                 => $user->id,
                'nik'                => $user->nik,
                'name'               => $user->name,
                'email'              => $user->email,
                'phone'              => $user->phone,
                'location'           => $user->location,
                'avatar_url'         => $user->avatar ? url('storage/' . $user->avatar) : null,
                'sim_a_photo_url'    => $user->sim_a_photo ? url('storage/' . $user->sim_a_photo) : null,
                'department_id'      => $user->department_id,
                'department_name'    => $user->department?->name,
                'is_department_head' => $user->is_department_head,
                'roles'              => $user->getRoleNames(),
                'availability_status' => $user->availability_status,
                'created_at'         => $user->created_at,
                'updated_at'         => $user->updated_at,
            ],
        ], 200);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6|confirmed',
            'phone'    => 'nullable|string|max:50',
            'location' => 'nullable|string|max:255',
        ], [
            'name.required'      => 'Nama harus diisi',
            'email.required'     => 'Email harus diisi',
            'email.email'        => 'Format email tidak valid',
            'email.unique'       => 'Email sudah terdaftar',
            'password.min'       => 'Password minimal 6 karakter',
            'password.confirmed' => 'Konfirmasi password tidak cocok',
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->phone = $validated['phone'] ?? null;
        $user->location = $validated['location'] ?? null;

        if (!empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Profil berhasil diperbarui',
            'data'    => [
                'id'                => $user->id,
                'nik'               => $user->nik,
                'name'              => $user->name,
                'email'             => $user->email,
                'phone'             => $user->phone,
                'location'          => $user->location,
                'avatar_url'        => $user->avatar ? url('storage/' . $user->avatar) : null,
                'department_id'     => $user->department_id,
                'department_name'   => $user->department?->name,
                'is_department_head' => $user->is_department_head,
                'roles'             => $user->getRoleNames(),
                'created_at'        => $user->created_at,
                'updated_at'        => $user->updated_at,
            ],
        ], 200);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ], [
            'avatar.required' => 'File foto profil wajib diunggah',
            'avatar.image'    => 'File harus berupa gambar',
            'avatar.mimes'    => 'Format gambar harus jpeg, png, jpg, atau gif',
            'avatar.max'      => 'Ukuran gambar maksimal 2MB',
        ]);

        // Hapus avatar lama jika ada di storage
        if ($user->avatar && \Illuminate\Support\Facades\Storage::disk('public')->exists($user->avatar)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar);
        }

        // Simpan file avatar baru
        $path = $request->file('avatar')->store('users/avatars', 'public');
        $user->avatar = $path;
        $user->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Foto profil berhasil diperbarui',
            'data'    => [
                'avatar_url' => url('storage/' . $path),
            ],
        ], 200);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|exists:users,email',
        ], [
            'email.required' => 'Email harus diisi',
            'email.email'    => 'Format email tidak valid',
            'email.exists'   => 'Email tidak terdaftar',
        ]);

        // Generate a 6-digit numeric OTP token
        $token = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        // Store in password_reset_tokens
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $validated['email']],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Token reset password telah dikirim (gunakan token berikut untuk mereset).',
            'data'    => [
                'email' => $validated['email'],
                'token' => $token, // Returned for ease of local demo/dev flow
            ]
        ], 200);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => 'required|email|exists:users,email',
            'token'    => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ], [
            'email.required'     => 'Email harus diisi',
            'email.email'        => 'Format email tidak valid',
            'email.exists'       => 'Email tidak terdaftar',
            'token.required'     => 'Token reset password harus diisi',
            'password.required'  => 'Password baru harus diisi',
            'password.min'       => 'Password baru minimal 6 karakter',
            'password.confirmed' => 'Konfirmasi password tidak cocok',
        ]);

        $reset = DB::table('password_reset_tokens')
            ->where('email', $validated['email'])
            ->first();

        if (!$reset || !Hash::check($validated['token'], $reset->token)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Token reset password tidak valid atau telah kedaluwarsa.',
            ], 422);
        }

        // Token is valid! Delete the token
        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        $user = User::where('email', $validated['email'])->firstOrFail();
        $user->password = $validated['password'];
        $user->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Password Anda berhasil diperbarui. Silakan login kembali.',
        ], 200);
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only drivers can toggle status (check DB roles directly to avoid Sanctum guard resolution issues)
        if (!$user->roles()->whereIn('name', ['Driver', 'driver'])->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Hanya pengemudi yang dapat memperbarui status ketersediaan.',
            ], 403);
        }

        $validated = $request->validate([
            'availability_status' => 'required|string|in:available,unavailable',
        ], [
            'availability_status.required' => 'Status ketersediaan wajib diisi',
            'availability_status.in'       => 'Status ketersediaan tidak valid',
        ]);

        // If driver is currently on_trip or assigned, they cannot go offline/off-duty
        if (in_array($user->availability_status, ['on_trip', 'assigned'], true)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Tidak dapat mengubah status saat sedang bertugas atau memiliki perjalanan aktif.',
            ], 422);
        }

        $user->update([
            'availability_status' => $validated['availability_status'],
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Status ketersediaan berhasil diperbarui',
            'data'    => [
                'availability_status' => $user->availability_status,
            ],
        ], 200);
    }
}
