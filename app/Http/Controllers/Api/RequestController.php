<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRequest as StoreRequestRequest;
use App\Http\Requests\UpdateRequestRequest;
use App\Http\Resources\RequestResource;
use App\Models\Request as VehicleRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Actions\Requests\CreateRequestAction;
use App\Actions\Approvals\ApproveRequestAction;
use App\Enums\RequestStatus;

class RequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $perPage = min((int) $request->query('per_page', 15), 100);
        $status  = $request->query('status');
        $search  = $request->query('search');

        // Cache role checks once to avoid repeated DB queries (N× hasRoleDirect calls)
        $isAdmin    = $user->hasRoleDirect('Admin');
        $isGA       = $user->hasRoleDirect('GA');
        $isApprover = $user->hasRoleDirect('Approver');
        $isDriver   = $user->hasRoleDirect('Driver');
        $isSecurity = $user->hasRoleDirect('Security');
        $isHrGaHead = $user->isHrGaHead();

        $query = VehicleRequest::with([
            'user',
            'department',   // ← fix N+1: department was missing from eager load
            'approvals.approver',
            'operationalTrip.vehicle',
            'operationalTrip.driver',
            'operationalTrips.driver',
            'operationalTrips.vehicle',
            'assignments.driver',
            'passengers.department',
            'driver',
            'vehicle',
            'itineraries.driver',
            'itineraries.vehicle',
        ]);

        if ($isApprover && !$isAdmin && !$isGA) {
            if ($isHrGaHead) {
                $query->where(function ($q) use ($user) {
                    $q->whereIn('department_id', $user->departmentGroup())
                      ->orWhere(function ($q) {
                          $q->whereIn('status', [
                              RequestStatus::APPROVED_DEPARTMENT,
                              RequestStatus::ASSIGNED_BY_GA,
                              RequestStatus::APPROVED_HRD,
                              RequestStatus::APPROVED_HRD_GA,
                              RequestStatus::WAITING_DRIVER,
                              RequestStatus::DRIVER_ASSIGNED,
                              RequestStatus::ON_GOING,
                              RequestStatus::COMPLETED,
                          ])->orWhere(function ($q2) {
                              $q2->where('status', RequestStatus::REJECTED)
                                 ->whereHas('approvals', function ($q3) {
                                     $q3->where('role', 'hrd_head');
                                 });
                          });
                      });
                });
            } else {
                $query->whereIn('department_id', $user->departmentGroup());
            }
        } elseif (!$isAdmin && !$isApprover && !$isGA && !$isSecurity && !$isHrGaHead) {
            if ($isDriver) {
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhere('driver_id', $user->id)
                      ->orWhereHas('assignments', function ($sub) use ($user) {
                          $sub->where('driver_id', $user->id);
                      })
                      ->orWhereHas('operationalTrip', function ($sub) use ($user) {
                          $sub->where('driver_id', $user->id);
                      })
                      ->orWhereHas('itineraries', function ($sub) use ($user) {
                          $sub->where('driver_id', $user->id);
                      });
                });
            } else {
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhereHas('passengers', function ($sub) use ($user) {
                          $sub->where('user_id', $user->id);
                      });
                });
            }
        }

        if ($status) {
            $upperStatus = strtoupper($status);
            if ($upperStatus === 'PENDING') {
                $query->whereIn('status', [
                    RequestStatus::SUBMITTED,
                    RequestStatus::APPROVED_DEPARTMENT,
                    RequestStatus::ASSIGNED_BY_GA,
                    RequestStatus::WAITING_DRIVER
                ]);
            } elseif ($upperStatus === 'APPROVED') {
                $query->whereIn('status', [RequestStatus::DRIVER_ASSIGNED, RequestStatus::APPROVED_HRD, RequestStatus::APPROVED_HRD_GA]);
            } elseif ($upperStatus === 'ONGOING') {
                $query->where('status', RequestStatus::ON_GOING);
            } elseif ($upperStatus === 'COMPLETED') {
                $query->where('status', RequestStatus::COMPLETED);
            } elseif ($upperStatus === 'REJECTED') {
                $query->where('status', RequestStatus::REJECTED);
            } else {
                $query->where('status', $status);
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('purpose', 'like', '%' . $search . '%')
                  ->orWhere('destination_city', 'like', '%' . $search . '%')
                  ->orWhere('destination_place', 'like', '%' . $search . '%');
            });
        }

        $requests = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status'     => 'success',
            'data'       => RequestResource::collection($requests->items()),
            'pagination' => [
                'total'        => $requests->total(),
                'per_page'     => $requests->perPage(),
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'from'         => $requests->firstItem(),
                'to'           => $requests->lastItem(),
            ],
        ], 200);
    }

    public function store(StoreRequestRequest $request, CreateRequestAction $action): JsonResponse
    {
        $data = $request->validated();

        if ($request->hasFile('itinerary_file')) {
            $data['itinerary_file_path'] = $request->file('itinerary_file')->store('itinerary_files', 'public');
        }

        if (isset($data['itineraries']) && is_string($data['itineraries'])) {
            $data['itineraries'] = json_decode($data['itineraries'], true);
        }

        $newRequest = $action->execute($data);

        return response()->json([
            'status'  => 'success',
            'message' => 'Permintaan berhasil diajukan',
            'data'    => new RequestResource($newRequest->load([
                'user',
                'passengers.department',
                'itineraries.driver',
                'itineraries.vehicle',
                'operationalTrips.driver',
                'operationalTrips.vehicle',
                'assignments.driver',
                'approvals.approver',
                'driver',
                'vehicle',
                'operationalTrip.driver',
                'operationalTrip.vehicle',
            ])),
        ], 201);
    }

    public function show(VehicleRequest $vehicleRequest): JsonResponse
    {
        $user = Auth::user();
        if ($user->hasRoleDirect('Approver') && !$user->hasRoleDirect('Admin')) {
            if ($user->isHrGaHead() && in_array($vehicleRequest->status, [
                RequestStatus::APPROVED_DEPARTMENT,
                RequestStatus::APPROVED_HRD,
                RequestStatus::APPROVED_HRD_GA,
                RequestStatus::WAITING_DRIVER,
                RequestStatus::DRIVER_ASSIGNED,
                RequestStatus::ON_GOING,
                RequestStatus::COMPLETED,
            ], true)) {
                // HRD&GA head can view requests after department approval for assignment flow.
            } elseif (!in_array($vehicleRequest->department_id, $user->departmentGroup(), true)) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized. Request tidak berasal dari departemen Anda.'], 403);
            }
        } elseif (
            Auth::id() !== $vehicleRequest->user_id && 
            !$user->hasRoleDirect('Admin') && 
            !$user->isHrGaHead() && 
            !$user->hasRoleDirect('GA') && 
            !$user->hasRoleDirect('Security') && 
            $vehicleRequest->driver_id !== $user->id && 
            $vehicleRequest->operationalTrip?->driver_id !== $user->id &&
            !$vehicleRequest->assignments()->where('driver_id', $user->id)->exists() &&
            !$vehicleRequest->itineraries()->where('driver_id', $user->id)->exists() &&
            !$vehicleRequest->passengers()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $vehicleRequest->load([
            'user',
            'approvals.approver',
            'operationalTrip.vehicle',
            'operationalTrip.driver',
            'operationalTrips.driver',
            'operationalTrips.vehicle',
            'assignments.driver',
            'passengers.department',
            'driver',
            'vehicle',
            'itineraries.driver',
            'itineraries.vehicle',
        ]);

        return response()->json([
            'status' => 'success',
            'data'   => new RequestResource($vehicleRequest),
        ], 200);
    }

    public function update(UpdateRequestRequest $request, VehicleRequest $vehicleRequest): JsonResponse
    {
        if ($vehicleRequest->status !== RequestStatus::SUBMITTED) {
            return response()->json(['status' => 'error', 'message' => 'Hanya dapat mengubah request yang baru diajukan (submitted)'], 422);
        }

        $validated = $request->validated();
        
        // Extract passengers from validated data if present
        $passengers = $validated['passengers'] ?? null;
        unset($validated['passengers']);

        // Update request
        $vehicleRequest->update($validated);

        // Update passengers if provided
        if ($passengers !== null) {
             $date = date('Y-m-d', strtotime($vehicleRequest->start_time));
             foreach ($passengers as $passengerData) {
                 if (empty($passengerData['name'])) continue;
                 $name = trim($passengerData['name']);
                 
                 $hasConflict = \App\Models\Passenger::where('name', $name)
                     ->whereHas('request', function ($q) use ($date, $vehicleRequest) {
                         $q->whereDate('start_time', $date)
                           ->where('id', '!=', $vehicleRequest->id)
                           ->whereIn('status', [
                               RequestStatus::SUBMITTED,
                               RequestStatus::APPROVED_DEPARTMENT,
                               RequestStatus::ASSIGNED_BY_GA,
                               RequestStatus::APPROVED_HRD,
                               RequestStatus::APPROVED_HRD_GA,
                               RequestStatus::WAITING_DRIVER,
                               RequestStatus::DRIVER_ASSIGNED,
                               RequestStatus::ON_GOING,
                           ]);
                     })
                     ->exists();
                 
                 if ($hasConflict) {
                     return response()->json([
                         'status' => 'error',
                         'message' => "Penumpang '{$name}' sudah terdaftar pada perjalanan lain pada tanggal tersebut. Silakan pilih penumpang lain yang tidak memiliki jadwal perjalanan."
                     ], 422);
                 }
             }

             // Delete old passengers and create new ones
             $vehicleRequest->passengers()->delete();
             foreach ($passengers as $passengerData) {
                 $userId = $passengerData['user_id'] ?? null;
                 if (!$userId && !empty($passengerData['name'])) {
                     $resolved = \App\Models\User::where('name', trim($passengerData['name']))->first();
                     if ($resolved) {
                         $userId = $resolved->id;
                     }
                 }
                 \App\Models\Passenger::create([
                     'request_id' => $vehicleRequest->id,
                     'name' => $passengerData['name'],
                     'department_id' => $passengerData['department_id'] ?? null,
                     'user_id' => $userId,
                 ]);
             }
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Permintaan berhasil diperbarui',
            'data'    => new RequestResource($vehicleRequest->fresh(['user', 'passengers.department', 'operationalTrips.driver', 'operationalTrips.vehicle', 'assignments.driver', 'approvals.approver', 'driver', 'vehicle', 'itineraries.driver', 'itineraries.vehicle', 'operationalTrip.driver', 'operationalTrip.vehicle'])),
        ], 200);
    }

    public function destroy(\Illuminate\Http\Request $httpRequest, VehicleRequest $vehicleRequest): JsonResponse
    {
        $user = Auth::user();
        $isOwner = (int) $vehicleRequest->user_id === (int) $user->id
            || $vehicleRequest->passengers()->where('user_id', $user->id)->exists();
        $isSameDept = in_array((int) $vehicleRequest->department_id, $user->departmentGroup() ?? [(int) $user->department_id], true);
        $isAuthorized = $isOwner || $isSameDept || $user->hasRoleDirect(['Admin', 'GA', 'Employee', 'admin', 'ga', 'employee', 'Approver', 'approver']) || $user->isHrGaHead();

        if (!$isAuthorized) {
            return response()->json([
                'status' => 'error',
                'message' => 'Hanya pemohon atau Administrator yang dapat membatalkan request ini'
            ], 403);
        }

        // Allow deleting requests unless they are already in progress (on_going) or completed
        if (in_array($vehicleRequest->status, [RequestStatus::ON_GOING, RequestStatus::COMPLETED], true)) {
            return response()->json([
                'status' => 'error', 
                'message' => 'Tidak dapat membatalkan request yang sedang berjalan atau sudah selesai'
            ], 422);
        }

        // Require cancellation reason
        $httpRequest->validate([
            'rejected_reason' => 'required|string|min:5|max:500',
        ], [
            'rejected_reason.required' => 'Alasan pembatalan wajib diisi.',
            'rejected_reason.min'      => 'Alasan pembatalan minimal 5 karakter.',
        ]);

        $reason = $httpRequest->input('rejected_reason');

        \Illuminate\Support\Facades\DB::transaction(function () use ($vehicleRequest, $reason) {
            // Restore driver availability status if assigned
            if ($vehicleRequest->driver) {
                $vehicleRequest->driver->update(['availability_status' => 'available']);
            }

            foreach ($vehicleRequest->itineraries as $itinerary) {
                if ($itinerary->driver) {
                    $itinerary->driver->update(['availability_status' => 'available']);
                }
            }

            // Delete assignments through Eloquent to trigger observers and restore driver status
            foreach ($vehicleRequest->assignments as $assignment) {
                if ($assignment->driver) {
                    $assignment->driver->update(['availability_status' => 'available']);
                }
                $assignment->delete();
            }

            if ($vehicleRequest->operationalTrip) {
                if ($vehicleRequest->operationalTrip->driver) {
                    $vehicleRequest->operationalTrip->driver->update(['availability_status' => 'available']);
                }
                $vehicleRequest->operationalTrip->delete();
            }

            $vehicleRequest->itineraries()->update(['status' => 'cancelled']);

            $vehicleRequest->update([
                'status'          => RequestStatus::CANCELLED,
                'rejected_reason' => $reason,
            ]);
        });

        return response()->json(['status' => 'success', 'message' => 'Permintaan berhasil dibatalkan'], 200);
    }

    public function approve(Request $request, VehicleRequest $vehicleRequest, ApproveRequestAction $action): JsonResponse
    {
        // Check authorization using policy
        if (!Auth::user()->can('approve', $vehicleRequest)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Anda tidak berhak untuk approve request ini. Pastikan departemen Anda sama dan Anda adalah Kepala Departemen.'
            ], 403);
        }

        $request->validate([
            'role'  => 'nullable|in:dept_head,hrd_head',
            'notes' => 'nullable|string'
        ]);

        $role = $request->input('role') ?? match($vehicleRequest->status) {
            RequestStatus::SUBMITTED           => 'dept_head',
            RequestStatus::ASSIGNED_BY_GA      => 'hrd_head',
            default => null,
        };

        if (!$role) {
            return response()->json(['status' => 'error', 'message' => 'Request tidak dapat disetujui pada status ini'], 422);
        }

        try {
            $updatedRequest = $action->execute($vehicleRequest, $role, 'approved', $request->input('notes'));
            
            return response()->json([
                'status'  => 'success',
                'message' => 'Permintaan berhasil disetujui',
                'data'    => new RequestResource($updatedRequest->fresh(['user', 'approvals.approver', 'operationalTrips.driver', 'operationalTrips.vehicle', 'assignments.driver', 'passengers.department', 'driver', 'vehicle', 'itineraries.driver', 'itineraries.vehicle', 'operationalTrip.driver', 'operationalTrip.vehicle'])),
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function reject(Request $request, VehicleRequest $vehicleRequest, ApproveRequestAction $action): JsonResponse
    {
        // Check authorization using policy
        if (!Auth::user()->can('reject', $vehicleRequest)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Anda tidak berhak untuk reject request ini. Pastikan departemen Anda sama dan Anda adalah Kepala Departemen.'
            ], 403);
        }

        $request->validate([
            'role'  => 'nullable|in:dept_head,hrd_head',
            'notes' => 'required|string'
        ]);

        // Auto-detect role from current request status if not provided
        $role = $request->input('role') ?? match($vehicleRequest->status) {
            RequestStatus::SUBMITTED           => 'dept_head',
            RequestStatus::APPROVED_DEPARTMENT => 'hrd_head',
            default => null,
        };

        if (!$role) {
            return response()->json(['status' => 'error', 'message' => 'Request tidak dapat ditolak pada status ini'], 422);
        }

        try {
            $updatedRequest = $action->execute($vehicleRequest, $role, 'rejected', $request->input('notes'));
            
            return response()->json([
                'status'  => 'success',
                'message' => 'Permintaan berhasil ditolak',
                'data'    => new RequestResource($updatedRequest->fresh(['user', 'approvals.approver', 'operationalTrips.driver', 'operationalTrips.vehicle', 'assignments.driver', 'passengers.department', 'driver', 'vehicle', 'itineraries.driver', 'itineraries.vehicle', 'operationalTrip.driver', 'operationalTrip.vehicle'])),
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function start(VehicleRequest $vehicleRequest): JsonResponse
    {
        $user = Auth::user();

        // Authorize:
        if ($vehicleRequest->is_external) {
            if (Auth::id() !== $vehicleRequest->user_id && !$user->hasRoleDirect('Admin') && !$user->isHrGaHead() && !$user->hasRoleDirect('GA')) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized. Perjalanan pihak ketiga hanya dapat dimulai oleh Requestor yang mengajukan.'], 403);
            }
        } else {
            $isAssigned = $vehicleRequest->driver_id === $user->id ||
                $vehicleRequest->operationalTrip?->driver_id === $user->id ||
                $vehicleRequest->assignments()->where('driver_id', $user->id)->exists() ||
                \App\Models\OperationalTrip::where('request_id', $vehicleRequest->id)->where('driver_id', $user->id)->exists() ||
                \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)->where('driver_id', $user->id)->exists();

            if (!$isAssigned && !$user->hasRoleDirect('Admin') && !$user->isHrGaHead()) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized. Hanya driver yang ditugaskan atau Kepala Departemen HRD&GA yang dapat memulai perjalanan.'], 403);
            }
        }

        if ($vehicleRequest->is_external) {
            if ($vehicleRequest->status !== RequestStatus::DRIVER_ASSIGNED) {
                return response()->json(['status' => 'error', 'message' => 'Perjalanan hanya dapat dimulai jika status adalah driver_assigned (siap berangkat).'], 422);
            }
        } else {
            if ($vehicleRequest->status !== RequestStatus::DRIVER_ASSIGNED && $vehicleRequest->status !== RequestStatus::ON_GOING) {
                return response()->json(['status' => 'error', 'message' => 'Perjalanan hanya dapat dimulai jika status adalah driver_assigned atau sedang berjalan.'], 422);
            }

            if (empty($vehicleRequest->driver_id) && empty($vehicleRequest->vehicle_id) && !$vehicleRequest->itineraries()->exists()) {
                return response()->json(['status' => 'error', 'message' => 'Tidak dapat memulai perjalanan tanpa driver atau kendaraan.'], 422);
            }
        }

        $errorResponse = null;

        \Illuminate\Support\Facades\DB::transaction(function () use ($vehicleRequest, $user, &$errorResponse) {
            $itineraries = $vehicleRequest->itineraries;
            if ($itineraries->isNotEmpty()) {
                // Find the first itinerary for the logged-in driver that is not completed
                $activeItinerary = $itineraries->first(function ($it) use ($user) {
                    return $it->driver_id === $user->id && $it->status !== 'completed';
                });

                if (!$activeItinerary) {
                    $errorResponse = response()->json(['status' => 'error', 'message' => 'Tidak ditemukan jadwal penugasan harian Anda yang aktif untuk permohonan ini.'], 422);
                    return;
                }

                // Check if any previous day itinerary is uncompleted
                $prevUncompletedIt = \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)
                    ->where('date', '<', $activeItinerary->date->format('Y-m-d'))
                    ->where('status', '!=', 'completed')
                    ->orderBy('date', 'asc')
                    ->first();

                if ($prevUncompletedIt) {
                    $errorResponse = response()->json(['status' => 'error', 'message' => 'Perjalanan hari sebelumnya (' . $prevUncompletedIt->date->format('d-m-Y') . ') belum selesai. Selesaikan perjalanan hari sebelumnya terlebih dahulu.'], 422);
                    return;
                }

                // Determine if we are starting Sesi 1 or Sesi 2
                if ($activeItinerary->morning_status !== 'completed' && $activeItinerary->morning_status !== 'on_going') {
                    $activeItinerary->update([
                        'morning_status' => 'on_going',
                        'morning_checked_out_at' => now(),
                        'morning_checkout_by' => $user->name,
                        'status' => 'on_going',
                    ]);
                } else if ($activeItinerary->morning_status === 'completed' && $activeItinerary->afternoon_status !== 'completed' && $activeItinerary->afternoon_status !== 'on_going') {
                    $activeItinerary->update([
                        'afternoon_status' => 'on_going',
                        'afternoon_checked_out_at' => now(),
                        'afternoon_checkout_by' => $user->name,
                        'status' => 'on_going',
                    ]);
                } else {
                    $errorResponse = response()->json(['status' => 'error', 'message' => 'Sesi perjalanan hari ini sudah dimulai/selesai.'], 422);
                    return;
                }

                // If overall request status is not on_going, set it to on_going
                if ($vehicleRequest->status !== RequestStatus::ON_GOING) {
                    $vehicleRequest->update([
                        'status' => RequestStatus::ON_GOING,
                        'started_at' => now(),
                    ]);
                }

                // Update driver and vehicle availability
                if ($activeItinerary->driver) {
                    $activeItinerary->driver->update(['availability_status' => 'on_trip']);
                }
                if ($activeItinerary->vehicle) {
                    $activeItinerary->vehicle->update(['status' => 'In Use']);
                }
            } else {
                // Regular single-day request
                $vehicleRequest->update([
                    'status' => RequestStatus::ON_GOING,
                    'started_at' => now(),
                ]);

                if (!$vehicleRequest->is_external) {
                    $trips = \App\Models\OperationalTrip::where('request_id', $vehicleRequest->id)->with(['driver', 'vehicle'])->get();
                    foreach ($trips as $trip) {
                        $trip->update(['status' => 'on_going']);
                        if ($trip->driver) {
                            $trip->driver->update(['availability_status' => 'on_trip']);
                        }
                        if ($trip->vehicle) {
                            $trip->vehicle->update(['status' => 'In Use']);
                        }
                    }
                }
            }
        });

        if ($errorResponse) {
            return $errorResponse;
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Perjalanan dimulai',
            'data' => new RequestResource($vehicleRequest->fresh(['user', 'approvals.approver', 'operationalTrip.vehicle', 'operationalTrip.driver', 'operationalTrips.driver', 'operationalTrips.vehicle', 'assignments.driver', 'passengers.department', 'driver', 'vehicle', 'itineraries.driver', 'itineraries.vehicle'])),
        ], 200);
    }

    public function complete(VehicleRequest $vehicleRequest): JsonResponse
    {
        $user = Auth::user();

        // Authorize:
        if ($vehicleRequest->is_external) {
            if (Auth::id() !== $vehicleRequest->user_id && !$user->hasRoleDirect('Admin') && !$user->isHrGaHead() && !$user->hasRoleDirect('GA')) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized. Perjalanan pihak ketiga hanya dapat diselesaikan oleh Requestor yang mengajukan.'], 403);
            }
        } else {
            $isAssigned = $vehicleRequest->driver_id === $user->id ||
                $vehicleRequest->operationalTrip?->driver_id === $user->id ||
                $vehicleRequest->assignments()->where('driver_id', $user->id)->exists() ||
                \App\Models\OperationalTrip::where('request_id', $vehicleRequest->id)->where('driver_id', $user->id)->exists() ||
                \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)->where('driver_id', $user->id)->exists();

            if (!$isAssigned && !$user->hasRoleDirect('Admin') && !$user->isHrGaHead()) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized. Hanya driver yang ditugaskan atau Kepala Departemen HRD&GA yang dapat menyelesaikan perjalanan.'], 403);
            }
        }

        if ($vehicleRequest->status !== RequestStatus::ON_GOING) {
            return response()->json(['status' => 'error', 'message' => 'Perjalanan hanya dapat diselesaikan jika sedang berjalan (on_going).'], 422);
        }

        $errorResponse = null;

        \Illuminate\Support\Facades\DB::transaction(function () use ($vehicleRequest, $user, &$errorResponse) {
            $itineraries = $vehicleRequest->itineraries;
            if ($itineraries->isNotEmpty()) {
                // Find the itinerary for the logged-in driver that is currently 'on_going'
                $activeItinerary = $itineraries->first(function ($it) use ($user) {
                    return $it->driver_id === $user->id && $it->status === 'on_going';
                });

                if (!$activeItinerary) {
                    $errorResponse = response()->json(['status' => 'error', 'message' => 'Tidak ditemukan jadwal penugasan harian Anda yang berstatus sedang berjalan (on_going).'], 422);
                    return;
                }

                // Determine if we are completing Sesi 1 or Sesi 2
                if ($activeItinerary->morning_status === 'on_going') {
                    $activeItinerary->update([
                        'morning_status' => 'completed',
                        'morning_checked_in_at' => now(),
                        'morning_checkin_by' => $user->name,
                    ]);

                    // If no afternoon destination, complete the itinerary status
                    if (empty($activeItinerary->afternoon_destination)) {
                        $activeItinerary->update([
                            'status' => 'completed',
                            'security_checked_in_at' => now(),
                        ]);
                    }
                } else if ($activeItinerary->afternoon_status === 'on_going') {
                    $activeItinerary->update([
                        'afternoon_status' => 'completed',
                        'afternoon_checked_in_at' => now(),
                        'afternoon_checkin_by' => $user->name,
                        'status' => 'completed',
                        'security_checked_in_at' => now(),
                    ]);
                }

                // Release driver and vehicle status to available
                if ($activeItinerary->driver) {
                    $activeItinerary->driver->update(['availability_status' => 'available']);
                }
                if ($activeItinerary->vehicle) {
                    $activeItinerary->vehicle->update(['status' => 'Available']);
                }

                // Check if ALL itineraries in this request are completed
                $allCompleted = \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)->where('status', '!=', 'completed')->count() === 0;
                if ($allCompleted) {
                    $vehicleRequest->update([
                        'status' => RequestStatus::COMPLETED,
                        'completed_at' => now(),
                    ]);
                }
            } else {
                // Regular single-day request
                $vehicleRequest->update([
                    'status' => RequestStatus::COMPLETED,
                    'completed_at' => now(),
                ]);

                if (!$vehicleRequest->is_external) {
                    $trips = \App\Models\OperationalTrip::where('request_id', $vehicleRequest->id)->with(['driver', 'vehicle'])->get();
                    foreach ($trips as $trip) {
                        $trip->update(['status' => 'completed']);
                        if ($trip->driver) {
                            $trip->driver->update(['availability_status' => 'available']);
                        }
                        if ($trip->vehicle) {
                            $trip->vehicle->update(['status' => 'Available']);
                        }
                    }

                    if ($vehicleRequest->driver) {
                        $vehicleRequest->driver->update(['availability_status' => 'available']);
                    }
                    if ($vehicleRequest->vehicle) {
                        $vehicleRequest->vehicle->update(['status' => 'Available']);
                    }
                }
            }
        });

        if ($errorResponse) {
            return $errorResponse;
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Perjalanan selesai',
            'data' => new RequestResource($vehicleRequest->fresh(['user', 'approvals.approver', 'operationalTrip.vehicle', 'operationalTrip.driver', 'operationalTrips.driver', 'operationalTrips.vehicle', 'assignments.driver', 'passengers.department', 'driver', 'vehicle', 'itineraries.driver', 'itineraries.vehicle'])),
        ], 200);
    }

    public function adjustDriver(Request $request, VehicleRequest $vehicleRequest): JsonResponse
    {
        $user = Auth::user();
        if (!$user->hasRoleDirect(['Admin', 'GA']) && !$user->isHrGaHead()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'driver_id'  => 'required|exists:users,id',
            'vehicle_id' => 'required|exists:vehicles,id',
        ]);

        $driver = \App\Models\User::findOrFail($validated['driver_id']);
        if (!$driver->hasRoleDirect('Driver')) {
            return response()->json(['status' => 'error', 'message' => 'User yang ditunjuk bukan Driver.'], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($vehicleRequest, $validated) {
            $vehicleRequest->update([
                'driver_id'  => $validated['driver_id'],
                'vehicle_id' => $validated['vehicle_id'],
            ]);

            if ($vehicleRequest->operationalTrip) {
                $vehicleRequest->operationalTrip->update([
                    'driver_id'  => $validated['driver_id'],
                    'vehicle_id' => $validated['vehicle_id'],
                ]);
            }
        });

        return response()->json([
            'status'  => 'success',
            'message' => 'Driver & Kendaraan berhasil disesuaikan di tengah perjalanan.',
            'data'    => new RequestResource($vehicleRequest->fresh(['user', 'approvals.approver', 'operationalTrip.vehicle', 'operationalTrip.driver', 'operationalTrips.driver', 'operationalTrips.vehicle', 'assignments.driver', 'passengers.department', 'driver', 'vehicle', 'itineraries.driver', 'itineraries.vehicle'])),
        ], 200);
    }
}