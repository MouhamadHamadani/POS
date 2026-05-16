<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrintTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'type', 'template_html', 'paper_size',
        'is_default', 'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
