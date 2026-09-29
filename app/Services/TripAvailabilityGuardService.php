<?php

namespace App\Services;

use App\Models\Request as VehicleRequest;
use App\Models\OperationalTrip;
use App\Models\RequestItinerary;
use App\Models\Vehicle;
use App\Models\User;
use App\Enums\RequestStatus;

class TripAvailabilityGuardService
{
    /**
     * Check if a vehicle currently has an unfinished/active trip in another request
     * where start_km/checkout has occurred but end_km/checkin has not finished.
     */
    public static function getUnfinishedTripForVehicle(int $vehicleId, ?int $excludeRequestId = null): ?array
    {
        // 1. Check in requests table (standard single-day requests)
        $reqQuery = VehicleRequest::where('vehicle_id', $vehicleId)
            ->where('is_external', false)
            ->whereNotIn('status', [
                RequestStatus::COMPLETED->value,
                RequestStatus::REJECTED->value,
                RequestStatus::CANCELLED->value,
            ]);

        if ($excludeRequestId) {
            $reqQuery->where('id', '!=', $excludeRequestId);
        }

        // Active operational criteria:
        // Already started (start_km not null, or started_at not null, or security_checked_out_at not null, or on_going)
        // BUT not yet finished (end_km is null, or (security_checked_out_at is not null and security_checked_in_at is null))
        $activeReq = (clone $reqQuery)
            ->where(function ($q) {
                $q->whereNotNull('start_km')
                  ->orWhereNotNull('started_at')
                  ->orWhereNotNull('security_checked_out_at')
                  ->orWhere('status', RequestStatus::ON_GOING->value);
            })
            ->where(function ($q) {
                $q->whereNull('end_km')
                  ->orWhere(function ($sub) {
                      $sub->whereNotNull('security_checked_out_at')
                          ->whereNull('security_checked_in_at');
                  });
            })
            ->with(['driver', 'vehicle'])
            ->latest('updated_at')
            ->first();

        if ($activeReq) {
            $vName = $activeReq->vehicle?->name ?? 'Kendaraan';
            $plate = $activeReq->vehicle?->plate_number ?? '';
            $dName = $activeReq->driver?->name ?? 'Driver';
            return [
                'request_id'      => $activeReq->id,
                'vehicle_id'      => $vehicleId,
                'vehicle_name'    => $vName,
                'plate_number'    => $plate,
                'driver_id'       => $activeReq->driver_id,
                'driver_name'     => $dName,
                'status'          => $activeReq->status,
                'missing_end_km'  => empty($activeReq->end_km),
                'missing_checkin' => !empty($activeReq->security_checked_out_at) && empty($activeReq->security_checked_in_at),
                'message'         => "Kendaraan {$vName} ({$plate}) masih aktif pada permohonan #REQ-{$activeReq->id} (Driver: {$dName}) dan belum menginput KM Akhir.",
            ];
        }

        // 2. Check in operational_trips table (for multi-car requests)
        $tripQuery = OperationalTrip::where('vehicle_id', $vehicleId)
            ->whereNotIn('status', ['completed', 'cancelled']);

        if ($excludeRequestId) {
            $tripQuery->where('request_id', '!=', $excludeRequestId);
        }

        $activeTrip = (clone $tripQuery)
            ->where(function ($q) {
                $q->whereNotNull('start_km')
                  ->orWhereNotNull('start_datetime')
                  ->orWhereNotNull('security_checked_out_at')
                  ->orWhere('status', 'on_going');
            })
            ->where(function ($q) {
                $q->whereNull('end_km')
                  ->orWhere(function ($sub) {
                      $sub->whereNotNull('security_checked_out_at')
                          ->whereNull('security_checked_in_at');
                  });
            })
            ->with(['driver', 'vehicle', 'request'])
            ->latest('updated_at')
            ->first();

        if ($activeTrip) {
            $vName = $activeTrip->vehicle?->name ?? 'Kendaraan';
            $plate = $activeTrip->vehicle?->plate_number ?? '';
            $dName = $activeTrip->driver?->name ?? 'Driver';
            return [
                'request_id'      => $activeTrip->request_id,
                'vehicle_id'      => $vehicleId,
                'vehicle_name'    => $vName,
                'plate_number'    => $plate,
                'driver_id'       => $activeTrip->driver_id,
                'driver_name'     => $dName,
                'status'          => $activeTrip->status,
                'missing_end_km'  => empty($activeTrip->end_km),
                'missing_checkin' => !empty($activeTrip->security_checked_out_at) && empty($activeTrip->security_checked_in_at),
                'message'         => "Kendaraan {$vName} ({$plate}) masih aktif pada permohonan #REQ-{$activeTrip->request_id} (Driver: {$dName}) dan belum menginput KM Akhir.",
            ];
        }

        // 3. Check in request_itineraries table (for multi-day requests)
        $itQuery = RequestItinerary::where('vehicle_id', $vehicleId)
            ->whereNotIn('status', ['completed', 'cancelled']);

        if ($excludeRequestId) {
            $itQuery->where('request_id', '!=', $excludeRequestId);
        }

        $activeIt = (clone $itQuery)
            ->where(function ($q) {
                $q->whereNotNull('start_km')
                  ->orWhereNotNull('morning_checked_out_at')
                  ->orWhereNotNull('afternoon_checked_out_at')
                  ->orWhere('status', 'on_going')
                  ->orWhere('morning_status', 'on_going')
                  ->orWhere('afternoon_status', 'on_going');
            })
            ->where(function ($q) {
                $q->whereNull('end_km')
                  ->orWhere(function ($sub) {
                      $sub->whereNotNull('morning_checked_out_at')
                          ->whereNull('morning_checked_in_at');
                  })
                  ->orWhere(function ($sub2) {
                      $sub2->whereNotNull('afternoon_checked_out_at')
                           ->whereNull('afternoon_checked_in_at');
                  });
            })
            ->with(['driver', 'vehicle', 'request'])
            ->latest('updated_at')
            ->first();

        if ($activeIt) {
            $vName = $activeIt->vehicle?->name ?? 'Kendaraan';
            $plate = $activeIt->vehicle?->plate_number ?? '';
            $dName = $activeIt->driver?->name ?? 'Driver';
            return [
                'request_id'      => $activeIt->request_id,
                'vehicle_id'      => $vehicleId,
                'vehicle_name'    => $vName,
                'plate_number'    => $plate,
                'driver_id'       => $activeIt->driver_id,
                'driver_name'     => $dName,
                'status'          => $activeIt->status,
                'missing_end_km'  => empty($activeIt->end_km),
                'missing_checkin' => !empty($activeIt->morning_checked_out_at) && empty($activeIt->morning_checked_in_at),
                'message'         => "Kendaraan {$vName} ({$plate}) masih aktif pada permohonan #REQ-{$activeIt->request_id} (Driver: {$dName}) dan belum menginput KM Akhir.",
            ];
        }

        return null;
    }

