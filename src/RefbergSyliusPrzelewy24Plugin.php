<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin;

use Refberg\SyliusPrzelewy24Plugin\DependencyInjection\RefbergSyliusPrzelewy24Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use function dirname;

final class RefbergSyliusPrzelewy24Plugin extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new RefbergSyliusPrzelewy24Extension();
        }

        return $this->extension;
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
