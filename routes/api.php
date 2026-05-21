<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuditLogController;

// Get current authenticated user
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Protected API routes
Route::middleware('auth:sanctum')->group(function () {
    // ===== REQUEST ENDPOINTS =====
    // List requests (user's own or all if authorized)
    Route::get('/requests', [RequestController::class, 'index']);
    
    // Create new request
    Route::post('/requests', [RequestController::class, 'store']);
    
    // Show specific request
    Route::get('/requests/{request}', [RequestController::class, 'show']);
    
    // Update specific request
    Route::put('/requests/{request}', [RequestController::class, 'update']);
    Route::patch('/requests/{request}', [RequestController::class, 'update']);
    
    // Delete specific request
    Route::delete('/requests/{request}', [RequestController::class, 'destroy']);
    
    // Approval endpoints
    Route::post('/requests/{request}/approve', [RequestController::class, 'approve']);
    Route::post('/requests/{request}/reject', [RequestController::class, 'reject']);
    
    // ===== VEHICLE ENDPOINTS =====
    // List all vehicles
    Route::get('/vehicles', [VehicleController::class, 'index']);
    
    // Create new vehicle
    Route::post('/vehicles', [VehicleController::class, 'store']);
    
    // Show specific vehicle
    Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show']);
    
    // Update specific vehicle
    Route::put('/vehicles/{vehicle}', [VehicleController::class, 'update']);
    Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update']);
    
    // Delete specific vehicle
    Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy']);

    // ===== ASSIGNMENT ENDPOINTS =====
    // List assignments
    Route::get('/assignments', [AssignmentController::class, 'index']);
    
    // Create new assignment
    Route::post('/assignments', [AssignmentController::class, 'store']);
    
    // Show specific assignment
    Route::get('/assignments/{assignment}', [AssignmentController::class, 'show']);
    
    // Update assignment (return vehicle)
    Route::put('/assignments/{assignment}', [AssignmentController::class, 'update']);
    Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update']);
    
    // Delete assignment
    Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy']);
    
    // Cancel assignment
    Route::post('/assignments/{assignment}/cancel', [AssignmentController::class, 'cancel']);

    // ===== AUDIT LOG ENDPOINTS =====
    // Get all audit logs (Admin & GA only)
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    
    // Get audit logs for specific model
    Route::get('/audit-logs/{type}/{id}', [AuditLogController::class, 'show']);
    
    // Get current user's activities
    Route::get('/my-activities', [AuditLogController::class, 'myActivities']);
});