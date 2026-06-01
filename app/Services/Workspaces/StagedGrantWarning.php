<?php

namespace App\Services\Workspaces;

use RuntimeException;

/**
 * Signal-only exception: `applyProjectGrant` throws this to tell the
 * accept handler "skip this single staged grant and record a warning"
 * without rolling back the surrounding transaction.
 *
 * Caught exclusively in `WorkspaceInvitationService::applyStagedGrants`.
 */
class StagedGrantWarning extends RuntimeException
{
    public function __construct(
        public readonly int $projectId,
        public readonly string $warningCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array{project_id:int,code:string,message:string}
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'code' => $this->warningCode,
            'message' => $this->getMessage(),
        ];
    }
}
