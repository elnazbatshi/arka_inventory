<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'tenant_id' => $this->resource['tenant_id'],
            'product_id' => $this->resource['product_id'],
            'warehouse_id' => $this->resource['warehouse_id'],
            'type' => $this->resource['type'],
            'quantity' => $this->resource['quantity'],
            'reference' => $this->resource['reference'] ?? null,
            'meta' => $this->resource['meta'],
            'created_at' => $this->resource['created_at'],
        ];
    }
}
