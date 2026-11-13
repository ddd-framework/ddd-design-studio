<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Devices;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Device presets, in picker order.
 *
 * @property DesignDevice[] $elements
 * @method DesignDevice[] getElements()
 * @method DesignDevice first()
 */
class DesignDevices extends ObjectSet
{
    /**
     * @param string $deviceKey
     * @return DesignDevice|null
     */
    public function getByDeviceKey(string $deviceKey): ?DesignDevice
    {
        foreach ($this->getElements() as $designDevice) {
            if ($designDevice->deviceKey === $deviceKey) {
                return $designDevice;
            }
        }
        return null;
    }
}
