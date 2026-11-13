<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Devices\DesignDevice;
use DDD\Domain\DesignStudio\Entities\Devices\DesignDevices;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;

/**
 * The device presets responsive boards are shown in (config `DesignStudio.devices`) and the frames a responsive board
 * shows on the canvas.
 */
class DesignDevicesService extends Service
{
    /** @var int Narrowest device width accepted from the config or a request */
    public const int MINIMUM_DEVICE_WIDTH = 240;

    /** @var int Widest device width accepted from the config or a request */
    public const int MAXIMUM_DEVICE_WIDTH = 3840;

    /** @var int Highest device height accepted from the config */
    public const int MAXIMUM_DEVICE_HEIGHT = 4000;

    /**
     * @return DesignDevices the configured presets; invalid entries are left out
     */
    public function getDevices(): DesignDevices
    {
        $designDevices = new DesignDevices();
        foreach ((array)(Config::get('DesignStudio.devices.devices') ?? []) as $deviceDescription) {
            if (!is_array($deviceDescription)) {
                continue;
            }
            $deviceKey = trim((string)($deviceDescription['key'] ?? ''));
            $width = (int)($deviceDescription['width'] ?? 0);
            $height = (int)($deviceDescription['height'] ?? 0);
            if (
                !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $deviceKey) || $designDevices->getByDeviceKey($deviceKey)
                || $width < static::MINIMUM_DEVICE_WIDTH || $width > static::MAXIMUM_DEVICE_WIDTH
                || $height < 1 || $height > static::MAXIMUM_DEVICE_HEIGHT
            ) {
                continue;
            }
            $designDevice = new DesignDevice();
            $designDevice->deviceKey = $deviceKey;
            $designDevice->label = trim((string)($deviceDescription['label'] ?? '')) ?: $deviceKey;
            $designDevice->width = $width;
            $designDevice->height = $height;
            $kind = strtoupper((string)($deviceDescription['kind'] ?? ''));
            $designDevice->kind = in_array($kind, [DesignDevice::KIND_DESKTOP, DesignDevice::KIND_TABLET, DesignDevice::KIND_PHONE], true)
                ? $kind
                : ($width >= 1024 ? DesignDevice::KIND_DESKTOP : ($width >= 600 ? DesignDevice::KIND_TABLET : DesignDevice::KIND_PHONE));
            $designDevices->add($designDevice);
        }
        return $designDevices;
    }

    /**
     * @return string[] the frames of a responsive board that names none (config `defaultFrameDeviceKeys`)
     */
    public function getDefaultFrameDeviceKeys(): array
    {
        $defaultFrameDeviceKeys = Config::get('DesignStudio.devices.defaultFrameDeviceKeys');
        return is_array($defaultFrameDeviceKeys) ? array_values(array_map('strval', $defaultFrameDeviceKeys)) : [];
    }

    /**
     * The frames a responsive board shows: the first list of known device keys among the board's own, the canvas'
     * and the configured default; else the first preset.
     * @param string[]|null $boardDeviceKeys `devices` of the board in canvas.json
     * @param string[]|null $canvasDeviceKeys `devices` of the canvas in canvas.json
     * @return string[]
     */
    public function resolveFrameDeviceKeys(?array $boardDeviceKeys, ?array $canvasDeviceKeys): array
    {
        $designDevices = $this->getDevices();
        foreach ([$boardDeviceKeys, $canvasDeviceKeys, $this->getDefaultFrameDeviceKeys()] as $deviceKeys) {
            $knownDeviceKeys = array_values(array_unique(array_filter(
                array_map('strval', $deviceKeys ?? []),
                static fn(string $deviceKey): bool => $designDevices->getByDeviceKey($deviceKey) !== null
            )));
            if ($knownDeviceKeys !== []) {
                return $knownDeviceKeys;
            }
        }
        $firstDesignDevice = $designDevices->first();
        return $firstDesignDevice ? [(string)$firstDesignDevice->deviceKey] : [];
    }
}
