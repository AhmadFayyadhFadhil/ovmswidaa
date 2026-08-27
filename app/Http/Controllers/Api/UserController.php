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

        $simStatusFilter = $request->query('sim_status');
        if ($simStatusFilter) {
            $today = now()->toDateString();
            $h30 = now()->addDays(30)->toDateString();
            if ($simStatusFilter === 'expiring_soon' || $simStatusFilter === 'H-30' || $simStatusFilter === 'h-30') {
                $query->whereNotNull('sim_expiry_date')->whereBetween('sim_expiry_date', [$today, $h30]);
            } elseif ($simStatusFilter === 'expired') {
                $query->whereNotNull('sim_expiry_date')->where('sim_expiry_date', '<', $today);
            } elseif ($simStatusFilter === 'valid' || $simStatusFilter === 'active') {
                $query->whereNotNull('sim_expiry_date')->where('sim_expiry_date', '>', $h30);
            }
        }

        $excludeRequestId = $request->query('exclude_busy_for_request_id');
        if ($excludeRequestId) {
            $targetRequest = \App\Models\Request::find($excludeRequestId);
            if ($targetRequest && $targetRequest->start_time) {
                $startTime = $targetRequest->start_time;
                $endTime = $targetRequest->end_time;
                if (!$endTime) {
                    $duration = $targetRequest->estimated_duration ?: 3;
                    $endTime = (clone $startTime)->addHours($duration);
                }

                $overlappingRequestIds = \App\Models\Request::where('id', '!=', $targetRequest->id)
                    ->whereNotIn('status', [
                        \App\Enums\RequestStatus::REJECTED,
                        \App\Enums\RequestStatus::COMPLETED,
                        \App\Enums\RequestStatus::CANCELLED
                    ])
                    ->where(function ($q) use ($startTime, $endTime) {
                        $q->where(function ($sub) use ($startTime, $endTime) {
                            $sub->where('start_time', '<', $endTime)
                                ->where('end_time', '>', $startTime);
                        });
                    })
                    ->pluck('id');

                $busyDriverIds = [];

                $busyFromRequests = \App\Models\Request::whereIn('id', $overlappingRequestIds)
                    ->whereNotNull('driver_id')
                    ->pluck('driver_id')
                    ->toArray();
                $busyDriverIds = array_merge($busyDriverIds, $busyFromRequests);

                $busyFromTrips = \App\Models\OperationalTrip::whereIn('request_id', $overlappingRequestIds)
                    ->where('status', '!=', 'cancelled')
                    ->whereNotNull('driver_id')
                    ->pluck('driver_id')
                    ->toArray();
                $busyDriverIds = array_merge($busyDriverIds, $busyFromTrips);

                $busyFromItineraries = \App\Models\RequestItinerary::whereIn('request_id', $overlappingRequestIds)
                    ->whereNotNull('driver_id')
                    ->pluck('driver_id')
                    ->toArray();
                $busyDriverIds = array_merge($busyDriverIds, $busyFromItineraries);

                $busyDriverIds = array_unique(array_filter($busyDriverIds));

                if (!empty($busyDriverIds)) {
                    $query->whereNotIn('id', $busyDriverIds);
                }
            }
        } elseif ($request->query('target_start_time') && $request->query('target_end_time')) {
            try {
                $startTime = \Carbon\Carbon::parse($request->query('target_start_time'));
                $endTime = \Carbon\Carbon::parse($request->query('target_end_time'));

                $overlappingRequestIds = \App\Models\Request::whereNotIn('status', [
                        \App\Enums\RequestStatus::REJECTED,
                        \App\Enums\RequestStatus::COMPLETED,
                        \App\Enums\RequestStatus::CANCELLED
                    ])
                    ->where(function ($q) use ($startTime, $endTime) {
                        $q->where('start_time', '<', $endTime)
                          ->where('end_time', '>', $startTime);
                    })
                    ->pluck('id');

                $busyDriverIds = \App\Models\Request::whereIn('id', $overlappingRequestIds)
                    ->whereNotNull('driver_id')
                    ->pluck('driver_id')
                    ->toArray();

                $busyDriverIds = array_unique(array_filter($busyDriverIds));

                if (!empty($busyDriverIds)) {
                    $query->whereNotIn('id', $busyDriverIds);
                }
            } catch (\Throwable $e) {}
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

            $roleInput = ucfirst(strtolower($request->input('role', '')));
            $isDeptHead = $request->has('is_department_head')
                ? $request->boolean('is_department_head')
                : (in_array($roleInput, ['Approver', 'Ga', 'GA']) ? true : false);

            $request->merge([
                'is_department_head' => $isDeptHead,
            ]);

            $validated = $request->validate([
                'nik'             => ['nullable', 'string', 'max:50', Rule::unique('users', 'nik')],
                'sim_number'      => ['nullable', 'string', 'max:50'],
                'sim_type'        => ['nullable', 'string', 'max:50'],
                'sim_expiry_date' => ['nullable', 'date'],
                'name'            => 'required|string|max:255',
                'email'           => ['required', 'email', Rule::unique('users', 'email')],
                'password'        => ['required', Password::min(6)],
                'role'            => ['required', Rule::in(['Admin', 'GA', 'Approver', 'Employee', 'Driver', 'admin', 'ga', 'approver', 'employee', 'driver'])],
                'rank'            => 'required_if:role,Approver|nullable|string|max:255',
                'department_id'   => ['nullable', 'integer', 'exists:departments,id'],
                'is_department_head' => 'boolean',
                'sim_a_photo'     => ['nullable'],
            ]);

            $role = ucfirst(strtolower($validated['role']));
            if ($role === 'Ga') {
                $role = 'GA';
            }

            // Guard: Non-Admin users (like GA) cannot create Admin accounts
            if ($role === 'Admin' && !$this->isAdmin()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Hanya Superadmin yang berhak membuat akun dengan role Admin.',
                ], 403);
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
                'sim_number'         => $validated['sim_number'] ?? null,
                'sim_type'           => $validated['sim_type'] ?? ($role === 'Driver' ? 'SIM A' : null),
                'sim_expiry_date'    => $validated['sim_expiry_date'] ?? null,
                'name'               => $validated['name'],
                'email'              => $validated['email'],
                'password'           => $validated['password'],
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
        $currentUser = Auth::user();
        $isAuthorized = $currentUser && (
            $currentUser->hasRoleDirect(['Admin', 'GA', 'admin', 'ga']) ||
            $currentUser->isHrGaHead() ||
            ($currentUser->isHrGaDepartment() && $currentUser->hasRoleDirect('Approver'))
        );

        if (!$isAuthorized) {
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

            // Guard: Non-Admin users cannot edit an Admin user's profile
            if ($user->hasRoleDirect('Admin') && !$this->isAdmin()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Hanya Superadmin yang berhak mengedit akun Admin.',
                ], 403);
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

            $roleInput = ucfirst(strtolower($request->input('role', $user->getRoleNames()[0] ?? '')));
            $isDeptHead = $request->has('is_department_head')
                ? $request->boolean('is_department_head')
                : (in_array($roleInput, ['Approver', 'Ga', 'GA']) ? true : $user->is_department_head);

            $request->merge([
                'is_department_head' => $isDeptHead,
            ]);

            $validated = $request->validate([
                'nik'             => ['nullable', 'string', 'max:50', Rule::unique('users', 'nik')->ignore($user->id)],
                'sim_number'      => ['nullable', 'string', 'max:50'],
                'sim_type'        => ['nullable', 'string', 'max:50'],
                'sim_expiry_date' => ['nullable'],
                'name'            => 'sometimes|required|string|max:255',
                'email'           => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
                'password'        => ['sometimes', Password::min(6)],
                'role'            => ['sometimes', Rule::in(['Admin', 'GA', 'Approver', 'Employee', 'Driver', 'admin', 'ga', 'approver', 'employee', 'driver'])],
                'rank'            => 'required_if:role,Approver|nullable|string|max:255',
                'department_id'   => ['nullable', 'integer', 'exists:departments,id'],
                'is_department_head' => 'boolean',
                'sim_a_photo'     => ['nullable'],
            ]);

            $role = isset($validated['role']) ? ucfirst(strtolower($validated['role'])) : null;
            if ($role === 'Ga') {
                $role = 'GA';
            }

            // Guard: Non-Admin users cannot assign the Admin role
            if ($role === 'Admin' && !$this->isAdmin()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Hanya Superadmin yang berhak menetapkan role Admin.',
                ], 403);
            }

            $targetRole = $role ?? ($user->getRoleNames()[0] ?? null);

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
        $currentUser = Auth::user();
        $isAuthorized = $currentUser && (
            $currentUser->hasRoleDirect(['Admin', 'GA', 'admin', 'ga']) ||
            $currentUser->isHrGaHead() ||
            ($currentUser->isHrGaDepartment() && $currentUser->hasRoleDirect('Approver'))
        );

        if (!$isAuthorized) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if ($user->id === Auth::id()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Tidak dapat menghapus akun sendiri',
            ], 422);
        }

        // Guard: Non-Admin users cannot delete Admin accounts
        if ($user->hasRoleDirect('Admin') && !$this->isAdmin()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Hanya Superadmin yang berhak menghapus akun Admin.',
            ], 403);
        }

        // Guard: Cannot delete user involved in active trips (ON_GOING or DRIVER_ASSIGNED)
        $hasActiveTrips = \App\Models\Request::where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('driver_id', $user->id);
            })
            ->whereIn('status', [RequestStatus::DRIVER_ASSIGNED->value, RequestStatus::ON_GOING->value])
            ->exists();

        if ($hasActiveTrips) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Tidak dapat menghapus user ini karena sedang terlibat dalam perjalanan aktif.',
            ], 422);
        }

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::transaction(function () use ($user) {
                $targetId = $user->id;

                // 1. Get all request IDs created by this user
                $userRequestIds = [];
                if (Schema::hasTable('requests')) {
                    $userRequestIds = DB::table('requests')->where('user_id', $targetId)->pluck('id')->toArray();
                }

                // 2. Delete child records referencing those request IDs
                if (!empty($userRequestIds)) {
                    if (Schema::hasTable('assignments')) {
                        DB::table('assignments')->whereIn('request_id', $userRequestIds)->delete();
                    }
                    if (Schema::hasTable('operational_trips')) {
                        DB::table('operational_trips')->whereIn('request_id', $userRequestIds)->delete();
                    }
                    if (Schema::hasTable('request_itineraries')) {
                        DB::table('request_itineraries')->whereIn('request_id', $userRequestIds)->delete();
                    }
                    if (Schema::hasTable('request_approvals')) {
                        DB::table('request_approvals')->whereIn('request_id', $userRequestIds)->delete();
                    }
                    if (Schema::hasTable('passengers')) {
                        DB::table('passengers')->whereIn('request_id', $userRequestIds)->delete();
                    }
                    DB::table('requests')->whereIn('id', $userRequestIds)->delete();
                }

                // 3. Delete driver & assignment records directly referencing this user
                if (Schema::hasTable('assignments')) {
                    DB::table('assignments')->where('driver_id', $targetId)->orWhere('assigned_by', $targetId)->delete();
                }

                if (Schema::hasTable('driver_assignments')) {
                    DB::table('driver_assignments')->where('driver_id', $targetId)->delete();
                }

                if (Schema::hasTable('operational_trips')) {
                    DB::table('operational_trips')->where('driver_id', $targetId)->delete();
                }

                if (Schema::hasTable('request_itineraries')) {
                    DB::table('request_itineraries')->where('driver_id', $targetId)->delete();
                }

                if (Schema::hasTable('requests')) {
                    DB::table('requests')->where('user_id', $targetId)->delete();
                    DB::table('requests')->where('driver_id', $targetId)->update(['driver_id' => null]);
                    DB::table('requests')->where('approver_id', $targetId)->update(['approver_id' => null]);
                    if (Schema::hasColumn('requests', 'cancelled_by')) {
                        DB::table('requests')->where('cancelled_by', $targetId)->update(['cancelled_by' => null]);
                    }
                }

                if (Schema::hasTable('request_approvals')) {
                    DB::table('request_approvals')->where('approver_id', $targetId)->delete();
                }

                if (Schema::hasTable('passengers')) {
                    DB::table('passengers')->where('user_id', $targetId)->delete();
                }

                if (Schema::hasTable('user_notification_states')) {
                    DB::table('user_notification_states')->where('user_id', $targetId)->delete();
                }

                if (Schema::hasTable('notifications')) {
                    DB::table('notifications')->where('user_id', $targetId)->delete();
                }

                if (Schema::hasTable('audit_logs')) {
                    DB::table('audit_logs')->where('user_id', $targetId)->delete();
                }

                if (Schema::hasTable('personal_access_tokens')) {
                    DB::table('personal_access_tokens')->where('tokenable_id', $targetId)->delete();
                }

                if (Schema::hasTable('model_has_roles')) {
                    DB::table('model_has_roles')->where('model_id', $targetId)->delete();
                }

                if (Schema::hasTable('model_has_permissions')) {
                    DB::table('model_has_permissions')->where('model_id', $targetId)->delete();
                }

                if (Schema::hasTable('password_reset_tokens') && !empty($user->email)) {
                    DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                }

                // 4. Hard delete user from users table
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
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
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
                    RequestStatus::WAITING_DRIVER->value,
                    RequestStatus::DRIVER_ASSIGNED->value,
                    RequestStatus::ON_GOING->value,
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
        $computedStatus = $user->availability_status;
        if (in_array(strtolower((string)$computedStatus), ['assigned', 'on_trip', 'busy'])) {
            $nowStr = now()->toDateTimeString();
            $hasActiveOngoingTrip = \Illuminate\Support\Facades\DB::table('requests')
                ->where('driver_id', $user->id)
                ->whereIn('status', [\App\Enums\RequestStatus::DRIVER_ASSIGNED->value, \App\Enums\RequestStatus::ON_GOING->value])
                ->where(function ($q) use ($nowStr) {
                    $q->where('status', \App\Enums\RequestStatus::ON_GOING->value)
                      ->orWhere(function ($q3) use ($nowStr) {
                          $q3->where('start_time', '<=', $nowStr)
                             ->where('end_time', '>=', $nowStr);
                      });
                })->exists();

            if (!$hasActiveOngoingTrip) {
                $computedStatus = 'available';
            }
        }

        $simStatus = 'not_set';
        $simExpiryDaysLeft = null;
        $simExpiryDateStr = null;

        if ($user->sim_expiry_date) {
            $simExpiryDateStr = $user->sim_expiry_date instanceof \DateTimeInterface 
                ? $user->sim_expiry_date->format('Y-m-d') 
                : date('Y-m-d', strtotime((string)$user->sim_expiry_date));
            
            $today = \Carbon\Carbon::today();
            $expiry = \Carbon\Carbon::parse($user->sim_expiry_date)->startOfDay();
            $simExpiryDaysLeft = (int) $today->diffInDays($expiry, false);

            if ($simExpiryDaysLeft < 0) {
                $simStatus = 'expired';
            } elseif ($simExpiryDaysLeft <= 30) {
                $simStatus = 'expiring_soon';
            } else {
                $simStatus = 'valid';
            }
        }

        return [
            'id'                  => $user->id,
            'nik'                 => $user->nik,
            'sim_number'          => $user->sim_number,
            'sim_type'            => $user->sim_type ?? 'SIM A',
            'sim_expiry_date'     => $simExpiryDateStr,
            'sim_status'          => $simStatus,
            'sim_expiry_days_left' => $simExpiryDaysLeft,
            'name'                => $user->name,
            'email'               => $user->email,
            'rank'                => $user->rank,
            'department_id'       => $user->department_id,
            'department_name'     => $user->department?->name,
            'availability_status' => $computedStatus ?? 'available',
            'is_department_head'  => $user->is_department_head ?? false,
            'avatar_url'          => $user->avatar ? url('storage/' . $user->avatar) : null,
            'sim_a_photo_url'     => $user->sim_a_photo ? url('storage/' . $user->sim_a_photo) : null,
            'is_active'           => $user->is_active ?? false,
            'can_request'         => $user->can_request ?? false,
            'availability_start'  => $user->availability_start,
            'availability_end'    => $user->availability_end,
            'roles'               => $user->getRoleNames(),
            'created_at'          => $user->created_at,
            'updated_at'          => $user->updated_at,
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