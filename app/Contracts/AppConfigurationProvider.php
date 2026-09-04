<?php

namespace App\Contracts;

use App\Models\AppInstallation;
use App\Models\User;

interface AppConfigurationProvider
{
    public function supports(AppInstallation $installation): bool;

    /**
     * @return array{
     *     category: string,
     *     description: string,
     *     configuration_status: string,
     *     configuration_status_label: string,
     *     management_url: ?string,
     *     action_label: string,
     *     unavailable_reason: ?string,
     *     metrics: list<array{label: string, value: string}>
     * }
     */
    public function present(AppInstallation $installation, User $user): array;
}
