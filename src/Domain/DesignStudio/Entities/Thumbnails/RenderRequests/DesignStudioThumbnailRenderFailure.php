<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;

/**
 * A picture (a fixed board or a device frame of a responsive board) a render request could not produce: the renderer
 * refused it, or its thumbnail could not be stored even after the retry at the end of the run. The other pictures
 * of the request are rendered regardless. Recorded on the request (DesignStudioThumbnailRenderRequest::failedBoards).
 */
class DesignStudioThumbnailRenderFailure extends ValueObject
{
    /** @var int Longest stored error per picture */
    public const int MAX_ERROR_LENGTH = 500;

    /** @var string|null DesignDirectoryBoard::getRenderTargetKey(): the board key, `<boardKey>@<deviceKey>` for a frame */
    public ?string $renderTargetKey = null;

    /** @var string|null Version of the board that failed: `<contentHash>:<assetsHash>`; a new version is tried again */
    public ?string $boardVersion = null;

    /** @var string|null Why the picture is missing */
    public ?string $error = null;

    /**
     * @param DesignDirectoryBoard $designDirectoryBoard a board or a device frame of one
     * @param string $error
     * @return static
     */
    public static function fromBoard(DesignDirectoryBoard $designDirectoryBoard, string $error): static
    {
        $designStudioThumbnailRenderFailure = new static();
        $designStudioThumbnailRenderFailure->renderTargetKey = $designDirectoryBoard->getRenderTargetKey();
        $designStudioThumbnailRenderFailure->boardVersion = static::computeBoardVersion($designDirectoryBoard);
        $designStudioThumbnailRenderFailure->error = $error;
        return $designStudioThumbnailRenderFailure;
    }

    /**
     * @param DesignDirectoryBoard $designDirectoryBoard
     * @return string the version a failure is recorded for: content and asset files
     */
    public static function computeBoardVersion(DesignDirectoryBoard $designDirectoryBoard): string
    {
        return ($designDirectoryBoard->contentHash ?? '') . ':' . ($designDirectoryBoard->assetsHash ?? '');
    }

    /**
     * @param DesignDirectoryBoard $designDirectoryBoard
     * @return bool the failure names exactly this board version
     */
    public function isForBoard(DesignDirectoryBoard $designDirectoryBoard): bool
    {
        return $this->renderTargetKey === $designDirectoryBoard->getRenderTargetKey()
            && $this->boardVersion === static::computeBoardVersion($designDirectoryBoard);
    }

    /**
     * @return string one line of DesignStudioThumbnailRenderRequest::$failedBoards (tab separated, the error last)
     */
    public function toStoredLine(): string
    {
        return implode("\t", [
            static::flattenToOneLine((string)$this->renderTargetKey),
            static::flattenToOneLine((string)$this->boardVersion),
            mb_substr(static::flattenToOneLine((string)$this->error), 0, static::MAX_ERROR_LENGTH),
        ]) . "\n";
    }

    /**
     * @param string $storedLine one line written by toStoredLine()
     * @return static|null null for an empty or malformed line
     */
    public static function fromStoredLine(string $storedLine): ?static
    {
        $parts = explode("\t", trim($storedLine, "\r\n"), 3);
        if (count($parts) !== 3 || $parts[0] === '') {
            return null;
        }
        $designStudioThumbnailRenderFailure = new static();
        $designStudioThumbnailRenderFailure->renderTargetKey = $parts[0];
        $designStudioThumbnailRenderFailure->boardVersion = $parts[1];
        $designStudioThumbnailRenderFailure->error = $parts[2];
        return $designStudioThumbnailRenderFailure;
    }

    /**
     * @param string $text
     * @return string the text without tabs and line breaks
     */
    protected static function flattenToOneLine(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->renderTargetKey);
    }
}
