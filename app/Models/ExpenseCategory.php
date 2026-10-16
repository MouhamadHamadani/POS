<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    public const KIND_FIXED = 'fixed';
    public const KIND_RUNNING = 'running';
    public const KINDS = [self::KIND_FIXED, self::KIND_RUNNING];

    protected $fillable = ['name', 'name_ar', 'kind', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function localName(): string
    {
        return app()->getLocale() === 'ar' && $this->name_ar ? $this->name_ar : $this->name;
    }

    public function kindLabel(): string
    {
        return \App\Support\EnumLabel::get('expense_kind', $this->kind);
    }
}
