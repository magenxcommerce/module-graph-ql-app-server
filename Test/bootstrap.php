<?php
/**
 * Magenx_AppServer — PHPUnit bootstrap for the unit suite.
 *
 * PHPUnit loads no autoloader of its own. Having a phpunit.xml.dist opts this
 * module out of the fallback autoloader the shared module CI generates, so it
 * brings its own, for a standalone checkout (composer install ran here) and
 * for a Magento installation (vendor/ or app/code).
 *
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

$moduleRoot = dirname(__DIR__);

foreach ([
    $moduleRoot . '/vendor/autoload.php',
    dirname($moduleRoot, 2) . '/autoload.php',
    dirname($moduleRoot, 3) . '/vendor/autoload.php',
] as $autoloader) {
    if (is_file($autoloader)) {
        require_once $autoloader;
        break;
    }
}

/*
 * Without the credential-gated Magento packages, map this module's namespace
 * onto the checkout (as composer.json's PSR-4 entry does), so the tests that
 * need no Magento class still run.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Magenx\\AppServer\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
