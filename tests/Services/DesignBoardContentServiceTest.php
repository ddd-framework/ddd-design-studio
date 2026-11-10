<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\Domain\DesignStudio\Services\DesignBoardContentService;
use DDD\Domain\DesignStudio\Entities\Themes\DesignThemeReference;
use DDD\Domain\DesignStudio\Services\DesignThemeProviderService;
use PHPUnit\Framework\TestCase;

/**
 * The sandbox form of a board (no scripts, theme first in head) and the theme reference format.
 */
class DesignBoardContentServiceTest extends TestCase
{
    public function testRemovesScripts(): void
    {
        $html = '<html><head><script src="support.js"></script><style>a{}</style></head>'
            . "<body><p>x</p><script>\nalert(1)\n</script><script src=\"a.js\"/></body></html>";
        $this->assertSame(
            '<html><head><style>a{}</style></head><body><p>x</p></body></html>',
            DesignBoardContentService::removeScripts($html)
        );
    }

    public function testPlacesTheThemeFirstInHead(): void
    {
        $themeStyle = '<style data-design-theme>:root{--p-500:#000}</style>';
        $this->assertSame(
            '<html><head lang="de">' . $themeStyle . '<style>a{}</style></head></html>',
            DesignBoardContentService::applyTheme('<html><head lang="de"><style>a{}</style></head></html>', $themeStyle)
        );
        $this->assertSame($themeStyle . '<p>x</p>', DesignBoardContentService::applyTheme('<p>x</p>', $themeStyle));
        $this->assertSame('<p>x</p>', DesignBoardContentService::applyTheme('<p>x</p>', null));
        // a <header> element is not the head
        $this->assertSame($themeStyle . '<header>x</header>', DesignBoardContentService::applyTheme('<header>x</header>', $themeStyle));
    }

    public function testSplitsThemeReferences(): void
    {
        $this->assertSame(['brand:2', 'light'], [DesignThemeReference::fromString('brand:2:light')->themeId, DesignThemeReference::fromString('brand:2:light')->mode]);
        $this->assertSame(['brand:1', 'dark'], [DesignThemeReference::fromString('brand:1:dark')->themeId, DesignThemeReference::fromString('brand:1:dark')->mode]);
        $this->assertSame(['brand:1', 'light'], [DesignThemeReference::fromString('brand:1')->themeId, DesignThemeReference::fromString('brand:1')->mode]);
        $this->assertSame(['brand', 'light'], [DesignThemeReference::fromString(' brand ')->themeId, DesignThemeReference::fromString(' brand ')->mode]);
    }

    public function testTheDefaultProviderKnowsNoThemes(): void
    {
        $designThemeProviderService = new DesignThemeProviderService();
        $this->assertSame(0, $designThemeProviderService->findThemes()->count());
        $this->assertNull($designThemeProviderService->getThemeStyleForReference('brand:1:dark'));
        $this->assertNull($designThemeProviderService->getThemeStyleForReference(null));
        $this->assertSame(hash('sha256', ''), DesignThemeProviderService::computeThemeHash(null));
    }
}
