<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\SecurityGuardController;

// ===== AUTH ENDPOINTS (public) =====
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:3,1');
Route::get('/departments', [\App\Http\Controllers\Api\DepartmentController::class, 'index']);
Route::get('/public-stats', [\App\Http\Controllers\Api\SettingController::class, 'getPublicStats']);

// Protected API routes
Route::middleware('auth:sanctum')->group(function () {

    // ===== AUTH =====
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/profile/avatar', [AuthController::class, 'updateAvatar']);
    Route::put('/profile/status', [AuthController::class, 'updateStatus']);

    // Get current authenticated user (legacy, keep for compatibility)
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // ===== USER MANAGEMENT ENDPOINTS (Admin only) =====
    Route::get('/users-search', [UserController::class, 'search']);
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);
    Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive']);
    Route::post('/users/{user}/toggle-request', [UserController::class, 'toggleRequest']);
    Route::post('/users/{user}/driver-duty', [UserController::class, 'updateDriverDuty']);

    // ===== REQUEST ENDPOINTS =====
    Route::get('/requests', [RequestController::class, 'index']);
    Route::post('/requests', [RequestController::class, 'store']);
    Route::get('/requests/{vehicleRequest}', [RequestController::class, 'show']);
    Route::put('/requests/{vehicleRequest}', [RequestController::class, 'update']);
    Route::patch('/requests/{vehicleRequest}', [RequestController::class, 'update']);
    Route::delete('/requests/{vehicleRequest}', [RequestController::class, 'destroy']);
    Route::post('/requests/{vehicleRequest}/approve', [RequestController::class, 'approve']);
    Route::post('/requests/{vehicleRequest}/reject', [RequestController::class, 'reject']);
    Route::post('/requests/{vehicleRequest}/start', [RequestController::class, 'start']);
    Route::post('/requests/{vehicleRequest}/complete', [RequestController::class, 'complete']);
    Route::post('/requests/{vehicleRequest}/adjust-driver', [RequestController::class, 'adjustDriver']);

    // ===== SECURITY SCAN ENDPOINTS =====
    Route::get('/security/lookup', [SecurityController::class, 'lookup']);
    Route::post('/security/scan', [SecurityController::class, 'scan']);
    Route::get('/security-guards', [SecurityGuardController::class, 'index']);
    Route::post('/security-guards', [SecurityGuardController::class, 'store']);
    Route::delete('/security-guards/{securityGuard}', [SecurityGuardController::class, 'destroy']);

    // ===== VEHICLE ENDPOINTS =====
    Route::get('/vehicles', [VehicleController::class, 'index']);
    Route::post('/vehicles', [VehicleController::class, 'store']);
    Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show']);
    Route::put('/vehicles/{vehicle}', [VehicleController::class, 'update']);
    Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update']);
    Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy']);

    // ===== ASSIGNMENT ENDPOINTS =====
    Route::get('/assignments', [AssignmentController::class, 'index']);
    Route::post('/assignments', [AssignmentController::class, 'store']);
    Route::get('/assignments/{assignment}', [AssignmentController::class, 'show']);
    Route::put('/assignments/{assignment}', [AssignmentController::class, 'update']);
    Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update']);
    Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy']);
    Route::post('/assignments/{assignment}/cancel', [AssignmentController::class, 'cancel']);

    // ===== AUDIT LOG ENDPOINTS =====
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/audit-logs/{type}/{id}', [AuditLogController::class, 'show']);
    Route::get('/my-activities', [AuditLogController::class, 'myActivities']);

    // ===== SYSTEM CONFIG ENDPOINTS (Admin only) =====
    Route::get('/system-config', [\App\Http\Controllers\Api\SettingController::class, 'index']);
    Route::put('/system-config', [\App\Http\Controllers\Api\SettingController::class, 'update']);
    Route::post('/system-config/logo', [\App\Http\Controllers\Api\SettingController::class, 'uploadLogo']);
    Route::get('/system-config/stats', [\App\Http\Controllers\Api\SettingController::class, 'getStats']);
    Route::post('/system-config/purge-logs', [\App\Http\Controllers\Api\SettingController::class, 'purgeLogs']);
    Route::post('/system-config/flush-cache', [\App\Http\Controllers\Api\SettingController::class, 'flushCache']);
});