<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'feishu_bitable_table_id', 'source_field_id',
    'name', 'type', 'field_order', 'is_primary', 'metadata_encrypted', 'synced_at',
])]
#[Hidden(['metadata_encrypted'])]
class FeishuBitableField extends Model
{
    public function table(): BelongsTo
    {
        return $this->belongsTo(FeishuBitableTable::class, 'feishu_bitable_table_id');
    }

    protected function casts(): array
    {
        return [
            'type' => 'integer',
            'field_order' => 'integer',
            'is_primary' => 'boolean',
            'metadata_encrypted' => 'encrypted:array',
            'synced_at' => 'datetime',
        ];
    }
}
