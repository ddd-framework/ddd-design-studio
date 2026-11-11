<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoards;
use DDD\Domain\DesignStudio\Services\DesignBoardCodesService;
use PHPUnit\Framework\TestCase;

/**
 * DesignBoardCodesService: board part from the file name (canvas code left out, parts up to the first with a digit) or
 * canvas.json `code`, full code with project and canvas code, duplicates lose their code, matching without case,
 * spaces and dashes.
 */
class DesignBoardCodesServiceTest extends TestCase
{
    protected function createCanvasDefinition(?string $projectCode = 'APP', ?string $code = 'ONB'): DesignCanvasDefinition
    {
        $canvasDefinition = new DesignCanvasDefinition();
        $canvasDefinition->projectKey = 'app';
        $canvasDefinition->canvasKey = 'onboarding';
        $canvasDefinition->projectCode = $projectCode;
        $canvasDefinition->code = $code;
        return $canvasDefinition;
    }

    protected function createBoards(string ...$boardKeys): DesignDirectoryBoards
    {
        $designDirectoryBoards = new DesignDirectoryBoards();
        foreach ($boardKeys as $boardKey) {
            $designDirectoryBoard = new DesignDirectoryBoard();
            $designDirectoryBoard->boardKey = $boardKey;
            $designDirectoryBoards->add($designDirectoryBoard);
        }
        return $designDirectoryBoards;
    }

    /** @return array<string, string|null> board key => code */
    protected function codesOf(DesignDirectoryBoards $designDirectoryBoards): array
    {
        $codes = [];
        foreach ($designDirectoryBoards->getElements() as $designDirectoryBoard) {
            $codes[(string)$designDirectoryBoard->boardKey] = $designDirectoryBoard->code;
        }
        return $codes;
    }

    public function testDerivesTheBoardPartFromTheFileName(): void
    {
        $service = new DesignBoardCodesService();
        $this->assertSame('W2', $service->deriveBoardPart('onb-w-2-welcome.dc.html', 'ONB'));
        $this->assertSame('S01', $service->deriveBoardPart('onb-s01-start.html', 'ONB'));
        $this->assertSame('3', $service->deriveBoardPart('onb-3-done.html', 'ONB'));
        $this->assertSame('W18B', $service->deriveBoardPart('onb-w-18b-states.dc.html', 'ONB'));
        $this->assertSame('W2', $service->deriveBoardPart('ONB_w_2_welcome.html', 'ONB'));
        // without a canvas code (or another prefix) the first part stays
        $this->assertSame('ONBW2', $service->deriveBoardPart('onb-w-2-welcome.html', null));
        $this->assertSame('X1', $service->deriveBoardPart('x1-start.html', 'ONB'));
        // no part with a digit, or a part with other characters before it: no code
        $this->assertNull($service->deriveBoardPart('onb-welcome.html', 'ONB'));
        $this->assertNull($service->deriveBoardPart('Main.dc.html', 'ONB'));
        $this->assertNull($service->deriveBoardPart('onb-wé-2.html', 'ONB'));
    }

    public function testAssignsFullCodesExplicitCodesAndDropsDuplicates(): void
    {
        $service = new DesignBoardCodesService();
        $designDirectoryBoards = $this->createBoards(
            'onb-w-2-welcome.dc.html',
            'onb-s01-start.html',
            'onb-overview.html',
            'onb-k-5-a.html',
            'onb-k5-b.html',
            'onb-custom.html',
            'onb-bad.html'
        );
        $warnings = $service->assignCodes($designDirectoryBoards, [
            'onb-custom.html' => ['code' => 'z9'],
            'onb-bad.html' => ['code' => 'not valid'],
        ], $this->createCanvasDefinition());
        $this->assertSame([
            'onb-w-2-welcome.dc.html' => 'APP ONB-W2',
            'onb-s01-start.html' => 'APP ONB-S01',
            'onb-overview.html' => null,
            'onb-k-5-a.html' => null,
            'onb-k5-b.html' => null,
            'onb-custom.html' => 'APP ONB-Z9',
            'onb-bad.html' => null,
        ], $this->codesOf($designDirectoryBoards));
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString("code 'not valid'", $warnings[0]);
        $this->assertStringContainsString("share the code 'K5'", $warnings[1]);
    }

    public function testCodesWithoutProjectOrCanvasCode(): void
    {
        $service = new DesignBoardCodesService();
        $designDirectoryBoards = $this->createBoards('onb-w-2-welcome.html');
        $service->assignCodes($designDirectoryBoards, [], $this->createCanvasDefinition(null, 'ONB'));
        $this->assertSame(['onb-w-2-welcome.html' => 'ONB-W2'], $this->codesOf($designDirectoryBoards));

        $designDirectoryBoards = $this->createBoards('onb-w-2-welcome.html');
        $service->assignCodes($designDirectoryBoards, [], $this->createCanvasDefinition('APP', null));
        $this->assertSame(['onb-w-2-welcome.html' => 'ONBW2'], $this->codesOf($designDirectoryBoards));
    }

    public function testMatchesFullShortAndBoardPartIgnoringCaseSpacesAndDashes(): void
    {
        $this->assertSame('APPONBW2', DesignBoardCodesService::normalizeCode(' app onb-w2 '));
        foreach (['APP ONB-W2', 'app-onb-w2', 'APPONBW2', 'ONB-W2', 'onb w2', 'W2', 'w-2'] as $typedCode) {
            $this->assertTrue(DesignBoardCodesService::codeMatches($typedCode, 'APP ONB-W2'), $typedCode);
        }
        foreach (['2', 'NBW2', 'APP ONB-W', 'APP ONB-W22', ''] as $typedCode) {
            $this->assertFalse(DesignBoardCodesService::codeMatches($typedCode, 'APP ONB-W2'), $typedCode);
        }
    }
}
