<?php

declare(strict_types=1);

namespace Magenx\AppServer\ObjectManager;

use Magenx\AppServer\ObjectManager\Environment\Compiled;
use Magento\Framework\App\Area;
use Magento\Framework\App\EnvironmentFactory as CoreEnvironmentFactory;
use Magento\Framework\App\ObjectManager\ConfigLoader\Compiled as CompiledConfigLoader;

/**
 * Always the compiled environment, with the reset-tracking factory.
 *
 * The worker runs production builds only. Core would silently fall back to
 * the developer environment when generated/metadata is missing; here that
 * means a broken deploy, so refuse to boot instead.
 */
class EnvironmentFactory extends CoreEnvironmentFactory
{
    /**
     * @inheritDoc
     */
    public function createEnvironment()
    {
        $compiledConfig = CompiledConfigLoader::getFilePath(Area::AREA_GLOBAL);
        // Runs while the object manager is being built: no Filesystem driver yet.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (!file_exists($compiledConfig)) {
            throw new \RuntimeException(
                "Compiled DI config not found ($compiledConfig). "
                . 'Run bin/magento setup:di:compile before starting the GraphQL worker.'
            );
        }
        return new Compiled($this);
    }
}
