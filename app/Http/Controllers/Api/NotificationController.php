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

            // 2. Determine User Role for Scope Filtering (Checks Spatie roles, user flags, and availability status)
            $userRoles = $user->getRoleNames()->map(fn($r) => strtolower(trim($r)))->toArray();
            $isAdminOrGA = in_array('admin', $userRoles, true) ||
                in_array('administrator', $userRoles, true) ||
                in_array('gahrd', $userRoles, true) ||
                in_array('ga', $userRoles, true) ||
                in_array('superadmin', $userRoles, true) ||
                in_array('hrd', $userRoles, true) ||
                (method_exists($user, 'isHrGaHead') && $user->isHrGaHead());

            $isCoordinator = ((in_array('driver coordinator', $userRoles, true) ||
                in_array('driver_coordinator', $userRoles, true) ||
                in_array('coordinator', $userRoles, true) ||
                (bool)$user->is_driver_coordinator)) && !$isAdminOrGA;

            $isApprover = (in_array('approver', $userRoles, true) ||
                in_array('manager', $userRoles, true) ||
                (bool)$user->is_department_head) && !$isAdminOrGA;

            $isSecurity = in_array('security', $userRoles, true) && !$isAdminOrGA;

            $isDriver = (in_array('driver', $userRoles, true) || $user->availability_status !== null) && !$isAdminOrGA && !$isCoordinator && !$isSecurity;

            $isEmployee = !$isAdminOrGA && !$isApprover && !$isDriver && !$isCoordinator && !$isSecurity;

            $query = VehicleRequest::with([
                'user',
                'department',
                'driver',
                'vehicle',
                'itineraries.driver',
                'itineraries.vehicle',
                'assignments.driver',
                'assignments.vehicle',
            ]);

            if ($isEmployee) {
                // Regular employee only sees their own requests
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhere('requested_by', $user->id);
                });
            } elseif ($isCoordinator) {
                // Coordinator sees requests waiting for allocation, pending urgent requests, active/completed trips, and personal driving assignments
                $query->where(function ($q) use ($user) {
                    $q->whereIn('status', [
                        RequestStatus::APPROVED_DEPARTMENT->value,
                        RequestStatus::ASSIGNED_BY_GA->value,
                        RequestStatus::DRIVER_ASSIGNED->value,
                        RequestStatus::ON_GOING->value,
                        RequestStatus::COMPLETED->value,
                    ])
                    ->orWhere(function ($uq) {
                        $uq->whereIn('status', [RequestStatus::SUBMITTED->value, 'pending'])
                           ->whereIn('priority', ['Urgent', 'Critical', 'URGENT', 'CRITICAL']);
                    })
                    ->orWhere('driver_id', $user->id)
                    ->orWhere('coordinator_id', $user->id)
                    ->orWhereHas('assignments', fn($aq) => $aq->where('driver_id', $user->id))
                    ->orWhereHas('itineraries', fn($iq) => $iq->where('driver_id', $user->id));
                });
            } elseif ($isDriver) {
                // Driver sees single-day and multi-day itinerary assignments
                $query->where(function ($q) use ($user) {
                    $q->where('driver_id', $user->id)
                      ->orWhereHas('assignments', fn($aq) => $aq->where('driver_id', $user->id))
                      ->orWhereHas('itineraries', fn($iq) => $iq->where('driver_id', $user->id));
                });
            } elseif ($isApprover) {
                // Approver sees requests from their department and own requests
                $query->where(function ($q) use ($user) {
                    $q->where('department_id', $user->department_id)
                      ->orWhere('user_id', $user->id)
                      ->orWhere('requested_by', $user->id);
                });
            } elseif ($isSecurity) {
                // Security sees scheduled/assigned trips and ongoing trips
                $query->whereIn('status', [
                    RequestStatus::DRIVER_ASSIGNED->value,
                    RequestStatus::ON_GOING->value,
                    RequestStatus::COMPLETED->value,
                ]);
            }

            $requests = $query->orderBy('id', 'desc')->take(100)->get();
            $notifications = [];

            foreach ($requests as $r) {
                $rawStatus = is_object($r->status) ? $r->status->value : (string) $r->status;
                $notifId = "req-{$r->id}-" . strtolower($rawStatus);

                // Skip deleted notifications for this user (do not suppress across different lifecycle statuses)
                if (in_array($notifId, $deletedIds, true)) {
                    continue;
                }

                $employeeName = $r->user ? $r->user->name : ($r->employee ?? 'Staff');
                $deptName = $r->department ? $r->department->name : 'Internal';
                
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

                $timeStr = $r->start_time ? $r->start_time->format('H:i') : '08:00';

                $driverName = $r->driver ? $r->driver->name : null;
                $vehicleModel = $r->vehicle ? ($r->vehicle->model . ' (' . ($r->vehicle->license_plate ?? $r->vehicle->plate_number ?? '') . ')') : null;
                $priority = is_object($r->priority) ? $r->priority->value : (string) ($r->priority ?? 'Normal');
                $isUrgent = in_array(strtolower($priority), ['urgent', 'critical']);
                $isOwner = ((int)$r->user_id === (int)$user->id || (int)$r->requested_by === (int)$user->id);

                // Determine Contextual Title, Description, Category, Severity, and Action URL based on User Role & Status
                $title = "Pengajuan Armada #REQ-{$r->id}";
                $desc = "Perjalanan dinas ke {$dest} pada {$dateStr}. Status: " . strtoupper(str_replace('_', ' ', $rawStatus)) . ".";
                $severity = 'info';
                $category = 'Operational';
                $actionUrl = '/employee/myrequests?id=' . $r->id;

                switch ($rawStatus) {
                    case 'submitted':
                    case 'pending':
                        if ($isApprover && !$isOwner) {
                            $title = "Persetujuan Diperlukan: #REQ-{$r->id} ({$employeeName})";
                            $desc = "Pengajuan dinas dari {$employeeName} ({$deptName}) ke {$dest} pada {$dateStr} menunggu persetujuan Anda.";
                            $severity = 'high';
                            $category = 'Approvals';
                            $actionUrl = '/approver/requests?id=' . $r->id;
                        } elseif ($isCoordinator || $isAdminOrGA) {
                            $title = $isUrgent ? "🚨 Pengajuan Mendesak: #REQ-{$r->id} ({$priority})" : "Pengajuan Masuk #REQ-{$r->id} ({$employeeName})";
                            $desc = "Pengajuan perjalanan ke {$dest} pada {$dateStr} ({$timeStr} WIB). Status: Menunggu Persetujuan.";
                            $severity = $isUrgent ? 'high' : 'medium';
                            $category = 'Approvals';
                            $actionUrl = $isCoordinator ? '/gahrd/requests?assign=' . $r->id : '/gahrd/requests?id=' . $r->id;
                        } else {
                            $title = "Pengajuan Terkirim: #REQ-{$r->id}";
                            $desc = "Pengajuan dinas Anda ke {$dest} pada {$dateStr} berhasil dibuat dan menunggu persetujuan Kepala Departemen.";
                            $severity = 'info';
                            $actionUrl = '/employee/myrequests?id=' . $r->id;
                        }
                        break;

                    case 'approved_department':
                        if ($isCoordinator || $isAdminOrGA) {
                            $title = "Alokasi Armada Diperlukan: #REQ-{$r->id}";
                            $desc = "Pengajuan {$employeeName} ({$deptName}) ke {$dest} telah disetujui Kadiv. Silakan alokasikan driver & mobil.";
                            $severity = 'high';
                            $category = 'Approvals';
                            $actionUrl = '/gahrd/requests?assign=' . $r->id;
                        } elseif ($isApprover && !$isOwner) {
                            $title = "Pengajuan Disetujui: #REQ-{$r->id}";
                            $desc = "Pengajuan {$employeeName} ke {$dest} telah disetujui dan diteruskan ke Koordinator Armada.";
                            $severity = 'low';
                            $actionUrl = '/approver/requests?id=' . $r->id;
                        } else {
                            $title = "Disetujui Departemen: #REQ-{$r->id}";
                            $desc = "Pengajuan Anda ke {$dest} telah disetujui Kadiv dan sedang menunggu alokasi armada.";
                            $severity = 'info';
                            $actionUrl = '/employee/myrequests?id=' . $r->id;
                        }
                        break;

                    case 'driver_assigned':
                    case 'assigned_by_ga':
                    case 'waiting_driver':
                        if ($isDriver) {
                            $title = "Tugas Menyetir Baru: #REQ-{$r->id}";
                            $desc = "Anda ditugaskan mengantar {$employeeName} ({$deptName}) ke {$dest} pada {$dateStr} ({$timeStr} WIB).";
                            $severity = 'high';
                            $category = 'Operational';
                            $actionUrl = '/driver/dashboard';
                        } elseif ($isSecurity) {
                            $title = "Armada Terjadwal: #REQ-{$r->id}";
                            $desc = "Kendaraan " . ($vehicleModel ?: 'Operasional') . " dikemudikan " . ($driverName ?: 'Driver') . " terjadwal berangkat ke {$dest}.";
                            $severity = 'info';
                            $actionUrl = '/security/dashboard';
                        } elseif ($isAdminOrGA || $isCoordinator) {
                            $title = "Armada Dialokasikan: #REQ-{$r->id}";
                            $desc = "Driver " . ($driverName ?: 'Driver') . " dan mobil " . ($vehicleModel ?: 'Unit') . " telah ditugaskan untuk #REQ-{$r->id}.";
                            $severity = 'low';
                            $actionUrl = '/gahrd/requests?id=' . $r->id;
                        } else {
                            $title = "Armada Telah Ditugaskan: #REQ-{$r->id}";
                            $desc = "Driver " . ($driverName ?: 'Driver') . " dan mobil " . ($vehicleModel ?: 'Mobil') . " telah disiapkan untuk perjalanan Anda.";
                            $severity = 'info';
                            $actionUrl = '/employee/myrequests?id=' . $r->id;
                        }
                        break;

                    case 'on_going':
                        if ($isSecurity) {
                            $title = "Armada Berangkat: #REQ-{$r->id}";
                            $desc = "Kendaraan " . ($vehicleModel ?: 'Operasional') . " telah checkout keluar gerbang pabrik.";
                            $severity = 'medium';
                            $actionUrl = '/security/dashboard';
                        } elseif ($isDriver) {
                            $title = "Trip Sedang Berjalan: #REQ-{$r->id}";
                            $desc = "Perjalanan dinas ke {$dest} sedang berlangsung. Utamakan keselamatan berkendara.";
                            $severity = 'medium';
                            $actionUrl = '/driver/dashboard';
                        } else {
                            $title = "Perjalanan Dimulai: #REQ-{$r->id}";
                            $desc = "Perjalanan dinas ke {$dest} telah dimulai bersama driver " . ($driverName ?: 'Driver') . ".";
                            $severity = 'medium';
                            $actionUrl = '/employee/myrequests?id=' . $r->id;
                        }
                        break;

                    case 'completed':
                        if ($isOwner) {
                            $title = "Perjalanan Selesai: #REQ-{$r->id}";
                            $desc = "Trip ke {$dest} telah selesai. Silakan berikan rating dan ulasan pelayanan driver.";
                            $severity = 'medium';
                            $actionUrl = '/employee/myrequests?id=' . $r->id . '&review=true';
                        } elseif ($isDriver) {
                            $title = "Trip Selesai: #REQ-{$r->id}";
                            $desc = "Perjalanan ke {$dest} telah tercatat selesai. Terima kasih atas pelayanan Anda.";
                            $severity = 'low';
                            $actionUrl = '/driver/dashboard';
                        } elseif ($isSecurity) {
                            $title = "Armada Telah Kembali: #REQ-{$r->id}";
                            $desc = "Kendaraan " . ($vehicleModel ?: 'Operasional') . " telah checkin kembali di pos security.";
                            $severity = 'low';
                            $actionUrl = '/security/dashboard';
                        } else {
                            $title = "Perjalanan Selesai: #REQ-{$r->id}";
                            $desc = "Trip #REQ-{$r->id} ({$employeeName}) ke {$dest} telah selesai.";
                            $severity = 'low';
                            $actionUrl = $isAdminOrGA || $isCoordinator ? '/gahrd/requests?id=' . $r->id : '/employee/myrequests?id=' . $r->id;
                        }
                        break;

                    case 'rejected':
                        $title = "Pengajuan Ditolak: #REQ-{$r->id}";
                        $desc = "Pengajuan dinas ke {$dest} ditolak." . ($r->rejected_reason ? " Alasan: " . $r->rejected_reason : "");
                        $severity = 'high';
                        $category = 'System';
                        $actionUrl = $isApprover ? '/approver/requests?id=' . $r->id : '/employee/myrequests?id=' . $r->id;
                        break;

                    case 'cancelled':
                        $title = "Pengajuan Dibatalkan: #REQ-{$r->id}";
                        $desc = "Pengajuan perjalanan ke {$dest} pada {$dateStr} telah dibatalkan.";
                        $severity = 'low';
                        $category = 'System';
                        $actionUrl = $isEmployee ? '/employee/myrequests?id=' . $r->id : '/gahrd/requests';
                        break;
                }

                $notifications[] = [
                    'id'          => $notifId,
                    'title'       => $title,
                    'description' => $desc,
                    'timeAgo'     => $dateStr,
                    'severity'    => $severity,
                    'category'    => $category,
                    'isRead'      => in_array($notifId, $readIds, true),
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
