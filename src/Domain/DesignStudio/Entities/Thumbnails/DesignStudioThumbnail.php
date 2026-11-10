<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Thumbnails;

use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\DBDesignStudioThumbnail;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailsService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * A rendered picture of one board version in one look. Identified by `thumbnailKey` = SHA-256 of the board's content
 * hash, the theme hash and the renderer version (DesignStudioThumbnailsService::computeThumbnailKey): themes with the
 * same CSS share a thumbnail, and a changed board, changed theme colours or a renderer update give a new key. It is a
 * cache of render output, not review state: rows can be deleted at any time and are rendered again on demand.
 *
 * @method static DesignStudioThumbnailsService getService()
 * @method static DBDesignStudioThumbnail getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDesignStudioThumbnail::class)]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['thumbnailKey'])]
class DesignStudioThumbnail extends Entity
{
    use QueryOptionsTrait;

    /** @var string Size of the delivery route: the full picture */
    public const string SIZE_FULL = 'full';

    /** @var string Size of the delivery route: the reduced copy for far zoom levels */
    public const string SIZE_SMALL = 'small';

    /** @var string|null SHA-256 of (content hash, theme hash, renderer version) */
    #[NotNull]
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $thumbnailKey;

    /** @var string|null Content hash of the board file it was rendered from (git blob hash) */
    #[NotNull]
    #[Length(max: 40)]
    public ?string $contentHash;

    /** @var string|null SHA-256 of the theme's `<style>` element (of the empty string without a theme) */
    #[NotNull]
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $themeHash;

    /** @var string|null Renderer version it was rendered with (config DesignStudio.renderer.rendererVersion) */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $rendererVersion;

    /** @var string|null Object key of the PNG (internal, never in API output) */
    #[HideProperty]
    #[NotNull]
    #[Length(max: 500)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $objectKey;

    /** @var string|null Object key of the reduced PNG (internal, never in API output) */
    #[HideProperty]
    #[Length(max: 500)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $smallObjectKey;

    /** @var int|null Width in pixels */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $width;

    /** @var int|null Height in pixels */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $height;

    /** @var int|null Size of the PNG in bytes */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $byteSize;

    public function uniqueKey(): string
    {
        return parent::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
