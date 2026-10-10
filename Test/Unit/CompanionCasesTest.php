<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AppServer\Test\Unit;

use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use Magenx\AppServer\Test\Integration\Cases\CompanionCases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Structure of the companion state-test catalogue, checked without Magento.
 *
 * The integration tests only run inside a Magento installation, so a malformed
 * case would otherwise surface late, as a confusing failure there. Whether the
 * cases match the installed schema and cover every companion resolver is
 * CompanionSchemaCoverageTest's job.
 */
class CompanionCasesTest extends TestCase
{
    private const KEYS = ['module', 'query', 'variables', 'variables2', 'auth', 'expected', 'forbidden', 'errors', 'seeds'];
    private const REQUIRED = ['module', 'query', 'auth', 'expected'];

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (['catalog', 'customer', 'cart', 'mutations'] as $group) {
            foreach (CompanionCases::$group() as $name => $case) {
                $cases["$group: $name"] = [$case, $group];
            }
        }
        return $cases;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testCaseIsWellFormed(array $case, string $group): void
    {
        $this->assertSame([], array_diff(array_keys($case), self::KEYS), 'Unknown keys.');
        $this->assertSame([], array_diff(self::REQUIRED, array_keys($case)), 'Missing keys.');
        $this->assertMatchesRegularExpression('/^Magenx_\w+$/', $case['module']);

        $this->assertCount(2, $case['auth']);
        foreach ($case['auth'] as $who) {
            $this->assertTrue($who === null || isset(CompanionCases::CUSTOMERS[$who]), 'auth: A, B or null.');
        }

        $this->assertCount(2, $case['expected']);
        foreach ($case['expected'] as $expected) {
            $this->assertNotSame([], (array) $expected);
            foreach ((array) $expected as $needle) {
                $this->assertIsString($needle);
                $this->assertNotSame('', $needle);
            }
        }
        foreach ($case['forbidden'] ?? [] as $needle) {
            $this->assertIsString($needle);
            $this->assertNotSame('', $needle);
        }
        if (isset($case['errors'])) {
            $this->assertIsBool($case['errors']);
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testVariablesMatchTheOperation(array $case, string $group): void
    {
        preg_match('/^[^{]*/', $case['query'], $header);
        preg_match_all('/\$(\w+)\s*:/', $header[0], $declared);
        $declared = $declared[1];
        sort($declared);

        foreach (['variables', 'variables2'] as $key) {
            if (!isset($case[$key]) && $key === 'variables2') {
                continue;
            }
            $passed = array_keys($case[$key] ?? []);
            sort($passed);
            $this->assertSame($declared, $passed, "$key must pass exactly the variables the operation declares.");
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testPlaceholdersComeFromASeed(array $case, string $group): void
    {
        $available = CompanionCases::GROUP_PLACEHOLDERS[$group] ?? [];
        foreach ($case['seeds'] ?? [] as $seed) {
            $this->assertArrayHasKey($seed, CompanionCases::SEEDS, "Unknown seed $seed.");
            $available = [...$available, ...CompanionCases::SEEDS[$seed]];
        }
        $this->assertSame(
            array_unique($case['seeds'] ?? []),
            $case['seeds'] ?? [],
            'A seed is listed twice.'
        );

        foreach ([$case['variables'] ?? [], $case['variables2'] ?? []] as $variables) {
            foreach ($variables as $value) {
                if (is_string($value) && preg_match('/^%(\w+)%$/', $value, $match)) {
                    $this->assertContains($match[1], $available, "Nothing provides %{$match[1]}%.");
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testQueryIsOneNamedOperation(array $case, string $group): void
    {
        if (!class_exists(Parser::class)) {
            $this->markTestSkipped('webonyx/graphql-php is not installed (composer install without Marketplace keys).');
        }
        $document = Parser::parse($case['query']);
        $operations = array_values(array_filter(
            iterator_to_array($document->definitions),
            static fn ($definition): bool => $definition instanceof OperationDefinitionNode
        ));
        $this->assertCount(1, $operations);
        $this->assertNotNull($operations[0]->name, 'Operations need a name: it keys the core skip lists.');
    }

    public function testOperationNamesAreUniqueAndOwn(): void
    {
        $names = [];
        foreach (CompanionCases::all() as $name => $case) {
            $this->assertMatchesRegularExpression(
                '/^\s*(query|mutation)\s+magenxState\w+/',
                $case['query'],
                "$name: name operations magenxState*, so no core skip-list entry applies to them."
            );
            preg_match('/^\s*(?:query|mutation)\s+(\w+)/', $case['query'], $match);
            $names[$name] = $match[1];
        }
        $duplicates = array_diff_assoc($names, array_unique($names));
        $this->assertSame([], $duplicates, 'Operation names must be unique.');
    }

    public function testEverySeedHasAnImplementation(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/Integration/CompanionStateTestCase.php');
        foreach (array_keys(CompanionCases::SEEDS) as $seed) {
            $method = 'seed' . str_replace('_', '', ucwords($seed, '_'));
            $this->assertMatchesRegularExpression("/function $method\\(\\)/", $source, "Seed $seed has no $method().");
        }
    }

    public function testCustomerCasesCrossAccounts(): void
    {
        // Private data must be shown not to reach the next requester: at
        // least one A-then-B or A-then-guest pair per module with private reads.
        $crossed = [];
        foreach (CompanionCases::customer() as $case) {
            if ($case['auth'][0] === 'A' && $case['auth'][1] !== 'A') {
                $crossed[$case['module']] = true;
            }
        }
        foreach (CompanionCases::customer() as $name => $case) {
            $this->assertArrayHasKey($case['module'], $crossed, "$name: its module has no cross-account pair.");
        }
    }
}
