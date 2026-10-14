<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductHistoryRequest;
use App\Http\Resources\StockMovementResource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class ProductHistoryController extends Controller
{
    public function index(
        ProductHistoryRequest $request,
        string $sku
    ): JsonResponse {
        $filters = $request->validated();

        $product = Product::query()
            ->where('sku', $sku)
            ->firstOrFail();

        $query = $product->stockMovements();

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['warehouse_id'])) {
            $warehouseId = (int) $filters['warehouse_id'];

            $query->where(function (Builder $query) use ($warehouseId) {
                $query->where('warehouse_id', $warehouseId)
                    ->orWhere(function (Builder $query) use ($warehouseId) {
                        $query->where('type', 'transfer')
                            ->where('meta->to_warehouse_id', $warehouseId);
                    });
            });
        }

        $history = $query
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->appends(array_intersect_key($filters, array_flip([
                'type',
                'warehouse_id',
                'per_page',
            ])));

        return StockMovementResource::collection($history)
            ->additional([
                'product' => [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                ],
            ])
            ->response();
    }
}
