<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class StockMovement extends Model
{
    use BelongsToTenant;
    public const null UPDATED_AT = null;

    /**
     * Database columns:
     * id, tenant_id, product_id, warehouse_id,
     * type, quantity, reference, created_at
     */
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'type',
        'quantity',
        'reference',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'tenant_id' => 'integer',
            'product_id' => 'integer',
            'warehouse_id' => 'integer',
            'quantity' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
