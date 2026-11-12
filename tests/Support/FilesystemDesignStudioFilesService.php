<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Support;

use DDD\Domain\DesignStudio\Services\DesignStudioFilesService;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;

/** The studio's files in a folder instead of a configured store */
class FilesystemDesignStudioFilesService extends DesignStudioFilesService
{
    public function __construct(protected string $testStorageDirectory)
    {
    }

    protected function getObjectStorage(): ?ObjectStorage
    {
        return new FilesystemObjectStorage($this->testStorageDirectory);
    }

    protected function getKeyPrefix(): string
    {
        return 'design-studio';
    }
}
