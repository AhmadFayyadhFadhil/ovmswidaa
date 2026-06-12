<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if (!Auth::attempt($validated)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Email atau password salah',
            ], 401);
        }

        $user  = Auth::user();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'status'  => 'success',
            'message' => 'Login berhasil',
            'data'    => [
                'user' => [
                    'id'                 => $user->id,
                    'name'               => $user->name,
                    'email'              => $user->email,
                    'department_id'      => $user->department_id,
                    'is_department_head' => $user->is_department_head,
                    'roles'              => $user->getRoleNames(),
                    'availability_status' => $user->availability_status,
                ],
                'token'      => $token,
                'token_type' => 'Bearer',
            ],
        ], 200);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'department_id' => ['nullable', 'string', 'max:255', Rule::in(User::validDepartments())],
        ], [
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

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'data'   => [
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                ],
                'token'      => $token,
                'token_type' => 'Bearer',
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
                'name'               => $user->name,
                'email'              => $user->email,
                'department_id'      => $user->department_id,
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

        if (!empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Profil berhasil diperbarui',
            'data'    => [
                'id'                => $user->id,
                'name'              => $user->name,
                'email'             => $user->email,
                'department_id'     => $user->department_id,
                'is_department_head' => $user->is_department_head,
                'roles'             => $user->getRoleNames(),
                'created_at'        => $user->created_at,
                'updated_at'        => $user->updated_at,
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

        return response()->json([
            'status'  => 'success',
            'message' => 'Email terverifikasi. Silakan reset password Anda.',
            'data'    => [
                'email' => $validated['email'],
            ]
        ], 200);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => 'required|email|exists:users,email',
            'password' => 'required|string|min:6|confirmed',
        ], [
            'email.required'     => 'Email harus diisi',
            'email.email'        => 'Format email tidak valid',
            'email.exists'       => 'Email tidak terdaftar',
            'password.required'  => 'Password baru harus diisi',
            'password.min'       => 'Password baru minimal 6 karakter',
            'password.confirmed' => 'Konfirmasi password tidak cocok',
        ]);

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
