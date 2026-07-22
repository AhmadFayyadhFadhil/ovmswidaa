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
            'itinerary_id'  => 'nullable|integer',
            'session'       => 'nullable|string|in:morning,afternoon',
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
            $itineraryId = $request->input('itinerary_id');
            $todayItinerary = null;

            if ($itineraryId) {
                $todayItinerary = \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)->find($itineraryId);
            }

            if (!$todayItinerary) {
                $todayItinerary = \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)
                    ->where('date', now()->format('Y-m-d'))
                    ->first()
                    ?? \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)
                        ->whereIn('status', ['pending', 'assigned', 'on_going'])
                        ->orderBy('date', 'asc')
                        ->first();
            }

            if ($todayItinerary) {
                if ($validated['type'] === 'checkout') {
                    // 1. Enforce Previous Day Completion Rule
                    $prevUncompletedIt = \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)
                        ->where('date', '<', $todayItinerary->date->format('Y-m-d'))
                        ->where('status', '!=', 'completed')
                        ->orderBy('date', 'asc')
                        ->first();

                    if ($prevUncompletedIt) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'Perjalanan hari sebelumnya (' . $prevUncompletedIt->date->format('d-m-Y') . ') belum selesai. Selesaikan perjalanan hari sebelumnya terlebih dahulu.',
                        ], 422);
                    }

                    // 2. Enforce Morning Session Completion before Afternoon Session
                    $requestedSession = $validated['session'] ?? null;
                    if ($requestedSession === 'afternoon' && !empty($todayItinerary->morning_destination) && $todayItinerary->morning_status !== 'completed') {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'Sesi perjalanan harus berurutan. Sesi 1 (Pagi) harus diselesaikan terlebih dahulu sebelum Sesi 2 (Sore) dapat dimulai.',
                        ], 422);
                    }

                    $hasActiveMorning = $todayItinerary->morning_status === 'on_going';
                    $hasActiveAfternoon = $todayItinerary->afternoon_status === 'on_going';

                    if ($hasActiveMorning || $hasActiveAfternoon) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'Sesi perjalanan saat ini sedang berjalan (ON GOING) dan belum di-checkin.',
                        ], 422);
                    }

                    $morningDone = $todayItinerary->morning_status === 'completed';
                    $afternoonDone = $todayItinerary->afternoon_status === 'completed';

                    if ($morningDone && ($afternoonDone || empty($todayItinerary->afternoon_destination))) {
                        $nextItinerary = \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)
                            ->where('date', '>', $todayItinerary->date->format('Y-m-d'))
                            ->whereIn('status', ['pending', 'assigned'])
                            ->orderBy('date', 'asc')
                            ->first();

                        if (!$nextItinerary) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => 'Seluruh sesi perjalanan untuk permohonan ini telah selesai.',
                            ], 422);
                        }
                    }
                } else { // checkin
                    $hasActiveMorning = $todayItinerary->morning_status === 'on_going';
                    $hasActiveAfternoon = $todayItinerary->afternoon_status === 'on_going';

                    if (!$hasActiveMorning && !$hasActiveAfternoon && $todayItinerary->status !== 'on_going' && $vehicleRequest->status !== RequestStatus::ON_GOING) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'Tidak ada sesi perjalanan yang sedang berjalan (ON GOING) untuk di-checkin.',
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
        }



        $customMessage = null;

        \Illuminate\Support\Facades\DB::transaction(function () use ($vehicleRequest, $validated, $targetTrip, &$customMessage) {
            $todayItinerary = \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)
                ->where('date', now()->format('Y-m-d'))
                ->first()
                ?? \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)
                    ->whereIn('status', ['pending', 'assigned', 'on_going'])
                    ->orderBy('date', 'asc')
                    ->first();

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

                    if ($vehicleRequest->status !== RequestStatus::ON_GOING) {
                        $vehicleRequest->update([
                            'status' => RequestStatus::ON_GOING,
                            'started_at' => $vehicleRequest->started_at ?? now(),
                            'security_checked_out_at' => $vehicleRequest->security_checked_out_at ?? now(),
                            'security_checkout_by' => $vehicleRequest->security_checkout_by ?? $validated['security_name'],
                            'security_checkout_notes' => $vehicleRequest->security_checkout_notes ?? $validated['notes'] ?? null,
                        ]);
                    }
                } else if ($todayItinerary) {
                    $driver = $todayItinerary->driver ?? $vehicleRequest->driver;
                    $vehicle = $todayItinerary->vehicle ?? $vehicleRequest->vehicle;

                    if ($todayItinerary->morning_status !== 'completed' && $todayItinerary->morning_status !== 'on_going') {
                        // Checkout Sesi 1
                        $todayItinerary->update([
                            'morning_status' => 'on_going',
                            'morning_checked_out_at' => now(),
                            'morning_checkout_by' => $validated['security_name'],
                            'morning_checkout_notes' => $validated['notes'] ?? null,
                            'status' => 'on_going',
                            'security_checked_out_at' => $todayItinerary->security_checked_out_at ?? now(),
                        ]);

                        if ($driver) {
                            $driver->update(['availability_status' => 'on_trip']);
                        }
                        if ($vehicle) {
                            $vehicle->update(['status' => 'In Use']);
                        }

                        $vehicleRequest->update([
                            'status' => RequestStatus::ON_GOING,
                            'started_at' => $vehicleRequest->started_at ?? now(),
                            'security_checked_out_at' => $vehicleRequest->security_checked_out_at ?? now(),
                            'security_checkout_by' => $vehicleRequest->security_checkout_by ?? $validated['security_name'],
                            'security_checkout_notes' => $vehicleRequest->security_checkout_notes ?? $validated['notes'] ?? null,
                        ]);

                        $customMessage = 'Scan Checkout Sesi 1 (' . ($todayItinerary->morning_destination ?? 'Perjalanan Pagi') . ') berhasil. Status Driver & Mobil: ON TRIP.';
                    } else if ($todayItinerary->morning_status === 'completed' && $todayItinerary->afternoon_status !== 'completed') {
                        // Checkout Sesi 2
                        $todayItinerary->update([
                            'afternoon_status' => 'on_going',
                            'afternoon_checked_out_at' => now(),
                            'afternoon_checkout_by' => $validated['security_name'],
                            'afternoon_checkout_notes' => $validated['notes'] ?? null,
                            'status' => 'on_going',
                        ]);

                        if ($driver) {
                            $driver->update(['availability_status' => 'on_trip']);
                        }
                        if ($vehicle) {
                            $vehicle->update(['status' => 'In Use']);
                        }

                        $vehicleRequest->update([
                            'status' => RequestStatus::ON_GOING,
                        ]);

                        $customMessage = 'Scan Checkout Sesi 2 (' . ($todayItinerary->afternoon_destination ?? 'Perjalanan Sore') . ') berhasil. Status Driver & Mobil: ON TRIP.';
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
            } else { // Check-IN
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
                } else if ($todayItinerary) {
                    $driver = $todayItinerary->driver ?? $vehicleRequest->driver;
                    $vehicle = $todayItinerary->vehicle ?? $vehicleRequest->vehicle;

                    if ($todayItinerary->morning_status === 'on_going') {
                        $todayItinerary->update([
                            'morning_status' => 'completed',
                            'morning_checked_in_at' => now(),
                            'morning_checkin_by' => $validated['security_name'],
                            'morning_checkin_notes' => $validated['notes'] ?? null,
                        ]);

                        // RELEASE DRIVER & VEHICLE TO AVAILABLE FOR SESSIONS GAP
                        if ($driver) {
                            $driver->update(['availability_status' => 'available']);
                        }
                        if ($vehicle) {
                            $vehicle->update(['status' => 'Available']);
                        }

                        if (empty($todayItinerary->afternoon_destination)) {
                            $todayItinerary->update([
                                'status' => 'completed',
                                'security_checked_in_at' => now(),
                            ]);
                        }

                        $allCount = \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)->count();
                        $doneCount = \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)->where('status', 'completed')->count();
                        if ($doneCount >= $allCount) {
                            $vehicleRequest->update([
                                'status'                 => RequestStatus::COMPLETED,
                                'completed_at'           => $vehicleRequest->completed_at ?? now(),
                                'security_checked_in_at' => now(),
                                'security_checkin_by'    => $validated['security_name'],
                                'security_checkin_notes' => $validated['notes'] ?? null,
                            ]);
                        }

                        $customMessage = 'Scan Checkin Sesi 1 berhasil. Driver & Mobil rilis kembali ke status AVAILABLE untuk jeda Sesi 2.';
                    } else if ($todayItinerary->afternoon_status === 'on_going') {
                        $todayItinerary->update([
                            'afternoon_status' => 'completed',
                            'afternoon_checked_in_at' => now(),
                            'afternoon_checkin_by' => $validated['security_name'],
                            'afternoon_checkin_notes' => $validated['notes'] ?? null,
                            'status' => 'completed',
                            'security_checked_in_at' => now(),
                        ]);

                        if ($driver) {
                            $driver->update(['availability_status' => 'available']);
                        }
                        if ($vehicle) {
                            $vehicle->update(['status' => 'Available']);
                        }

                        $allCount = \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)->count();
                        $doneCount = \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)->where('status', 'completed')->count();
                        if ($doneCount >= $allCount) {
                            $vehicleRequest->update([
                                'status'                 => RequestStatus::COMPLETED,
                                'completed_at'           => $vehicleRequest->completed_at ?? now(),
                                'security_checked_in_at' => now(),
                                'security_checkin_by'    => $validated['security_name'],
                                'security_checkin_notes' => $validated['notes'] ?? null,
                            ]);
                        }

                        $customMessage = 'Scan Checkin Sesi 2 berhasil. Driver & Mobil kembali ke status AVAILABLE.';
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
                'message' => $customMessage ?? ($targetTrip ? 'Checkout unit armada berhasil dikonfirmasi.' : 'Scan Berangkat (Checkout) berhasil dikonfirmasi.'),
                'data'    => $vehicleRequest->load(['user', 'operationalTrip.vehicle', 'operationalTrip.driver', 'passengers', 'itineraries.driver', 'itineraries.vehicle']),
            ], 200);
        } else {
            return response()->json([
                'status'  => 'success',
                'message' => $customMessage ?? ($targetTrip ? 'Checkin unit armada berhasil dikonfirmasi.' : 'Scan Kembali (Checkin) berhasil dikonfirmasi.'),
                'data'    => $vehicleRequest->load(['user', 'operationalTrip.vehicle', 'operationalTrip.driver', 'passengers', 'itineraries.driver', 'itineraries.vehicle']),
            ], 200);
        }
    }

    public function lookup(Request $request): JsonResponse
    {
        $token = $request->query('qr_code_token');
        if (!empty($token) && (filter_var($token, FILTER_VALIDATE_URL) || str_contains($token, '?token='))) {
            $parts = parse_url($token);
            if (isset($parts['query'])) {
                parse_str($parts['query'], $queryParts);
                if (isset($queryParts['token'])) {
                    $token = $queryParts['token'];
                }
            } else {
                preg_match('/[?&]token=([^&]+)/', $token, $matches);
                if (isset($matches[1])) {
                    $token = $matches[1];
                }
            }
        }

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



        // Auto-heal/sync itinerary statuses if request is already COMPLETED
        if ($vehicleRequest->status === RequestStatus::COMPLETED) {
            foreach ($vehicleRequest->itineraries as $it) {
                if ($it->status !== 'completed' || $it->morning_status === 'on_going' || $it->afternoon_status === 'on_going') {
                    $it->update([
                        'status' => 'completed',
                        'morning_status' => $it->morning_destination ? 'completed' : $it->morning_status,
                        'afternoon_status' => $it->afternoon_destination ? 'completed' : $it->afternoon_status,
                    ]);
                    if ($it->driver) {
                        $it->driver->update(['availability_status' => 'available']);
                    }
                    if ($it->vehicle) {
                        $it->vehicle->update(['status' => 'Available']);
                    }
                }
            }
            $vehicleRequest->load('itineraries');
        }

        return response()->json([
            'status' => 'success',
            'data'   => new \App\Http\Resources\RequestResource($vehicleRequest->load(['user', 'operationalTrip.vehicle', 'operationalTrip.driver', 'passengers', 'itineraries.driver', 'itineraries.vehicle', 'operationalTrips.driver', 'operationalTrips.vehicle', 'assignments.driver'])),
        ], 200);
    }
}
