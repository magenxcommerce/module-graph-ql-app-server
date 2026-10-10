<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

use Magento\Config\App\Config\Type\System;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Cache\CacheConstants;
use Magenx\AppServer\Test\Integration\Cases\CompanionCases;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
/** @var WriterInterface $writer */
$writer = $objectManager->get(WriterInterface::class);
foreach (CompanionCases::CONFIG as [$scope, $scopeId, $path]) {
    $writer->delete($path, $scope, $scopeId);
}
$objectManager->get(ConfigCache::class)->clean(CacheConstants::CLEANING_MODE_MATCHING_TAG, [System::CACHE_TAG]);
