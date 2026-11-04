<?php

namespace App\Http\Controllers;

use App\Domain\PublicLeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicLeaveController extends Controller
{
    public function __construct(private PublicLeaveService $leaves) {}

    public function configuration(): JsonResponse
    {
        return response()->json($this->leaves->configuration());
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json($this->leaves->preview($request->all()));
    }

    public function submit(Request $request): JsonResponse
    {
        return response()->json($this->leaves->submit($request->all(), $request->header('Idempotency-Key', '')), 201);
    }
}
