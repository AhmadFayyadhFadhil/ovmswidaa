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
     * Helper to extract clean normalized string ID from notification identifier.
     */
    private function cleanId($id): string
    {
        return trim((string) $id);
    }

    /**
     * Fetch user notifications with persistent read and deleted statuses.
     * 100% server-driven — uses user_notification_states table with Role-Aware filtering.
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

            // 1. Get all notification states for this user from dedicated table
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

            // 2. Determine User Role for Scope Filtering
            $userRoles = $user->getRoleNames()->map(fn($r) => strtolower(trim($r)))->toArray();
            $isAdminOrGA = in_array('admin', $userRoles, true) || in_array('administrator', $userRoles, true) || in_array('gahrd', $userRoles, true) || in_array('ga', $userRoles, true) || in_array('superadmin', $userRoles, true) || in_array('hrd', $userRoles, true);
            $isCoordinator = (in_array('driver coordinator', $userRoles, true) || in_array('driver_coordinator', $userRoles, true) || in_array('coordinator', $userRoles, true)) && !$isAdminOrGA;
            $isDriver = in_array('driver', $userRoles, true) && !$isAdminOrGA && !$isCoordinator;
            $isApprover = in_array('approver', $userRoles, true) && !$isAdminOrGA;
            $isEmployee = in_array('employee', $userRoles, true) && !$isAdminOrGA && !$isApprover && !$isDriver && !$isCoordinator;

            $query = VehicleRequest::with(['user', 'department', 'driver']);

            if ($isEmployee) {
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhere('requested_by', $user->id);
                });
            } elseif ($isCoordinator) {
                $query->where(function ($q) use ($user) {
                    $q->whereIn('status', [
                        RequestStatus::APPROVED_DEPARTMENT->value,
                        RequestStatus::ASSIGNED_BY_GA->value,
                        RequestStatus::DRIVER_ASSIGNED->value,
                        RequestStatus::ON_GOING->value,
                        RequestStatus::COMPLETED->value,
                    ])
                    ->orWhere('driver_id', $user->id)
                    ->orWhere('coordinator_id', $user->id)
                    ->orWhereHas('assignments', fn($aq) => $aq->where('driver_id', $user->id));
                });
            } elseif ($isDriver) {
                $query->where(function ($q) use ($user) {
                    $q->where('driver_id', $user->id)
                      ->orWhereHas('assignments', fn($aq) => $aq->where('driver_id', $user->id));
                });
            } elseif ($isApprover) {
                $query->where(function ($q) use ($user) {
                    $q->where('department_id', $user->department_id)
                      ->orWhere('user_id', $user->id)
                      ->orWhere('requested_by', $user->id);
                });
            }

            $requests = $query->orderBy('id', 'desc')->take(100)->get();
            $notifications = [];

            foreach ($requests as $r) {
                $rawStatus = is_object($r->status) ? $r->status->value : (string) $r->status;
                $notifId = "req-{$r->id}-" . strtolower($rawStatus);
                $legacyId = (string) $r->id;

                // Skip deleted notifications for this user
                if (in_array($notifId, $deletedIds, true) || in_array($legacyId, $deletedIds, true)) {
                    continue;
                }

                $employeeName = $r->user ? $r->user->name : ($r->employee ?? 'Staff');
                $dest = 'Tujuan';
                if ($r->destination_city && $r->destination_place) {
                    $dest = "{$r->destination_city} - {$r->destination_place}";
                } elseif ($r->destination_city) {
                    $dest = $r->destination_city;
                } elseif ($r->destination) {
                    $dest = $r->destination;
                }

                $dateStr = $r->start_time
                    ? $r->start_time->format('d M Y')
                    : ($r->created_at ? $r->created_at->format('d M Y') : 'Terbaru');

                $severity = 'info';
                $category = 'Operational';
                $actionUrl = '/employee/myrequests';

                if ($isAdminOrGA) {
                    $actionUrl = '/admin/requests';
                } elseif ($isApprover) {
                    $actionUrl = '/approver/requests';
                } elseif ($isDriver) {
                    $actionUrl = '/driver/dashboard';
                }

                if (in_array($rawStatus, ['submitted', 'approved_department', 'approved_hrd', 'approved_hrd_ga', 'waiting_driver'])) {
                    $severity = 'high';
                    $category = 'Approvals';
                } elseif ($rawStatus === 'on_going') {
                    $severity = 'medium';
                } elseif ($rawStatus === 'completed') {
                    $severity = 'low';
                } elseif ($rawStatus === 'rejected' || $rawStatus === 'cancelled') {
                    $severity = 'low';
                    $category = 'System';
                }

                $notifications[] = [
                    'id'          => $notifId,
                    'title'       => "Pengajuan Armada #REQ-{$r->id} ({$employeeName})",
                    'description' => "Perjalanan dinas ke {$dest} pada {$dateStr}. Status: " . strtoupper(str_replace('_', ' ', $rawStatus)) . ".",
                    'timeAgo'     => $dateStr,
                    'severity'    => $severity,
                    'category'    => $category,
                    'isRead'      => in_array($notifId, $readIds, true) || in_array($legacyId, $readIds, true),
                    'metadata'    => "REQ ID: #{$r->id}",
                    'rawStatus'   => $rawStatus,
                    'actionUrl'   => $actionUrl,
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
                $requests = VehicleRequest::orderBy('id', 'desc')->take(100)->get();
                $ids = [];
                foreach ($requests as $r) {
                    $rawStatus = is_object($r->status) ? $r->status->value : (string) $r->status;
                    $ids[] = "req-{$r->id}-" . strtolower($rawStatus);
                    $ids[] = (string) $r->id;
                }
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
     * Diagnostic test endpoint.
     */
    public function test(Request $request): JsonResponse
    {
        $results = [];
        $user = $request->user();
        $results['user_id'] = $user ? $user->id : 'NOT AUTHENTICATED';
        $results['user_name'] = $user ? $user->name : 'N/A';

        try {
            $tableExists = \Illuminate\Support\Facades\Schema::hasTable('user_notification_states');
            $results['table_exists'] = $tableExists;
        } catch (\Throwable $e) {
            $results['table_exists'] = 'ERROR: ' . $e->getMessage();
        }

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
            'status'  => 'success',
            'message' => 'Notification system diagnostic',
            'data'    => $results,
        ]);
    }
}
