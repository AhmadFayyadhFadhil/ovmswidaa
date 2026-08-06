<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Request as VehicleRequest;
use App\Models\UserNotificationState;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    /**
     * Fetch user notifications with persistent read and deleted statuses.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get notification states for current user
        $states = UserNotificationState::where('user_id', $user->id)
            ->get()
            ->keyBy('notification_id');

        // Fetch requests for generating system notifications
        $requests = VehicleRequest::with(['user', 'department'])
            ->orderBy('id', 'desc')
            ->take(100)
            ->get();

        $notifications = [];

        foreach ($requests as $r) {
            $notifId = (string) $r->id;
            $state = $states->get($notifId);

            // Skip deleted notifications for this user
            if ($state && $state->is_deleted) {
                continue;
            }

            $rawStatus = is_object($r->status) ? $r->status->value : (string) $r->status;
            $employeeName = $r->user ? $r->user->name : 'Staff';

            $dest = 'Tujuan';
            if ($r->destination_city && $r->destination_place) {
                $dest = "{$r->destination_city} - {$r->destination_place}";
            } else if ($r->destination_city) {
                $dest = $r->destination_city;
            } else if ($r->destination) {
                $dest = $r->destination;
            }

            $dateStr = $r->start_time ? $r->start_time->format('d-m-Y') : ($r->created_at ? $r->created_at->format('d-m-Y') : 'Terbaru');

            $severity = 'info';
            $category = 'Operational';

            if (in_array($rawStatus, ['submitted', 'approved_department', 'approved_hrd', 'approved_hrd_ga', 'waiting_driver'])) {
                $severity = 'high';
                $category = 'Approvals';
            } else if ($rawStatus === 'on_going') {
                $severity = 'medium';
                $category = 'Operational';
            } else if ($rawStatus === 'completed') {
                $severity = 'low';
                $category = 'Operational';
            } else if ($rawStatus === 'rejected' || $rawStatus === 'cancelled') {
                $severity = 'low';
                $category = 'System';
            }

            $notifications[] = [
                'id'          => $notifId,
                'title'       => "Pengajuan Armada #{$r->id} ({$employeeName})",
                'description' => "Perjalanan dinas ke {$dest} tanggal {$dateStr}. Status: " . strtoupper(str_replace('_', ' ', $rawStatus)) . ".",
                'timeAgo'     => $dateStr,
                'severity'    => $severity,
                'category'    => $category,
                'isRead'      => $state ? (bool) $state->is_read : false,
                'metadata'    => "REQ ID: #{$r->id}",
                'rawStatus'   => $rawStatus,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data'   => $notifications,
            'total'  => count($notifications),
        ], 200);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|string',
        ]);

        $user = $request->user();
        $notifId = (string) $validated['id'];

        $state = UserNotificationState::updateOrCreate(
            [
                'user_id'         => $user->id,
                'notification_id' => $notifId,
            ],
            [
                'is_read' => true,
            ]
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Notifikasi ditandai sebagai dibaca',
            'data'    => $state,
        ], 200);
    }

    /**
     * Mark all notifications as read for current user.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            $ids = VehicleRequest::orderBy('id', 'desc')->take(100)->pluck('id')->map(fn($id) => (string)$id)->toArray();
        }

        foreach ($ids as $notifId) {
            UserNotificationState::updateOrCreate(
                [
                    'user_id'         => $user->id,
                    'notification_id' => (string) $notifId,
                ],
                [
                    'is_read' => true,
                ]
            );
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Semua notifikasi ditandai sebagai dibaca',
        ], 200);
    }

    /**
     * Permanently hide/delete a notification for current user.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $state = UserNotificationState::updateOrCreate(
            [
                'user_id'         => $user->id,
                'notification_id' => (string) $id,
            ],
            [
                'is_deleted' => true,
            ]
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Notifikasi berhasil dihapus',
            'data'    => $state,
        ], 200);
    }
}
