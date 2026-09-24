<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GaTeamApprover;
use Illuminate\Http\Request;

class GaTeamApproverController extends Controller
{
    /**
     * Get list of GA Team Approvers.
     */
    public function index(Request $request)
    {
        $query = GaTeamApprover::query();

        // If 'active_only' is passed or by default for normal fetch
        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        $approvers = $query->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $approvers,
        ]);
    }

    /**
     * Store a new GA Team Approver.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $approver = GaTeamApprover::create([
            'name' => trim($validated['name']),
            'position' => isset($validated['position']) ? trim($validated['position']) : null,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Data Penyetujui GA Team berhasil ditambahkan!',
            'data' => $approver,
        ], 201);
    }

    /**
     * Update an existing GA Team Approver.
     */
    public function update(Request $request, $id)
    {
        $approver = GaTeamApprover::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $approver->update([
            'name' => trim($validated['name']),
            'position' => isset($validated['position']) ? trim($validated['position']) : null,
            'is_active' => $validated['is_active'] ?? $approver->is_active,
            'sort_order' => $validated['sort_order'] ?? $approver->sort_order,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Data Penyetujui GA Team berhasil diperbarui!',
            'data' => $approver,
        ]);
    }

    /**
     * Delete a GA Team Approver.
     */
    public function destroy($id)
    {
        $approver = GaTeamApprover::findOrFail($id);
        $approver->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Data Penyetujui GA Team berhasil dihapus!',
        ]);
    }

    /**
     * Toggle active status.
     */
    public function toggleActive($id)
    {
        $approver = GaTeamApprover::findOrFail($id);
        $approver->is_active = !$approver->is_active;
        $approver->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Status penyetujui berhasil diubah!',
            'data' => $approver,
        ]);
    }
}
