<?php
/**
 * Checks every etc/reset.json entry in this module against the installed code.
 *
 * Usage, from the Magento root:
 *     php vendor/magenxcommerce/module-graph-ql-app-server/bin/check-reset-json.php
 *
 * For each class and property it checks three things. The property must be
 * declared on that class. Its declared default must equal the reset value,
 * so the reset puts back the state a freshly built object has. The
 * constructor must not assign it, or the reset would wipe a dependency the
 * object needs. A class that isn't installed is skipped.
 * The core Resetter ignores reset.json for classes that have their own
 * _resetState(), so that case is reported too.
 */

declare(strict_types=1);

$magentoRoot = getcwd();
if (!is_file($magentoRoot . '/app/autoload.php')) {
    fwrite(STDERR, "Run from the Magento root (app/autoload.php not found).\n");
    exit(1);
}
require $magentoRoot . '/app/autoload.php';

$entries = json_decode((string) file_get_contents(dirname(__DIR__) . '/etc/reset.json'), true, flags: JSON_THROW_ON_ERROR);
$problems = 0;

foreach ($entries as $class => $properties) {
    if (!class_exists($class)) {
        echo "skip   $class (not installed)\n";
        continue;
    }
    $reflection = new ReflectionClass($class);
    if ($reflection->hasMethod('_resetState')) {
        echo "WARN   $class has its own _resetState(); the Resetter ignores its reset.json entry\n";
        $problems++;
    }
    $constructorSource = '';
    $constructor = $reflection->getConstructor();
    if ($constructor !== null && $constructor->getDeclaringClass()->getName() === $class) {
        $lines = file((string) $constructor->getFileName());
        $constructorSource = implode('', array_slice(
            $lines,
            $constructor->getStartLine() - 1,
            $constructor->getEndLine() - $constructor->getStartLine() + 1
        ));
    }
    foreach ($properties as $name => $resetValue) {
        if (!$reflection->hasProperty($name)) {
            echo "FAIL   $class::\$$name does not exist\n";
            $problems++;
            continue;
        }
        $property = $reflection->getProperty($name);
        if ($property->getDeclaringClass()->getName() !== $class) {
            echo "FAIL   $class::\$$name is declared on " . $property->getDeclaringClass()->getName() . "\n";
            $problems++;
            continue;
        }
        // getDefaultProperties() covers static and instance properties alike.
        $default = $reflection->getDefaultProperties()[$name] ?? null;
        if ($default !== $resetValue) {
            echo "FAIL   $class::\$$name declared default " . var_export($default, true)
                . ' differs from reset value ' . var_export($resetValue, true) . "\n";
            $problems++;
            continue;
        }
        $assignedInConstructor = preg_match('/(\$this->|self::\$|static::\$)' . preg_quote($name, '/') . '\s*=[^=]/', $constructorSource);
        if ($assignedInConstructor) {
            echo "FAIL   $class::\$$name is assigned in the constructor; resetting it would break the object\n";
            $problems++;
            continue;
        }
        echo "ok     $class::\$$name\n";
    }
}

echo $problems === 0 ? "\nAll entries are safe to reset.\n" : "\n$problems problem(s) found.\n";
exit($problems === 0 ? 0 : 1);
