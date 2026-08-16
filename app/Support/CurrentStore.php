<?php

namespace App\Support;

use App\Models\Store;

class CurrentStore
{
    private ?Store $store = null;

    public function set(Store $store): void
    {
        $this->store = $store;
    }

    public function get(): ?Store
    {
        return $this->store;
    }

    public function require(): Store
    {
        abort_unless($this->store, 422, 'No current store has been selected.');

        return $this->store;
    }

    public function clear(): void
    {
        $this->store = null;
    }
}
