<?php

namespace App\Models\Concerns;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Phase 16A / T16-03 — optional TOTP (RFC 6238) two-factor authentication.
 *
 * Enrolment is two-phase: a secret is generated and stored unconfirmed, then
 * confirmed with a valid code. Only a confirmed secret is treated as "enabled",
 * so abandoning the flow never locks a user out.
 */
trait HasTwoFactorAuthentication
{
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null
            && filled($this->two_factor_secret);
    }

    /**
     * The TOTP secret in plain text (decrypted by the model cast).
     */
    public function twoFactorSecret(): ?string
    {
        $secret = $this->two_factor_secret;

        return filled($secret) ? (string) $secret : null;
    }

    /**
     * Start (or restart) the enrolment: a new unconfirmed secret is stored.
     */
    public function beginTwoFactorEnrollment(): ?string
    {
        $secret = (new Google2FA)->generateSecretKey();

        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secret;
    }

    public function twoFactorQrCodeUrl(): ?string
    {
        $secret = $this->twoFactorSecret();

        if ($secret === null) {
            return null;
        }

        return (new Google2FA)->getQRCodeUrl(
            (string) config('security.two_factor.issuer', config('app.name')),
            (string) $this->email,
            $secret,
        );
    }

    public function twoFactorQrCodeSvg(int $size = 220): ?string
    {
        $url = $this->twoFactorQrCodeUrl();

        if ($url === null) {
            return null;
        }

        $writer = new Writer(
            new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)
        );

        return $writer->writeString($url);
    }

    public function verifyTwoFactorCode(?string $code): bool
    {
        $secret = $this->twoFactorSecret();
        $code = trim((string) $code);

        if ($secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        return (bool) (new Google2FA)->verifyKey(
            $secret,
            $code,
            (int) config('security.two_factor.window', 1),
        );
    }

    /**
     * Confirm a pending enrolment and issue the one-time recovery codes.
     */
    public function confirmTwoFactor(?string $code): bool
    {
        if (! $this->verifyTwoFactorCode($code)) {
            return false;
        }

        $this->forceFill([
            'two_factor_recovery_codes' => $this->generateRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function recoveryCodes(): array
    {
        $codes = $this->two_factor_recovery_codes;

        return is_array($codes) ? array_values($codes) : [];
    }

    /**
     * @return array<int, string>
     */
    public function generateRecoveryCodes(): array
    {
        $count = max(1, (int) config('security.two_factor.recovery_codes', 8));

        return collect(range(1, $count))
            ->map(fn (): string => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)))
            ->all();
    }

    /**
     * Use a recovery code once; it is removed from the stored list.
     */
    public function consumeRecoveryCode(?string $code): bool
    {
        $normalised = $this->normaliseRecoveryCode($code);

        if ($normalised === null) {
            return false;
        }

        $remaining = $this->recoveryCodes();

        foreach ($remaining as $index => $candidate) {
            if ($this->normaliseRecoveryCode((string) $candidate) === null) {
                continue;
            }

            if (hash_equals($this->normaliseRecoveryCode((string) $candidate), $normalised)) {
                unset($remaining[$index]);

                $this->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->save();

                return true;
            }
        }

        return false;
    }

    public function disableTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * True when the configured policy requires this user to enrol.
     */
    public function mustEnrollTwoFactor(): bool
    {
        $roles = (array) config('security.two_factor.enforced_roles', []);

        if ($roles === [] || $this->hasTwoFactorEnabled()) {
            return false;
        }

        if (in_array($this->role, $roles, true)) {
            return true;
        }

        $this->loadMissing('accessRole');

        return $this->accessRole !== null
            && in_array($this->accessRole->name, $roles, true);
    }

    private function normaliseRecoveryCode(?string $code): ?string
    {
        $normalised = preg_replace('/[^A-Z0-9]/', '', Str::upper(trim((string) $code)));

        return $normalised === null || $normalised === '' ? null : $normalised;
    }
}
