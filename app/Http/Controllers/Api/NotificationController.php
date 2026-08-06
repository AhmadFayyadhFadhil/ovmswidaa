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
     * 100% server-driven — uses ONLY user_notification_states table.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Get all notification states for this user from dedicated table
            $readIds = [];
            $deletedIds = [];

            try {
                $states = UserNotificationState::where('user_id', $user->id)->get();
                foreach ($states as $st) {
                    $stId = (string) $st->notification_id;
                    if ($st->is_deleted) {
                        $deletedIds[] = $stId;
                    }
                    if ($st->is_read) {
                        $readIds[] = $stId;
                    }
                }
            } catch (\Throwable $e) {
                // Table might not exist yet — continue with empty arrays
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
                if (in_array($notifId, $deletedIds, true)) {
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

                $dateStr = $r->start_time
                    ? $r->start_time->format('d-m-Y')
                    : ($r->created_at ? $r->created_at->format('d-m-Y') : 'Terbaru');

                $severity = 'info';
                $category = 'Operational';

                if (in_array($rawStatus, ['submitted', 'approved_department', 'approved_hrd', 'approved_hrd_ga', 'waiting_driver'])) {
                    $severity = 'high';
                    $category = 'Approvals';
                } else if ($rawStatus === 'on_going') {
                    $severity = 'medium';
                } else if ($rawStatus === 'completed') {
                    $severity = 'low';
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
                    'isRead'      => in_array($notifId, $readIds, true),
                    'metadata'    => "REQ ID: #{$r->id}",
                    'rawStatus'   => $rawStatus,
                ];
            }

            return response()->json([
                'status' => 'success',
                'data'   => $notifications,
                'total'  => count($notifications),
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal memuat notifikasi: ' . $e->getMessage(),
                'data'    => [],
                'total'   => 0,
            ], 200); // Return 200 so frontend doesn't crash
        }
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'id' => 'required|string',
            ]);

            $user = $request->user();
            $notifId = (string) $validated['id'];

            UserNotificationState::updateOrCreate(
                ['user_id' => $user->id, 'notification_id' => $notifId],
                ['is_read' => true]
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Notifikasi ditandai sebagai dibaca',
                'data'    => ['id' => $notifId, 'isRead' => true],
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal menandai notifikasi: ' . $e->getMessage(),
            ], 200);
        }
    }

    /**
     * Mark all notifications as read for current user.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $ids = $request->input('ids', []);

            if (empty($ids)) {
                $ids = VehicleRequest::orderBy('id', 'desc')
                    ->take(100)
                    ->pluck('id')
                    ->map(fn($id) => (string) $id)
                    ->toArray();
            }

            foreach ($ids as $notifId) {
                UserNotificationState::updateOrCreate(
                    ['user_id' => $user->id, 'notification_id' => (string) $notifId],
                    ['is_read' => true]
                );
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Semua notifikasi ditandai sebagai dibaca',
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal menandai semua notifikasi: ' . $e->getMessage(),
            ], 200);
        }
    }

    /**
     * Permanently hide/delete a notification for current user.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $notifId = (string) $id;

            UserNotificationState::updateOrCreate(
                ['user_id' => $user->id, 'notification_id' => $notifId],
                ['is_deleted' => true]
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Notifikasi berhasil dihapus',
                'data'    => ['id' => $notifId, 'isDeleted' => true],
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal menghapus notifikasi: ' . $e->getMessage(),
            ], 200);
        }
    }

    /**
     * Diagnostic test endpoint — verifies table exists, insert works, read works.
     */
    public function test(Request $request): JsonResponse
    {
        $results = [];
        $user = $request->user();
        $results['user_id'] = $user ? $user->id : 'NOT AUTHENTICATED';
        $results['user_name'] = $user ? $user->name : 'N/A';

        // 1. Check table
        try {
            $tableExists = \Illuminate\Support\Facades\Schema::hasTable('user_notification_states');
            $results['table_exists'] = $tableExists;
        } catch (\Throwable $e) {
            $results['table_exists'] = 'ERROR: ' . $e->getMessage();
        }

        // 2. Try insert test row
        if ($user) {
            try {
                $state = UserNotificationState::updateOrCreate(
                    ['user_id' => $user->id, 'notification_id' => 'TEST_DIAGNOSTIC'],
                    ['is_read' => true, 'is_deleted' => false]
                );
                $results['insert_test'] = 'SUCCESS, id=' . $state->id;

                // Read it back
                $readBack = UserNotificationState::where('user_id', $user->id)
                    ->where('notification_id', 'TEST_DIAGNOSTIC')
                    ->first();
                $results['read_back'] = $readBack ? 'SUCCESS, is_read=' . ($readBack->is_read ? 'true' : 'false') : 'FAILED';

                // Clean up
                UserNotificationState::where('user_id', $user->id)
                    ->where('notification_id', 'TEST_DIAGNOSTIC')
                    ->delete();
                $results['cleanup'] = 'SUCCESS';

            } catch (\Throwable $e) {
                $results['insert_test'] = 'ERROR: ' . $e->getMessage();
            }
        }

        // 3. Count existing states for this user
        if ($user) {
            try {
                $count = UserNotificationState::where('user_id', $user->id)->count();
                $results['user_states_count'] = $count;
                $deletedCount = UserNotificationState::where('user_id', $user->id)->where('is_deleted', true)->count();
                $results['user_deleted_count'] = $deletedCount;
                $readCount = UserNotificationState::where('user_id', $user->id)->where('is_read', true)->count();
                $results['user_read_count'] = $readCount;
            } catch (\Throwable $e) {
                $results['user_states_count'] = 'ERROR: ' . $e->getMessage();
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Notification system diagnostic',
            'data' => $results,
        ], 200);
    }
}
