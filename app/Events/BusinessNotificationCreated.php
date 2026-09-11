<?php

namespace App\Events;

use App\Models\BusinessNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BusinessNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public BusinessNotification $notification) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("business-notifications.{$this->notification->organization_id}.{$this->notification->user_id}");
    }

    public function broadcastAs(): string
    {
        return 'business-notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'notification' => [
                'uuid' => $this->notification->uuid,
                'type' => $this->notification->type,
                'title' => $this->notification->title,
                'message' => $this->notification->message,
                'action_url' => $this->notification->action_url,
                'read_at' => $this->notification->read_at?->toIso8601String(),
                'created_at' => $this->notification->created_at?->toIso8601String(),
            ],
        ];
    }
}
