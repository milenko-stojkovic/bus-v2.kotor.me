<?php

namespace App\Contracts;

use App\Services\ExternalArchive\MegaUploadResult;

interface MegaArchiveClient
{
    /**
     * Upload a single local file into the configured MEGA base folder (no subfolders).
     * Server-side only; credentials never leave the server process environment.
     */
    public function uploadLocalFile(string $absoluteLocalPath, string $generatedFileName): MegaUploadResult;

    /**
     * Download an archived file from MEGA into an absolute local path (parent dirs must exist).
     *
     * @param  string|null  $generatedFileName  Passed to the Node helper when mega path lookup needs a name fallback.
     */
    public function downloadToAbsolutePath(string $megaPathOrName, string $absoluteDestPath, ?string $generatedFileName = null): MegaUploadResult;
}
