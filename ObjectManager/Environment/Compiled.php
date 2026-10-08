<?php

declare(strict_types=1);

namespace Magenx\AppServer\ObjectManager\Environment;

use Magenx\AppServer\ObjectManager\Factory\Compiled as CompiledFactory;
use Magento\Framework\App\ObjectManager\Environment\Compiled as CoreCompiled;

/**
 * Compiled environment whose object factory is the reset-tracking one.
 *
 * AbstractEnvironment resolves the factory class as the DI preference for
 * $configPreference; with no preference declared for our class it is used
 * as-is. Only the worker builds this environment, so PHP-FPM is untouched.
 */
class Compiled extends CoreCompiled
{
    /**
     * @var string
     */
    protected $configPreference = CompiledFactory::class;
}
