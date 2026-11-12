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
    public function execute(
        Request $request, 
        string $role, 
        string $status, 
        ?string $notes = null, 
        ?string $approvedByName = null,
        array $options = []
    ): Request
    {
        $updatedRequest = DB::transaction(function () use ($request, $role, $status, $notes, $approvedByName, $options) {
            $user = Auth::user();

            // Validate approver authorization
            if (!$user->hasRoleDirect('Admin') && !$user->hasRoleDirect('Approver') && !$user->hasRoleDirect('GA') && !$user->isHrGaHead()) {
                throw new Exception("Anda tidak berhak untuk approve/reject request ini.");
            }

            $isAdmin = $user->hasRoleDirect('Admin');

            // Determine if approval is executed by gaTeam backup account
            $isGaTeamAccount = false;
            if ($user) {
                $nikLower = strtolower($user->nik ?? '');
                $emailLower = strtolower($user->email ?? '');
                $nameLower = strtolower($user->name ?? '');
                if ($nikLower === 'gateam' || $emailLower === 'gateam@widatra.com' || str_contains($nameLower, 'gateam') || str_contains($nameLower, 'ga team')) {
                    $isGaTeamAccount = true;
                }
            }

            // Prepare clear notes & role label for Admin
            $recordedRole = $isGaTeamAccount ? 'ga_team' : $role;
            $finalNotes = $notes;
            if ($isAdmin) {
                if ($status === 'approved' && (!$notes || trim($notes) === '' || trim($notes) === 'Disetujui')) {
                    $stageDesc = match($role) {
                        'dept_head' => 'Mewakili Kepala Departemen',
                        'coordinator' => 'Alokasi Koordinator Driver',
                        'hrd_head', 'ga_final' => 'GA & HRD Final',
                        default => 'Super Admin'
                    };
                    $finalNotes = "Disetujui oleh Admin ({$user->name}) — {$stageDesc}";
                }
            }

            // Create approval record
            RequestApproval::create([
                'request_id' => $request->id,
                'approver_id' => $user->id,
                'role' => $recordedRole,
                'status' => $status,
                'notes' => $finalNotes,
            ]);

            // Update request status
            if ($status === 'rejected') {
                $newStatus = RequestStatus::REJECTED;
                $request->update([
                    'status'          => $newStatus,
                    'rejected_reason' => $finalNotes,
                ]);
            } else {
                $updatePayload = [];

                // Assign driver or vehicle if passed in options
                if (!empty($options['driver_id'])) {
                    $updatePayload['driver_id'] = $options['driver_id'];
                }
                if (!empty($options['vehicle_id'])) {
                    $updatePayload['vehicle_id'] = $options['vehicle_id'];
                }

                if ($role === 'dept_head') {
                    $newStatus = RequestStatus::APPROVED_DEPARTMENT;
                } else {
                    $newStatus = RequestStatus::DRIVER_ASSIGNED;
                    $updatePayload['ga_approved_by'] = $user->id;
                    $updatePayload['ga_approved_at'] = now();
                    $updatePayload['driver_response_status'] = 'accepted';

                    if ($isGaTeamAccount) {
                        $updatePayload['ga_approval_source'] = 'ga_team';
                        $cleanApprovedName = $approvedByName ? trim($approvedByName) : '';
                        $updatePayload['ga_approved_by_name'] = $cleanApprovedName !== '' ? $cleanApprovedName : 'Tim GA Operasional';
                    } elseif ($isAdmin) {
                        $updatePayload['ga_approval_source'] = 'admin';
                        $updatePayload['ga_approved_by_name'] = $user->name ?? 'Administrator';
                    } else {
                        $updatePayload['ga_approval_source'] = 'primary';
                        $updatePayload['ga_approved_by_name'] = $approvedByName ? trim($approvedByName) : ($user->name ?? 'Melodi Bella Astria');
                    }

                    if (!$request->qr_code_token) {
                        $updatePayload['qr_code_token'] = 'REQ-' . time() . '-' . bin2hex(random_bytes(4));
                    }

                    // Auto-accept assignment records
                    try {
                        \App\Models\Assignment::where('request_id', $request->id)
                            ->update(['status' => 'accepted']);
                    } catch (\Throwable $ex) {}

                    // Ensure operational trip is created as scheduled
                    $driverId = $updatePayload['driver_id'] ?? $request->driver_id;
                    $vehicleId = $updatePayload['vehicle_id'] ?? $request->vehicle_id;
                    if ($driverId && $vehicleId) {
                        try {
                            \App\Models\OperationalTrip::firstOrCreate(
                                ['request_id' => $request->id, 'driver_id' => $driverId],
                                [
                                    'vehicle_id'     => $vehicleId,
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
            } elseif ($updatedRequest->status === RequestStatus::DRIVER_ASSIGNED) {
                \App\Services\EmailNotificationService::sendDriverAssigned($updatedRequest->fresh(['user', 'department', 'driver', 'vehicle', 'assignments.driver', 'assignments.vehicle']));
            }
        } catch (\Throwable $mailErr) {
            \Illuminate\Support\Facades\Log::warning('Failed triggering email on approval/rejection: ' . $mailErr->getMessage());
        }

        return $updatedRequest;
    }
}
