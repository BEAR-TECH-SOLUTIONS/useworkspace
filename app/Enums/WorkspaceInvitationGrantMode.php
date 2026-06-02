<?php

namespace App\Enums;

enum WorkspaceInvitationGrantMode: string
{
    case Project = 'project';
    case Resources = 'resources';
}
