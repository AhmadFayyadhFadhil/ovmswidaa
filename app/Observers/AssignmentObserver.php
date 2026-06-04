<?php

namespace App\Observers;

use App\Models\Assignment;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

class AssignmentObserver
{
    /**
     * Handle the Assignment "created" event.
     */
    public function created(Assignment $assignment): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'auditable_id' => $assignment->id,
            'auditable_type' => Assignment::class,
            'action' => 'created',
            'new_values' => $assignment->toArray(),
            'old_values' => null,
        ]);

        // Mark driver as assigned to prevent double assignment until driver responds
        try {
            if ($assignment->driver) {
                $assignment->driver->update(['availability_status' => 'assigned']);
            }
        } catch (\Throwable $e) {
            \Log::error('AssignmentObserver created: failed to update driver availability', ['err' => $e->getMessage()]);
        }
    }

    /**
     * Handle the Assignment "updated" event.
     */
    public function updated(Assignment $assignment): void
    {
        $changes = $assignment->getChanges();
        
        // Only log if there are actual changes (exclude timestamps)
        if (!empty(array_diff_key($changes, ['updated_at' => null]))) {
            AuditLog::create([
                'user_id' => Auth::id(),
                'auditable_id' => $assignment->id,
                'auditable_type' => Assignment::class,
                'action' => 'updated',
                'old_values' => $assignment->getOriginal(),
                'new_values' => $assignment->toArray(),
            ]);
        }

        // React on status changes to keep driver availability in sync
        if (array_key_exists('status', $changes)) {
            try {
                $status = $changes['status'];
                if ($status === 'rejected' && $assignment->driver) {
                    $assignment->driver->update(['availability_status' => 'available']);
                }

                if ($status === 'accepted' && $assignment->driver) {
                    $assignment->driver->update(['availability_status' => 'assigned']);
                }
            } catch (\Throwable $e) {
                \Log::error('AssignmentObserver updated: failed to sync driver availability', ['err' => $e->getMessage()]);
            }
        }
    }

    /**
     * Handle the Assignment "deleted" event.
     */
    public function deleted(Assignment $assignment): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'auditable_id' => $assignment->id,
            'auditable_type' => Assignment::class,
            'action' => 'deleted',
            'old_values' => $assignment->toArray(),
            'new_values' => null,
        ]);

        // Ensure driver availability is restored when assignment removed
        try {
            if ($assignment->driver) {
                $assignment->driver->update(['availability_status' => 'available']);
            }
        } catch (\Throwable $e) {
            \Log::error('AssignmentObserver deleted: failed to restore driver availability', ['err' => $e->getMessage()]);
        }
    }

    /**
     * Handle the Assignment "restored" event.
     */
    public function restored(Assignment $assignment): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'auditable_id' => $assignment->id,
            'auditable_type' => Assignment::class,
            'action' => 'restored',
            'old_values' => null,
            'new_values' => $assignment->toArray(),
        ]);

        // When restored, mark driver as assigned again
        try {
            if ($assignment->driver) {
                $assignment->driver->update(['availability_status' => 'assigned']);
            }
        } catch (\Throwable $e) {
            \Log::error('AssignmentObserver restored: failed to set driver availability', ['err' => $e->getMessage()]);
        }
    }
}
