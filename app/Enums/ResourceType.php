<?php

namespace App\Enums;

enum ResourceType: string
{
    case Project = 'project';
    case Board = 'board';
    case Vault = 'vault';
    case Bucket = 'bucket';
    case Doc = 'doc';
}
