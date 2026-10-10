<?php
/**
 * The settings of CompanionCases::CONFIG, saved to core_config_data.
 *
 * StateDiff runs the requests in a fresh object manager, which reads config
 * from the database (through the config cache), not from the test framework's
 * in-memory @magentoConfigFixture overrides. So the values go to the database
 * and the config cache is cleaned.
 *
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
foreach (CompanionCases::CONFIG as [$scope, $scopeId, $path, $value]) {
    $writer->save($path, $value, $scope, $scopeId);
}
$objectManager->get(ReinitableConfigInterface::class)->reinit();
