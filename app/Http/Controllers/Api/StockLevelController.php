<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockLevelController extends Controller
{
    public function index(Request $request): JsonResponse
    {

        $filters = $request->validate([
            'warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'sku' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = StockLevel::query()
            ->with([
                'product:id,tenant_id,sku,name',
                'warehouse:id,tenant_id,name',
            ]);

        if (isset($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (isset($filters['sku'])) {
            $query->whereHas('product', function ($query) use ($filters) {
                $query->where('sku', $filters['sku']);
            });
        }

        $levels = $query
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json($levels);
    }
}
