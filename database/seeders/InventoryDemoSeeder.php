<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(TenantContext::class);

        $companies = [
            [
                'name' => 'شرکت آلفا',
                'subdomain' => 'alpha',
                'products' => [
                    [
                        'sku' => 'SKU-001',
                        'name' => 'محصول اول آلفا',
                        'unit_price' => '150000.00',
                    ],
                    [
                        'sku' => 'SKU-002',
                        'name' => 'محصول دوم آلفا',
                        'unit_price' => '250000.00',
                    ],
                ],
                'warehouses' => [
                    [
                        'name' => 'انبار مرکزی آلفا',
                        'location' => 'تهران',
                    ],
                    [
                        'name' => 'انبار شعبه آلفا',
                        'location' => 'کرج',
                    ],
                ],
            ],
            [
                'name' => 'شرکت بتا',
                'subdomain' => 'beta',
                'products' => [
                    [
                        'sku' => 'SKU-001',
                        'name' => 'محصول اول بتا',
                        'unit_price' => '180000.00',
                    ],
                    [
                        'sku' => 'SKU-002',
                        'name' => 'محصول دوم بتا',
                        'unit_price' => '280000.00',
                    ],
                ],
                'warehouses' => [
                    [
                        'name' => 'انبار مرکزی بتا',
                        'location' => 'شیراز',
                    ],
                    [
                        'name' => 'انبار شعبه بتا',
                        'location' => 'اصفهان',
                    ],
                ],
            ],
        ];

        try {
            DB::transaction(function () use ($companies, $context): void {
                foreach ($companies as $company) {
                    $tenant = Tenant::firstOrCreate(
                        ['subdomain' => $company['subdomain']],
                        ['name' => $company['name']]
                    );

                    // Required by the global scope and model events.
                    $context->set((int) $tenant->id);

                    foreach ($company['products'] as $product) {
                        Product::firstOrCreate(
                            ['sku' => $product['sku']],
                            [
                                'name' => $product['name'],
                                'unit_price' => $product['unit_price'],
                            ]
                        );
                    }

                    foreach ($company['warehouses'] as $warehouse) {
                        Warehouse::firstOrCreate(
                            ['name' => $warehouse['name']],
                            ['location' => $warehouse['location']]
                        );
                    }
                }
            });

            $this->command?->table(
                ['Tenant ID', 'Name', 'Subdomain'],
                Tenant::query()
                    ->whereIn('subdomain', ['alpha', 'beta'])
                    ->orderBy('id')
                    ->get(['id', 'name', 'subdomain'])
                    ->toArray()
            );
        } finally {
            $context->clear();
        }
    }
}
