<?php

namespace App\Modules\SelfHosted\Console\Commands;

use App\Modules\SelfHosted\Models\LicenseState;
use App\Modules\SelfHosted\Services\Licensing\LicenseValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Run from the self-hosted Docker image's entrypoint, ahead of
 * php-fpm. Refuses to start the container when:
 *   - LICENSE_TOKEN is missing or malformed,
 *   - the Ed25519 signature doesn't verify,
 *   - the token has already expired at boot,
 *   - the fingerprint disagrees with TC_DOMAIN.
 *
 * Compose treats a non-zero exit as a failed container, so
 * `docker compose ps` shows the failure with the precise reason in
 * the container logs.
 */
class BootLicenseCheckCommand extends Command
{
    protected $signature = 'tc:license:check';

    protected $description = 'Verify the self-hosted license token at container boot. Exits non-zero on failure.';

    public function handle(LicenseValidator $validator): int
    {
        $token = (string) env('LICENSE_TOKEN', '');
        if ($token === '') {
            $this->error('LICENSE_TOKEN is unset.');

            return self::FAILURE;
        }

        $expectedFingerprint = $this->expectedFingerprint();
        $result = $validator->validate($token, $expectedFingerprint);

        if ($result['valid'] === false) {
            $this->error('License check failed: '.$result['reason']);

            return self::FAILURE;
        }

        $now = Carbon::now();
        LicenseState::query()->updateOrCreate(
            ['id' => 1],
            [
                'token' => $token,
                'verified_payload' => $result['payload'],
                'verified_at' => $now,
                'updated_at' => $now,
            ],
        );

        $this->info('License OK; expires '.($result['payload']['expires_at'] ?? '?'));

        return self::SUCCESS;
    }

    private function expectedFingerprint(): ?string
    {
        $domain = (string) env('TC_DOMAIN', '');

        return $domain === '' ? null : "domain:{$domain}";
    }
}
