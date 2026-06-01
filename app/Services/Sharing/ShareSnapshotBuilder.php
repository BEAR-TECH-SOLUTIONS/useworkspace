<?php

namespace App\Services\Sharing;

use App\Http\Resources\Sharing\BoardShareSnapshot;
use App\Http\Resources\Sharing\CredentialShareSnapshot;
use App\Http\Resources\Sharing\DocShareSnapshot;
use App\Http\Resources\Sharing\ExpenseShareSnapshot;
use App\Http\Resources\Sharing\TaskShareSnapshot;
use App\Models\Docs\Doc;
use App\Models\Expenses\Expense;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskItem;
use App\Models\Vault\Credential;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Dispatches a source resource to its snapshot serialiser and enforces
 * the per-type byte cap (5 MB for doc — BlockNote content can carry
 * embedded base64 images — 1 MB for everything else).
 */
class ShareSnapshotBuilder
{
    private const CAP_DEFAULT = 1 * 1024 * 1024;

    private const CAP_DOC = 5 * 1024 * 1024;

    /**
     * @param  array{encrypted_blob: string, blob_iv: string, key_salt: string}|null  $credentialCrypto
     * @param  array<string, mixed>|null  $boardStats  Optional pre-computed progress-stats block; only honoured when $resourceType='board'.
     * @return array<string, mixed>
     */
    public function build(string $resourceType, Model $resource, ?array $credentialCrypto = null, ?array $boardStats = null): array
    {
        $snapshot = match ($resourceType) {
            'board' => $this->expectModel($resource, TaskBoard::class)
                ?: BoardShareSnapshot::forResource($resource, $boardStats),
            'task' => $this->expectModel($resource, TaskItem::class)
                ?: TaskShareSnapshot::forResource($resource),
            'doc' => $this->expectModel($resource, Doc::class)
                ?: DocShareSnapshot::forResource($resource),
            'expense' => $this->expectModel($resource, Expense::class)
                ?: ExpenseShareSnapshot::forResource($resource),
            'credential' => $this->buildCredentialSnapshot($resource, $credentialCrypto),
            default => throw new InvalidArgumentException("Unknown share-link resource_type [{$resourceType}]"),
        };

        $this->enforceSizeCap($resourceType, $snapshot);

        return $snapshot;
    }

    /**
     * Returns null when the actual class matches; throws otherwise.
     * Match-arm helper so the closures stay terse.
     */
    private function expectModel(Model $resource, string $expected): ?array
    {
        if (! $resource instanceof $expected) {
            throw new InvalidArgumentException(
                'Share resource type mismatch: expected '.$expected.', got '.$resource::class,
            );
        }

        return null;
    }

    /**
     * @param  array{encrypted_blob: string, blob_iv: string, key_salt: string}|null  $crypto
     * @return array<string, mixed>
     */
    private function buildCredentialSnapshot(Model $resource, ?array $crypto): array
    {
        if (! $resource instanceof Credential) {
            throw new InvalidArgumentException('Expected Credential, got '.$resource::class);
        }

        if ($crypto === null) {
            throw new InvalidArgumentException('Credential snapshots require client-supplied crypto payload.');
        }

        return CredentialShareSnapshot::forResource($resource, $crypto);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function enforceSizeCap(string $resourceType, array $snapshot): void
    {
        $cap = $resourceType === 'doc' ? self::CAP_DOC : self::CAP_DEFAULT;
        $size = strlen(json_encode($snapshot, JSON_THROW_ON_ERROR));

        if ($size > $cap) {
            throw new SnapshotTooLargeException($size, $cap, $resourceType);
        }
    }
}
