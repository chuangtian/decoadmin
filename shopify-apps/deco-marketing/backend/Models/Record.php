<?php

namespace DecoMarketing\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class Record extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function ($record): void {
            $record->uuid ??= (string) Str::uuid();
        });
    }

    public function scopeForStore(Builder $query, Store $store): Builder
    {
        return $query->where($this->qualifyColumn('organization_id'), $store->organization_id)->where($this->qualifyColumn('store_id'), $store->id);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
