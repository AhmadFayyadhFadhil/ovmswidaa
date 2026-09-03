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
        $updatedRequest = DB::transaction(function () use ($request, $role, $status, $notes) {
            $user = Auth::user();

            // Validate approver authorization
            if (!$user->hasRoleDirect('Admin') && !$user->hasRoleDirect('Approver') && !$user->hasRoleDirect('GA') && !$user->isHrGaHead()) {
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
                $request->update([
                    'status'          => $newStatus,
                    'rejected_reason' => $notes,
                ]);
            } else {
                $updatePayload = [];
                if ($role === 'dept_head') {
                    $newStatus = RequestStatus::APPROVED_DEPARTMENT;
                } else {
                    $newStatus = RequestStatus::DRIVER_ASSIGNED;
                    $updatePayload['ga_approved_by'] = $user->id;
                    $updatePayload['ga_approved_at'] = now();
                    $updatePayload['driver_response_status'] = 'accepted';

                    if (!$request->qr_code_token) {
                        $updatePayload['qr_code_token'] = 'REQ-' . time() . '-' . bin2hex(random_bytes(4));
                    }

                    // Auto-accept assignment records
                    try {
                        \App\Models\Assignment::where('request_id', $request->id)
                            ->update(['status' => 'accepted']);
                    } catch (\Throwable $ex) {}

                    // Ensure operational trip is created as scheduled
                    if ($request->driver_id && $request->vehicle_id) {
                        try {
                            \App\Models\OperationalTrip::firstOrCreate(
                                ['request_id' => $request->id, 'driver_id' => $request->driver_id],
                                [
                                    'vehicle_id'     => $request->vehicle_id,
                                    'start_datetime' => $request->start_time ?? now(),
                                    'end_datetime'   => $request->end_time ?? now()->addHours(4),
                                    'status'         => 'scheduled',
                                ]
                            );
                        } catch (\Throwable $ex) {}
                    }
                }

                $updatePayload['status'] = $newStatus;
                $request->update($updatePayload);
            }

            return $request;
        });

        // Trigger safe email notification
        try {
            if ($status === 'rejected') {
                \App\Services\EmailNotificationService::sendRequestRejected($updatedRequest, $notes);
            } elseif ($updatedRequest->status === RequestStatus::APPROVED_DEPARTMENT) {
                \App\Services\EmailNotificationService::sendDepartmentApproved($updatedRequest);
            }
        } catch (\Throwable $mailErr) {
            \Illuminate\Support\Facades\Log::warning('Failed triggering email on approval/rejection: ' . $mailErr->getMessage());
        }

        return $updatedRequest;
    }
}
