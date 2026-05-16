<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'symbol', 'rate', 'is_base',
        'decimal_places', 'rounding_step', 'updated_by',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'is_base' => 'boolean',
        'decimal_places' => 'integer',
        'rounding_step' => 'decimal:2',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
