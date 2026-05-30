<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\DTO;

use App\Domain\GatewayLog\Enums\ImportFileType;

final readonly class ResolvedImportFileData
{
    public function __construct(
        public string $inputPath,
        public string $resolvedPath,
        public ImportFileType $fileType,
        public string $fileHash,
        public bool $wasExtracted,
        public ?string $extractedFrom = null,
    ) {}
}
