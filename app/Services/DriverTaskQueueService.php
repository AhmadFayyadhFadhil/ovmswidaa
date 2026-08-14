<?php

namespace App\Services;

use App\Models\User;
use App\Models\Request as VehicleRequest;
use App\Enums\RequestStatus;
use Illuminate\Support\Facades\Log;

class DriverTaskQueueService
{
    /**
     * Check if the driver has a pending or displaced Normal Priority request.
     * If so, automatically restore the driver's duty status back to that Normal Request.
     */
    public static function restorePendingDriverDuty(int|string|null $driverId): bool
    {
        if (!$driverId) {
            return false;
        }

        $driver = User::find($driverId);
        if (!$driver) {
            return false;
        }

        // Find active/pending requests assigned to this driver that are NOT completed and NOT cancelled
        $pendingRequest = VehicleRequest::where(function ($q) use ($driverId) {
                $q->where('driver_id', $driverId)
                  ->orWhereHas('assignments', function ($aQuery) use ($driverId) {
                      $aQuery->where('driver_id', $driverId);
                  })
                  ->orWhereHas('operationalTrips', function ($oQuery) use ($driverId) {
                      $oQuery->where('driver_id', $driverId);
                  })
                  ->orWhereHas('itineraries', function ($iQuery) use ($driverId) {
                      $iQuery->where('driver_id', $driverId);
                  });
            })
            ->whereIn('status', [
                RequestStatus::DRIVER_ASSIGNED,
                RequestStatus::ON_GOING,
                RequestStatus::APPROVED_HRD_GA,
            ])
            ->orderBy('priority', 'asc') // Non-urgent tasks
            ->orderBy('start_time', 'asc')
            ->first();

        if ($pendingRequest) {
            // Determine if the driver is TRULY on a trip right now
            $isTrulyOnTrip = false;
            if ($pendingRequest->relationLoaded('itineraries') || $pendingRequest->itineraries()->exists()) {
                $isTrulyOnTrip = $pendingRequest->itineraries()->where(function ($q) use ($driverId) {
                    $q->where(function ($sq) use ($driverId) {
                        $sq->where('driver_id', $driverId)->orWhereNull('driver_id');
                    })->where(function ($sq2) {
                        $sq2->where('morning_status', 'on_going')
                            ->orWhere('afternoon_status', 'on_going')
                            ->orWhere('status', 'on_going');
                    });
                })->exists();
            } else {
                $isTrulyOnTrip = ($pendingRequest->status === RequestStatus::ON_GOING);
            }

            // Driver is only 'on_trip' if an active segment is ongoing on the road, otherwise 'available'
            $newStatus = $isTrulyOnTrip ? 'on_trip' : 'available';
            $driver->update(['availability_status' => $newStatus]);

            if ($pendingRequest->status === RequestStatus::APPROVED_HRD_GA) {
                $pendingRequest->update(['status' => RequestStatus::DRIVER_ASSIGNED]);
            }

            $priorityStr = $pendingRequest->priority instanceof \BackedEnum ? $pendingRequest->priority->value : (string)($pendingRequest->priority ?? 'Normal');
            Log::info("DriverTaskQueue: Driver ID {$driverId} status updated to {$newStatus} for Request #{$pendingRequest->id} ({$priorityStr}).");
            return true;
        }

        // If no pending tasks remain, set driver status to available
        $driver->update(['availability_status' => 'available']);
        return false;
    }
}
