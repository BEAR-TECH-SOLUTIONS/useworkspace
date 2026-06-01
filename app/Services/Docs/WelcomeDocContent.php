<?php

namespace App\Services\Docs;

/**
 * Loads the canonical "Welcome to TeamCore" doc content from the
 * resources directory once per process. The BlockNote JSON is sizable
 * (~140 blocks) so we cache the parsed array statically to avoid
 * re-reading the file on every project creation inside a long-running
 * request or queue worker.
 */
class WelcomeDocContent
{
    public const TITLE = 'Welcome to TeamCore';

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $cachedContent = null;

    public function __construct(private readonly DocContentTextExtractor $extractor) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function content(): array
    {
        if (self::$cachedContent === null) {
            $path = resource_path('defaults/welcome-doc.json');
            $raw = file_get_contents($path);
            if ($raw === false) {
                // Don't hard-fail project creation if the asset is
                // somehow missing in a weird deploy — seed an empty
                // doc and let the owner rewrite it. The bootstrapper
                // still runs the rest of the defaults.
                self::$cachedContent = [];
            } else {
                /** @var array<int, array<string, mixed>> $decoded */
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                self::$cachedContent = $decoded;
            }
        }

        return self::$cachedContent;
    }

    public function plaintext(): ?string
    {
        return $this->extractor->extract($this->content());
    }

    /**
     * Test-only hook: reset the process-wide cache. Not wired into the
     * app — feature tests can call this if they need to reload the
     * asset between test runs.
     */
    public static function flushCache(): void
    {
        self::$cachedContent = null;
    }
}
