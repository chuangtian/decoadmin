<?php

namespace App\Support;

use App\Models\Organization;

class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function require(): Organization
    {
        abort_unless($this->organization, 422, 'No current organization has been selected.');

        return $this->organization;
    }

    public function clear(): void
    {
        $this->organization = null;
    }
}
