<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->enum('type', ['in', 'out', 'transfer']);
            $table->unsignedInteger('quantity');
            $table->string('reference')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants');

            $table->foreign('product_id')
                ->references('id')
                ->on('products');

            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses');

            $table->json('meta')->nullable();
            $table->index(
                ['tenant_id', 'product_id', 'id'],
                'sm_tenant_product_id_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
