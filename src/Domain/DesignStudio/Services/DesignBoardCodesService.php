<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoards;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Board codes: a short name per board (`APP ONB-W2`) that people say in a chat, write in a ticket or paste into the
 * studio, and that opens the canvas on that board. A code is `<project code> <canvas code>-<board part>`; the project
 * and canvas codes come from the config (`code` of a project and of a canvas), the board part from the board:
 *
 * - `code` of the board in canvas.json, when set (letters and digits);
 * - else derived from the file name: without `.html` / `.dc.html`, split at `-` and `_`, a first part equal to the
 *   canvas code left out, then the parts up to and including the first one with a digit, joined in upper case —
 *   `onb-w-2-welcome.dc.html` → `W2`, `onb-s01-start.html` → `S01`, `onb-3-done.html` → `3`, `onb-w-18b-x.html` → `W18B`;
 * - none when the file name has no part with a digit, or when two boards of the canvas arrive at the same board part
 *   (both lose it; the canvas lists a warning).
 *
 * Codes are read on every request like the rest of the directory; nothing is stored. Matching ignores case, spaces and
 * dashes, and a code may be given without its project part (`ONB-W2`) or, on its canvas, as the board part alone (`W2`).
 */
class DesignBoardCodesService extends Service
{
    /** @var string Key of a board entry in canvas.json that sets its board part explicitly */
    public const string CANVAS_JSON_CODE_KEY = 'code';

    /** @var string A board part: letters and digits */
    public const string BOARD_PART_PATTERN = '/^[A-Z0-9]{1,16}$/';

    /**
     * @param string $boardKey file name of the board
     * @param string|null $canvasCode the canvas code (left out of the file name when the name starts with it)
     * @return string|null the board part derived from the file name, null when the name has no part with a digit
     */
    public function deriveBoardPart(string $boardKey, ?string $canvasCode): ?string
    {
        $baseName = preg_replace('/(?:\.dc)?\.html$/i', '', $boardKey) ?? $boardKey;
        $nameParts = array_values(array_filter(preg_split('/[-_]+/', $baseName) ?: [], static fn(string $part) => $part !== ''));
        if ($canvasCode !== null && isset($nameParts[0]) && strtoupper($nameParts[0]) === $canvasCode) {
            array_shift($nameParts);
        }
        $codeParts = [];
        foreach ($nameParts as $namePart) {
            if (!preg_match('/^[A-Za-z0-9]+$/', $namePart)) {
                return null;
            }
            $codeParts[] = strtoupper($namePart);
            if (preg_match('/\d/', $namePart)) {
                $boardPart = implode('', $codeParts);
                return preg_match(static::BOARD_PART_PATTERN, $boardPart) ? $boardPart : null;
            }
        }
        return null;
    }

    /**
     * Sets `code` on every board of a canvas.
     * @param DesignDirectoryBoards $designDirectoryBoards boards of one canvas
     * @param array<string, array> $boardDescriptions the `boards` object of canvas.json (file name → entry)
     * @param DesignCanvasDefinition $canvasDefinition
     * @return string[] warnings: explicit codes that are not letters and digits, board parts used twice
     */
    public function assignCodes(
        DesignDirectoryBoards $designDirectoryBoards,
        array $boardDescriptions,
        DesignCanvasDefinition $canvasDefinition
    ): array {
        $warnings = [];
        $boardKeysByPart = [];
        foreach ($designDirectoryBoards->getElements() as $designDirectoryBoard) {
            $boardKey = (string)$designDirectoryBoard->boardKey;
            $explicitPart = $boardDescriptions[$boardKey][static::CANVAS_JSON_CODE_KEY] ?? null;
            if ($explicitPart !== null && $explicitPart !== '') {
                $boardPart = strtoupper(trim((string)$explicitPart));
                if (!preg_match(static::BOARD_PART_PATTERN, $boardPart)) {
                    $warnings[] = "Board $boardKey: code '$explicitPart' is not 1–16 letters and digits";
                    continue;
                }
            } else {
                $boardPart = $this->deriveBoardPart($boardKey, $canvasDefinition->code);
                if ($boardPart === null) {
                    continue;
                }
            }
            $boardKeysByPart[$boardPart][] = $designDirectoryBoard;
        }
        $codePrefix = $canvasDefinition->getCodePrefix();
        foreach ($boardKeysByPart as $boardPart => $designDirectoryBoardsWithPart) {
            if (count($designDirectoryBoardsWithPart) > 1) {
                $boardKeys = array_map(static fn(DesignDirectoryBoard $board) => (string)$board->boardKey, $designDirectoryBoardsWithPart);
                $warnings[] = "Boards " . implode(', ', $boardKeys) . " share the code '$boardPart' (set \"code\" in "
                    . DesignDirectoryReaderService::CANVAS_FILE_NAME . ' or rename a file)';
                continue;
            }
            $designDirectoryBoardsWithPart[0]->code = $codePrefix !== null ? "$codePrefix-$boardPart" : (string)$boardPart;
        }
        return $warnings;
    }

