<?php


namespace App\Services;

use Illuminate\Support\Facades\DB;

class InventoryReconciliationService
{
    public function discrepancies(int $tenantId): iterable
    {
        // ورود مثبت است؛ خروج و انتقال از مبدأ منفی هستند.
        $sourceMovements = DB::table('stock_movements')
            ->where('tenant_id', $tenantId)
            ->select('product_id', 'warehouse_id')
            ->selectRaw("
                CASE
                    WHEN type = 'in' THEN CAST(quantity AS SIGNED)
                    ELSE -CAST(quantity AS SIGNED)
                END AS expected_quantity
            ")
            ->selectRaw('0 AS actual_quantity');

        // انتقال، موجودی مقصد را هم افزایش می‌دهد.
        $destinationMovements = DB::table('stock_movements')
            ->where('tenant_id', $tenantId)
            ->where('type', 'transfer')
            ->select('product_id')
            ->selectRaw("
                CAST(
                    JSON_UNQUOTE(JSON_EXTRACT(meta, '$.to_warehouse_id'))
                    AS UNSIGNED
                ) AS warehouse_id
            ")
            ->selectRaw('CAST(quantity AS SIGNED) AS expected_quantity')
            ->selectRaw('0 AS actual_quantity');

        $currentLevels = DB::table('stock_levels')
            ->where('tenant_id', $tenantId)
            ->select('product_id', 'warehouse_id')
            ->selectRaw('0 AS expected_quantity')
            ->selectRaw('CAST(quantity AS SIGNED) AS actual_quantity');

        $entries = $sourceMovements
            ->unionAll($destinationMovements)
            ->unionAll($currentLevels);

        return DB::query()
            ->fromSub($entries, 'inventory_entries')
            ->select('product_id', 'warehouse_id')
            ->selectRaw('SUM(expected_quantity) AS expected')
            ->selectRaw('SUM(actual_quantity) AS actual')
            ->groupBy('product_id', 'warehouse_id')
            ->havingRaw('SUM(expected_quantity) <> SUM(actual_quantity)')
            ->orderBy('product_id')
            ->orderBy('warehouse_id')
            ->cursor();
    }
}
