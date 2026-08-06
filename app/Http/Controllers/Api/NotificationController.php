<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Request as VehicleRequest;
use App\Models\UserNotificationState;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class NotificationController extends Controller
{
    /**
     * Fetch user notifications with persistent read and deleted statuses.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get user notification state lists from User model
        $userReadIds = is_array($user->read_notification_ids) ? array_map('strval', $user->read_notification_ids) : [];
        $userDeletedIds = is_array($user->deleted_notification_ids) ? array_map('strval', $user->deleted_notification_ids) : [];

        // Fallback/Merge with user_notification_states table if available
        if (Schema::hasTable('user_notification_states')) {
            try {
                $states = UserNotificationState::where('user_id', $user->id)->get();
                foreach ($states as $st) {
                    $stId = (string)$st->notification_id;
                    if ($st->is_deleted && !in_array($stId, $userDeletedIds, true)) {
                        $userDeletedIds[] = $stId;
                    }
                    if ($st->is_read && !in_array($stId, $userReadIds, true)) {
                        $userReadIds[] = $stId;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore if table query fails
            }
        }

        // Fetch requests for generating system notifications
        $requests = VehicleRequest::with(['user', 'department'])
            ->orderBy('id', 'desc')
            ->take(100)
            ->get();

        $notifications = [];

        foreach ($requests as $r) {
            $notifId = (string) $r->id;

            // Skip deleted notifications for this user
            if (in_array($notifId, $userDeletedIds, true)) {
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
                'isRead'      => in_array($notifId, $userReadIds, true),
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

        // Update User model JSON column
        $readIds = is_array($user->read_notification_ids) ? array_map('strval', $user->read_notification_ids) : [];
        if (!in_array($notifId, $readIds, true)) {
            $readIds[] = $notifId;
            $user->read_notification_ids = array_values(array_unique($readIds));
            $user->save();
        }

        // Also update user_notification_states table if available
        if (Schema::hasTable('user_notification_states')) {
            try {
                UserNotificationState::updateOrCreate(
                    ['user_id' => $user->id, 'notification_id' => $notifId],
                    ['is_read' => true]
                );
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Notifikasi ditandai sebagai dibaca',
            'data'    => ['id' => $notifId, 'isRead' => true],
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

        $readIds = is_array($user->read_notification_ids) ? array_map('strval', $user->read_notification_ids) : [];
        foreach ($ids as $notifId) {
            $notifId = (string) $notifId;
            if (!in_array($notifId, $readIds, true)) {
                $readIds[] = $notifId;
            }

            if (Schema::hasTable('user_notification_states')) {
                try {
                    UserNotificationState::updateOrCreate(
                        ['user_id' => $user->id, 'notification_id' => $notifId],
                        ['is_read' => true]
                    );
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
        }

        $user->read_notification_ids = array_values(array_unique($readIds));
        $user->save();

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
        $notifId = (string) $id;

        // Update User model JSON column
        $deletedIds = is_array($user->deleted_notification_ids) ? array_map('strval', $user->deleted_notification_ids) : [];
        if (!in_array($notifId, $deletedIds, true)) {
            $deletedIds[] = $notifId;
            $user->deleted_notification_ids = array_values(array_unique($deletedIds));
            $user->save();
        }

        // Also update user_notification_states table if available
        if (Schema::hasTable('user_notification_states')) {
            try {
                UserNotificationState::updateOrCreate(
                    ['user_id' => $user->id, 'notification_id' => $notifId],
                    ['is_deleted' => true]
                );
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Notifikasi berhasil dihapus',
            'data'    => ['id' => $notifId, 'isDeleted' => true],
        ], 200);
    }
}
