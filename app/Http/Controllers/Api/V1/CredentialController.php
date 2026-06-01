<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EntryType;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vault\StoreCredentialRequest;
use App\Http\Requests\Vault\UpdateCredentialRequest;
use App\Http\Resources\Vault\CredentialHistoryResource;
use App\Http\Resources\Vault\CredentialResource;
use App\Models\Project\Project;
use App\Models\Vault\Credential;
use App\Models\Vault\CredentialHistory;
use App\Models\Vault\Vault;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CredentialController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $user = $request->user();

        // Pattern B users have no project-level row but must still reach
        // this endpoint. visibleScope() narrows the result to only the
        // vaults they actually have access to, so a direct vault-grant
        // user sees exactly those credentials and nothing else.
        abort_unless($this->perms->hasAnyGrantIn($user, $project), 403);

        $visibleVaultIds = $this->perms
            ->visibleScope($user, ResourceType::Vault, $project)
            ->pluck('id');

        // Credentials with `vault_id = NULL` live in the project's
        // "All entries" bucket. Only users with a project-level grant
        // (or the project owner) can see them — a Pattern B user scoped
        // to a specific vault must not leak them.
        $hasProjectLevelAccess = $this->perms->can($user, 'view', $project);

        $query = Credential::query()
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($visibleVaultIds, $hasProjectLevelAccess): void {
                $q->whereIn('vault_id', $visibleVaultIds);

                if ($hasProjectLevelAccess) {
                    $q->orWhereNull('vault_id');
                }
            });

        if ($vaultId = $request->query('vault_id')) {
            $query->where('vault_id', (int) $vaultId);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($q = $request->query('q')) {
            $query->where('name', 'ilike', '%'.$q.'%');
        }

        if ($tag = $request->query('tag')) {
            // Postgres array contains.
            $query->whereRaw('? = ANY (tags)', [$tag]);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 100)));

        $sortable = ['id', 'updated_at', 'created_at', 'name'];
        $sort = $request->input('sort', 'updated_at');

        if (in_array($sort, $sortable, true)) {
            $direction = $sort === 'id' ? 'asc' : 'desc';
            $query->orderBy($sort, $direction);
        } else {
            $query->orderByDesc('updated_at');
        }

        $credentials = $query
            ->paginate($perPage)
            ->withQueryString();

        return CredentialResource::collection($credentials);
    }

    public function store(StoreCredentialRequest $request, Project $project): JsonResponse
    {
        $vaultId = $request->input('vault_id');

        // If a vault_id is supplied, the only check that matters is
        // "update on that vault" — a Pattern B editor with a direct
        // grant qualifies. Creating a credential in "All entries"
        // (vault_id NULL) requires project-level update.
        if ($vaultId !== null) {
            /** @var Vault $vault */
            $vault = Vault::query()->where('id', $vaultId)->where('project_id', $project->id)->firstOrFail();
            $this->authorize('update', $vault);
        } else {
            $this->authorize('update', $project);
        }

        $credential = Credential::create([
            'project_id' => $project->id,
            'vault_id' => $vaultId,
            'type' => EntryType::from($request->string('type')->toString()),
            'name' => $request->string('name')->toString(),
            'url' => $request->input('url'),
            'encrypted_data' => $request->string('encrypted_data')->toString(),
            'iv' => $request->string('iv')->toString(),
            'tags' => $request->input('tags', []),
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'credential' => new CredentialResource($credential),
        ], 201);
    }

    public function show(Credential $credential): JsonResponse
    {
        $this->authorize('view', $credential);

        return response()->json([
            'credential' => new CredentialResource($credential),
        ]);
    }

    public function update(UpdateCredentialRequest $request, Credential $credential): JsonResponse
    {
        $this->authorize('update', $credential);

        // Audit M10: is_archived rides on the generic `update` ability,
        // which makes the model brittle — any future role that gets
        // `update` but not `archive` would silently inherit archive
        // power. Explicit gate keeps the two distinct.
        if ($request->has('is_archived')) {
            $this->authorize('archive', $credential);
        }

        $user = $request->user();

        DB::transaction(function () use ($credential, $request, $user): void {
            // Snapshot the previous ciphertext into history *before* mutating.
            if ($request->has('encrypted_data')) {
                CredentialHistory::create([
                    'credential_id' => $credential->id,
                    'changed_by' => $user->id,
                    'encrypted_data' => $credential->encrypted_data,
                    'iv' => $credential->iv,
                ]);
            }

            $credential->fill($request->only([
                'vault_id',
                'name',
                'url',
                'encrypted_data',
                'iv',
                'tags',
                'is_favorite',
                'is_archived',
            ]))->save();
        });

        return response()->json([
            'credential' => new CredentialResource($credential->refresh()),
        ]);
    }

    public function destroy(Credential $credential): JsonResponse
    {
        $this->authorize('delete', $credential);

        $credential->delete();

        return response()->json(status: 204);
    }

    public function history(Credential $credential): AnonymousResourceCollection
    {
        $this->authorize('view', $credential);

        $history = CredentialHistory::query()
            ->where('credential_id', $credential->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return CredentialHistoryResource::collection($history);
    }
}
