<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generate a fresh Ed25519 keypair for license signing. The public
 * key is written to licensing/public_key.pem (committed in-repo and
 * baked into the self-hosted Docker image at build time); the
 * private key is printed once for the operator to copy into the
 * cloud env as LICENSE_SIGNING_PRIVATE_KEY.
 */
class GenerateLicenseKeypair extends Command
{
    protected $signature = 'tc:license:keygen
        {--path= : Override the public-key output path (defaults to licensing/public_key.pem)}
        {--force : Overwrite an existing public-key file without prompting}';

    protected $description = 'Generate a fresh Ed25519 license-signing keypair.';

    public function handle(): int
    {
        $publicKeyPath = (string) ($this->option('path') ?: base_path('licensing/public_key.pem'));
        $force = (bool) $this->option('force');

        if (file_exists($publicKeyPath) && ! $force) {
            if (! $this->confirm("Overwrite existing {$publicKeyPath}?", false)) {
                $this->warn('Aborted — pass --force to skip this prompt in CI.');

                return self::FAILURE;
            }
        }

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $pem = $this->wrapPem('ED25519 PUBLIC KEY', base64_encode($publicKey));
        if (! is_dir(dirname($publicKeyPath))) {
            mkdir(dirname($publicKeyPath), 0755, recursive: true);
        }
        file_put_contents($publicKeyPath, $pem);

        $this->info("Wrote public key to {$publicKeyPath}.");
        $this->newLine();
        $this->line('Copy the following line into your cloud env (and rotate the previous value):');
        $this->newLine();
        $this->line('LICENSE_SIGNING_PRIVATE_KEY='.base64_encode($secretKey));
        $this->newLine();
        $this->warn('This is the only time the private key will be displayed. Store it in a secret manager.');

        return self::SUCCESS;
    }

    private function wrapPem(string $label, string $base64): string
    {
        return "-----BEGIN {$label}-----\n".chunk_split($base64, 64, "\n")."-----END {$label}-----\n";
    }
}
