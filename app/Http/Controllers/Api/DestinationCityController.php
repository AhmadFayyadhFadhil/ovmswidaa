<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DestinationCity;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DestinationCityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DestinationCity::query();

        if ($request->query('all') !== '1') {
            $query->where('is_active', true);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('province', 'like', "%{$search}%");
            });
        }

        $cities = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'data'   => $cities,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user || (!$user->hasRoleDirect('Admin') && !$user->hasRoleDirect('GA') && !$user->hasRoleDirect('admin') && !$user->hasRoleDirect('ga'))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Anda tidak memiliki hak akses untuk mengelola Master Data Kota Tujuan.',
            ], 403);
        }

        $validated = $request->validate([
            'name'      => 'required|string|max:255|unique:destination_cities,name',
            'province'  => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        $city = DestinationCity::create([
            'name'      => trim($validated['name']),
            'province'  => isset($validated['province']) ? trim($validated['province']) : null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Kota Tujuan berhasil ditambahkan',
            'data'    => $city,
        ], 201);
    }

    public function update(Request $request, DestinationCity $destinationCity): JsonResponse
    {
        $user = Auth::user();
        if (!$user || (!$user->hasRoleDirect('Admin') && !$user->hasRoleDirect('GA') && !$user->hasRoleDirect('admin') && !$user->hasRoleDirect('ga'))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Anda tidak memiliki hak akses untuk mengelola Master Data Kota Tujuan.',
            ], 403);
        }

        $validated = $request->validate([
            'name'      => 'required|string|max:255|unique:destination_cities,name,' . $destinationCity->id,
            'province'  => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        $destinationCity->update([
            'name'      => trim($validated['name']),
            'province'  => isset($validated['province']) ? trim($validated['province']) : $destinationCity->province,
            'is_active' => $validated['is_active'] ?? $destinationCity->is_active,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Kota Tujuan berhasil diperbarui',
            'data'    => $destinationCity,
        ]);
    }

    public function destroy(DestinationCity $destinationCity): JsonResponse
    {
        $user = Auth::user();
        if (!$user || (!$user->hasRoleDirect('Admin') && !$user->hasRoleDirect('GA') && !$user->hasRoleDirect('admin') && !$user->hasRoleDirect('ga'))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Anda tidak memiliki hak akses untuk mengelola Master Data Kota Tujuan.',
            ], 403);
        }

        $destinationCity->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Kota Tujuan berhasil dihapus',
        ]);
    }
}
