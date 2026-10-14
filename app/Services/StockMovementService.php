<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Exceptions\InsufficientStockException;

class StockMovementService
{
    private const MAX_QUANTITY = 4294967295;

    public function __construct(
        private readonly TenantContext $context
    )
    {
    }

    public function store(array $data): array
    {
        $tenantId = $this->context->id();

        return DB::transaction(function () use ($data, $tenantId): array {
            // تمام تغییرات موجودی این محصول به ترتیب اجرا می‌شوند.
            $product = Product::query()
                ->where('tenant_id', $tenantId)
                ->where('sku', $data['sku'])
                ->lockForUpdate()
                ->first();

            if (!$product) {
                throw ValidationException::withMessages([
                    'sku' => 'محصول در شرکت جاری پیدا نشد.',
                ]);
            }

            $warehouseId = (int)$data['warehouse_id'];
            $quantity = (int)$data['quantity'];
            $type = $data['type'];

            $destinationId = $type === 'transfer'
                ? (int)$data['to_warehouse_id']
                : null;

            $warehouseIds = [$warehouseId];

            if ($destinationId !== null) {
                $warehouseIds[] = $destinationId;
            }

            // ترتیب ثابت قفل‌ها.
            sort($warehouseIds, SORT_NUMERIC);

            $warehouses = Warehouse::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $warehouseIds)
                ->orderBy('id')
                ->sharedLock()
                ->get()
                ->keyBy('id');

            if (!$warehouses->has($warehouseId)) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'انبار در شرکت جاری پیدا نشد.',
                ]);
            }

            if (
                $destinationId !== null
                && !$warehouses->has($destinationId)
            ) {
                throw ValidationException::withMessages([
                    'to_warehouse_id' => 'انبار مقصد در شرکت جاری پیدا نشد.',
                ]);
            }

            $levels = [];

            foreach ($warehouseIds as $id) {
                $query = DB::table('stock_levels')
                    ->where('tenant_id', $tenantId)
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $id);

                $level = (clone $query)->lockForUpdate()->first();

                if (!$level) {
                    // قفل محصول، ساخت هم‌زمان همین موجودی را کنترل می‌کند.
                    $levelId = DB::table('stock_levels')->insertGetId([
                        'tenant_id' => $tenantId,
                        'product_id' => $product->id,
                        'warehouse_id' => $id,
                        'quantity' => 0,
                        'updated_at' => now(),
                    ]);

                    $level = (clone $query)
                        ->where('id', $levelId)
                        ->lockForUpdate()
                        ->first();
                }

                $levels[$id] = $level;
            }

            $before = (int)$levels[$warehouseId]->quantity;

            if ($type !== 'in' && $before < $quantity) {
                throw new InsufficientStockException(
                    'موجودی انبار برای این عملیات کافی نیست.'
                );
            }

            $after = $type === 'in'
                ? $before + $quantity
                : $before - $quantity;

            $this->checkCapacity($after);

            $meta = [
                'quantity_before' => $before,
                'quantity_after' => $after,
            ];

            $changes = [$warehouseId => $after];

            if ($destinationId !== null) {
                $destinationBefore = (int)$levels[$destinationId]->quantity;
                $destinationAfter = $destinationBefore + $quantity;

                $this->checkCapacity($destinationAfter);

                $changes[$destinationId] = $destinationAfter;

                $meta['from_warehouse_id'] = $warehouseId;
                $meta['to_warehouse_id'] = $destinationId;
                $meta['destination_quantity_before'] = $destinationBefore;
                $meta['destination_quantity_after'] = $destinationAfter;
            }

            foreach ($changes as $id => $newQuantity) {
                DB::table('stock_levels')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $levels[$id]->id)
                    ->update([
                        'quantity' => $newQuantity,
                        'updated_at' => now(),
                    ]);
            }

            $createdAt = now();

            $movementId = DB::table('stock_movements')->insertGetId([
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'type' => $type,
                'quantity' => $quantity,
                'reference' => $data['reference'] ?? null,
                'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
                'created_at' => $createdAt,
            ]);

            return [
                'id' => $movementId,
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'type' => $type,
                'quantity' => $quantity,
                'reference' => $data['reference'] ?? null,
                'meta' => $meta,
                'created_at' => $createdAt->toISOString(),
            ];
        }, 3);
    }

    private function checkCapacity(int $quantity): void
    {
        if ($quantity > self::MAX_QUANTITY) {
            throw ValidationException::withMessages([
                'quantity' => 'موجودی از ظرفیت ستون quantity بیشتر می‌شود.',
            ]);
        }
    }
}
