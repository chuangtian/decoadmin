<?php

namespace App\Services\SeoAnalytics;

use App\Models\Store;
use App\Services\StoreBusinessCredentialService;

class SeoAnalyticsConfigurationService
{
    public const CHANNELS = ['Organic Search', 'Organic Shopping', 'AI assistants'];

    public const BRAND_REGEX = "fox e bike|fox ebike|fox electric bike|foxmac|mac's bike|mac e|mac bicycle|mac bike|mac box e bike|mac box bike|mac cycle|mac e bike|mac ebike|mac electric bike|mac foc|mac foz|mac fox|mac fix|mac gox|macbike|macbox ebike|maccfox|macefox|macfax|macfix|macgox|macdox|macfoc|macfox|macfoxbike|macfoz|macfpx|macofox|macofx|macpox|macrox ebike|macrox x1s|macs adventure|macs adventures|macs bike|macvox|mach fox|mack fox|mackfox|mad fox|madfox|magfox|mak fox|makfox|matfox|mavfox|max fox|maxfoc|maxfox|maxgox|mocfox|mscfox|fox|macbox|maddox";

    public function __construct(private StoreBusinessCredentialService $credentials) {}

    /** @return array<string, mixed> */
    public function forStore(Store $store): array
    {
        $value = fn (string $key): string => trim((string) $this->credentials->value($store, 'google_search_console_ga4', $key));
        $site = $value('gsc_site_url');
        $property = $value('ga4_property_id');

        if ($this->isMacfox($store)) {
            $site = $site ?: trim((string) config('services.google_search_console.default_site_url'));
            $property = $property ?: trim((string) config('services.google_analytics.default_property_id'));
        }

        return [
            'gsc_client_id' => $value('gsc_client_id'),
            'gsc_client_secret' => $value('gsc_client_secret'),
            'gsc_refresh_token' => $value('gsc_refresh_token'),
            'gsc_site_url' => $site,
            'ga4_property_id' => $property,
            'ga4_service_account_json' => $value('ga4_service_account_json'),
            'ga4_channel_dimension' => (string) config('services.google_analytics.channel_dimension'),
        ];
    }

    /** @return array{gsc: bool, ga4: bool, configured: bool, missing: list<string>} */
    public function status(Store $store): array
    {
        $config = $this->forStore($store);
        $gsc = $this->filled($config, ['gsc_client_id', 'gsc_client_secret', 'gsc_refresh_token', 'gsc_site_url']);
        $ga4 = $this->filled($config, ['ga4_property_id', 'ga4_service_account_json', 'ga4_channel_dimension']);
        $required = ['gsc_client_id', 'gsc_client_secret', 'gsc_refresh_token', 'gsc_site_url', 'ga4_property_id', 'ga4_service_account_json'];

        return [
            'gsc' => $gsc,
            'ga4' => $ga4,
            'configured' => $gsc && $ga4,
            'missing' => array_values(array_filter($required, fn (string $key): bool => blank($config[$key] ?? null))),
        ];
    }

    /** @param array<string, mixed> $config @param list<string> $keys */
    private function filled(array $config, array $keys): bool
    {
        return collect($keys)->every(fn (string $key): bool => filled($config[$key] ?? null));
    }

    private function isMacfox(Store $store): bool
    {
        return str_contains(strtolower((string) $store->shopify_domain), 'macfox')
            || str_contains(strtolower((string) $store->name), 'macfox');
    }
}
