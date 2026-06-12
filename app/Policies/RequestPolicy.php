<?php

namespace App\Policies;

use App\Models\Request;
use App\Models\User;
use App\Enums\RequestStatus;
use Illuminate\Auth\Access\Response;

class RequestPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Admin, Approver, or HRD&GA head can view requests.
        return $user->hasRoleDirect(['Admin', 'Approver']) || $user->isHrGaHead();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Request $request): bool
    {
        // User can view their own requests or if they have permission to view all
        return $user->id === $request->user_id || 
               $user->hasRoleDirect(['Admin', 'Approver']) ||
               $user->isHrGaHead();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionDirect('create-request');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Request $request): bool
    {
        // Only owner can update pending requests, or admin
        return ($user->id === $request->user_id && $request->status === RequestStatus::SUBMITTED) || 
               $user->hasRoleDirect('Admin');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Request $request): bool
    {
        // Only owner can delete pending requests, or admin
        return ($user->id === $request->user_id && $request->status === RequestStatus::SUBMITTED) || 
               $user->hasRoleDirect('Admin');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Request $request): bool
    {
        return $user->hasRoleDirect('Admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Request $request): bool
    {
        return $user->hasRoleDirect('Admin');
    }

    /**
     * Determine whether the user can approve a request.
     */
    public function approve(User $user, Request $request): bool
    {
        // Only Admin can always approve
        if ($user->hasRoleDirect('Admin')) {
            return true;
        }

        // Approver can approve if they have the permission AND same department AND are department head
        // HR&GA head can also approve any request at HRD stage.
        if ($user->hasRoleDirect('Approver')) {
            if ($user->isHrGaHead() && $request->status === RequestStatus::APPROVED_DEPARTMENT) {
                return true;
            }

            if ($request->status === RequestStatus::SUBMITTED) {
                return $user->hasPermissionDirect('approve-request') &&
                       $user->is_department_head &&
                       in_array($request->department_id, $user->departmentGroup(), true);
            }
        }

        return false;
    }

    /**
     * Determine whether the user can reject a request.
     */
    public function reject(User $user, Request $request): bool
    {
        // Only Admin can always reject
        if ($user->hasRoleDirect('Admin')) {
            return true;
        }

        if ($user->hasRoleDirect('Approver')) {
            if ($user->isHrGaHead() && $request->status === RequestStatus::APPROVED_DEPARTMENT) {
                return true;
            }

            if ($request->status === RequestStatus::SUBMITTED) {
                return $user->hasPermissionDirect('reject-request') &&
                       $user->is_department_head &&
                       in_array($request->department_id, $user->departmentGroup(), true);
            }
        }

        return false;
    }
}
