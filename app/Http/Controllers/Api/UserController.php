<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use App\Enums\RequestStatus;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

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

        $query = User::with(['roles', 'department'])
            ->where(function ($q) {
                $q->whereNull('nik')->orWhere('nik', 'not like', '%_del_%');
            })
            ->where(function ($q) {
                $q->whereNull('email')->orWhere('email', 'not like', '%_del_%');
            });

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
        try {
            $currentUser = Auth::user();
            $isAuthorized = $currentUser && (
                $currentUser->hasRoleDirect(['Admin', 'GA', 'admin', 'ga']) ||
                $currentUser->isHrGaHead() ||
                ($currentUser->isHrGaDepartment() && $currentUser->hasRoleDirect('Approver'))
            );

            if (!$isAuthorized) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
            }

            // Normalize department input
            $deptInput = $request->input('department_id') ?? $request->input('department') ?? $request->input('department_name');
            if ($deptInput !== null && $deptInput !== '') {
                if (is_numeric($deptInput)) {
                    $request->merge(['department_id' => (int) $deptInput]);
                } else {
                    $deptId = \App\Models\Department::where('name', trim($deptInput))->value('id');
                    if ($deptId) {
                        $request->merge(['department_id' => $deptId]);
                    }
                }
            }

            $request->merge([
                'is_department_head' => $request->boolean('is_department_head'),
            ]);

            $validated = $request->validate([
                'nik'      => ['nullable', 'string', 'max:50', Rule::unique('users', 'nik')->whereNull('deleted_at')],
                'name'     => 'required|string|max:255',
                'email'    => ['required', 'email', Rule::unique('users', 'email')->whereNull('deleted_at')],
                'password' => ['required', Password::min(6)],
                'role'     => ['required', Rule::in(['Admin', 'GA', 'Approver', 'Employee', 'Driver', 'admin', 'ga', 'approver', 'employee', 'driver'])],
                'rank'     => 'required_if:role,Approver|nullable|string|max:255',
                'department_id' => ['nullable', 'integer', 'exists:departments,id'],
                'is_department_head' => 'boolean',
                'sim_a_photo' => ['nullable'],
            ]);

            $role = ucfirst(strtolower($validated['role']));
            if ($role === 'Ga') {
                $role = 'GA';
            }

            if ($role === 'Approver' && empty($validated['rank'] ?? null)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Rank wajib diisi untuk role Approver',
                ], 422);
            }

            // SIM A photo is optional for Driver role
            // Photo will be stored if provided

            if (in_array($role, ['Approver', 'GA']) && !empty($validated['is_department_head'] ?? false) && empty($validated['department_id'] ?? null)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Department wajib dipilih jika menjadi Kepala Departemen',
                ], 422);
            }

            $data = [
                'nik'                => $validated['nik'] ?? null,
                'name'               => $validated['name'],
                'email'              => $validated['email'],
                'password'           => Hash::make($validated['password']),
                'rank'               => $validated['rank'] ?? null,
                'department_id'      => $validated['department_id'] ?? null,
                'is_department_head' => $validated['is_department_head'] ?? false,
                'location'           => 'Pandaan Head Office',
                'is_active'          => true,
                'can_request'        => true,
            ];

            $simFile = $request->file('sim_a_photo') ?? $request->file('sim_photo') ?? $request->file('photo');
            if ($simFile) {
                $simPath = $this->storePublicFileSafely($simFile, 'users/sim');
                if ($simPath) {
                    $data['sim_a_photo'] = $simPath;
                }
            }

            $user = User::create($data);

            $this->assignRoleSafely($user, $role);

            return response()->json([
                'status'  => 'success',
                'message' => 'User berhasil dibuat',
                'data'    => $this->formatUser($user->load('roles')),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => collect($e->errors())->flatten()->first() ?? 'Data yang dimasukkan tidak valid.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Create User Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal membuat user: ' . $e->getMessage(),
            ], 500);
        }
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
        try {
            $currentUser = Auth::user();
            $isGaOrHrHead = $currentUser && (
                $currentUser->hasRoleDirect(['Admin', 'GA', 'admin', 'ga']) ||
                $currentUser->isHrGaHead() ||
                ($currentUser->isHrGaDepartment() && $currentUser->hasRoleDirect('Approver'))
            );

            $isDutyStatusOnly = ($request->has('availability_status') || $request->has('status')) &&
                !$request->hasAny(['name', 'email', 'password', 'role', 'nik', 'rank', 'department_id', 'sim_a_photo']);

            if (!$isGaOrHrHead && !$this->isAdmin()) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
            }

            if ($isDutyStatusOnly) {
                return $this->updateDriverDuty($user, $request);
            }

            // Normalize department input
            $deptInput = $request->input('department_id') ?? $request->input('department') ?? $request->input('department_name');
            if ($deptInput !== null && $deptInput !== '') {
                if (is_numeric($deptInput)) {
                    $request->merge(['department_id' => (int) $deptInput]);
                } else {
                    $deptId = \App\Models\Department::where('name', trim($deptInput))->value('id');
                    if ($deptId) {
                        $request->merge(['department_id' => $deptId]);
                    }
                }
            }

            $request->merge([
                'is_department_head' => $request->boolean('is_department_head'),
            ]);

            $validated = $request->validate([
                'nik'      => ['nullable', 'string', 'max:50', Rule::unique('users', 'nik')->ignore($user->id)->whereNull('deleted_at')],
                'name'     => 'sometimes|required|string|max:255',
                'email'    => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at')],
                'password' => ['sometimes', Password::min(6)],
                'role'     => ['sometimes', Rule::in(['Admin', 'GA', 'Approver', 'Employee', 'Driver', 'admin', 'ga', 'approver', 'employee', 'driver'])],
                'rank'     => 'required_if:role,Approver|nullable|string|max:255',
                'department_id' => ['nullable', 'integer', 'exists:departments,id'],
                'is_department_head' => 'boolean',
                'sim_a_photo' => ['nullable'],
            ]);

            $role = isset($validated['role']) ? ucfirst(strtolower($validated['role'])) : null;
            if ($role === 'Ga') {
                $role = 'GA';
            }
            $targetRole = $role ?? ($user->getRoleNames()[0] ?? null);

            // SIM A photo is optional for Driver role
            // Photo will be stored if provided, existing photo preserved if not

            if ($role === 'Approver' && empty($validated['rank'] ?? null)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Rank wajib diisi untuk role Approver',
                ], 422);
            }

            $currentRole = $role ?? ($user->getRoleNames()[0] ?? null);
            if (in_array($currentRole, ['Approver', 'GA']) && !empty($validated['is_department_head'] ?? false) && empty($validated['department_id'] ?? ($user->department_id ?? null))) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Department wajib dipilih jika menjadi Kepala Departemen',
                ], 422);
            }

            unset($validated['role']);

            if (!empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }

            $simFile = $request->file('sim_a_photo') ?? $request->file('sim_photo') ?? $request->file('photo');
            if ($simFile) {
                $simPath = $this->storePublicFileSafely($simFile, 'users/sim');
                if ($simPath) {
                    $validated['sim_a_photo'] = $simPath;
                }
            }

            $user->update($validated);

            if ($role) {
                $this->assignRoleSafely($user, $role);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'User berhasil diperbarui',
                'data'    => $this->formatUser($user->fresh('roles')),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => collect($e->errors())->flatten()->first() ?? 'Data yang dimasukkan tidak valid.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Update User Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal memperbarui user: ' . $e->getMessage(),
            ], 500);
        }
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

        try {
            DB::transaction(function () use ($user) {
                $targetId = $user->id;

                // Delete or nullify all foreign key constraints before deleting user
                if (Schema::hasTable('assignments')) {
                    DB::table('assignments')->where('driver_id', $targetId)->delete();
                    DB::table('assignments')->where('assigned_by', $targetId)->update(['assigned_by' => null]);
                }

                if (Schema::hasTable('driver_assignments')) {
                    DB::table('driver_assignments')->where('driver_id', $targetId)->delete();
                }

                if (Schema::hasTable('vehicle_requests')) {
                    DB::table('vehicle_requests')->where('user_id', $targetId)->update(['user_id' => null]);
                    DB::table('vehicle_requests')->where('driver_id', $targetId)->update(['driver_id' => null]);
                    DB::table('vehicle_requests')->where('approver_id', $targetId)->update(['approver_id' => null]);
                }

                if (Schema::hasTable('passengers')) {
                    DB::table('passengers')->where('user_id', $targetId)->delete();
                }

                if (Schema::hasTable('notifications')) {
                    DB::table('notifications')->where('user_id', $targetId)->delete();
                }

                if (Schema::hasTable('audit_logs')) {
                    DB::table('audit_logs')->where('user_id', $targetId)->delete();
                }

                if (Schema::hasTable('model_has_roles')) {
                    DB::table('model_has_roles')->where('model_id', $targetId)->delete();
                }

                if (Schema::hasTable('model_has_permissions')) {
                    DB::table('model_has_permissions')->where('model_id', $targetId)->delete();
                }

                // Hard delete user from users table
                DB::table('users')->where('id', $targetId)->delete();
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'User berhasil dihapus permanen dari sistem',
            ], 200);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('User Delete Error: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal menghapus user: ' . $e->getMessage(),
            ], 500);
        }
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
        if (!$currentUser) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $hasAccess = $currentUser->hasRoleDirect(['Admin', 'GA', 'admin', 'ga'])
            || $currentUser->isHrGaHead()
            || ($currentUser->isHrGaDepartment() && $currentUser->hasRoleDirect('Approver'));

        if (!$hasAccess) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if (!$user->hasRoleDirect(['Driver', 'driver'])) {
            return response()->json(['status' => 'error', 'message' => 'User ini bukan merupakan Driver.'], 422);
        }

        $statusInput = $request->input('availability_status') ?? $request->input('status');
        if ($statusInput) {
            $request->merge(['availability_status' => $statusInput]);
        }

        $validated = $request->validate([
            'availability_status' => 'nullable|string|in:available,unavailable',
            'availability_start'  => 'nullable|string',
            'availability_end'    => 'nullable|string',
        ]);

        if (isset($validated['availability_status']) && $validated['availability_status'] === 'unavailable') {
            $conflict = \App\Models\Request::where('driver_id', $user->id)
                ->whereIn('status', [
                    RequestStatus::WAITING_DRIVER,
                    RequestStatus::DRIVER_ASSIGNED,
                    RequestStatus::ON_GOING,
                ])
                ->whereDate('start_time', '>=', now()->toDateString())
                ->orderBy('start_time', 'asc')
                ->first();

            if ($conflict) {
                $formattedDate = date('d-m-Y', strtotime($conflict->start_time));
                return response()->json([
                    'status'  => 'error',
                    'message' => "Driver {$user->name} memiliki jadwal penugasan aktif pada tanggal {$formattedDate} (Request #{$conflict->id}). Silakan ubah driver pada request tersebut terlebih dahulu."
                ], 422);
            }
        }

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
            'avatar_url'     => $user->avatar ? url('storage/' . $user->avatar) : null,
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

        if (!$user->hasRoleDirect(['Admin', 'GA', 'Approver', 'Employee', 'Driver'])) {
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

    private function assignRoleSafely(User $user, string $roleName): void
    {
        $roleName = ucfirst(strtolower($roleName));
        if ($roleName === 'Ga') {
            $roleName = 'GA';
        }

        $allowedRoles = [
            'Admin'    => 'Admin',
            'GA'       => 'GA',
            'Approver' => 'Approver',
            'Employee' => 'Employee',
            'Driver'   => 'Driver',
        ];
        $standardName = $allowedRoles[$roleName] ?? $roleName;

        Role::firstOrCreate([
            'name'       => $standardName,
            'guard_name' => 'sanctum',
        ]);

        Role::firstOrCreate([
            'name'       => $standardName,
            'guard_name' => 'web',
        ]);

        $user->syncRoles([$standardName]);
    }

    private function storePublicFileSafely(\Illuminate\Http\UploadedFile $file, string $folder): ?string
    {
        try {
            $originalName = $file->getClientOriginalName();
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'jpg');
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'])) {
                $ext = 'jpg';
            }

            $filename = time() . '_' . uniqid() . '.' . $ext;
            $relativeDir = trim($folder, '/');
            $targetDir = storage_path('app/public/' . $relativeDir);

            if (!file_exists($targetDir)) {
                @mkdir($targetDir, 0777, true);
            }

            $targetPath = $targetDir . '/' . $filename;

            $success = false;
            if ($file->getRealPath()) {
                $success = @move_uploaded_file($file->getRealPath(), $targetPath) || @copy($file->getRealPath(), $targetPath);
            }

            if (!$success) {
                $storedPath = $file->store($relativeDir, 'public');
                return $storedPath ?: null;
            }

            return $relativeDir . '/' . $filename;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("File Storage Error ({$folder}): " . $e->getMessage());
            try {
                return $file->store($folder, 'public');
            } catch (\Throwable $ex) {
                \Illuminate\Support\Facades\Log::error("Laravel store fallback failed: " . $ex->getMessage());
                return null;
            }
        }
    }
}