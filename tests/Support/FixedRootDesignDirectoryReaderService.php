<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Support;

use DDD\Domain\DesignStudio\Services\DesignDirectoryReaderService;

/** A directory reader on a temporary project root, with its hashes kept in memory instead of the cache */
class FixedRootDesignDirectoryReaderService extends DesignDirectoryReaderService
{
    /** @var array folder path => stored board hashes */
    public array $storedFileHashes = [];

    /** @var string[] file state key => content hash of an asset file */
    public array $storedAssetContentHashes = [];

    public function __construct(protected string $testProjectRootDirectory, protected int $testAssetMaximumBytes = 100000)
    {
    }

    public function getAssetMaximumBytes(): int
    {
        return $this->testAssetMaximumBytes;
    }

    protected function getProjectRootDirectory(): string
    {
        return $this->testProjectRootDirectory;
    }

    protected function loadFileHashes(string $folderPath): array
    {
        return $this->storedFileHashes[$folderPath] ?? [];
    }

    protected function storeFileHashes(string $folderPath, array $fileHashes): void
    {
        $this->storedFileHashes[$folderPath] = $fileHashes;
    }

    protected function loadAssetContentHash(string $fileStateKey): ?string
    {
        return $this->storedAssetContentHashes[$fileStateKey] ?? null;
    }

    protected function storeAssetContentHash(string $fileStateKey, string $contentHash): void
    {
        $this->storedAssetContentHashes[$fileStateKey] = $contentHash;
    }
}
