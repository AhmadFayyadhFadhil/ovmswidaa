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
            if (!$user->hasRoleDirect('Admin') && !$user->hasRoleDirect('Approver') && !$user->hasRoleDirect('GA')) {
                throw new Exception("Anda tidak berhak untuk approve/reject request ini.");
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
            if ($status === 'rejected') {
                $newStatus = RequestStatus::REJECTED;
            } else {
                $newStatus = RequestStatus::DRIVER_ASSIGNED;
                if (!$request->qr_code_token) {
                    $request->update([
                        'qr_code_token' => 'REQ-' . time() . '-' . bin2hex(random_bytes(4)),
                    ]);
                }
            }

            $request->update(['status' => $newStatus]);

            return $request;
        });
    }
}

