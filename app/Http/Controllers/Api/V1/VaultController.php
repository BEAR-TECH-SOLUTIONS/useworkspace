<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResourceType;
use App\Events\Project\VaultCreated;
use App\Events\Project\VaultDeleted;
use App\Events\Project\VaultUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vault\StoreVaultRequest;
use App\Http\Requests\Vault\UpdateVaultRequest;
use App\Http\Resources\Vault\VaultResource;
use App\Models\Project\Project;
use App\Models\User;
use App\Models\Vault\Vault;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class VaultController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $user = $request->user();

        // Pattern B users (only child-level grants) cannot pass `view` on
        // the project itself, so gate the list on "user has ANY grant in
        // this project" and let visibleScope() narrow the results.
        abort_unless($this->perms->hasAnyGrantIn($user, $project), 403);

        $vaults = $this->perms
            ->visibleScope($user, ResourceType::Vault, $project)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $this->attachWrappedKeys($user, $vaults->all());

        return VaultResource::collection($vaults);
    }

    public function store(StoreVaultRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $vault = Vault::create([
            'project_id' => $project->id,
            'name' => $request->string('name')->toString(),
            'color' => $request->input('color'),
            'icon' => $request->input('icon'),
            'position' => (float) $request->input('position', $this->nextPosition($project)),
            'is_default' => false,
            'created_by' => $request->user()->id,
        ]);

        $this->attachWrappedKeys($request->user(), [$vault]);

        // VaultCreated nulls my_wrapped_key on the wire (the shared channel
        // can't personalise per recipient); the actor still gets its key via
        // this HTTP response and is excluded from the broadcast by socket id.
        VaultCreated::dispatch($vault, $request->header('X-Socket-Id'));

        return response()->json([
            'vault' => new VaultResource($vault),
        ], 201);
    }

    public function show(Request $request, Vault $vault): JsonResponse
    {
        $this->authorize('view', $vault);

        $this->attachWrappedKeys($request->user(), [$vault]);

        return response()->json([
            'vault' => new VaultResource($vault),
        ]);
    }

    public function update(UpdateVaultRequest $request, Vault $vault): JsonResponse
    {
        $this->authorize('update', $vault);

        $vault->fill($request->only(['name', 'color', 'icon', 'position']))->save();

        $fresh = $vault->refresh();
        $this->attachWrappedKeys($request->user(), [$fresh]);

        VaultUpdated::dispatch($fresh, $request->header('X-Socket-Id'));

        return response()->json([
            'vault' => new VaultResource($fresh),
        ]);
    }

    public function archive(Request $request, Vault $vault): JsonResponse
    {
        $this->authorize('archive', $vault);

        $vault->update(['is_archived' => ! $vault->is_archived]);

        $fresh = $vault->refresh();
        $this->attachWrappedKeys($request->user(), [$fresh]);

        return response()->json([
            'vault' => new VaultResource($fresh),
        ]);
    }

    public function destroy(Request $request, Vault $vault): JsonResponse
    {
        $this->authorize('delete', $vault);

        $projectId = $vault->project_id;
        $vaultId = $vault->id;
        $wasDefault = $vault->is_default;

        // Defaults are now deletable (#212). Credentials in the deleted vault
        // are reassigned to "All entries" (vault_id → NULL) by the FK, the
        // same as for any vault. When the default is removed and others
        // remain, promote the lowest-positioned survivor so the project keeps
        // a default; if none remain it legitimately has zero vaults.
        $promoted = DB::transaction(function () use ($vault, $projectId, $wasDefault): ?Vault {
            $vault->delete();

            if (! $wasDefault) {
                return null;
            }

            $next = Vault::query()
                ->where('project_id', $projectId)
                ->orderBy('position')
                ->orderBy('id')
                ->first();

            $next?->update(['is_default' => true]);

            return $next;
        });

        $socketId = $request->header('X-Socket-Id');

        VaultDeleted::dispatch($projectId, $vaultId, $socketId);

        if ($promoted !== null) {
            VaultUpdated::dispatch($promoted, $socketId);
        }

        return response()->json(status: 204);
    }

    private function nextPosition(Project $project): float
    {
        $max = (float) Vault::query()->where('project_id', $project->id)->max('position');

        return $max > 0 ? $max + 10000 : 10000;
    }

    /**
     * Pre-attach the current user's latest wrapped vault key to each vault
     * so VaultResource can emit it without an N+1 lookup. Vaults with no
     * matching resource_keys row (unmigrated vaults or recipients that
     * never received a wrapped key) get `null`.
     *
     * @param  array<int, Vault>  $vaults
     */
    private function attachWrappedKeys(User $user, array $vaults): void
    {
        if ($vaults === []) {
            return;
        }

        $keys = $this->perms->wrappedVaultKeysFor(
            $user,
            array_map(static fn (Vault $v): int => $v->id, $vaults),
        );

        foreach ($vaults as $vault) {
            $vault->setAttribute(
                VaultResource::WRAPPED_KEY_ATTR,
                $keys[$vault->id] ?? null,
            );
        }
    }
}
