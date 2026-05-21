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
    }
}
