<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    use ApiResponse;

    public function registerToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string'],
            'platform'  => ['required', 'in:android,ios'],
        ]);

        $user = $request->user();

        // Store FCM token on the user record.
        // Requires `fcm_token` + `fcm_platform` columns on users table (added via migration).
        $user->update([
            'fcm_token'    => $data['fcm_token'],
            'fcm_platform' => $data['platform'],
        ]);

        return $this->success(['registered' => true], 'FCM token registered.');
    }

    public function revokeToken(Request $request): JsonResponse
    {
        $request->user()->update(['fcm_token' => null, 'fcm_platform' => null]);

        return $this->noContent('FCM token revoked.');
    }
}
