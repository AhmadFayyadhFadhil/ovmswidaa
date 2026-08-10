<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TripPurpose;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class TripPurposeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TripPurpose::query();

        // If for form, return active only unless specified
        if ($request->query('all') !== '1') {
            $query->where('is_active', true);
        }

        $purposes = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'data'   => $purposes,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user || (!$user->hasRoleDirect('Admin') && !$user->hasRoleDirect('GA') && !$user->hasRoleDirect('admin') && !$user->hasRoleDirect('ga'))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Anda tidak memiliki hak akses untuk mengelola Master Data Purpose of Trip.',
            ], 403);
        }

        $validated = $request->validate([
            'name'      => 'required|string|max:255|unique:trip_purposes,name',
            'is_active' => 'nullable|boolean',
        ]);

        $purpose = TripPurpose::create([
            'name'      => trim($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Purpose of Trip berhasil ditambahkan',
            'data'    => $purpose,
        ], 201);
    }

    public function update(Request $request, TripPurpose $tripPurpose): JsonResponse
    {
        $user = Auth::user();
        if (!$user || (!$user->hasRoleDirect('Admin') && !$user->hasRoleDirect('GA') && !$user->hasRoleDirect('admin') && !$user->hasRoleDirect('ga'))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Anda tidak memiliki hak akses untuk mengelola Master Data Purpose of Trip.',
            ], 403);
        }

        $validated = $request->validate([
            'name'      => 'required|string|max:255|unique:trip_purposes,name,' . $tripPurpose->id,
            'is_active' => 'nullable|boolean',
        ]);

        $tripPurpose->update([
            'name'      => trim($validated['name']),
            'is_active' => $validated['is_active'] ?? $tripPurpose->is_active,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Purpose of Trip berhasil diperbarui',
            'data'    => $tripPurpose,
        ]);
    }

    public function destroy(TripPurpose $tripPurpose): JsonResponse
    {
        $user = Auth::user();
        if (!$user || (!$user->hasRoleDirect('Admin') && !$user->hasRoleDirect('GA') && !$user->hasRoleDirect('admin') && !$user->hasRoleDirect('ga'))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Anda tidak memiliki hak akses untuk mengelola Master Data Purpose of Trip.',
            ], 403);
        }

        $tripPurpose->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Purpose of Trip berhasil dihapus',
        ]);
    }
}
