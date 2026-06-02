<?php

namespace App\Modules\SelfHosted\Console\Commands;

use App\Models\Identity\Organisation;
use App\Models\Project\Project;
use App\Modules\SelfHosted\Models\LicenseState;
use App\Modules\SelfHosted\Services\Licensing\LicenseValidator;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hourly phone-home for self-hosted instances. Posts an aggregate
 * heartbeat to the central backend's `/licenses/verify` endpoint and
 * reflects the result into `license_state`.
 *
 * Payload allow-list (matches spec §6.4 / §4.4): license token,
 * instance id/url/version, aggregate member + project counts. No
 * project names, no user emails, no vault data ever — this is the
 * trust contract with self-hosters.
 *
 * Behaviour (audit C7 fix):
 *  - 200 valid:true  → re-verify the local token with the baked-in
 *                       Ed25519 public key and persist the LOCAL
 *                       payload into license_state. The remote
 *                       response is treated as a revocation channel
 *                       ONLY, never as authoritative cap data — a
 *                       compromised cloud / hijacked DNS cannot
 *                       silently elevate caps because the source of
 *                       truth is the signed token on disk.
 *  - 200 valid:false → record the reason; LicenseGuard will refuse
 *                       on the next request with the precise code.
 *  - Network failure → no-op. The grace clock continues.
 */
class PhoneHomeCommand extends Command
{
    protected $signature = 'license:phone-home';

    protected $description = 'Self-hosted: report aggregate telemetry to the central license server and reflect revocation status.';

    public function handle(HttpFactory $http, LicenseValidator $validator): int
    {
        $token = (string) env('LICENSE_TOKEN', '');
        if ($token === '') {
            $this->warn('LICENSE_TOKEN is unset — skipping phone-home.');

            return self::SUCCESS;
        }

        $endpoint = (string) env('LICENSE_PHONE_HOME_URL', 'https://api.teamcore.space/api/v1/licenses/verify');

        $body = [
            'token' => $token,
            'instance_id' => (string) env('LICENSE_INSTANCE_ID', ''),
            'instance_url' => $this->instanceUrl(),
            'instance_version' => $this->instanceVersion(),
            'member_count' => (int) Organisation::query()->sum('member_count'),
            'project_count' => (int) Project::query()->count(),
        ];

        try {
            $response = $http->timeout(15)->asJson()->post($endpoint, $body);
        } catch (ConnectionException|Throwable $e) {
            $this->warn('Phone-home network failure: '.$e->getMessage());

            // Intentionally leave `last_phone_home_at` and `_ok`
            // alone — the grace clock advances only on a successful
            // call, transient network failures don't reset it.
            return self::SUCCESS;
        }

        if (! $response->successful()) {
            $this->warn('Phone-home returned HTTP '.$response->status());

            return self::SUCCESS;
        }

        $json = (array) $response->json();
        $now = Carbon::now();

        // Serialise the whole read-validate-write cycle (audit M7).
        // Without lockForUpdate, two overlapping phone-homes (e.g. a
        // manual `artisan license:phone-home` while the scheduler
        // fires) could race a LicenseGuard read in the middle and
        // surface a half-updated row. The lock is cheap — the table
        // has one row by construction.
        $message = DB::transaction(function () use ($token, $json, $validator, $now): array {
            $state = LicenseState::query()->lockForUpdate()->find(1);
            if ($state === null) {
                $state = LicenseState::singleton();
                LicenseState::query()->whereKey($state->id)->lockForUpdate()->first();
            }

            if (($json['valid'] ?? false) === true) {
                $local = $validator->validate($token, $this->expectedFingerprint());
                if ($local['valid'] === false) {
                    $state->update([
                        'last_phone_home_at' => $now,
                        'last_phone_home_ok' => false,
                        'last_phone_home_code' => substr('local_'.$local['reason'], 0, 64),
                        'updated_at' => $now,
                    ]);

                    return ['level' => 'warn', 'text' => "Phone-home OK but local re-verify failed: {$local['reason']}"];
                }

                $state->update([
                    'token' => $token,
                    'verified_payload' => $local['payload'],
                    'verified_at' => $now,
                    'last_phone_home_at' => $now,
                    'last_phone_home_ok' => true,
                    'last_phone_home_code' => null,
                    'updated_at' => $now,
                ]);

                return ['level' => 'info', 'text' => 'Phone-home OK.'];
            }

            $reason = is_string($json['reason'] ?? null) ? $json['reason'] : 'unknown';
            $state->update([
                'last_phone_home_at' => $now,
                'last_phone_home_ok' => false,
                'last_phone_home_code' => substr($reason, 0, 64),
                'updated_at' => $now,
            ]);

            return ['level' => 'warn', 'text' => "Phone-home rejected: {$reason}"];
        });

        $message['level'] === 'info' ? $this->info($message['text']) : $this->warn($message['text']);

        return self::SUCCESS;
    }

    private function instanceUrl(): ?string
    {
        $domain = (string) env('TC_DOMAIN', '');

        return $domain === '' ? null : "https://{$domain}";
    }

    private function instanceVersion(): ?string
    {
        $version = (string) env('APP_VERSION', '');

        return $version === '' ? null : substr($version, 0, 32);
    }

    private function expectedFingerprint(): ?string
    {
        $domain = (string) env('TC_DOMAIN', '');

        return $domain === '' ? null : "domain:{$domain}";
    }
}
