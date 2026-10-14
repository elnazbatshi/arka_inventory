<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\InventoryReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('inventory:reconcile {tenant}')]
#[Description('Compare stock levels against recorded stock movements')]
class ReconcileInventory extends Command
{
    public function handle(InventoryReconciliationService $service): int
    {
        $tenantId = filter_var(
            $this->argument('tenant'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($tenantId === false) {
            $this->error('A valid tenant ID is required.');

            return self::FAILURE;
        }

        if (! Tenant::query()->whereKey($tenantId)->exists()) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $count = 0;

        foreach ($service->discrepancies($tenantId) as $difference) {
            $details = [
                'tenant_id' => $tenantId,
                'product_id' => (int) $difference->product_id,
                'warehouse_id' => (int) $difference->warehouse_id,
                'expected' => (int) $difference->expected,
                'actual' => (int) $difference->actual,
            ];

            Log::warning('Inventory discrepancy detected.', $details);

            $this->warn(sprintf(
                'Product: %d | Warehouse: %d | Expected: %d | Actual: %d',
                $details['product_id'],
                $details['warehouse_id'],
                $details['expected'],
                $details['actual'],
            ));

            $count++;
        }

        $this->info(
            "Reconciliation completed. Discrepancies: {$count}"
        );

        return self::SUCCESS;
    }
}
