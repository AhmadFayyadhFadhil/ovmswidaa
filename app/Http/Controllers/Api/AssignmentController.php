<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\Request as VehicleRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Actions\Assignments\AssignDriverAction;
use App\Actions\Assignments\DriverRespondAction;

class AssignmentController extends Controller
{
    private function hasRoleDirect($user, array $roles): bool
    {
        if (!$user) return false;
        return $user->roles()->whereIn('name', $roles)->exists();
    }

    public function index(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $perPage = $request->query('per_page', 15);
        $status  = $request->query('status');

        $query = Assignment::with(['request.user', 'request.passengers', 'request.driver', 'request.vehicle', 'request.operationalTrip.vehicle', 'driver', 'assignedBy']);

        if (!$this->hasRoleDirect($user, ['Admin', 'admin']) && !Auth::user()->isHrGaHead() && !$this->hasRoleDirect($user, ['GA', 'ga'])) {
            $query->where('driver_id', $user->id);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($request->query('request_id')) {
            $query->where('request_id', $request->query('request_id'));
        }

        $assignments = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status'     => 'success',
            'data'       => AssignmentResource::collection($assignments->items()),
            'pagination' => [
                'total'        => $assignments->total(),
                'per_page'     => $assignments->perPage(),
                'current_page' => $assignments->currentPage(),
                'last_page'    => $assignments->lastPage(),
            ],
        ], 200);
    }

    public function store(StoreAssignmentRequest $request, AssignDriverAction $action): JsonResponse
    {
        $user = Auth::user();
        if (!$this->hasRoleDirect($user, ['Admin', 'admin']) && !$user->isHrGaHead() && !$this->hasRoleDirect($user, ['GA', 'ga'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validated();
        $vehicleRequest = VehicleRequest::findOrFail($validated['request_id']);

        if (!empty($validated['is_external'])) {
            $priority = $validated['priority'] ?? $vehicleRequest->priority->value ?? 'Normal';
            // Removed strict priority constraint: Urgent and Critical can now use third party fleet if needed

            $photoPath = null;
            if ($request->hasFile('external_photo')) {
                $photoPath = $request->file('external_photo')->store('external_photos', 'public');
            }

            $returnPhotoPath = null;
            if ($request->hasFile('external_return_photo')) {
                $returnPhotoPath = $request->file('external_return_photo')->store('external_photos', 'public');
            }

            $photoPath2 = null;
            if ($request->hasFile('external_photo_2')) {
                $photoPath2 = $request->file('external_photo_2')->store('external_photos', 'public');
            }

            $returnPhotoPath2 = null;
            if ($request->hasFile('external_return_photo_2')) {
                $returnPhotoPath2 = $request->file('external_return_photo_2')->store('external_photos', 'public');
            }

            $newStatus = \App\Enums\RequestStatus::DRIVER_ASSIGNED;

            $vehicleRequest->update([
                'status' => $newStatus,
                'qr_code_token' => $vehicleRequest->qr_code_token ?? ('REQ-' . time() . '-' . bin2hex(random_bytes(4))),
                'is_external' => true,
                'third_party_cost' => $validated['third_party_cost'] ?? 0,
                'estimated_duration' => $validated['estimated_duration'] ?? null,
                'priority' => $priority,
                'notes' => $validated['notes'] ?? $vehicleRequest->notes,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
                'external_fleet_info' => $validated['external_fleet_info'] ?? null,
                'external_photo_path' => $photoPath ?? $vehicleRequest->external_photo_path,
                'external_trip_type' => $validated['external_trip_type'] ?? 'round_trip',
                'external_departure_cost' => $validated['external_departure_cost'] ?? 0,
                'external_return_cost'    => $validated['external_return_cost'] ?? 0,
                'external_return_fleet_info' => $validated['external_return_fleet_info'] ?? null,
                'external_return_photo_path' => $returnPhotoPath ?? $vehicleRequest->external_return_photo_path,
                'external_driver_name'       => $validated['external_driver_name'] ?? null,
                'external_license_plate'      => $validated['external_license_plate'] ?? null,
                'external_return_driver_name' => $validated['external_return_driver_name'] ?? null,
                'external_return_license_plate'=> $validated['external_return_license_plate'] ?? null,
                'external_provider'           => $validated['external_provider'] ?? null,

                // Second external vehicle:
                'external_driver_name_2'       => $validated['external_driver_name_2'] ?? null,
                'external_license_plate_2'      => $validated['external_license_plate_2'] ?? null,
                'external_fleet_info_2'        => $validated['external_fleet_info_2'] ?? null,
                'external_photo_path_2'        => $photoPath2 ?? $vehicleRequest->external_photo_path_2,
                'external_departure_cost_2'    => $validated['external_departure_cost_2'] ?? 0,
                'external_return_cost_2'       => $validated['external_return_cost_2'] ?? 0,
                'external_return_driver_name_2' => $validated['external_return_driver_name_2'] ?? null,
                'external_return_license_plate_2'=> $validated['external_return_license_plate_2'] ?? null,
                'external_return_fleet_info_2' => $validated['external_return_fleet_info_2'] ?? null,
                'external_return_photo_path_2' => $returnPhotoPath2 ?? $vehicleRequest->external_return_photo_path_2,
                'third_party_cost_2'           => $validated['third_party_cost_2'] ?? 0,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Request berhasil ditugaskan ke Pihak Ketiga',
                'data'    => [
                    'id' => null,
                    'request' => $vehicleRequest->fresh(['user', 'passengers']),
                ],
            ], 201);
        }

        $driverIds = $validated['driver_ids'] ?? [$validated['driver_id']];
        $vehicleIds = $validated['vehicle_ids'] ?? [$validated['vehicle_id']];

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($vehicleRequest, &$driverIds, &$vehicleIds) {
                $existing = \App\Models\Assignment::where('request_id', $vehicleRequest->id)->get();
                
                $newDriversMap = [];
                foreach ($driverIds as $index => $driverId) {
                    $vId = $vehicleIds[$index] ?? $vehicleIds[0];
                    $newDriversMap[(int)$driverId] = (int)$vId;
                }

                foreach ($existing as $extAsg) {
                    $dId = (int)$extAsg->driver_id;
                    
                    if ($extAsg->status === 'accepted' && isset($newDriversMap[$dId]) && $newDriversMap[$dId] == $extAsg->vehicle_id) {
                        unset($newDriversMap[$dId]);
                    } else {
                        if ($extAsg->status === 'pending_driver') {
                            $extAsg->driver()->update(['availability_status' => 'available']);
                        }
                        
                        \App\Models\OperationalTrip::where('request_id', $vehicleRequest->id)
                            ->where('driver_id', $dId)
                            ->delete();
                            
                        $extAsg->delete();
                    }
                }

                $driverIds = array_keys($newDriversMap);
                $vehicleIds = array_values($newDriversMap);
            });

            $assignment = null;
            foreach ($driverIds as $index => $driverId) {
                $vId = $vehicleIds[$index] ?? $vehicleIds[0];
                
                $driver = \App\Models\User::findOrFail($driverId);
                if (!$this->hasRoleDirect($driver, ['Driver', 'driver'])) {
                    return response()->json(['status' => 'error', 'message' => 'User yang dipilih bukan merupakan Driver'], 422);
                }

                $asg = $action->execute($vehicleRequest, $driverId, $vId, $validated['notes'] ?? null, $validated);
                if ($index === 0) {
                    $assignment = $asg;
                }
            }

            if (!$assignment) {
                $assignment = \App\Models\Assignment::where('request_id', $vehicleRequest->id)->first();
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Kendaraan berhasil di-assign ke driver',
                'data'    => new AssignmentResource($assignment->load(['request', 'driver', 'assignedBy'])),
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Assignment creation error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function show(Assignment $assignment): JsonResponse
    {
        if (Auth::id() !== $assignment->driver_id && !$this->hasRoleDirect(Auth::user(), ['Admin', 'admin']) && !Auth::user()->isHrGaHead() && !$this->hasRoleDirect(Auth::user(), ['GA', 'ga'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $assignment->load(['request.user', 'request.passengers', 'request.driver', 'request.vehicle', 'request.operationalTrip.vehicle', 'driver', 'assignedBy']);

        return response()->json([
            'status' => 'success',
            'data'   => new AssignmentResource($assignment),
        ], 200);
    }

    /**
     * Driver responds to the assignment.
     */
    public function update(UpdateAssignmentRequest $request, Assignment $assignment, DriverRespondAction $action): JsonResponse
    {
        if (Auth::id() !== $assignment->driver_id && !$this->hasRoleDirect(Auth::user(), ['Admin', 'admin'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validated();

        try {
            // Handle photo uploads
            $startPhotoPath = null;
            $endPhotoPath = null;
            
            if ($request->hasFile('start_photo')) {
                $startPhotoPath = $request->file('start_photo')->store('assignments/photos', 'public');
            }
            
            if ($request->hasFile('end_photo')) {
                $endPhotoPath = $request->file('end_photo')->store('assignments/photos', 'public');
            }

            $action->execute(
                $assignment,
                $validated['response'],
                $validated['vehicle_id'] ?? null,
                $validated['reject_reason'] ?? null,
                $startPhotoPath,
                $endPhotoPath
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Respon driver berhasil disimpan',
                'data'    => new AssignmentResource($assignment->fresh(['request.operationalTrip.vehicle', 'driver', 'assignedBy'])),
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Assignment $assignment): JsonResponse
    {
        if (!$this->hasRoleDirect(Auth::user(), ['Admin', 'admin'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if ($assignment->status !== 'pending_driver') {
            return response()->json(['status' => 'error', 'message' => 'Tidak dapat menghapus assignment yang sudah diproses'], 422);
        }

        $assignment->delete();

        return response()->json(['status' => 'success', 'message' => 'Assignment berhasil dihapus'], 200);
    }

    public function cancel(Assignment $assignment): JsonResponse
    {
        if (!$this->hasRoleDirect(Auth::user(), ['Admin', 'admin']) && !Auth::user()->isHrGaHead() && !$this->hasRoleDirect(Auth::user(), ['GA', 'ga'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if ($assignment->status !== 'pending_driver') {
            return response()->json(['status' => 'error', 'message' => 'Tidak dapat membatalkan assignment yang sudah diproses'], 422);
        }

        // Kembalikan status request ke state sebelumnya (submitted)
        $revertStatus = \App\Enums\RequestStatus::SUBMITTED;

        $assignment->request()->update([
            'status'      => $revertStatus,
            'driver_id'   => null,
            'assigned_by' => null,
            'assigned_at' => null,
            'driver_response_status' => null,
            'qr_code_token' => null,
        ]);

        $assignment->delete();

        return response()->json(['status' => 'success', 'message' => 'Assignment berhasil dibatalkan'], 200);
    }
}
