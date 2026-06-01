<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\FinalizeDeferredAccessBatchRequest;
use App\Http\Requests\Workspaces\FinalizeDeferredAccessRequest;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\DeferredAccessGrant;
use App\Models\Project\Project;
use App\Models\User;
use App\Models\Vault\Vault;
use App\Services\Workspaces\DeferredAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeferredAccessController extends Controller
{
    public function __construct(private readonly DeferredAccessService $deferred) {}

    /**
     * List provisioned users in a workspace with unfinished vault key
     * wrapping. Groups deferred rows by user; sorts master-password-
     * ready users first so an admin sees who's actionable up top.
     */
    public function index(Request $request, Organisation $workspace): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        $rows = DeferredAccessGrant::query()
            ->with(['user', 'project'])
            ->where('workspace_id', $workspace->id)
            ->orderBy('created_at')
            ->get();

        // Gather all vault ids across every deferred row in a single
        // `IN (…)` fetch so the list endpoint stays cheap on workspaces
        // with dozens of pending rows. Names are denormalised onto the
        // row shape the client renders.
        //
        // Legacy rows (pre-split-provisioning) stored non-vault entries
        // like `{type:'board', id:N, role:'editor'}` in the same
        // `resources` JSONB column. Filter to vault entries only so a
        // leftover legacy row doesn't 500 the whole list endpoint.
        $allVaultIds = $rows->flatMap(
            static fn (DeferredAccessGrant $g) => collect((array) ($g->resources ?? []))
                ->filter(static fn ($r): bool => is_array($r) && isset($r['vault_id']))
                ->map(static fn (array $r): int => (int) $r['vault_id']),
        )->unique()->values();

        $vaultNames = $allVaultIds->isEmpty()
            ? collect()
            : Vault::query()->whereIn('id', $allVaultIds)->pluck('name', 'id');

        $grouped = $rows->groupBy('user_id');

        $data = $grouped->map(function ($grants, $userId) use ($vaultNames): array {
            /** @var User|null $user */
            $user = $grants->first()?->user;

            return [
                'user_id' => (int) $userId,
                'user' => $user !== null ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'public_key' => $user->public_key,
                    'master_password_set' => $user->hasMasterPassword(),
                ] : null,
                'grants' => $grants->map(fn (DeferredAccessGrant $g): array => [
                    'id' => $g->id,
                    'project_id' => $g->project_id,
                    'project_name' => $g->project?->name,
                    'vaults' => collect((array) ($g->resources ?? []))
                        ->filter(static fn ($r): bool => is_array($r) && isset($r['vault_id']))
                        ->map(fn (array $r): array => [
                            'vault_id' => (int) $r['vault_id'],
                            'vault_name' => $vaultNames->get((int) $r['vault_id']),
                        ])
                        ->values()
                        ->all(),
                ])->values()->all(),
            ];
        })->values();

        // Ready-to-finalise users surface first (master_password_set = true);
        // still-pending users follow in creation order.
        $sorted = $data->sortByDesc(fn (array $row): bool => (bool) ($row['user']['master_password_set'] ?? false))
            ->values()
            ->all();

        return response()->json(['data' => $sorted]);
    }

    public function finalize(
        FinalizeDeferredAccessRequest $request,
        DeferredAccessGrant $deferredAccess,
    ): JsonResponse {
        $applied = $this->deferred->finalize(
            $deferredAccess,
            $request->user(),
            (array) $request->input('vault_keys', []),
        );

        return response()->json(['applied' => $applied]);
    }

    /**
     * Partial-success batch finalise. Per-grant errors are reported
     * inline — one stale vault key doesn't roll back the others.
     */
    public function finalizeBatch(
        FinalizeDeferredAccessBatchRequest $request,
        Organisation $workspace,
    ): JsonResponse {
        $this->authorize('manageMembers', $workspace);

        $applied = [];
        $errors = [];

        foreach ((array) $request->input('grants', []) as $entry) {
            $deferredId = (int) ($entry['deferred_access_id'] ?? 0);
            $vaultKeys = (array) ($entry['vault_keys'] ?? []);

            $deferred = DeferredAccessGrant::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($deferredId)
                ->first();

            if ($deferred === null) {
                $errors[] = [
                    'deferred_access_id' => $deferredId,
                    'code' => 'not_found',
                    'message' => 'Deferred grant not found in this workspace.',
                ];
                continue;
            }

            try {
                $result = $this->deferred->finalize($deferred, $request->user(), $vaultKeys);
                $applied[] = [
                    'deferred_access_id' => $deferredId,
                    'project_id' => (int) $result['project_id'],
                    'vaults_applied' => (int) $result['vaults_applied'],
                    'status' => 'ok',
                ];
            } catch (ValidationException $e) {
                $errors[] = [
                    'deferred_access_id' => $deferredId,
                    'code' => $this->extractCode($e),
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'applied' => $applied,
            'errors' => $errors,
        ]);
    }

    private function extractCode(ValidationException $e): string
    {
        $codes = $e->errors()['code'] ?? [];

        return $codes[0] ?? 'validation_failed';
    }
}
