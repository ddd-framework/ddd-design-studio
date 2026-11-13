<?php

declare(strict_types=1);

/**
 * Devices a responsive board is shown in (canvas frames, focus view, present mode, site view). `devices`: the presets,
 * in picker order (`key`, `label`, `width`, `height` in CSS pixels, `kind` DESKTOP / TABLET / PHONE).
 * `defaultFrameDeviceKeys`: the frames a responsive board shows on the canvas when neither the board nor the canvas
 * names its own (`devices` in canvas.json). An application file replaces the module default as a whole.
 */
return [
    'devices' => [
        ['key' => 'desktop-1920', 'label' => 'Desktop 1920', 'width' => 1920, 'height' => 1080, 'kind' => 'DESKTOP'],
        ['key' => 'desktop-1440', 'label' => 'Desktop 1440', 'width' => 1440, 'height' => 900, 'kind' => 'DESKTOP'],
        ['key' => 'laptop-1280', 'label' => 'Laptop 1280', 'width' => 1280, 'height' => 800, 'kind' => 'DESKTOP'],
        ['key' => 'ipad-pro-12-landscape', 'label' => 'iPad Pro 12.9" landscape', 'width' => 1366, 'height' => 1024, 'kind' => 'TABLET'],
        ['key' => 'ipad-pro-12-portrait', 'label' => 'iPad Pro 12.9" portrait', 'width' => 1024, 'height' => 1366, 'kind' => 'TABLET'],
        ['key' => 'ipad-10-landscape', 'label' => 'iPad 10.9" landscape', 'width' => 1180, 'height' => 820, 'kind' => 'TABLET'],
        ['key' => 'ipad-10-portrait', 'label' => 'iPad 10.9" portrait', 'width' => 820, 'height' => 1180, 'kind' => 'TABLET'],
        ['key' => 'iphone-16-pro-max', 'label' => 'iPhone 16 Pro Max', 'width' => 440, 'height' => 956, 'kind' => 'PHONE'],
        ['key' => 'iphone-16', 'label' => 'iPhone 16 / 15', 'width' => 393, 'height' => 852, 'kind' => 'PHONE'],
        ['key' => 'iphone-se', 'label' => 'iPhone SE', 'width' => 375, 'height' => 667, 'kind' => 'PHONE'],
        ['key' => 'android', 'label' => 'Android', 'width' => 412, 'height' => 915, 'kind' => 'PHONE'],
    ],
    'defaultFrameDeviceKeys' => ['desktop-1440', 'ipad-10-portrait', 'iphone-16'],
];
