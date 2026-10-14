<?php

namespace Tests\Feature\Landing;

use Illuminate\Support\Facades\File;

/**
 * Points the user-guide config at a throwaway directory under public/docs.
 * Cleanup deletes only that directory, never the real guide PDFs.
 */
trait IsolatesUserGuidePdfFixtures
{
    private string $userGuideFixtureDirectory = '';

    protected function isolateUserGuidePdfFixtures(): void
    {
        $relativeDirectory = 'docs/.test-fixtures/'.bin2hex(random_bytes(8));
        $this->userGuideFixtureDirectory = public_path($relativeDirectory);
        File::ensureDirectoryExists($this->userGuideFixtureDirectory);

        config([
            'user-guides.cg' => $relativeDirectory.'/cgbuskotor.pdf',
            'user-guides.en' => $relativeDirectory.'/engbuskotor.pdf',
        ]);
    }

    protected function writeUserGuideFixture(string $locale): string
    {
        $relativePath = $this->userGuideRelativePath($locale);
        $absolutePath = public_path($relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        file_put_contents($absolutePath, '%PDF-1.4');

        return $relativePath;
    }

    protected function userGuideRelativePath(string $locale): string
    {
        return (string) config('user-guides.'.$locale);
    }

    protected function removeUserGuidePdfFixtures(): void
    {
        $directory = $this->userGuideFixtureDirectory;
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }

        $normalized = str_replace('\\', '/', $directory);
        $publicDocs = str_replace('\\', '/', public_path('docs'));
        $isIsolatedFixture = str_contains($normalized, '/docs/.test-fixtures/')
            && $normalized !== $publicDocs
            && ! str_ends_with($normalized, '/docs');

        if (! $isIsolatedFixture) {
            return;
        }

        File::deleteDirectory($directory);

        $parent = dirname($directory);
        $normalizedParent = str_replace('\\', '/', $parent);
        if (is_dir($parent) && str_ends_with($normalizedParent, '/docs/.test-fixtures') && $this->directoryIsEmpty($parent)) {
            rmdir($parent);
        }
    }

    private function directoryIsEmpty(string $directory): bool
    {
        $handle = opendir($directory);
        if ($handle === false) {
            return false;
        }

        try {
            while (($entry = readdir($handle)) !== false) {
                if ($entry !== '.' && $entry !== '..') {
                    return false;
                }
            }
        } finally {
            closedir($handle);
        }

        return true;
    }
}
