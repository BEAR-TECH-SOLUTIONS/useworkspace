<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResourceType;
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

    public function destroy(Vault $vault): JsonResponse
    {
        $this->authorize('delete', $vault);

        if ($vault->is_default) {
            return response()->json(['message' => 'The default vault cannot be deleted.'], 422);
        }

        $vault->delete();

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
