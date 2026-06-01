<?php

namespace App\Enums;

enum MemberRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Viewer = 'viewer';
}
