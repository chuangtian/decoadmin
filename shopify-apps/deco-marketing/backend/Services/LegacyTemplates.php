<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use DecoMarketing\Models\Template;

class LegacyTemplates
{
    /** Read from the old UI on 2026-09-10 without saving or sending there. */
    public function contents(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../resources/legacy-templates.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Stage only: publishing still requires testing the exact content. Existing sends keep snapshots. */
    public function stage(Store $store): int
    {
        app(Guard::class)->store($store);
        $count = 0;
        foreach ($this->contents() as $key => $content) {
            $template = Template::forStore($store)->where('key', $key)->firstOrFail();
            if ($template->draft !== $content) {
                $template->update(['draft' => $content, 'tested_at' => null, 'tested_hash' => null]);
                $count++;
            }
        }
        return $count;
    }
}