    /**
     * Check if a driver currently has an unfinished/active trip in another request.
     */
    public static function getUnfinishedTripForDriver(int $driverId, ?int $excludeRequestId = null): ?array
    {
        // 1. Check in requests
        $reqQuery = VehicleRequest::where('driver_id', $driverId)
            ->whereNotIn('status', [
                RequestStatus::COMPLETED->value,
                RequestStatus::REJECTED->value,
                RequestStatus::CANCELLED->value,
            ]);

        if ($excludeRequestId) {
            $reqQuery->where('id', '!=', $excludeRequestId);
        }

        $activeReq = (clone $reqQuery)
            ->where(function ($q) {
                $q->whereNotNull('start_km')
                  ->orWhereNotNull('started_at')
                  ->orWhereNotNull('security_checked_out_at')
                  ->orWhere('status', RequestStatus::ON_GOING->value);
            })
            ->where(function ($q) {
                $q->whereNull('end_km')
                  ->orWhere(function ($sub) {
                      $sub->whereNotNull('security_checked_out_at')
                          ->whereNull('security_checked_in_at');
                  });
            })
            ->with(['driver', 'vehicle'])
            ->latest('updated_at')
            ->first();

        if ($activeReq) {
            $vName = $activeReq->vehicle?->name ?? 'Kendaraan Operasional';
            $plate = $activeReq->vehicle?->plate_number ?? '';
            $dName = $activeReq->driver?->name ?? 'Driver';
            return [
                'request_id'   => $activeReq->id,
                'driver_id'    => $driverId,
                'driver_name'  => $dName,
                'vehicle_name' => $vName,
                'plate_number' => $plate,
                'message'      => "Driver {$dName} masih memiliki perjalanan aktif pada permohonan #REQ-{$activeReq->id} yang belum menginput KM Akhir.",
            ];
        }

        // 2. Check operational_trips
        $tripQuery = OperationalTrip::where('driver_id', $driverId)
            ->whereNotIn('status', ['completed', 'cancelled']);

        if ($excludeRequestId) {
            $tripQuery->where('request_id', '!=', $excludeRequestId);
        }

        $activeTrip = (clone $tripQuery)
            ->where(function ($q) {
                $q->whereNotNull('start_km')
                  ->orWhereNotNull('start_datetime')
                  ->orWhereNotNull('security_checked_out_at')
                  ->orWhere('status', 'on_going');
            })
            ->where(function ($q) {
                $q->whereNull('end_km')
                  ->orWhere(function ($sub) {
                      $sub->whereNotNull('security_checked_out_at')
                          ->whereNull('security_checked_in_at');
                  });
            })
            ->with(['driver', 'vehicle', 'request'])
            ->latest('updated_at')
            ->first();

        if ($activeTrip) {
            $vName = $activeTrip->vehicle?->name ?? 'Kendaraan Operasional';
            $plate = $activeTrip->vehicle?->plate_number ?? '';
            $dName = $activeTrip->driver?->name ?? 'Driver';
            return [
                'request_id'   => $activeTrip->request_id,
                'driver_id'    => $driverId,
                'driver_name'  => $dName,
                'vehicle_name' => $vName,
                'plate_number' => $plate,
                'message'      => "Driver {$dName} masih memiliki perjalanan aktif pada permohonan #REQ-{$activeTrip->request_id} yang belum menginput KM Akhir.",
            ];
        }

        return null;
    }

