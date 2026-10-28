<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    // state, total, confirmed_* y voided_* los escriben solo ConfirmarCompra y AnularCompra (forceFill).
    protected $fillable = ['supplier_id', 'warehouse_id', 'document_date', 'supplier_reference', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'document_date' => 'date:Y-m-d',
            'confirmed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
