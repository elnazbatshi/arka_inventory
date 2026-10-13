<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStockMovementRequest;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;

class StockMovementController extends Controller
{
    public function store(
        StoreStockMovementRequest $request,
        StockMovementService $service
    ): JsonResponse {
        $movement = $service->store($request->validated());

        return response()->json([
            'message' => 'حرکت موجودی با موفقیت ثبت شد.',
            'data' => $movement,
        ], 201);
    }
}
