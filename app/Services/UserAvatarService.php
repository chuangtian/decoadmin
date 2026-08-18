<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class UserAvatarService
{
    public function store(UploadedFile $avatar): string
    {
        $path = $avatar->store('avatars', 'public');

        if (! $path) {
            throw new RuntimeException('用户头像保存失败。');
        }

        return '/storage/'.ltrim($path, '/');
    }

    public function delete(?string $avatarUrl): void
    {
        if (! $avatarUrl || ! str_starts_with($avatarUrl, '/storage/avatars/')) {
            return;
        }

        Storage::disk('public')->delete(Str::after($avatarUrl, '/storage/'));
    }

    public function replace(User $user, UploadedFile $avatar): string
    {
        $previousAvatarUrl = $user->avatar_url;
        $avatarUrl = $this->store($avatar);

        try {
            $user->forceFill(['avatar_url' => $avatarUrl])->save();
        } catch (Throwable $exception) {
            $this->delete($avatarUrl);

            throw $exception;
        }

        if ($previousAvatarUrl !== $avatarUrl) {
            $this->delete($previousAvatarUrl);
        }

        return $avatarUrl;
    }

    public function remove(User $user): void
    {
        $previousAvatarUrl = $user->avatar_url;

        $user->forceFill(['avatar_url' => null])->save();
        $this->delete($previousAvatarUrl);
    }
}
