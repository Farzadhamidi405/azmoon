<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PingController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'API v1 is working.',
            'version' => 'v1',
        ]);
    }

    public function stub(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This endpoint will be implemented in Section 2.',
        ], 501);
    }
}