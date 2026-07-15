<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecurityGuard;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SecurityGuardController extends Controller
{
    public function index(): JsonResponse
    {
        $guards = SecurityGuard::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'data'   => $guards,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        if (!Auth::user()->hasRoleDirect(['Admin', 'GA'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:security_guards,name',
        ], [
            'name.unique' => 'Nama petugas security ini sudah terdaftar.',
            'name.required' => 'Nama petugas security wajib diisi.',
        ]);

        $guard = SecurityGuard::create($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Petugas security berhasil ditambahkan.',
            'data'    => $guard,
        ], 201);
    }

    public function destroy(SecurityGuard $securityGuard): JsonResponse
    {
        if (!Auth::user()->hasRoleDirect(['Admin', 'GA'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $securityGuard->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Petugas security berhasil dihapus.',
        ], 200);
    }
}
