<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexStockLevelRequest;
use App\Http\Resources\StockLevelResource;
use App\Models\StockLevel;
use Illuminate\Http\JsonResponse;

class StockLevelController extends Controller
{
    public function index(IndexStockLevelRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = StockLevel::query()
            ->with([
                'product:id,tenant_id,sku,name',
                'warehouse:id,tenant_id,name',
            ]);

        if (isset($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (isset($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        $levels = $query
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20)
            ->appends($request->safe()->only([
                'product_id',
                'warehouse_id',
                'per_page',
            ]));

        return StockLevelResource::collection($levels)->response();
    }
}