    /**
     * Check if a specific Request is currently locked from starting/recording KM awal
     * due to its vehicle or driver being occupied in an unfinished previous trip.
     */
    public static function getLockForRequest(VehicleRequest $request, ?int $currentUserId = null): ?array
    {
        if ($request->is_external) {
            return null; // External rentals do not have internal odometer locks
        }

        // If this request itself is already completed or cancelled, no lock needed
        if (in_array($request->status, [RequestStatus::COMPLETED, RequestStatus::REJECTED, RequestStatus::CANCELLED])) {
            return null;
        }

        // Determine specific vehicle and driver for this user / trip
        $trips = $request->relationLoaded('operationalTrips') ? $request->operationalTrips : $request->operationalTrips()->get();
        $myTrip = $currentUserId ? ($trips->firstWhere('driver_id', $currentUserId) ?? $trips->first()) : $trips->first();

        $vehicleId = $myTrip?->vehicle_id 
            ?? $request->vehicle_id;

        $driverId = $currentUserId 
            ?? $myTrip?->driver_id 
            ?? $request->driver_id;

        // Check vehicle lock
        if ($vehicleId) {
            $vLock = self::getUnfinishedTripForVehicle((int)$vehicleId, (int)$request->id);
            if ($vLock) {
                return [
                    'is_locked'         => true,
                    'lock_type'         => 'vehicle',
                    'vehicle_name'      => $vLock['vehicle_name'],
                    'plate_number'      => $vLock['plate_number'],
                    'other_request_id'  => $vLock['request_id'],
                    'other_driver_name' => $vLock['driver_name'],
                    'message'           => $vLock['message'],
                ];
            }
        }

        // Check driver lock
        if ($driverId) {
            $dLock = self::getUnfinishedTripForDriver((int)$driverId, (int)$request->id);
            if ($dLock) {
                return [
                    'is_locked'         => true,
                    'lock_type'         => 'driver',
                    'vehicle_name'      => $dLock['vehicle_name'],
                    'plate_number'      => $dLock['plate_number'],
                    'other_request_id'  => $dLock['request_id'],
                    'other_driver_name' => $dLock['driver_name'],
                    'message'           => $dLock['message'],
                ];
            }
        }

        return null;
    }
}
