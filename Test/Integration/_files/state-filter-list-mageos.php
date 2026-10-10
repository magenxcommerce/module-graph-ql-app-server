<?php
/**
 * State-comparator filter list for Mage-OS: properties of components Mage-OS
 * bundles and Magento Open Source does not. Copy it next to the core lists
 * (dev/tests/integration/framework/Magento/TestFramework/
 * ApplicationStateComparator/_files/), which load every state-filter-list*.php.
 *
 * - creatuity/magento2-interceptors generates compiled interceptors that fetch
 *   each plugin from the object manager on first use and keep the shared
 *   instance in a ____plugin_<name> property. Only that reference is filtered;
 *   the plugin object itself is still compared as its own service.
 * - Swissup\Ignition\Plugin\App replaces its error handler on every launch.
 *
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

$pluginProperties = [];
$diFiles = array_merge(
    glob(BP . '/app/etc/di.xml') ?: [],
    glob(BP . '/{vendor/*/*,app/code/*/*}/etc/{,*/}di.xml', GLOB_BRACE) ?: []
);
foreach ($diFiles as $diFile) {
    preg_match_all('/<plugin\b[^>]*\bname="([^"]+)"/', (string) file_get_contents($diFile), $matches);
    foreach ($matches[1] as $pluginName) {
        // CompiledInterceptor::getPluginInfo(): the name with non-word characters
        // replaced, plus a counter when one name maps to several classes.
        $property = '____plugin_' . preg_replace('/[^A-Za-z0-9_]/', '_', $pluginName);
        foreach (['', '2', '3', '4', '5'] as $suffix) {
            $pluginProperties[$property . $suffix] = null;
        }
    }
}

return [
    'all' => $pluginProperties,
    'parents' => [
        'Swissup\Ignition\Plugin\App' => ['ignition' => null],
    ],
];
