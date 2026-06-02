<?php

namespace App\Services\Permissions;

use App\Enums\AuditAction;
use App\Enums\ResourceType;
use App\Models\Permissions\AuditLog;
use App\Models\User;

/**
 * Writes rows into audit_log. Always called from inside the caller's
 * DB::transaction so that a failure to emit the audit row rolls back the
 * state change it describes — audit completeness is a load-bearing
 * security property and we cannot let the two diverge.
 *
 * Controllers do NOT call this class directly. PermissionService is the
 * only place the application writes to the audit log.
 */
class AuditLogger
{
    public function record(
        ?User $actor,
        AuditAction $action,
        ?int $projectId = null,
        ?ResourceType $resourceType = null,
        ?int $resourceId = null,
        ?int $targetUserId = null,
        array $metadata = [],
    ): AuditLog {
        return AuditLog::create([
            'actor_user_id' => $actor?->id,
            'action' => $action->value,
            'resource_type' => $resourceType?->value,
            'resource_id' => $resourceId,
            'target_user_id' => $targetUserId,
            'project_id' => $projectId,
            'metadata' => $metadata,
        ]);
    }
}