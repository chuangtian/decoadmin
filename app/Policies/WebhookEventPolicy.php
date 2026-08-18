<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\CurrentOrganization;

class WebhookEventPolicy
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        $organization = $this->currentOrganization->get();

        return $organization && $user->hasPermission('webhooks.view', $organization);
    }

    public function view(User $user, WebhookEvent $event): bool
    {
        $organization = $this->currentOrganization->get();
        $event->loadMissing('store');

        return $organization
            && $event->organization_id === $organization->getKey()
            && $event->store
            && $user->hasPermission('webhooks.view', $organization, $event->store);
    }

    public function retry(User $user, WebhookEvent $event): bool
    {
        $organization = $this->currentOrganization->get();
        $event->loadMissing('store');

        return $organization
            && $event->organization_id === $organization->getKey()
            && $event->store
            && $user->hasPermission('webhooks.retry', $organization, $event->store);
    }
}
