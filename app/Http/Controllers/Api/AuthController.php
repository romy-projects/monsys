<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use App\Services\UserPasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'one_time_password' => ['nullable', 'string', 'max:32'],
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

        // Phase 16A / T16-03 — enrolled users must also present a TOTP code
        // (or a single-use recovery code). The password alone is not enough.
        if ($user->hasTwoFactorEnabled()) {
            $code = $data['one_time_password'] ?? null;

            if (blank($code)) {
                return $this->error(
                    'Two-factor authentication is enabled for this account. Resend the request with one_time_password.',
                    422,
                    ['two_factor_required' => ['A two-factor authentication code is required.']],
                );
            }

            if (! $user->verifyTwoFactorCode($code) && ! $user->consumeRecoveryCode($code)) {
                return $this->error(
                    'The provided two-factor authentication code is invalid.',
                    422,
                    ['one_time_password' => ['The provided two-factor authentication code is invalid.']],
                );
            }
        }

        $deviceName = $data['device_name'] ?? ($request->userAgent() ?? 'mobile');
        $token = $user->createToken($deviceName, ['*'], now()->addDays(90))->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 90 * 24 * 60 * 60,
            // The mobile app may sign in while a rotation is pending; it is not
            // blocked (unlike the web panel) but must show the change screen.
            'must_change_password' => $user->mustChangePassword(),
            'user' => new UserResource($user),
        ], 'Login successful.');
    }

    /**
     * Phase 18 / T18-01 — self-service password change for API clients.
     *
     * The token used for this request survives so the device stays signed in;
     * every other token belonging to the account is revoked by the service.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $minimumLength = app(UserPasswordService::class)->minimumLength();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'min:'.$minimumLength,
                'confirmed',
                'different:current_password',
            ],
        ]);

        $user = $request->user();
        $token = $user->currentAccessToken();
        $keepTokenId = $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;

        if (! app(UserPasswordService::class)->change($user, $data['current_password'], $data['password'], $keepTokenId)) {
            throw ValidationException::withMessages([
                'current_password' => ['The provided current password is incorrect.'],
            ]);
        }

        return $this->success([
            'must_change_password' => false,
            'user' => new UserResource($user->fresh()->load('branch')),
        ], 'Password changed.');
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
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 90 * 24 * 60 * 60,
        ], 'Token refreshed.');
    }
}
