<?php
/**
 * The settings of CompanionCases::CONFIG, saved to core_config_data.
 *
 * StateDiff runs the requests in a fresh object manager, which reads config
 * from the database (through the config cache), not from the test framework's
 * in-memory @magentoConfigFixture overrides. So the values go to the database,
 * and only the cached scope config is dropped: the fresh object manager loads
 * it again on first use. A full reinit() would read and cache every scope's
 * config again here and in the rollback, for every test.
 *
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
foreach (CompanionCases::CONFIG as [$scope, $scopeId, $path, $value]) {
    $writer->save($path, $value, $scope, $scopeId);
}
$objectManager->get(ConfigCache::class)->clean(CacheConstants::CLEANING_MODE_MATCHING_TAG, [System::CACHE_TAG]);
