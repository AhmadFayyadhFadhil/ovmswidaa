<?php

namespace App\Observers;

use App\Models\Vehicle;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

class VehicleObserver
{
    /**
     * Handle the Vehicle "created" event.
     */
    public function created(Vehicle $vehicle): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'auditable_id' => $vehicle->id,
            'auditable_type' => Vehicle::class,
            'action' => 'created',
            'new_values' => $vehicle->toArray(),
            'old_values' => null,
        ]);
    }

    /**
     * Handle the Vehicle "updated" event.
     */
    public function updated(Vehicle $vehicle): void
    {
        $changes = $vehicle->getChanges();
        
        // Only log if there are actual changes (exclude timestamps)
        if (!empty(array_diff_key($changes, ['updated_at' => null]))) {
            AuditLog::create([
                'user_id' => Auth::id(),
                'auditable_id' => $vehicle->id,
                'auditable_type' => Vehicle::class,
                'action' => 'updated',
                'old_values' => $vehicle->getOriginal(),
                'new_values' => $vehicle->toArray(),
            ]);
        }
    }

    /**
     * Handle the Vehicle "deleted" event.
     */
    public function deleted(Vehicle $vehicle): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'auditable_id' => $vehicle->id,
            'auditable_type' => Vehicle::class,
            'action' => 'deleted',
            'old_values' => $vehicle->toArray(),
            'new_values' => null,
        ]);
    }

    /**
     * Handle the Vehicle "restored" event.
     */
    public function restored(Vehicle $vehicle): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'auditable_id' => $vehicle->id,
            'auditable_type' => Vehicle::class,
            'action' => 'restored',
            'new_values' => $vehicle->toArray(),
            'old_values' => null,
        ]);
    }

    /**
     * Handle the Vehicle "force deleted" event.
     */
    public function forceDeleted(Vehicle $vehicle): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'auditable_id' => $vehicle->id,
            'auditable_type' => Vehicle::class,
            'action' => 'force_deleted',
            'old_values' => $vehicle->toArray(),
            'new_values' => null,
        ]);
    }
}
