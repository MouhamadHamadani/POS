<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_id', 'sale_item_id', 'product_id',
        'qty_returned', 'refund_amount_usd', 'restock', 'condition',
    ];

    protected $casts = [
        'qty_returned' => 'decimal:4',
        'refund_amount_usd' => 'decimal:4',
        'restock' => 'boolean',
    ];

    public function returnTransaction(): BelongsTo
    {
        return $this->belongsTo(ReturnTransaction::class, 'return_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
