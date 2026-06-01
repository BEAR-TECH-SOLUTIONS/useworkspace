<?php

use App\Models\Expenses\ExpenseBucket;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\User;
use App\Models\Vault\Vault;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{userId}', function (User $user, int $userId): bool {
    return $user->id === $userId;
});

Broadcast::channel('project.{projectId}', function (User $user, int $projectId): bool {
    $project = Project::find($projectId);

    return $project !== null
        && app(PermissionService::class)->can($user, Abilities::VIEW, $project);
});

Broadcast::channel('board.{boardId}', function (User $user, int $boardId): bool {
    $board = TaskBoard::find($boardId);

    return $board !== null
        && app(PermissionService::class)->can($user, Abilities::VIEW, $board);
});

Broadcast::channel('vault.{vaultId}', function (User $user, int $vaultId): bool {
    $vault = Vault::find($vaultId);

    return $vault !== null
        && app(PermissionService::class)->can($user, Abilities::VIEW, $vault);
});

Broadcast::channel('bucket.{bucketId}', function (User $user, int $bucketId): bool {
    $bucket = ExpenseBucket::find($bucketId);

    return $bucket !== null
        && app(PermissionService::class)->can($user, Abilities::VIEW, $bucket);
});
