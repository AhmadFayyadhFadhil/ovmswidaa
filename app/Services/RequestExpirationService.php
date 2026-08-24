<?php

namespace App\Services;

use App\Models\Request as VehicleRequest;
use App\Enums\RequestStatus;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequestExpirationService
{
    /**
     * Scan and process auto-cancellation for scheduled requests that have passed start_time by >= 24 hours
     * and have not started yet (no security_checked_out_at and not on_going).
     */
    public static function checkAndCancelExpired(): int
    {
        $expiredThreshold = now()->subHours(24);

        $validScheduledStatuses = [
            RequestStatus::ASSIGNED_BY_GA->value,
            RequestStatus::APPROVED_DEPARTMENT->value,
            'driver_assigned',
            'assigned_by_ga',
            'approved_hrd_ga',
            'approved_hrd',
            'waiting_driver',
        ];

        $expiredRequests = VehicleRequest::whereIn('status', $validScheduledStatuses)
            ->whereNull('security_checked_out_at')
            ->where('start_time', '<=', $expiredThreshold)
            ->get();

        $processedCount = 0;

        foreach ($expiredRequests as $vehicleRequest) {
            try {
                DB::transaction(function () use ($vehicleRequest, &$processedCount) {
                    $formattedSchedule = $vehicleRequest->start_time
                        ? \Carbon\Carbon::parse($vehicleRequest->start_time)->format('d/m/Y H:i')
                        : 'jadwal yang ditentukan';

                    $cancelReason = "Pembatalan Otomatis Sistem: Permintaan dibatalkan secara otomatis karena telah melewati batas waktu 24 jam dari jadwal keberangkatan ({$formattedSchedule}) dan belum dimulai. Silakan buat pengajuan ulang.";

                    $vehicleRequest->update([
                        'status' => RequestStatus::CANCELLED,
                        'rejected_reason' => $cancelReason,
                        'cancelled_at' => now(),
                    ]);

                    // Release assigned driver
                    if ($vehicleRequest->driver_id) {
                        DriverTaskQueueService::restorePendingDriverDuty($vehicleRequest->driver_id);
                    }

                    // Release assigned vehicle
                    if ($vehicleRequest->vehicle) {
                        $vehicleRequest->vehicle->update(['status' => 'Available']);
                    }

                    // Release operational trips if any
                    $trips = \App\Models\OperationalTrip::where('request_id', $vehicleRequest->id)->get();
                    foreach ($trips as $trip) {
                        $trip->update(['status' => 'cancelled']);
                        if ($trip->driver_id) {
                            DriverTaskQueueService::restorePendingDriverDuty($trip->driver_id);
                        }
                        if ($trip->vehicle) {
                            $trip->vehicle->update(['status' => 'Available']);
                        }
                    }

                    // Release itineraries if any
                    if (method_exists($vehicleRequest, 'itineraries')) {
                        $vehicleRequest->itineraries()->update(['status' => 'cancelled']);
                    }

                    // Write audit log
                    try {
                        AuditLog::create([
                            'user_id' => $vehicleRequest->user_id,
                            'action' => 'AUTO_CANCEL_EXPIRED',
                            'category' => 'System',
                            'details' => "Request #{$vehicleRequest->id} dibatalkan otomatis oleh sistem karena melewati 24 jam dari jadwal keberangkatan ({$formattedSchedule}).",
                            'ip_address' => '127.0.0.1',
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning("Failed to record audit log for expired request #{$vehicleRequest->id}: " . $e->getMessage());
                    }

                    $processedCount++;
                });
            } catch (\Throwable $e) {
                Log::error("Failed to auto-cancel expired request #{$vehicleRequest->id}: " . $e->getMessage());
            }
        }

        return $processedCount;
    }
}
