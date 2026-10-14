<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStockMovementRequest;
use App\Http\Resources\StockMovementResource;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;

class StockMovementController extends Controller
{
    public function store(
        StoreStockMovementRequest $request,
        StockMovementService $service
    ): JsonResponse {
        $movement = $service->store($request->validated());

        return (new StockMovementResource($movement))
            ->additional([
                'message' => 'حرکت موجودی با موفقیت ثبت شد.',
            ])
            ->response()
            ->setStatusCode(201);
    }
}
