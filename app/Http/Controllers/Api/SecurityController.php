<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Request as VehicleRequest;
use App\Enums\RequestStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SecurityController extends Controller
{
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_code_token' => 'required|string',
            'security_name' => 'required|string|exists:security_guards,name',
            'type'          => 'required|in:checkout,checkin',
            'notes'         => 'nullable|string|max:1000',
            'trip_id'       => 'nullable|integer',
        ]);

        $vehicleRequest = VehicleRequest::where('qr_code_token', $validated['qr_code_token'])->first();

        if (!$vehicleRequest) {
            // Fallback: try finding by request ID parsed from input
            $idStr = preg_replace('/[^0-9]/', '', $validated['qr_code_token']);
            if ($idStr) {
                $vehicleRequest = VehicleRequest::find((int)$idStr);
            }
        }

        if (!$vehicleRequest) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Permintaan kendaraan tidak ditemukan. Periksa kembali QR Code atau Kode Request.',
            ], 404);
        }

        $tripId = $request->input('trip_id');
        $targetTrip = null;

        if ($tripId && !$vehicleRequest->is_external) {
            $targetTrip = \App\Models\OperationalTrip::where('request_id', $vehicleRequest->id)->find($tripId);
            if (!$targetTrip) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Detail unit kendaraan (trip) tidak ditemukan untuk request ini.'
                ], 404);
            }
        }

        // Validate status: trip must be driver_assigned, on_going, or completed to scan
        if (!in_array($vehicleRequest->status, [RequestStatus::DRIVER_ASSIGNED, RequestStatus::ON_GOING, RequestStatus::COMPLETED], true)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Status request saat ini (' . $vehicleRequest->status->value . ') tidak valid untuk dipindai Security.',
            ], 422);
        }

        // Validate checkout / checkin conditions per trip
        if ($targetTrip) {
            if ($validated['type'] === 'checkout') {
                if ($targetTrip->security_checked_out_at !== null) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Unit kendaraan ini sudah melakukan check-out (berangkat) sebelumnya.',
                    ], 422);
                }
            } else { // checkin
                if ($targetTrip->security_checked_in_at !== null) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Unit kendaraan ini sudah melakukan check-in (kembali) sebelumnya.',
                    ], 422);
                }
                if ($targetTrip->security_checked_out_at === null && $targetTrip->status !== 'on_going' && $targetTrip->status !== 'completed') {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Unit kendaraan ini belum melakukan check-out / belum dimulai.',
                    ], 422);
                }
            }
        } else {
            if ($validated['type'] === 'checkout') {
                if ($vehicleRequest->security_checked_out_at !== null) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Perjalanan ini sudah melakukan check-out (berangkat) sebelumnya.',
                    ], 422);
                }
                if ($vehicleRequest->status === RequestStatus::COMPLETED) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Perjalanan ini sudah selesai (completed) dan tidak bisa di-checkout.',
                    ], 422);
                }
            } else { // checkin
                if ($vehicleRequest->security_checked_in_at !== null) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Perjalanan ini sudah melakukan check-in (kembali) sebelumnya.',
                    ], 422);
                }
                if ($vehicleRequest->security_checked_out_at === null && $vehicleRequest->status !== RequestStatus::ON_GOING && $vehicleRequest->status !== RequestStatus::COMPLETED) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Perjalanan ini belum melakukan check-out / belum dimulai.',
                    ], 422);
                }
            }
        }



        \Illuminate\Support\Facades\DB::transaction(function () use ($vehicleRequest, $validated, $targetTrip) {
            if ($validated['type'] === 'checkout') {
                if ($targetTrip) {
                    $targetTrip->update([
                        'status' => 'on_going',
                        'start_datetime' => $targetTrip->start_datetime ?? now(),
                        'security_checked_out_at' => now(),
                        'security_checkout_by' => $validated['security_name'],
                        'security_checkout_notes' => $validated['notes'] ?? null,
                    ]);

                    if ($targetTrip->driver) {
                        $targetTrip->driver->update(['availability_status' => 'on_trip']);
                    }
                    if ($targetTrip->vehicle) {
                        $targetTrip->vehicle->update(['status' => 'In Use']);
                    }

                    // Update main request status to on_going if not already
                    if ($vehicleRequest->status !== RequestStatus::ON_GOING) {
                        $vehicleRequest->update([
                            'status' => RequestStatus::ON_GOING,
                            'started_at' => $vehicleRequest->started_at ?? now(),
                            'security_checked_out_at' => $vehicleRequest->security_checked_out_at ?? now(),
                            'security_checkout_by' => $vehicleRequest->security_checkout_by ?? $validated['security_name'],
                            'security_checkout_notes' => $vehicleRequest->security_checkout_notes ?? $validated['notes'] ?? null,
                        ]);
                    }
                } else {
                    $vehicleRequest->update([
                        'status'                  => RequestStatus::ON_GOING,
                        'started_at'              => $vehicleRequest->started_at ?? now(),
                        'security_checked_out_at' => now(),
                        'security_checkout_by'    => $validated['security_name'],
                        'security_checkout_notes' => $validated['notes'] ?? null,
                    ]);

                    if (!$vehicleRequest->is_external) {
                        $trips = \App\Models\OperationalTrip::where('request_id', $vehicleRequest->id)->with(['driver', 'vehicle'])->get();
                        foreach ($trips as $trip) {
                            $trip->update([
                                'status' => 'on_going',
                                'start_datetime' => $trip->start_datetime ?? now(),
                                'security_checked_out_at' => now(),
                                'security_checkout_by' => $validated['security_name'],
                                'security_checkout_notes' => $validated['notes'] ?? null,
                            ]);
                            if ($trip->driver) {
                                $trip->driver->update(['availability_status' => 'on_trip']);
                            }
                            if ($trip->vehicle) {
                                $trip->vehicle->update(['status' => 'In Use']);
                            }
                        }
                    }
                }
            } else { // checkin
                if ($targetTrip) {
                    $targetTrip->update([
                        'status' => 'completed',
                        'end_datetime' => $targetTrip->end_datetime ?? now(),
                        'security_checked_in_at' => now(),
                        'security_checkin_by' => $validated['security_name'],
                        'security_checkin_notes' => $validated['notes'] ?? null,
                    ]);

                    if ($targetTrip->driver) {
                        $targetTrip->driver->update(['availability_status' => 'available']);
                    }
                    if ($targetTrip->vehicle) {
                        $targetTrip->vehicle->update(['status' => 'Available']);
                    }

                    // Check if all trips are completed
                    $allTripsCount = \App\Models\OperationalTrip::where('request_id', $vehicleRequest->id)->count();
                    $completedTripsCount = \App\Models\OperationalTrip::where('request_id', $vehicleRequest->id)->where('status', 'completed')->count();

                    if ($completedTripsCount >= $allTripsCount) {
                        $vehicleRequest->update([
                            'status'                 => RequestStatus::COMPLETED,
                            'completed_at'           => $vehicleRequest->completed_at ?? now(),
                            'security_checked_in_at' => now(),
                            'security_checkin_by'    => $validated['security_name'],
                            'security_checkin_notes' => $validated['notes'] ?? null,
                        ]);
                    }
                } else {
                    $vehicleRequest->update([
                        'status'                 => RequestStatus::COMPLETED,
                        'completed_at'           => $vehicleRequest->completed_at ?? now(),
                        'security_checked_in_at' => now(),
                        'security_checkin_by'    => $validated['security_name'],
                        'security_checkin_notes' => $validated['notes'] ?? null,
                    ]);

                    if (!$vehicleRequest->is_external) {
                        $trips = \App\Models\OperationalTrip::where('request_id', $vehicleRequest->id)->with(['driver', 'vehicle'])->get();
                        foreach ($trips as $trip) {
                            $trip->update([
                                'status' => 'completed',
                                'end_datetime' => $trip->end_datetime ?? now(),
                                'security_checked_in_at' => now(),
                                'security_checkin_by' => $validated['security_name'],
                                'security_checkin_notes' => $validated['notes'] ?? null,
                            ]);
                            if ($trip->driver) {
                                $trip->driver->update(['availability_status' => 'available']);
                            }
                            if ($trip->vehicle) {
                                $trip->vehicle->update(['status' => 'Available']);
                            }
                        }
                    }
                }
            }
        });

        if ($validated['type'] === 'checkout') {
            return response()->json([
                'status'  => 'success',
                'message' => $targetTrip ? 'Checkout unit armada berhasil dikonfirmasi.' : 'Scan Berangkat (Checkout) berhasil dikonfirmasi.',
                'data'    => $vehicleRequest->load(['user', 'operationalTrip.vehicle', 'operationalTrip.driver', 'passengers']),
            ], 200);
        } else {
            return response()->json([
                'status'  => 'success',
                'message' => $targetTrip ? 'Checkin unit armada berhasil dikonfirmasi.' : 'Scan Kembali (Checkin) berhasil dikonfirmasi.',
                'data'    => $vehicleRequest->load(['user', 'operationalTrip.vehicle', 'operationalTrip.driver', 'passengers']),
            ], 200);
        }
    }

    public function lookup(Request $request): JsonResponse
    {
        $token = $request->query('qr_code_token');
        if (empty($token)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Token QR Code wajib disertakan.',
            ], 422);
        }

        $vehicleRequest = VehicleRequest::where('qr_code_token', $token)->first();

        if (!$vehicleRequest) {
            // Fallback: try finding by request ID parsed from input
            $idStr = preg_replace('/[^0-9]/', '', $token);
            if ($idStr) {
                $vehicleRequest = VehicleRequest::find((int)$idStr);
            }
        }

        if (!$vehicleRequest) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Permintaan kendaraan tidak ditemukan.',
            ], 404);
        }



        // Validate status: trip must be driver_assigned, on_going, or completed to scan
        if (!in_array($vehicleRequest->status, [RequestStatus::DRIVER_ASSIGNED, RequestStatus::ON_GOING, RequestStatus::COMPLETED], true)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Status request saat ini (' . $vehicleRequest->status->value . ') tidak valid untuk dipindai Security.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data'   => new \App\Http\Resources\RequestResource($vehicleRequest->load(['user', 'operationalTrip.vehicle', 'operationalTrip.driver', 'passengers'])),
        ], 200);
    }
}
