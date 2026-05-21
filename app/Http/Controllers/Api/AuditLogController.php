<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AuditLogController extends Controller
{
    /**
     * Display audit logs with pagination and filtering
     */
    public function index(Request $request): JsonResponse
    {
        // Only Admin and GA can view all audit logs
        if (!Auth::user()->hasAnyRole(['Admin', 'GA'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 403);
        }

        $perPage = $request->query('per_page', 25);
        $auditable_type = $request->query('type');
        $action = $request->query('action');
        $user_id = $request->query('user_id');

        $query = AuditLog::with('user');

        // Filter by auditable type (Request, Vehicle, Assignment)
        if ($auditable_type) {
            $query->where('auditable_type', 'like', '%' . $auditable_type . '%');
        }

        // Filter by action (created, updated, deleted, restored)
        if ($action && in_array($action, ['created', 'updated', 'deleted', 'restored'])) {
            $query->where('action', $action);
        }

        // Filter by user ID
        if ($user_id) {
            $query->where('user_id', $user_id);
        }

        $auditLogs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => AuditLogResource::collection($auditLogs->items()),
            'pagination' => [
                'total' => $auditLogs->total(),
                'per_page' => $auditLogs->perPage(),
                'current_page' => $auditLogs->currentPage(),
                'last_page' => $auditLogs->lastPage(),
                'from' => $auditLogs->firstItem(),
                'to' => $auditLogs->lastItem(),
            ]
        ], 200);
    }

    /**
     * Display audit logs for a specific model type and ID
     */
    public function show(Request $request, string $type, int $id): JsonResponse
    {
        // Validate and normalize the type
        $validTypes = ['Request', 'Vehicle', 'Assignment'];
        if (!in_array($type, $validTypes)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid auditable type. Valid types: ' . implode(', ', $validTypes)
            ], 422);
        }

        $perPage = $request->query('per_page', 25);

        // Build the full class name
        $auditableType = 'App\\Models\\' . $type;

        $auditLogs = AuditLog::with('user')
            ->where('auditable_type', $auditableType)
            ->where('auditable_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        if ($auditLogs->isEmpty() && $auditLogs->currentPage() === 1) {
            return response()->json([
                'status' => 'success',
                'message' => 'No audit logs found',
                'data' => [],
                'pagination' => [
                    'total' => 0,
                    'per_page' => $perPage,
                    'current_page' => 1,
                    'last_page' => 1,
                    'from' => null,
                    'to' => null,
                ]
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'data' => AuditLogResource::collection($auditLogs->items()),
            'pagination' => [
                'total' => $auditLogs->total(),
                'per_page' => $auditLogs->perPage(),
                'current_page' => $auditLogs->currentPage(),
                'last_page' => $auditLogs->lastPage(),
                'from' => $auditLogs->firstItem(),
                'to' => $auditLogs->lastItem(),
            ]
        ], 200);
    }

    /**
     * Display current user's activities
     */
    public function myActivities(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 25);

        $auditLogs = AuditLog::with('user')
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => AuditLogResource::collection($auditLogs->items()),
            'pagination' => [
                'total' => $auditLogs->total(),
                'per_page' => $auditLogs->perPage(),
                'current_page' => $auditLogs->currentPage(),
                'last_page' => $auditLogs->lastPage(),
                'from' => $auditLogs->firstItem(),
                'to' => $auditLogs->lastItem(),
            ]
        ], 200);
    }
}
