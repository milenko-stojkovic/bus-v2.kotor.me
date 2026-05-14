<?php

namespace Tests\Support;

use App\Contracts\MegaArchiveClient;
use App\Services\ExternalArchive\MegaUploadResult;

final class MegaArchiveFakeClient implements MegaArchiveClient
{
    public int $uploadCalls = 0;

    public int $downloadCalls = 0;

    public ?string $lastDownloadGeneratedFileName = null;

    public bool $uploadShouldFail = false;

    public bool $downloadShouldFail = false;

    public function uploadLocalFile(string $absoluteLocalPath, string $generatedFileName): MegaUploadResult
    {
        $this->uploadCalls++;
        if ($this->uploadShouldFail) {
            return new MegaUploadResult(false, null, null, 'fake_upload_failed');
        }

        return new MegaUploadResult(true, 'fake-node-'.$this->uploadCalls, 'bus.kotor/'.$generatedFileName, null);
    }

    public function downloadToAbsolutePath(string $megaPathOrName, string $absoluteDestPath, ?string $generatedFileName = null): MegaUploadResult
    {
        $this->downloadCalls++;
        $this->lastDownloadGeneratedFileName = $generatedFileName;
        if ($this->downloadShouldFail) {
            return new MegaUploadResult(false, null, null, 'fake_download_failed');
        }

        $dir = dirname($absoluteDestPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($absoluteDestPath, 'restored-by-fake');

        return new MegaUploadResult(true, null, $megaPathOrName, null);
    }
}
