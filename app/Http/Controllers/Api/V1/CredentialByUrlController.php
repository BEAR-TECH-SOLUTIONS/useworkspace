<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Vault\Credential;
use App\Services\Vault\DomainExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Browser extension endpoint — finds all credentials the current user
 * can access + decrypt that match a website's domain, across every
 * workspace/project/vault. Single-query replacement for the extension
 * having to enumerate projects and vaults one by one.
 */
class CredentialByUrlController extends Controller
{
    public function __construct(private readonly DomainExtractor $extractor) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $request->filled('url')) {
            throw ValidationException::withMessages([
                'url' => ['The url parameter is required.'],
            ]);
        }

        $queryDomain = $this->extractor->extract($request->query('url'));

        if ($queryDomain === null) {
            return response()->json(['data' => []]);
        }

        $type = $request->query('type', 'login');
        $limit = min((int) $request->query('limit', 50), 50);

        // Step 1: Find all vault IDs the user can access — either
        // via Pattern A (project-level grant → cascades to all vaults
        // in the project) or Pattern B (direct vault grant).
        $projectIds = ResourcePermission::query()
            ->where('user_id', $user->id)
            ->where('resource_type', ResourceType::Project->value)
            ->pluck('resource_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $directVaultIds = ResourcePermission::query()
            ->where('user_id', $user->id)
            ->where('resource_type', ResourceType::Vault->value)
            ->pluck('resource_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Also include vaults in projects where user is the implicit
        // owner (projects.owner_id = user_id).
        $ownedProjectIds = \App\Models\Project\Project::query()
            ->where('owner_id', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $allProjectIds = array_values(array_unique(array_merge($projectIds, $ownedProjectIds)));

        // Step 2: Query credentials matching the domain + accessible.
        // Primary path: indexed `domain` column. Fallback: parse the
        // `url` column at query time for rows where the observer
        // hasn't populated `domain` yet (pre-deploy credentials,
        // edge cases). The OR keeps the index usable for the common
        // case while catching stragglers without a second round-trip.
        // Escape LIKE metacharacters in the domain, then wrap with %
        // on both sides so "ursavpn.com" matches "https://ursavpn.com/register".
        $escaped = str_replace(['%', '_'], ['\%', '\_'], $queryDomain);
        $domainLike = '%'.$escaped.'%';

        $credentials = Credential::query()
            ->with('vault:id,name', 'project:id,name,organisation_id', 'project.organisation:id,name')
            ->where(function ($q) use ($queryDomain, $domainLike): void {
                $q->where('domain', $queryDomain)
                    ->orWhere(function ($fallback) use ($domainLike): void {
                        $fallback->whereNull('domain')
                            ->where('url', 'ILIKE', $domainLike);
                    });
            })
            ->where('type', $type)
            ->where('is_archived', false)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($allProjectIds, $directVaultIds): void {
                if ($allProjectIds !== []) {
                    $q->whereIn('project_id', $allProjectIds);
                }
                if ($directVaultIds !== []) {
                    $q->orWhereIn('vault_id', $directVaultIds);
                }
                if ($allProjectIds === [] && $directVaultIds === []) {
                    $q->whereRaw('false');
                }
            })
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        // Step 3: Attach my_wrapped_key per vault. Only return rows
        // where the user has a wrapped key — spec says exclude
        // credentials where my_wrapped_key is null.
        $vaultIds = $credentials->pluck('vault_id')->filter()->unique()->values()->all();

        $wrappedKeys = [];
        if ($vaultIds !== []) {
            $wrappedKeys = ResourceKey::query()
                ->select(['resource_id', 'encrypted_key', 'key_version'])
                ->where('resource_type', ResourceType::Vault->value)
                ->where('user_id', $user->id)
                ->whereIn('resource_id', $vaultIds)
                ->orderBy('resource_id')
                ->orderByDesc('key_version')
                ->get()
                ->unique('resource_id')
                ->keyBy('resource_id');
        }

        $data = $credentials
            ->filter(fn (Credential $c) => isset($wrappedKeys[(int) $c->vault_id]))
            ->map(function (Credential $c) use ($wrappedKeys): array {
                $key = $wrappedKeys[(int) $c->vault_id];

                return [
                    'id' => (int) $c->id,
                    'name' => $c->name,
                    'url' => $c->url,
                    'type' => $c->type?->value,
                    'encrypted_data' => $c->encrypted_data,
                    'iv' => $c->iv,
                    'key_version' => $c->key_version,
                    'vault_id' => (int) $c->vault_id,
                    'vault_name' => $c->vault?->name,
                    'project_id' => (int) $c->project_id,
                    'project_name' => $c->project?->name,
                    'organisation_id' => $c->project?->organisation_id !== null ? (int) $c->project->organisation_id : null,
                    'organisation_name' => $c->project?->organisation?->name,
                    'my_wrapped_key' => (string) $key->encrypted_key,
                ];
            })
            ->values();

        return response()->json(['data' => $data]);
    }
}
