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
    private const VALID_DEPARTMENTS = [
        'Information and Technology',
        'Finance and Accounting',
        'HRD & GA',
        'Supply Chain',
        'Technical and Development',
        'Quality Assurance',
        'Quality Control',
        'Production',
        'Regulatory Affairs & PV',
        'Legal & Compliance',
        'Plant Management',
    ];

    private const CATEGORY_DEPARTMENT_MAP = [
        'HRD'                         => ['HRD & GA'],
        'GA'                          => ['HRD & GA'],
        'HRD&GA'                      => ['HRD & GA'],
        'HRD & GA'                    => ['HRD & GA'],
        'INFORMATION AND TECHNOLOGY'  => ['Information and Technology'],
        'FINANCE AND ACCOUNTING'      => ['Finance and Accounting'],
        'SUPPLY CHAIN'                => ['Supply Chain'],
        'TECHNICAL AND DEVELOPMENT'   => ['Technical and Development'],
        'QUALITY ASSURANCE'           => ['Quality Assurance'],
        'QUALITY CONTROL'             => ['Quality Control'],
        'PRODUCTION'                  => ['Production'],
        'REGULATORY AFFAIRS & PV'     => ['Regulatory Affairs & PV'],
        'LEGAL & COMPLIANCE'          => ['Legal & Compliance'],
        'PLANT MANAGEMENT'            => ['Plant Management'],
    ];

    private function isAdmin(): bool
    {
        $user = Auth::user();
        return $user && $user->hasRoleDirect('Admin');
    }

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $hasAccess = $user->hasRoleDirect(['Admin', 'GA']) || $user->isHrGaHead();

        if (!$hasAccess) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $perPage  = min((int) $request->query('per_page', 15), 1000);
        $search   = $request->query('search');
        $role     = $request->query('role');
        $category = $request->query('category');

        $query = User::with(['roles', 'department']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        if ($category) {
            $categoryKey = strtoupper(trim($category));

            if ($categoryKey === 'ALL' || $categoryKey === 'ALL USER') {
                // no additional filter, show all users
            } elseif ($categoryKey === 'APPROVER' || $categoryKey === 'APPROVER / KEPALA DEPARTEMEN') {
                $query->where(function ($q) {
                    $q->whereHas('roles', function ($q) {
                        $q->where('name', 'Approver');
                    })->orWhere('is_department_head', true);
                });
            } elseif ($categoryKey === 'GA') {
                $query->where(function ($q) {
                    $q->whereHas('roles', function ($q) {
                        $q->where('name', 'GA');
                    })->orWhere(function ($q) {
                        $gaDeptIds = \App\Models\Department::whereIn('name', self::CATEGORY_DEPARTMENT_MAP['GA'])->pluck('id')->toArray();
                        $q->whereIn('department_id', $gaDeptIds)
                          ->where('is_department_head', true);
                    });
                });
            } else {
                $departments = self::CATEGORY_DEPARTMENT_MAP[$categoryKey] ?? [$categoryKey];
                $deptIds = \App\Models\Department::whereIn('name', $departments)->pluck('id')->toArray();
                $query->whereIn('department_id', $deptIds);
            }
        }

        if ($role) {
            $query->whereHas('roles', function ($q) use ($role) {
                $q->where('name', $role);
            });
        }

        $status = $request->query('status');
        if ($status) {
            $upperStatus = strtoupper($status);
            if ($upperStatus === 'AVAILABLE') {
                $query->where(function ($q) {
                    $q->where('availability_status', 'available')
                      ->orWhere(function ($sub) {
                          $sub->whereIn('availability_status', ['assigned', 'on_trip'])
                              ->whereNotExists(function ($querySub) {
                                  $querySub->select(\Illuminate\Support\Facades\DB::raw(1))
                                      ->from('requests')
                                      ->whereColumn('requests.driver_id', 'users.id')
                                      ->whereIn('requests.status', [\App\Enums\RequestStatus::DRIVER_ASSIGNED->value, \App\Enums\RequestStatus::ON_GOING->value])
                                      ->where(function ($q2) {
                                          $nowStr = now()->toDateTimeString();
                                          $q2->where('requests.status', \App\Enums\RequestStatus::ON_GOING->value)
                                             ->orWhere(function ($q3) use ($nowStr) {
                                                 $q3->where('requests.start_time', '<=', $nowStr)
                                                    ->where('requests.end_time', '>=', $nowStr);
                                             });
                                      });
                              });
                      });
                });
            } elseif ($upperStatus === 'ON DUTY' || $upperStatus === 'ON_DUTY') {
                $query->whereIn('availability_status', ['assigned', 'on_trip'])
                      ->whereExists(function ($querySub) {
                          $querySub->select(\Illuminate\Support\Facades\DB::raw(1))
                              ->from('requests')
                              ->whereColumn('requests.driver_id', 'users.id')
                              ->whereIn('requests.status', [\App\Enums\RequestStatus::DRIVER_ASSIGNED->value, \App\Enums\RequestStatus::ON_GOING->value])
                              ->where(function ($q2) {
                                  $nowStr = now()->toDateTimeString();
                                  $q2->where('requests.status', \App\Enums\RequestStatus::ON_GOING->value)
                                     ->orWhere(function ($q3) use ($nowStr) {
                                         $q3->where('requests.start_time', '<=', $nowStr)
                                            ->where('requests.end_time', '>=', $nowStr);
                                     });
                              });
                      });
            } elseif ($upperStatus === 'OFF DUTY' || $upperStatus === 'OFF_DUTY') {
                $query->where(function ($q) {
                    $q->where('availability_status', 'unavailable')
                      ->orWhereNull('availability_status');
                });
            }
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

        $request->merge([
            'is_department_head' => $request->boolean('is_department_head'),
        ]);

        $validated = $request->validate([
            'nik'      => ['nullable', 'string', 'max:50', 'unique:users,nik'],
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => ['required', Password::min(6)],
            'role'     => ['required', Rule::in(['Admin', 'GA', 'Approver', 'Employee', 'Driver'])],
            'rank'     => 'required_if:role,Approver|nullable|string|max:255',
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'is_department_head' => 'boolean',
            'sim_a_photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:5120'],
        ]);

        if ($validated['role'] === 'Approver' && empty($validated['rank'] ?? null)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Rank wajib diisi untuk role Approver',
            ], 422);
        }

        if ($validated['role'] === 'Driver' && !$request->hasFile('sim_a_photo')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Foto SIM A wajib untuk role Driver',
            ], 422);
        }

        // If Approver or GA and marked as department head, department must be provided
        if (in_array($validated['role'], ['Approver', 'GA']) && !empty($validated['is_department_head'] ?? false) && empty($validated['department_id'] ?? null)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Department wajib dipilih jika menjadi Kepala Departemen',
            ], 422);
        }

        $data = [
            'nik'                => $validated['nik'] ?? null,
            'name'               => $validated['name'],
            'email'              => $validated['email'],
            'password'           => Hash::make($validated['password']),   // ← di-hash
            'rank'               => $validated['rank'] ?? null,
            'department_id'      => $validated['department_id'] ?? null,
            'is_department_head' => $validated['is_department_head'] ?? false,
        ];

        if ($request->hasFile('sim_a_photo')) {
            $data['sim_a_photo'] = $request->file('sim_a_photo')->store('users/sim', 'public');
        }

        $user = User::create($data);

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

        $request->merge([
            'is_department_head' => $request->boolean('is_department_head'),
        ]);

        $validated = $request->validate([
            'nik'      => ['nullable', 'string', 'max:50', Rule::unique('users', 'nik')->ignore($user->id)],
            'name'     => 'sometimes|required|string|max:255',
            'email'    => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => ['sometimes', Password::min(6)],
            'role'     => ['sometimes', Rule::in(['Admin', 'GA', 'Approver', 'Employee', 'Driver'])],
            'rank'     => 'required_if:role,Approver|nullable|string|max:255',
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'is_department_head' => 'boolean',
            'sim_a_photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:5120'],
        ]);

        $role = $validated['role'] ?? null;
        $targetRole = $role ?? ($user->getRoleNames()[0] ?? null);

        if ($targetRole === 'Driver' && !$request->hasFile('sim_a_photo') && !$user->sim_a_photo) {
            return response()->json([
                'status' => 'error',
                'message' => 'Foto SIM A wajib untuk role Driver',
            ], 422);
        }
        
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

        if ($request->hasFile('sim_a_photo')) {
            $validated['sim_a_photo'] = $request->file('sim_a_photo')->store('users/sim', 'public');
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

    public function toggleActive(User $user): JsonResponse
    {
        $currentUser = Auth::user();
        if (!$currentUser->hasRoleDirect(['Admin', 'GA']) && !$currentUser->isHrGaHead()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $user->update([
            'is_active' => !$user->is_active,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Status aktif user berhasil diubah.',
            'data'    => $this->formatUser($user->fresh('roles')),
        ], 200);
    }

    public function toggleRequest(User $user): JsonResponse
    {
        $currentUser = Auth::user();
        if (!$currentUser->hasRoleDirect(['Admin', 'GA']) && !$currentUser->isHrGaHead()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $user->update([
            'can_request' => !$user->can_request,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Hak akses request user berhasil diubah.',
            'data'    => $this->formatUser($user->fresh('roles')),
        ], 200);
    }

    public function updateDriverDuty(User $user, Request $request): JsonResponse
    {
        $currentUser = Auth::user();
        if (!$currentUser->hasRoleDirect(['Admin', 'GA']) && !$currentUser->isHrGaHead()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if (!$user->hasRoleDirect('Driver')) {
            return response()->json(['status' => 'error', 'message' => 'User ini bukan merupakan Driver.'], 422);
        }

        $validated = $request->validate([
            'availability_status' => 'nullable|string|in:available,unavailable',
            'availability_start'  => 'nullable|string',
            'availability_end'    => 'nullable|string',
        ]);

        $user->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Tugas driver berhasil diperbarui.',
            'data'    => $this->formatUser($user->fresh('roles')),
        ], 200);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'         => $user->id,
            'nik'        => $user->nik,
            'name'       => $user->name,
            'email'      => $user->email,
            'rank'       => $user->rank,
            'department_id' => $user->department_id,
            'department_name' => $user->department?->name,
            'availability_status' => $user->availability_status,
            'is_department_head' => $user->is_department_head ?? false,
            'sim_a_photo_url' => $user->sim_a_photo ? url('storage/' . $user->sim_a_photo) : null,
            'is_active'  => $user->is_active ?? false,
            'can_request' => $user->can_request ?? false,
            'availability_start' => $user->availability_start,
            'availability_end' => $user->availability_end,
            'roles'      => $user->getRoleNames(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }

    public function search(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if (!$user->hasRoleDirect(['Admin', 'GA', 'Approver'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $search = $request->query('search');

        $query = User::with('department');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        $users = $query->orderBy('name', 'asc')->limit(15)->get();

        return response()->json([
            'status' => 'success',
            'data'   => $users->map(fn($u) => [
                'id'            => $u->id,
                'nik'           => $u->nik,
                'name'          => $u->name,
                'email'         => $u->email,
                'department_id' => $u->department_id,
                'department_name' => $u->department?->name,
            ]),
        ]);
    }
}