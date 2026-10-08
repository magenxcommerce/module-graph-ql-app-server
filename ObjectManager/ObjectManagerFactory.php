<?php

declare(strict_types=1);

namespace Magenx\AppServer\ObjectManager;

use Magento\Framework\App\ObjectManagerFactory as CoreObjectManagerFactory;
use Magento\Framework\ObjectManager\Resetter\ResetterInterface;

/**
 * ObjectManagerFactory for the worker: same bootstrap, reset-tracking factory.
 *
 * Passed to Bootstrap::create() by bin/graphql-worker.php. PHP-FPM keeps
 * using the core factory, so nothing changes outside the worker.
 */
class ObjectManagerFactory extends CoreObjectManagerFactory
{
    /**
     * @var string
     */
    protected $envFactoryClassName = EnvironmentFactory::class;

    /**
     * Resetter of the object factory built by create().
     *
     * @throws \LogicException before create() ran, or when MAGE_PROFILER=2
     *         wrapped the factory in the profiler decorator.
     */
    public function getResetter(): ResetterInterface
    {
        if (!$this->factory instanceof Factory\Compiled) {
            throw new \LogicException(
                'Object factory does not track instances for reset. '
                . 'Run the worker without MAGE_PROFILER=2.'
            );
        }
        return $this->factory->getResetter();
    }
}
