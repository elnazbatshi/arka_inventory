<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockMovementConcurrencyTest extends TestCase
{
    public function test_100_concurrent_out_movements_only_50_succeed(): void
    {
        // این تست باید روی دیتابیس تست MySQL اجرا شود.
        $connection = config('database.default');
        $databaseConfig = config("database.connections.{$connection}");

        $this->assertSame('mysql', $databaseConfig['driver']);
        $this->assertSame(
            'arka_inventory_test',
            DB::connection()->getDatabaseName()
        );
        $this->assertSame(0, DB::connection()->transactionLevel());

        $tenant = Tenant::create([
            'name' => 'شرکت تست هم‌زمانی',
            'subdomain' => 'concurrency-'.Str::uuid(),
        ]);

        $tenantId = (int) $tenant->id;
        $context = app(TenantContext::class);

        try {
            $context->set($tenantId);

            $product = Product::create([
                'sku' => 'CONCURRENT-001',
                'name' => 'محصول تست هم‌زمانی',
                'unit_price' => '100.00',
            ]);

            $warehouse = Warehouse::create([
                'name' => 'انبار تست هم‌زمانی',
                'location' => 'تهران',
            ]);

            // موجودی اولیه از طریق سرویس ثبت می‌شود.
            app(StockMovementService::class)->store([
                'sku' => $product->sku,
                'type' => 'in',
                'warehouse_id' => $warehouse->id,
                'quantity' => 50,
                'reference' => 'INITIAL-STOCK',
            ]);

            $context->clear();

            $sku = $product->sku;
            $warehouseId = (int) $warehouse->id;
            $tasks = [];

            for ($i = 1; $i <= 100; $i++) {
                $tasks[] = static function () use (
                    $tenantId,
                    $sku,
                    $warehouseId,
                    $i,
                    $connection,
                    $databaseConfig
                ): string {
                    // هر پردازش به همان دیتابیس تست متصل می‌شود.
                    config([
                        'database.default' => $connection,
                        "database.connections.{$connection}" => $databaseConfig,
                    ]);

                    DB::purge($connection);

                    $context = app(TenantContext::class);
                    $context->set($tenantId);

                    try {
                        app(StockMovementService::class)->store([
                            'sku' => $sku,
                            'type' => 'out',
                            'warehouse_id' => $warehouseId,
                            'quantity' => 1,
                            'reference' => 'CONCURRENT-OUT-'.$i,
                        ]);

                        return 'success';
                    } catch (InsufficientStockException $exception) {
                        return 'insufficient_stock';
                    } finally {
                        $context->clear();
                        DB::disconnect($connection);
                    }
                };
            }

            $results = Concurrency::driver('process')->run(
                $tasks,
                timeout: 120
            );

            $this->assertCount(100, $results);

            $counts = array_count_values($results);

            $this->assertSame(50, $counts['success'] ?? 0);
            $this->assertSame(50, $counts['insufficient_stock'] ?? 0);

            $finalQuantity = DB::table('stock_levels')
                ->where('tenant_id', $tenantId)
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->value('quantity');

            $this->assertNotNull($finalQuantity);
            $this->assertSame(0, (int) $finalQuantity);

            $successfulOuts = DB::table('stock_movements')
                ->where('tenant_id', $tenantId)
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->where('type', 'out')
                ->count();

            $this->assertSame(50, $successfulOuts);
        } finally {
            $context->clear();

            // فقط داده‌های شرکت ساخته‌شده در همین تست پاک می‌شوند.
            DB::table('stock_movements')
                ->where('tenant_id', $tenantId)
                ->delete();

            DB::table('stock_levels')
                ->where('tenant_id', $tenantId)
                ->delete();

            DB::table('products')
                ->where('tenant_id', $tenantId)
                ->delete();

            DB::table('warehouses')
                ->where('tenant_id', $tenantId)
                ->delete();

            DB::table('tenants')
                ->where('id', $tenantId)
                ->delete();
        }
    }
}
