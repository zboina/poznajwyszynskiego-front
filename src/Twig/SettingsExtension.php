<?php

namespace App\Twig;

use App\Service\SettingsService;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class SettingsExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private SettingsService $settings) {}

    public function getGlobals(): array
    {
        return [
            'site_settings' => $this->settings->getFrontendDefaults(),
        ];
    }
}