    /**
     * @param string $code
     * @return string the code for comparisons: upper case, letters and digits only (`app onb-w2` → `APPONBW2`)
     */
    public static function normalizeCode(string $code): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) ?? '';
    }

    /**
     * Whether a typed code names a board: its full code, the code without the project part, or the board part alone.
     * @param string $typedCode
     * @param string $boardCode the board's full code (`APP ONB-W2`)
     * @return bool
     */
    public static function codeMatches(string $typedCode, string $boardCode): bool
    {
        $typed = static::normalizeCode($typedCode);
        if ($typed === '') {
            return false;
        }
        $codeParts = preg_split('/[\s-]+/', strtoupper(trim($boardCode))) ?: [];
        for ($index = 0; $index < count($codeParts); $index++) {
            if ($typed === implode('', array_slice($codeParts, $index))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Finds the board a code names anywhere in the installation (console commands, links without a canvas). A code
     * without its project part is accepted when exactly one canvas matches.
     * @param string $typedCode
     * @return array{0: DesignCanvasDefinition, 1: DesignDirectoryBoard}|null canvas and board, null when no board or
     *                                                                         more than one board has the code
     * @throws BadRequestException
     */
    public function findBoardByCode(string $typedCode): ?array
    {
        /** @var DesignProjectDefinitionsService $designProjectDefinitionsService */
        $designProjectDefinitionsService = DDDService::instance()->getService(DesignProjectDefinitionsService::class);
        /** @var DesignDirectoryReaderService $designDirectoryReaderService */
        $designDirectoryReaderService = DDDService::instance()->getService(DesignDirectoryReaderService::class);
        $typed = static::normalizeCode($typedCode);
        $found = [];
        foreach ($designProjectDefinitionsService->getProjectDefinitions()->getElements() as $projectDefinition) {
            foreach (($projectDefinition->canvasDefinitions?->getElements() ?? []) as $canvasDefinition) {
                $codePrefix = $canvasDefinition->getCodePrefix();
                // only canvases whose code the typed code starts with (with or without the project part)
                if (
                    $codePrefix === null
                    || (!str_starts_with($typed, static::normalizeCode($codePrefix))
                        && !str_starts_with($typed, static::normalizeCode((string)$canvasDefinition->code)))
                ) {
                    continue;
                }
                foreach ($designDirectoryReaderService->readCanvas($canvasDefinition)->boards->getElements() as $designDirectoryBoard) {
                    if ($designDirectoryBoard->code !== null && static::codeMatchesWithCanvas($typed, $designDirectoryBoard->code, $canvasDefinition)) {
                        $found[] = [$canvasDefinition, $designDirectoryBoard];
                    }
                }
            }
        }
        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * @param string $typed normalized typed code
     * @param string $boardCode the board's full code
     * @param DesignCanvasDefinition $canvasDefinition
     * @return bool the typed code is the full code or the code without the project part (never the board part alone)
     */
    protected static function codeMatchesWithCanvas(string $typed, string $boardCode, DesignCanvasDefinition $canvasDefinition): bool
    {
        $full = static::normalizeCode($boardCode);
        $projectPart = static::normalizeCode((string)$canvasDefinition->projectCode);
        return $typed === $full || ($projectPart !== '' && $typed === substr($full, strlen($projectPart)));
    }
}
