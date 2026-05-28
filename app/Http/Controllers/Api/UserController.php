<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    private function isAdmin(): bool
    {
        return Auth::user()->hasRole('Admin');
    }

    public function index(Request $request): JsonResponse
    {
        if (!Auth::user()->hasAnyRole(['Admin', 'GA'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $perPage = $request->query('per_page', 15);
        $search  = $request->query('search');
        $role    = $request->query('role');

        $query = User::with('roles');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        if ($role) {
            $query->role($role);
        }

        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status'     => 'success',
            'data'       => $users->map(fn($u) => $this->formatUser($u)),
            'pagination' => [
                'total'        => $users->total(),
                'per_page'     => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'from'         => $users->firstItem(),
                'to'           => $users->lastItem(),
            ],
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => ['required', Password::min(6)],
            'role'     => ['required', Rule::in(['Admin', 'GA', 'Approver', 'Employee', 'Driver'])],
            'rank'     => 'required_if:role,Approver|nullable|string|max:255',
            'department_id' => ['nullable', 'string', 'max:255', Rule::in(['IT','FA','HR&GA','PRODUCTION','QC','QA'])],
            'is_department_head' => 'boolean',
        ]);

        if ($validated['role'] === 'Approver' && empty($validated['rank'] ?? null)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Rank wajib diisi untuk role Approver',
            ], 422);
        }

        // If Approver or GA and marked as department head, department must be provided
        if (in_array($validated['role'], ['Approver', 'GA']) && !empty($validated['is_department_head'] ?? false) && empty($validated['department_id'] ?? null)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Department wajib dipilih jika menjadi Kepala Departemen',
            ], 422);
        }

        $user = User::create([
            'name'               => $validated['name'],
            'email'              => $validated['email'],
            'password'           => Hash::make($validated['password']),   // ← di-hash
            'rank'               => $validated['rank'] ?? null,
            'department_id'      => $validated['department_id'] ?? null,
            'is_department_head' => $validated['is_department_head'] ?? false,
        ]);

        $user->assignRole($validated['role']);

        return response()->json([
            'status'  => 'success',
            'message' => 'User berhasil dibuat',
            'data'    => $this->formatUser($user->load('roles')),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $this->formatUser($user->load('roles')),
        ], 200);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name'     => 'sometimes|required|string|max:255',
            'email'    => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => ['sometimes', Password::min(6)],
            'role'     => ['sometimes', Rule::in(['Admin', 'GA', 'Approver', 'Employee', 'Driver'])],
            'rank'     => 'required_if:role,Approver|nullable|string|max:255',
            'department_id' => ['nullable', 'string', 'max:255', Rule::in(['IT','FA','HR&GA','PRODUCTION','QC','QA'])],
            'is_department_head' => 'boolean',
        ]);

        $role = $validated['role'] ?? null;
        
        // Check if changing to Approver role without rank
        if ($role === 'Approver' && empty($validated['rank'] ?? null)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Rank wajib diisi untuk role Approver',
            ], 422);
        }

        // If Approver or GA and marked as department head, ensure department selected
        $currentRole = $role ?? ($user->getRoleNames()[0] ?? null);
        if (in_array($currentRole, ['Approver', 'GA']) && !empty($validated['is_department_head'] ?? false) && empty($validated['department_id'] ?? ($user->department_id ?? null))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Department wajib dipilih jika menjadi Kepala Departemen',
            ], 422);
        }

        unset($validated['role']);

        // Hash password jika ada perubahan
        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);   // ← di-hash
        }

        $user->update($validated);

        if ($role) {
            $user->syncRoles([$role]);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'User berhasil diperbarui',
            'data'    => $this->formatUser($user->fresh('roles')),
        ], 200);
    }

    public function destroy(User $user): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if ($user->id === Auth::id()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Tidak dapat menghapus akun sendiri',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'User berhasil dihapus',
        ], 200);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'rank'       => $user->rank,
            'department_id' => $user->department_id,
            'availability_status' => $user->availability_status,
            'is_department_head' => $user->is_department_head ?? false,
            'roles'      => $user->getRoleNames(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}