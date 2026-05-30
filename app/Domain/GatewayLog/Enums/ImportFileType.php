<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\Enums;

enum ImportFileType: string
{
    case Txt = 'txt';
    case Zip = 'zip';
}
