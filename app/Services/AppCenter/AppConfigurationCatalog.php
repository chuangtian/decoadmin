<?php

namespace App\Services\AppCenter;

use App\Contracts\AppConfigurationProvider;
use App\Models\AppInstallation;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

class AppConfigurationCatalog
{
    /** @var list<AppConfigurationProvider> */
    private array $providers;

    /** @param iterable<AppConfigurationProvider> $providers */
    public function __construct(iterable $providers)
    {
        $this->providers = is_array($providers) ? $providers : iterator_to_array($providers);
    }

    /**
     * @param  Collection<int, AppInstallation>  $installations
     * @return list<array<string, mixed>>
     */
    public function forInstallations(Collection $installations, User $user): array
    {
        return $installations
            ->map(fn (AppInstallation $installation): array => $this->forInstallation($installation, $user))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function forInstallation(AppInstallation $installation, User $user): array
    {
        $app = $installation->app;
        $store = $installation->store;
        if (! $app || ! $store) {
            throw new RuntimeException('Application configuration requires loaded app and store relationships.');
        }

        $provider = collect($this->providers)
            ->first(fn (AppConfigurationProvider $provider): bool => $provider->supports($installation));
        if (! $provider) {
            throw new RuntimeException("No application configuration provider supports app {$app->handle}.");
        }

        return [
            'installation_id' => $installation->id,
            'installation_status' => $installation->status,
            'connection_status' => $installation->shopifyConnection?->status,
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'handle' => $app->handle,
                'description' => $app->description,
            ],
            ...$provider->present($installation, $user),
        ];
    }
}
