<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Request as VehicleRequest;
use App\Models\UserNotificationState;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * Helper to return JSON response with no-cache headers.
     */
    private function jsonNoCache(array $data, int $code = 200): JsonResponse
    {
        return response()->json($data, $code)
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Helper to extract clean numeric string ID from notification string (e.g. "#RQ-17" -> "17", "REQ-17" -> "17").
     */
    private function cleanId($id): string
    {
        $str = (string) $id;
        $digits = preg_replace('/[^0-9]/', '', $str);
        return $digits !== '' ? $digits : trim($str);
    }

    /**
     * Fetch user notifications with persistent read and deleted statuses.
     * 100% server-driven — uses ONLY user_notification_states table.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->jsonNoCache([
                    'status'  => 'error',
                    'message' => 'Unauthenticated',
                    'data'    => [],
                    'total'   => 0,
                ], 401);
            }

            // Get all notification states for this user from dedicated table
            $readIds = [];
            $deletedIds = [];

            try {
                $states = UserNotificationState::where('user_id', $user->id)->get();
                foreach ($states as $st) {
                    $stId = $this->cleanId($st->notification_id);
                    if ($st->is_deleted) {
                        $deletedIds[] = $stId;
                    }
                    if ($st->is_read) {
                        $readIds[] = $stId;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Notification index DB query failed: ' . $e->getMessage());
            }

            // Fetch requests for generating system notifications
            $requests = VehicleRequest::with(['user', 'department'])
                ->orderBy('id', 'desc')
                ->take(100)
                ->get();

            $notifications = [];

            foreach ($requests as $r) {
                $notifId = $this->cleanId($r->id);

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

            return $this->jsonNoCache([
                'status' => 'success',
                'data'   => $notifications,
                'total'  => count($notifications),
            ]);

        } catch (\Throwable $e) {
            Log::error('Notification index error: ' . $e->getMessage());
            return $this->jsonNoCache([
                'status'  => 'error',
                'message' => 'Gagal memuat notifikasi: ' . $e->getMessage(),
                'data'    => [],
                'total'   => 0,
            ]);
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
            if (!$user) {
                return $this->jsonNoCache(['status' => 'error', 'message' => 'Unauthenticated'], 401);
            }

            $notifId = $this->cleanId($validated['id']);

            $state = UserNotificationState::firstOrNew([
                'user_id'         => $user->id,
                'notification_id' => $notifId,
            ]);

            $state->is_read = true;
            if ($state->is_deleted === null) {
                $state->is_deleted = false;
            }
            $state->save();

            Log::info("Notification #{$notifId} marked as read for user {$user->id}");

            return $this->jsonNoCache([
                'status'  => 'success',
                'message' => 'Notifikasi ditandai sebagai dibaca',
                'data'    => ['id' => $notifId, 'isRead' => true],
            ]);

        } catch (\Throwable $e) {
            Log::error('Notification markAsRead error: ' . $e->getMessage());
            return $this->jsonNoCache([
                'status'  => 'error',
                'message' => 'Gagal menandai notifikasi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark all notifications as read for current user.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return $this->jsonNoCache(['status' => 'error', 'message' => 'Unauthenticated'], 401);
            }

            $ids = $request->input('ids', []);

            if (empty($ids)) {
                $ids = VehicleRequest::orderBy('id', 'desc')
                    ->take(100)
                    ->pluck('id')
                    ->map(fn($id) => (string) $id)
                    ->toArray();
            }

            foreach ($ids as $rawId) {
                $notifId = $this->cleanId($rawId);
                $state = UserNotificationState::firstOrNew([
                    'user_id'         => $user->id,
                    'notification_id' => $notifId,
                ]);
                $state->is_read = true;
                if ($state->is_deleted === null) {
                    $state->is_deleted = false;
                }
                $state->save();
            }

            Log::info("All notifications marked as read for user {$user->id}");

            return $this->jsonNoCache([
                'status'  => 'success',
                'message' => 'Semua notifikasi ditandai sebagai dibaca',
            ]);

        } catch (\Throwable $e) {
            Log::error('Notification markAllAsRead error: ' . $e->getMessage());
            return $this->jsonNoCache([
                'status'  => 'error',
                'message' => 'Gagal menandai semua notifikasi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Permanently hide/delete a notification for current user.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return $this->jsonNoCache(['status' => 'error', 'message' => 'Unauthenticated'], 401);
            }

            $notifId = $this->cleanId($id);

            $state = UserNotificationState::firstOrNew([
                'user_id'         => $user->id,
                'notification_id' => $notifId,
            ]);

            $state->is_deleted = true;
            if ($state->is_read === null) {
                $state->is_read = false;
            }
            $state->save();

            Log::info("Notification #{$notifId} DELETED for user {$user->id} (Row ID: {$state->id})");

            return $this->jsonNoCache([
                'status'  => 'success',
                'message' => 'Notifikasi berhasil dihapus',
                'data'    => ['id' => $notifId, 'isDeleted' => true],
            ]);

        } catch (\Throwable $e) {
            Log::error("Notification destroy error for ID {$id}: " . $e->getMessage());
            return $this->jsonNoCache([
                'status'  => 'error',
                'message' => 'Gagal menghapus notifikasi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete/hide all notifications for current user.
     */
    public function deleteAll(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return $this->jsonNoCache(['status' => 'error', 'message' => 'Unauthenticated'], 401);
            }

            $ids = $request->input('ids', []);

            if (empty($ids)) {
                $ids = VehicleRequest::orderBy('id', 'desc')
                    ->take(100)
                    ->pluck('id')
                    ->map(fn($id) => (string) $id)
                    ->toArray();
            }

            foreach ($ids as $rawId) {
                $notifId = $this->cleanId($rawId);
                $state = UserNotificationState::firstOrNew([
                    'user_id'         => $user->id,
                    'notification_id' => $notifId,
                ]);
                $state->is_deleted = true;
                if ($state->is_read === null) {
                    $state->is_read = true;
                }
                $state->save();
            }

            Log::info("All notifications deleted for user {$user->id}");

            return $this->jsonNoCache([
                'status'  => 'success',
                'message' => 'Semua notifikasi berhasil dihapus',
            ]);

        } catch (\Throwable $e) {
            Log::error('Notification deleteAll error: ' . $e->getMessage());
            return $this->jsonNoCache([
                'status'  => 'error',
                'message' => 'Gagal menghapus semua notifikasi: ' . $e->getMessage(),
            ], 500);
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
                $state = UserNotificationState::firstOrNew([
                    'user_id' => $user->id,
                    'notification_id' => 'TEST_DIAGNOSTIC',
                ]);
                $state->is_read = true;
                $state->is_deleted = false;
                $state->save();

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

        return $this->jsonNoCache([
            'status' => 'success',
            'message' => 'Notification system diagnostic',
            'data' => $results,
        ]);
    }
}
