<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamp('updated_at')->useCurrent()
                ->useCurrentOnUpdate();

            $table->unique([
                'tenant_id',
                'product_id',
                'warehouse_id',
            ]);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants');

            $table->foreign('product_id')
                ->references('id')
                ->on('products');

            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses');


            $table->index(
                ['tenant_id', 'id'],
                'sl_tenant_id_index'
            );


            $table->index(
                ['tenant_id', 'product_id', 'id'],
                'sl_tenant_product_id_index'
            );


            $table->index(
                ['tenant_id', 'warehouse_id', 'id'],
                'sl_tenant_warehouse_id_index'
            );


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
