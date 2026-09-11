<?php

namespace App\Http\Controllers;

use App\Models\BusinessNotification;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessNotificationController extends Controller
{
    public function index(Request $request, CurrentOrganization $currentOrganization): JsonResponse
    {
        $organization = $currentOrganization->require();
        $user = $request->user();
        abort_unless($user, 401);

        $scope = BusinessNotification::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id);

        return response()->json([
            'data' => [
                'unread_count' => (clone $scope)->whereNull('read_at')->count(),
                'notifications' => (clone $scope)
                    ->latest('created_at')
                    ->latest('id')
                    ->limit(12)
                    ->get()
                    ->map(fn (BusinessNotification $notification) => $this->serialize($notification))
                    ->all(),
            ],
        ])->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    public function read(Request $request, BusinessNotification $businessNotification, CurrentOrganization $currentOrganization): JsonResponse
    {
        $organization = $currentOrganization->require();
        $user = $request->user();
        abort_unless($user
            && (int) $businessNotification->organization_id === (int) $organization->id
            && (int) $businessNotification->user_id === (int) $user->id, 404);

        if ($businessNotification->read_at === null) {
            $businessNotification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => $this->serialize($businessNotification->fresh())])
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    public function readAll(Request $request, CurrentOrganization $currentOrganization): JsonResponse
    {
        $organization = $currentOrganization->require();
        $user = $request->user();
        abort_unless($user, 401);

        BusinessNotification::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['unread_count' => 0]])
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    private function serialize(BusinessNotification $notification): array
    {
        return [
            'uuid' => $notification->uuid,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'action_url' => $notification->action_url,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
