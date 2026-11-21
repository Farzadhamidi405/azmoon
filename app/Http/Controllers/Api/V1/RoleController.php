<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

/**
 * کنترلر نمایش فقط‌خواندنی نقش‌ها و ماتریس مجوزها (RBAC Matrix).
 */
class RoleController extends Controller
{
    /**
     * فهرست کلیه نقش‌های سیستمی همراه با مجوزهای منتسب به آنها.
     * GET /api/v1/admin/roles
     */
    public function index(): JsonResponse
    {
        $roles = Role::with(['permissions:id,name,module,action'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $roles,
        ]);
    }
}
