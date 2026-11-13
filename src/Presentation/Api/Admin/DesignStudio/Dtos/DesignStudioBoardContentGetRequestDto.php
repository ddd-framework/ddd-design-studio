<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * A board file for the sandboxed iframe.
 */
class DesignStudioBoardContentGetRequestDto extends RequestDto
{
    /** @var string Key of the design project */
    #[Parameter(in: Parameter::PATH, required: true)]
    #[Length(max: 64)]
    public string $projectKey;

    /** @var string Key of the canvas within the project */
    #[Parameter(in: Parameter::PATH, required: true)]
    #[Length(max: 64)]
    public string $canvasKey;

    /** @var string File name of the board in the canvas folder */
    #[Parameter(in: Parameter::PATH, required: true)]
    #[Length(max: 255)]
    public string $boardKey;

    /** @var string|null Content token from the canvas response */
    #[Parameter(in: Parameter::QUERY, required: true)]
    #[Length(max: 2000)]
    public ?string $token = null;

    /** @var string|null Version of the board (content hash); omitted = the file of the checkout */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 40)]
    public ?string $contentHash = null;

    /** @var string|null Version of the asset files the board shows (assets hash of the canvas response); omitted = none */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 40)]
    public ?string $assetsHash = null;

    /**
     * @var bool|null true: the board for the site view — with the studio's navigation script (link clicks, anchors and
     *      the page height are reported to the parent page); omitted = the board as on the canvas, without any script
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?bool $site = null;

    /**
     * @var bool|null Set by the canvas view on a board whose scripts run, so a cached copy of the URL never mixes the
     *      delivery with and without them; whether the scripts are kept follows the canvas configuration alone
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?bool $scripts = null;

    /** @var string|null Theme reference `<themeId>[:<mode>]`; omitted = no theme */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 255)]
    public ?string $theme = null;
}
