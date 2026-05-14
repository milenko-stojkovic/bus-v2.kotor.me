<?php

namespace App\Services\ExternalArchive;

final readonly class MegaUploadResult
{
    public function __construct(
        public bool $ok,
        public ?string $megaNodeId = null,
        public ?string $megaPath = null,
        public ?string $error = null,
    ) {}
}
