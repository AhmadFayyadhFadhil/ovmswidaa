<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        $departments = Department::select('id', 'name')->get();
        return response()->json([
            'status' => 'success',
            'data' => $departments,
        ]);
    }
}
