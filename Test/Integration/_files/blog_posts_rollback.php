<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;

if (!class_exists(\Magenx\Blog\Model\PostRepository::class)) {
    return;
}

$objectManager = Bootstrap::getObjectManager();
$repositories = [
    \Magenx\Blog\Model\PostRepository::class => ['state-test-post', 'state-test-post-related'],
    \Magenx\Blog\Model\TagRepository::class => ['state-test-tag'],
    \Magenx\Blog\Model\CategoryRepository::class => ['state-test-category'],
];
foreach ($repositories as $class => $urlKeys) {
    $repository = $objectManager->get($class);
    foreach ($urlKeys as $urlKey) {
        try {
            $repository->delete($repository->getByUrlKey($urlKey));
        } catch (NoSuchEntityException) {
            // Already gone.
        }
    }
}
