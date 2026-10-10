<?php
/**
 * Customer B for the companion state tests: same website and group as
 * Magento/Customer/_files/customer.php (customer A), different account.
 *
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

use Magento\Customer\Model\Customer;
use Magento\TestFramework\Helper\Bootstrap;

/** @var Customer $customer */
$customer = Bootstrap::getObjectManager()->create(Customer::class);
$customer->setWebsiteId(1)
    ->setStoreId(1)
    ->setGroupId(1)
    ->setIsActive(1)
    ->setEmail('customer_two@example.com')
    ->setPassword('password')
    ->setFirstname('Second')
    ->setLastname('Customer');
$customer->isObjectNew(true);
$customer->save();
