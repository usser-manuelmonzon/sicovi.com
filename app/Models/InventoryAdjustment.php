<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustment extends Model
{
    protected $fillable = ['warehouse_id', 'type', 'adjustment_date', 'reason', 'reference', 'notes', 'created_by', 'applied_idempotency_key'];

    protected function casts(): array
    {
        return ['adjustment_date' => 'date:Y-m-d', 'voided_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
