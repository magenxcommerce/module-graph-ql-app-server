<?php

declare(strict_types=1);

namespace Magenx\AppServer\ObjectManager\Factory;

use Magento\Framework\ObjectManager\Factory\Compiled as CoreCompiled;
use Magento\Framework\ObjectManager\Resetter\ResetterFactory;
use Magento\Framework\ObjectManager\Resetter\ResetterInterface;
use Magento\Framework\ObjectManagerInterface;

/**
 * Compiled-DI object factory that registers every object it builds with the
 * core Resetter.
 *
 * Core 2.4.8 ships the Resetter (ResetAfterRequestInterface / _resetState() /
 * reset.json) but nothing in Open Source feeds it: the factory that does is
 * part of the Commerce-only ApplicationServer. This is that missing piece.
 * It must be a subclass, not a decorator, because Compiled::create() builds
 * non-shared constructor arguments through $this->create(); a wrapper around
 * the factory would never see them.
 *
 * Resetter::addInstance() keeps only objects that implement
 * ResetAfterRequestInterface, have a _resetState() method, or match a
 * reset.json entry, in a WeakMap — so the per-create cost is an instanceof,
 * a method_exists and a per-class cached lookup.
 */
class Compiled extends CoreCompiled
{
    private ?ResetterInterface $resetter = null;

    /**
     * @inheritDoc
     */
    public function create($requestedType, array $arguments = [])
    {
        $object = parent::create($requestedType, $arguments);
        $this->getResetter()->addInstance($object);
        return $object;
    }

    /**
     * @inheritDoc
     */
    public function setObjectManager(ObjectManagerInterface $objectManager)
    {
        parent::setObjectManager($objectManager);
        $this->getResetter()->setObjectManager($objectManager);
    }

    /**
     * Resetter tracking every resettable object this factory has built.
     */
    public function getResetter(): ResetterInterface
    {
        return $this->resetter ??= ResetterFactory::create();
    }
}
