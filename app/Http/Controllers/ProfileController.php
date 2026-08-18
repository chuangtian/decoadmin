<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UpdateUserAvatarRequest;
use App\Http\Resources\UserResource;
use App\Services\UserAvatarService;
use App\Services\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(
        private UserProfileService $profiles,
        private UserAvatarService $avatars,
    ) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'profile' => new UserResource($request->user()),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse|JsonResponse
    {
        $user = $this->profiles->update($request->user(), $request->validated());

        return $request->expectsJson()
            ? (new UserResource($user))->response()
            : back()->with('success', '个人资料已保存。');
    }

    public function updateAvatar(UpdateUserAvatarRequest $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $this->avatars->replace($user, $request->file('avatar'));
        $user->refresh();

        return $request->expectsJson()
            ? (new UserResource($user))->response()
            : back()->with('success', '头像已自动保存。');
    }

    public function destroyAvatar(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $this->avatars->remove($user);

        return $request->expectsJson()
            ? response()->json(['data' => ['id' => $user->getKey(), 'avatar_url' => null]])
            : back()->with('success', '头像已移除。');
    }
}
