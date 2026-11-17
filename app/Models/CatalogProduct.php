<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogProduct extends Model
{
    use Concerns\Audited;

    protected $table = 'catalog_products';

    protected $guarded = ['id'];

    protected $casts = ['fields' => 'array', 'equipment_ids' => 'array', 'alternative_ids' => 'array', 'active' => 'boolean', 'area_pricing' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $product) {
            $product->search_name = mb_strtolower($product->name);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(CatalogSupplier::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('active', true)->whereHas('category', fn ($q) => $q->where('active', true));
    }

    public function suppliers(): Builder
    {
        return CatalogSupplier::whereIn('id', array_filter([$this->supplier_id, ...($this->alternative_ids ?? [])]));
    }
}
