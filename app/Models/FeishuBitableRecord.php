<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'feishu_bitable_table_id', 'source_record_id',
    'fields_encrypted', 'source_created_at', 'source_updated_at', 'synced_at',
])]
#[Hidden(['fields_encrypted'])]
class FeishuBitableRecord extends Model
{
    public function table(): BelongsTo
    {
        return $this->belongsTo(FeishuBitableTable::class, 'feishu_bitable_table_id');
    }

    protected function casts(): array
    {
        return [
            'fields_encrypted' => 'encrypted:array',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
