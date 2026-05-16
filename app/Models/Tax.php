<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tax extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'name_ar', 'rate', 'is_inclusive', 'is_default',
        'description', 'is_active',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'is_inclusive' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
