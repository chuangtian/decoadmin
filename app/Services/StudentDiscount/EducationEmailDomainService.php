<?php

namespace App\Services\StudentDiscount;

class EducationEmailDomainService
{
    /** @param list<string> $customDomains */
    public function matches(string $email, array $customDomains): bool
    {
        $domain = strtolower((string) str($email)->afterLast('@'));
        if ($domain === '' || ! str_contains($email, '@')) {
            return false;
        }

        if (str_ends_with($domain, '.edu')
            || preg_match('/\.edu\.[a-z]{2}$/', $domain) === 1
            || preg_match('/\.ac\.[a-z]{2}$/', $domain) === 1) {
            return true;
        }

        foreach ($customDomains as $configured) {
            $configured = strtolower(trim($configured, " .\t\n\r\0\x0B"));
            if ($configured !== '' && ($domain === $configured || str_ends_with($domain, '.'.$configured))) {
                return true;
            }
        }

        return false;
    }
}
