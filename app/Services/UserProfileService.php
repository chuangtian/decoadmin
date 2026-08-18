<?php

namespace App\Services;

use App\Models\User;

class UserProfileService
{
    /** @param array{name: string, email: string, password?: string|null} $attributes */
    public function update(User $user, array $attributes): User
    {
        $user->fill([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
        ]);

        if (! empty($attributes['password'])) {
            $user->password = $attributes['password'];
        }

        $user->save();

        return $user->refresh();
    }
}
