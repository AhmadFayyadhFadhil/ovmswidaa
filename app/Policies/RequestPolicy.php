<?php

namespace App\Policies;

use App\Models\Request;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RequestPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Approver, GA, Admin can view all requests
        return $user->hasAnyRole(['Admin', 'Approver', 'GA']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Request $request): bool
    {
        // User can view their own requests or if they have permission to view all
        return $user->id === $request->user_id || 
               $user->hasAnyRole(['Admin', 'Approver', 'GA']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create-request');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Request $request): bool
    {
        // Only owner can update pending requests, or admin
        return ($user->id === $request->user_id && $request->status === 'Pending') || 
               $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Request $request): bool
    {
        // Only owner can delete pending requests, or admin
        return ($user->id === $request->user_id && $request->status === 'Pending') || 
               $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Request $request): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Request $request): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can approve a request.
     */
    public function approve(User $user, Request $request): bool
    {
        return $user->hasPermissionTo('approve-request') && $request->status === 'Pending';
    }

    /**
     * Determine whether the user can reject a request.
     */
    public function reject(User $user, Request $request): bool
    {
        return $user->hasPermissionTo('reject-request') && $request->status === 'Pending';
    }
}
