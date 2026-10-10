<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
/** @var Registry $registry */
$registry = $objectManager->get(Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

try {
    $repository = $objectManager->get(CustomerRepositoryInterface::class);
    $repository->delete($repository->get('customer_two@example.com', 1));
} catch (NoSuchEntityException) {
    // Already gone.
}

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);
