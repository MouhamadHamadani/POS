<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id', 'product_id', 'product_name',
        'qty_ordered', 'qty_received',
        'cost_usd', 'tax_rate', 'line_total_usd',
        'batch_number', 'expiry_date', 'note',
    ];

    protected $casts = [
        'qty_ordered' => 'decimal:4',
        'qty_received' => 'decimal:4',
        'cost_usd' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'line_total_usd' => 'decimal:4',
        'expiry_date' => 'date',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
