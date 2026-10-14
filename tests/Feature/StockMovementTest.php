<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use DatabaseTransactions;
    public function test_movement_cannot_use_another_tenants_warehouse(): void
    {
        $firstTenant = Tenant::create([
            'name' => 'شرکت اول',
            'subdomain' => 'isolation-first',
        ]);

        $secondTenant = Tenant::create([
            'name' => 'شرکت دوم',
            'subdomain' => 'isolation-second',
        ]);

        $context = app(TenantContext::class);

        try {
            $context->set($firstTenant->id);

            $product = Product::create([
                'sku' => 'TEST-ISOLATION-001',
                'name' => 'محصول شرکت اول',
                'unit_price' => '100.00',
            ]);

            $context->set($secondTenant->id);

            $otherWarehouse = Warehouse::create([
                'name' => 'انبار شرکت دوم',
                'location' => 'تهران',
            ]);
        } finally {
            $context->clear();
        }

        $this->postJson('/api/stock-movements', [
            'sku' => $product->sku,
            'type' => 'in',
            'warehouse_id' => $otherWarehouse->id,
            'quantity' => 10,
        ], [
            'X-Tenant-ID' => (string) $firstTenant->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['warehouse_id']);

        $this->assertDatabaseMissing('stock_levels', [
            'product_id' => $product->id,
            'warehouse_id' => $otherWarehouse->id,
        ]);

        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $product->id,
            'warehouse_id' => $otherWarehouse->id,
        ]);
    }
    public function test_transfer_with_insufficient_stock_rolls_back(): void
    {
        $tenant = Tenant::create([
            'name' => 'شرکت تست انتقال ناموفق',
            'subdomain' => 'inventory-transfer-failure-test',
        ]);

        $context = app(TenantContext::class);
        $context->set($tenant->id);

        try {
            $product = Product::create([
                'sku' => 'TEST-TRANSFER-FAIL-001',
                'name' => 'محصول تست',
                'unit_price' => '100.00',
            ]);

            $source = Warehouse::create([
                'name' => 'انبار مبدأ',
                'location' => 'تهران',
            ]);

            $destination = Warehouse::create([
                'name' => 'انبار مقصد',
                'location' => 'شیراز',
            ]);
        } finally {
            $context->clear();
        }

        $headers = [
            'X-Tenant-ID' => (string) $tenant->id,
        ];

        $this->postJson('/api/stock-movements', [
            'sku' => $product->sku,
            'type' => 'in',
            'warehouse_id' => $source->id,
            'quantity' => 5,
        ], $headers)->assertCreated();

        $this->postJson('/api/stock-movements', [
            'sku' => $product->sku,
            'type' => 'transfer',
            'warehouse_id' => $source->id,
            'to_warehouse_id' => $destination->id,
            'quantity' => 6,
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        $this->assertDatabaseHas('stock_levels', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'quantity' => 5,
        ]);

        $this->assertDatabaseMissing('stock_levels', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $destination->id,
        ]);

        $this->assertDatabaseMissing('stock_movements', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'type' => 'transfer',
        ]);
    }
    public function test_transfer_moves_stock_between_warehouses(): void
    {
        $tenant = Tenant::create([
            'name' => 'شرکت تست انتقال',
            'subdomain' => 'inventory-transfer-test',
        ]);

        $context = app(TenantContext::class);
        $context->set($tenant->id);

        try {
            $product = Product::create([
                'sku' => 'TEST-TRANSFER-001',
                'name' => 'محصول تست انتقال',
                'unit_price' => '100.00',
            ]);

            $source = Warehouse::create([
                'name' => 'انبار مبدأ',
                'location' => 'تهران',
            ]);

            $destination = Warehouse::create([
                'name' => 'انبار مقصد',
                'location' => 'شیراز',
            ]);
        } finally {
            $context->clear();
        }

        $headers = [
            'X-Tenant-ID' => (string) $tenant->id,
        ];

        $this->postJson('/api/stock-movements', [
            'sku' => $product->sku,
            'type' => 'in',
            'warehouse_id' => $source->id,
            'quantity' => 10,
        ], $headers)->assertCreated();

        $response = $this->postJson('/api/stock-movements', [
            'sku' => $product->sku,
            'type' => 'transfer',
            'warehouse_id' => $source->id,
            'to_warehouse_id' => $destination->id,
            'quantity' => 4,
            'reference' => 'TEST-TRANSFER-001',
        ], $headers);

        $response
            ->assertCreated()
            ->assertJsonPath('data.type', 'transfer')
            ->assertJsonPath('data.meta.from_warehouse_id', $source->id)
            ->assertJsonPath('data.meta.to_warehouse_id', $destination->id);

        $this->assertDatabaseHas('stock_levels', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'quantity' => 6,
        ]);

        $this->assertDatabaseHas('stock_levels', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $destination->id,
            'quantity' => 4,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'id' => $response->json('data.id'),
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'type' => 'transfer',
            'quantity' => 4,
            'meta->from_warehouse_id' => $source->id,
            'meta->to_warehouse_id' => $destination->id,
        ]);
    }
    public function test_out_movement_with_insufficient_stock_is_rejected(): void
    {
        $tenant = Tenant::create([
            'name' => 'شرکت تست کمبود موجودی',
            'subdomain' => 'inventory-insufficient-test',
        ]);

        $context = app(TenantContext::class);
        $context->set($tenant->id);

        try {
            $product = Product::create([
                'sku' => 'TEST-INSUFFICIENT-001',
                'name' => 'محصول تست',
                'unit_price' => '100.00',
            ]);

            $warehouse = Warehouse::create([
                'name' => 'انبار تست',
                'location' => 'تهران',
            ]);
        } finally {
            $context->clear();
        }

        $headers = [
            'X-Tenant-ID' => (string) $tenant->id,
        ];

        $this->postJson('/api/stock-movements', [
            'sku' => $product->sku,
            'type' => 'in',
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
        ], $headers)->assertCreated();

        $this->postJson('/api/stock-movements', [
            'sku' => $product->sku,
            'type' => 'out',
            'warehouse_id' => $warehouse->id,
            'quantity' => 6,
            'reference' => 'FAILED-OUT-001',
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        $this->assertDatabaseHas('stock_levels', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
        ]);

        $this->assertDatabaseMissing('stock_movements', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'type' => 'out',
        ]);
    }
    public function test_out_movement_decreases_stock(): void
    {
        $tenant = Tenant::create([
            'name' => 'شرکت تست خروج',
            'subdomain' => 'inventory-out-test',
        ]);

        $context = app(TenantContext::class);
        $context->set($tenant->id);

        try {
            $product = Product::create([
                'sku' => 'TEST-OUT-001',
                'name' => 'محصول تست خروج',
                'unit_price' => '100.00',
            ]);

            $warehouse = Warehouse::create([
                'name' => 'انبار تست',
                'location' => 'تهران',
            ]);
        } finally {
            $context->clear();
        }

        $headers = [
            'X-Tenant-ID' => (string) $tenant->id,
        ];

        $this->postJson('/api/stock-movements', [
            'sku' => $product->sku,
            'type' => 'in',
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ], $headers)->assertCreated();

        $this->postJson('/api/stock-movements', [
            'sku' => $product->sku,
            'type' => 'out',
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
            'reference' => 'TEST-OUT-001',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.type', 'out')
            ->assertJsonPath('data.quantity', 3);

        $this->assertDatabaseHas('stock_levels', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 7,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'out',
            'quantity' => 3,
            'reference' => 'TEST-OUT-001',
        ]);
    }
    public function test_in_movement_creates_stock_level(): void
    {
        $tenant = Tenant::create([
            'name' => 'شرکت تست',
            'subdomain' => 'inventory-test',
        ]);

        $context = app(TenantContext::class);
        $context->set($tenant->id);

        try {
            $product = Product::create([
                'sku' => 'TEST-001',
                'name' => 'محصول تست',
                'unit_price' => '100.00',
            ]);

            $warehouse = Warehouse::create([
                'name' => 'انبار تست',
                'location' => 'تهران',
            ]);
        } finally {
            $context->clear();
        }

        $response = $this->postJson('/api/stock-movements', [
            'sku' => $product->sku,
            'type' => 'in',
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
            'reference' => 'TEST-IN-001',
        ], [
            'X-Tenant-ID' => (string) $tenant->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.type', 'in')
            ->assertJsonPath('data.quantity', 10);

        $this->assertDatabaseHas('stock_levels', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'in',
            'quantity' => 10,
            'reference' => 'TEST-IN-001',
        ]);
    }
}
