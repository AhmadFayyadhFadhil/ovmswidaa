<?php

namespace App\Actions\Approvals;

use App\Models\Request;
use App\Models\RequestApproval;
use App\Enums\RequestStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;

class ApproveRequestAction
{
    public function execute(Request $request, string $role, string $status, ?string $notes = null): Request
    {
        return DB::transaction(function () use ($request, $role, $status, $notes) {
            $user = Auth::user();

            // Validate approver authorization
            if (!$user->hasAnyRole(['Admin', 'GA'])) {
                if ($user->hasRole('Approver')) {
                    // Approver must be department head and from same department
                    if (!$user->is_department_head || $user->department_id !== $request->department_id) {
                        throw new Exception("Anda bukan Kepala Departemen untuk departemen ini atau departemen tidak sesuai.");
                    }
                } else {
                    throw new Exception("Anda tidak berhak untuk approve/reject request ini.");
                }
            }

            // Validate sequence
            if ($role === 'dept_head') {
                if ($request->status !== RequestStatus::SUBMITTED) {
                    throw new Exception("Request cannot be approved by Department Head because it is not in submitted status.");
                }
                $newStatus = $status === 'approved' ? RequestStatus::APPROVED_DEPARTMENT : RequestStatus::REJECTED;
            } elseif ($role === 'hrd_head') {
                if ($request->status !== RequestStatus::APPROVED_DEPARTMENT) {
                    throw new Exception("Request must be approved by Department Head first.");
                }
                $newStatus = $status === 'approved' ? RequestStatus::APPROVED_HRD_GA : RequestStatus::REJECTED;
            } else {
                throw new Exception("Invalid approver role.");
            }

            // Create approval record
            RequestApproval::create([
                'request_id' => $request->id,
                'approver_id' => $user->id,
                'role' => $role,
                'status' => $status,
                'notes' => $notes,
            ]);

            // Update request status
            $request->update(['status' => $newStatus]);

            return $request;
        });
    }
}

