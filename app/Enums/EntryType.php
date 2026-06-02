<?php

namespace App\Enums;

enum EntryType: string
{
    case Login = 'login';
    case Ssh = 'ssh';
    case Ftp = 'ftp';
    case Database = 'database';
    case ApiKey = 'api_key';
    case Note = 'note';
    case SoftwareLicense = 'software_license';
    case Env = 'env';
}
