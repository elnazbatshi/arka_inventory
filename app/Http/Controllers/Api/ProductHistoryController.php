<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductHistoryController extends Controller
{
    public function index(Request $request, string $sku): JsonResponse
    {
        $filters = $request->validate([
            'type' => [
                'sometimes',
                Rule::in(['in', 'out', 'transfer']),
            ],
            'warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        // Global Scope فقط محصول شرکت جاری را پیدا می‌کند.
        $product = Product::query()
            ->where('sku', $sku)
            ->firstOrFail();

        $query = $product->stockMovements()
            ->with('warehouse:id,tenant_id,name');

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['warehouse_id'])) {
            $warehouseId = (int) $filters['warehouse_id'];

            // انتقال‌ها هم برای مبدأ و هم مقصد نمایش داده می‌شوند.
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
            ->appends($request->only([
                'type',
                'warehouse_id',
                'per_page',
            ]));

        return response()->json([
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
            ],
            'history' => $history,
        ]);
    }
}
