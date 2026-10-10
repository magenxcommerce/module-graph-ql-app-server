<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AppServer\Test\Integration;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Visitor;
use GraphQL\Type\Definition\ImplementingType;
use GraphQL\Utils\TypeInfo;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Config\Element\InterfaceType;
use Magento\Framework\GraphQl\Config\Element\Type;
use Magento\Framework\GraphQl\ConfigInterface;
use Magento\Framework\GraphQl\Schema\SchemaGeneratorInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magenx\AppServer\Test\Integration\Cases\CompanionCases;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the companion state tests complete, against the schema of this installation.
 *
 * A companion field without a state-test case is a field nobody has shown
 * to be safe in a worker. These tests fail when:
 *  - a field resolved by a companion resolver (class in COMPANION_NAMESPACES)
 *    is selected by no case in Cases\CompanionCases, e.g. after a module
 *    added a query, mutation or field;
 *  - a case no longer validates against the installed schema;
 *  - a core state skip or filter list names a companion class, which would
 *    silence exactly the state these tests exist to catch.
 *
 * "Selected" is static: a nested resolver also needs a row to resolve, which
 * is what the seeds and fixtures of the state tests provide.
 *
 * App isolation: Magento narrows the generated schema to the fields of the
 * query it last parsed, so it must not inherit a previous test's request.
 *
 * @magentoAppIsolation enabled
 * @magentoAppArea graphql
 */
class CompanionSchemaCoverageTest extends TestCase
{
    /** Resolver namespaces that count as companion code. */
    private const COMPANION_NAMESPACES = ['Magenx\\'];

    private const STATE_LIST_PATH =
        '/dev/tests/integration/framework/Magento/TestFramework/ApplicationStateComparator/_files';

    public function testEveryCompanionResolverFieldHasAStateCase(): void
    {
        $required = $this->companionFields();
        if ($required === []) {
            $this->markTestSkipped('No companion module with a GraphQL resolver is enabled.');
        }

        $uncovered = array_diff_key($required, $this->selectedFields());
        ksort($uncovered);

        $this->assertSame(
            [],
            $uncovered,
            "Companion fields without a case in Test/Integration/Cases/CompanionCases.php.\n"
            . "Add one for each (field => resolver) before the worker serves it."
        );
    }

    public function testEveryCaseValidatesAgainstTheInstalledSchema(): void
    {
        $schema = Bootstrap::getObjectManager()->get(SchemaGeneratorInterface::class)->generate();
        $enabledModules = Bootstrap::getObjectManager()->get(\Magento\Framework\Module\Manager::class);

        $invalid = [];
        foreach (CompanionCases::all() as $name => $case) {
            if (!$enabledModules->isEnabled($case['module'])) {
                continue;
            }
            $errors = \GraphQL\Validator\DocumentValidator::validate($schema, Parser::parse($case['query']));
            foreach ($errors as $error) {
                $invalid[$name][] = $error->getMessage();
            }
        }

        $this->assertSame([], $invalid, 'Cases that no longer match the installed schema.');
    }

    public function testNoCompanionClassIsOnACoreStateSkipList(): void
    {
        $listed = [];
        foreach (glob(BP . self::STATE_LIST_PATH . '/state-{skip,filter}-list*.php', GLOB_BRACE) ?: [] as $file) {
            // phpcs:ignore Magento2.Security.IncludeFile -- core test-framework data file
            $this->collectCompanionKeys(include $file, basename($file), $listed);
        }

        $this->assertSame(
            [],
            $listed,
            'A skip or filter list hides state from the comparator. Fix the class '
            . '(_resetState() or etc/reset.json) instead of listing it.'
        );
    }

    /**
     * "Type.field" => resolver class, for every field a companion resolver resolves.
     *
     * A field an object type inherits from an interface is required once, on
     * the interface: Magento copies interface fields into every implementing type.
     *
     * @return array<string, string>
     */
    private function companionFields(): array
    {
        $config = Bootstrap::getObjectManager()->get(ConfigInterface::class);

        $required = [];
        foreach ($config->getDeclaredTypes() as $declared) {
            if (!in_array($declared['type'], ['graphql_type', 'graphql_interface'], true)) {
                continue;
            }
            $element = $config->getConfigElement($declared['name']);
            if (!$element instanceof Type && !$element instanceof InterfaceType) {
                continue;
            }
            $inherited = $element instanceof Type ? $this->interfaceFieldNames($config, $element) : [];
            /** @var Field $field */
            foreach ($element->getFields() as $field) {
                $resolver = ltrim((string) $field->getResolver(), '\\');
                if (!$this->isCompanion($resolver) || isset($inherited[$field->getName()])) {
                    continue;
                }
                $required[$element->getName() . '.' . $field->getName()] = $resolver;
            }
        }
        return $required;
    }

    /**
     * @return array<string, true> names of the fields $type gets from its interfaces
     */
    private function interfaceFieldNames(ConfigInterface $config, Type $type): array
    {
        $names = [];
        foreach ($type->getInterfaces() as $interface) {
            $interfaceName = is_array($interface) ? ($interface['interface'] ?? '') : (string) $interface;
            $element = $interfaceName === '' ? null : $config->getConfigElement($interfaceName);
            if ($element instanceof InterfaceType) {
                foreach ($element->getFields() as $field) {
                    $names[$field->getName()] = true;
                }
            }
        }
        return $names;
    }

    /**
     * "Type.field" for every field any case selects, on its parent type and on
     * every interface of that type that declares the field.
     *
     * @return array<string, true>
     */
    private function selectedFields(): array
    {
        $schema = Bootstrap::getObjectManager()->get(SchemaGeneratorInterface::class)->generate();

        $selected = [];
        foreach (CompanionCases::all() as $case) {
            $typeInfo = new TypeInfo($schema);
            Visitor::visit(Parser::parse($case['query']), Visitor::visitWithTypeInfo($typeInfo, [
                'Field' => static function (FieldNode $node) use ($typeInfo, &$selected): void {
                    $parent = $typeInfo->getParentType();
                    if ($parent === null) {
                        return;
                    }
                    $name = $node->name->value;
                    $selected[$parent->name . '.' . $name] = true;
                    if ($parent instanceof ImplementingType) {
                        foreach ($parent->getInterfaces() as $interface) {
                            if ($interface->hasField($name)) {
                                $selected[$interface->name . '.' . $name] = true;
                            }
                        }
                    }
                },
            ]));
        }
        return $selected;
    }

    private function isCompanion(string $class): bool
    {
        foreach (self::COMPANION_NAMESPACES as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $list
     * @param array<string, string[]> $found
     */
    private function collectCompanionKeys(mixed $list, string $path, array &$found): void
    {
        if (!is_array($list)) {
            return;
        }
        foreach ($list as $key => $value) {
            if (is_string($key) && $this->isCompanion(ltrim($key, '\\'))) {
                $found[$path][] = $key;
            }
            $this->collectCompanionKeys($value, $path . ' > ' . $key, $found);
        }
    }
}
