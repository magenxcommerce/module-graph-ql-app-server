<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magenx\AppServer\Test\Integration\Cases\CompanionCases;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
/** @var WriterInterface $writer */
$writer = $objectManager->get(WriterInterface::class);
foreach (CompanionCases::CONFIG as [$scope, $scopeId, $path]) {
    $writer->delete($path, $scope, $scopeId);
}
$objectManager->get(ReinitableConfigInterface::class)->reinit();
