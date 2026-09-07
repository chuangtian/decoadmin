<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'mail_enabled', 'mail_host', 'mail_port', 'mail_encryption',
    'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name', 'mail_recipients',
    'feishu_enabled', 'feishu_webhook_url', 'feishu_secret', 'notify_sync_failed',
    'notify_webhook_failed', 'notify_connection_unhealthy', 'notify_discount_monitor', 'notify_product_monitor', 'updated_by',
])]
#[Hidden(['mail_password', 'feishu_webhook_url', 'feishu_secret'])]
class StoreNotificationSetting extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function casts(): array
    {
        return [
            'mail_enabled' => 'boolean', 'mail_port' => 'integer', 'mail_password' => 'encrypted',
            'mail_recipients' => 'array', 'feishu_enabled' => 'boolean',
            'feishu_webhook_url' => 'encrypted', 'feishu_secret' => 'encrypted',
            'notify_sync_failed' => 'boolean', 'notify_webhook_failed' => 'boolean',
            'notify_connection_unhealthy' => 'boolean',
            'notify_discount_monitor' => 'boolean',
            'notify_product_monitor' => 'boolean',
        ];
    }
}
