<?php

namespace App\Observers;

use App\Models\Request;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

class RequestObserver
{
    /**
     * Handle the Request "created" event.
     */
    public function created(Request $request): void
    {
        try {
            AuditLog::create([
                'user_id' => Auth::id(),
                'auditable_id' => $request->id,
                'auditable_type' => Request::class,
                'action' => 'created',
                'new_values' => $request->toArray(),
                'old_values' => null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('RequestObserver created audit log error: ' . $e->getMessage());
        }
    }

    /**
     * Handle the Request "updated" event.
     */
    public function updated(Request $request): void
    {
        $changes = $request->getChanges();
        
        // Only log if there are actual changes (exclude timestamps)
        if (!empty(array_diff_key($changes, ['updated_at' => null]))) {
            try {
                AuditLog::create([
                    'user_id' => Auth::id(),
                    'auditable_id' => $request->id,
                    'auditable_type' => Request::class,
                    'action' => 'updated',
                    'old_values' => $request->getOriginal(),
                    'new_values' => $request->toArray(),
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('RequestObserver updated audit log error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Handle the Request "deleted" event.
     */
    public function deleted(Request $request): void
    {
        try {
            AuditLog::create([
                'user_id' => Auth::id(),
                'auditable_id' => $request->id,
                'auditable_type' => Request::class,
                'action' => 'deleted',
                'old_values' => $request->toArray(),
                'new_values' => null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('RequestObserver deleted audit log error: ' . $e->getMessage());
        }
    }

    /**
     * Handle the Request "restored" event.
     */
    public function restored(Request $request): void
    {
        try {
            AuditLog::create([
                'user_id' => Auth::id(),
                'auditable_id' => $request->id,
                'auditable_type' => Request::class,
                'action' => 'restored',
                'new_values' => $request->toArray(),
                'old_values' => null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('RequestObserver restored audit log error: ' . $e->getMessage());
        }
    }

    /**
     * Handle the Request "force deleted" event.
     */
    public function forceDeleted(Request $request): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'auditable_id' => $request->id,
            'auditable_type' => Request::class,
            'action' => 'force_deleted',
            'old_values' => $request->toArray(),
            'new_values' => null,
        ]);
    }
}
