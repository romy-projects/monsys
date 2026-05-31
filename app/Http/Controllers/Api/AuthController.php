<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->with('branch')->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            return $this->error('Your account is inactive. Please contact your administrator.', 403);
        }

        $deviceName = $data['device_name'] ?? ($request->userAgent() ?? 'mobile');
        $token = $user->createToken($deviceName, ['*'], now()->addDays(90))->plainTextToken;

        return $this->success([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => 90 * 24 * 60 * 60,
            'user'       => new UserResource($user),
        ], 'Login successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->noContent('Logged out successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(new UserResource($request->user()->load('branch')));
    }

    public function refreshToken(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        $token = $user->createToken($request->userAgent() ?? 'mobile', ['*'], now()->addDays(90))->plainTextToken;

        return $this->success([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => 90 * 24 * 60 * 60,
        ], 'Token refreshed.');
    }
}
