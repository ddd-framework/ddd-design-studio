<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinitions;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignProjectDefinition;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignProjectDefinitions;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;

/**
 * The design projects and canvases an application declares in the config `DesignStudio.projects`. Projects and
 * canvases are configuration, never database rows: a canvas is a folder of the running checkout.
 */
class DesignProjectDefinitionsService extends Service
{
    /** @var string Config path of the project list */
    public const string CONFIG_PATH = 'DesignStudio.projects';

    /** @var string Keys are used in URLs, file cache keys and review state */
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /** @var string Project and canvas codes (upper case after reading): letters and digits, starting with a letter */
    public const string CODE_PATTERN = '/^[A-Z][A-Z0-9]{0,11}$/';

    /**
     * @return DesignProjectDefinitions every declared project with its canvases, in config order
     * @throws BadRequestException when the config declares an invalid or duplicate key or an unsafe folder
     */
    public function getProjectDefinitions(): DesignProjectDefinitions
    {
        $projectDefinitions = new DesignProjectDefinitions();
        $seenProjectKeys = [];
        $seenCodePrefixes = [];
        foreach ($this->getProjectsConfig() as $projectConfig) {
            $projectKey = (string)($projectConfig['key'] ?? '');
            if (!preg_match(static::KEY_PATTERN, $projectKey) || isset($seenProjectKeys[$projectKey])) {
                throw new BadRequestException("Design studio config: invalid or duplicate project key '$projectKey'");
            }
            $seenProjectKeys[$projectKey] = true;
            $projectDefinition = new DesignProjectDefinition();
            $projectDefinition->projectKey = $projectKey;
            $projectDefinition->label = (string)($projectConfig['label'] ?? $projectKey);
            $projectDefinition->path = (string)($projectConfig['path'] ?? $projectKey);
            $projectDefinition->code = $this->readCode($projectConfig['code'] ?? null, "project '$projectKey'");
            $projectDefinition->canvasDefinitions = new DesignCanvasDefinitions();
            $seenCanvasKeys = [];
            foreach (($projectConfig['canvases'] ?? []) as $canvasConfig) {
                $canvasKey = (string)($canvasConfig['key'] ?? '');
                if (!preg_match(static::KEY_PATTERN, $canvasKey) || isset($seenCanvasKeys[$canvasKey])) {
                    throw new BadRequestException(
                        "Design studio config: invalid or duplicate canvas key '$canvasKey' in project '$projectKey'"
                    );
                }
                $seenCanvasKeys[$canvasKey] = true;
                $folder = trim((string)($canvasConfig['folder'] ?? ''), '/');
                if ($folder === '' || str_starts_with((string)($canvasConfig['folder'] ?? ''), '/') || in_array('..', explode('/', $folder), true)) {
                    throw new BadRequestException(
                        "Design studio config: canvas '$projectKey/$canvasKey' needs a folder relative to the project root without '..'"
                    );
                }
                $canvasDefinition = new DesignCanvasDefinition();
                $canvasDefinition->projectKey = $projectKey;
                $canvasDefinition->canvasKey = $canvasKey;
                $canvasDefinition->label = isset($canvasConfig['label']) ? (string)$canvasConfig['label'] : null;
                $canvasDefinition->folder = $folder;
                $canvasDefinition->boardPatterns = array_values(array_map('strval', $canvasConfig['boards'] ?? []));
                $canvasDefinition->sectionPatterns = array_values(array_map('strval', $canvasConfig['sections'] ?? []));
                $canvasDefinition->referenceTheme = isset($canvasConfig['referenceTheme']) ? (string)$canvasConfig['referenceTheme'] : null;
                $canvasDefinition->runsBoardScripts = (bool)($canvasConfig['scripts'] ?? false);
                $canvasDefinition->projectCode = $projectDefinition->code;
                $canvasDefinition->code = $this->readCode($canvasConfig['code'] ?? null, "canvas '$projectKey/$canvasKey'");
                $codePrefix = $canvasDefinition->getCodePrefix();
                if ($codePrefix !== null) {
                    // a board code names one board of the whole installation: no two canvases share a prefix
                    if (isset($seenCodePrefixes[$codePrefix])) {
                        throw new BadRequestException(
                            "Design studio config: canvas '$projectKey/$canvasKey' has the code '$codePrefix' of canvas '{$seenCodePrefixes[$codePrefix]}'"
                        );
                    }
                    $seenCodePrefixes[$codePrefix] = "$projectKey/$canvasKey";
                }
                $projectDefinition->canvasDefinitions->add($canvasDefinition);
            }
            $projectDefinitions->add($projectDefinition);
        }
        return $projectDefinitions;
    }

    /**
     * @param string $projectKey
     * @return DesignProjectDefinition|null null when the project is not declared
     * @throws BadRequestException
     */
    public function getProjectDefinition(string $projectKey): ?DesignProjectDefinition
    {
        foreach ($this->getProjectDefinitions()->getElements() as $projectDefinition) {
            if ($projectDefinition->projectKey === $projectKey) {
                return $projectDefinition;
            }
        }
        return null;
    }

    /**
     * @param string $projectKey
     * @param string $canvasKey
     * @return DesignCanvasDefinition|null null when the project or the canvas is not declared
     * @throws BadRequestException
     */
    public function getCanvasDefinition(string $projectKey, string $canvasKey): ?DesignCanvasDefinition
    {
        foreach (($this->getProjectDefinition($projectKey)?->canvasDefinitions?->getElements() ?? []) as $canvasDefinition) {
            if ($canvasDefinition->canvasKey === $canvasKey) {
                return $canvasDefinition;
            }
        }
        return null;
    }

    /**
     * @param mixed $code the configured code, null / '' = none
     * @param string $owner what the code belongs to (for the error message)
     * @return string|null the code in upper case
     * @throws BadRequestException when the code is not 1–12 letters and digits starting with a letter
     */
    protected function readCode(mixed $code, string $owner): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $code = strtoupper(trim((string)$code));
        if (!preg_match(static::CODE_PATTERN, $code)) {
            throw new BadRequestException("Design studio config: invalid code '$code' of $owner (1–12 letters and digits, starting with a letter)");
        }
        return $code;
    }

    /**
     * @return array the raw project list of the config (application file before module default)
     */
    protected function getProjectsConfig(): array
    {
        $projectsConfig = Config::get(static::CONFIG_PATH);
        return is_array($projectsConfig) ? $projectsConfig : [];
    }
}
