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
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
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
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'roles'      => $user->getRoleNames(),
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
        ], 200);
    }
}
