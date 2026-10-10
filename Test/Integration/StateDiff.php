<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AppServer\Test\Integration;

use Magento\Framework\App\Area;
use Magento\Framework\App\Http as HttpApp;
use Magento\Framework\App\ObjectManager as AppObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\TestFramework\ApplicationStateComparator\Comparator;
use Magento\Framework\TestFramework\ApplicationStateComparator\ObjectManager;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Runs a GraphQL operation twice in one application and fails on state left behind.
 *
 * The same check as core's Magento\GraphQl\App\State\GraphQlStateDiff (what
 * GraphQlStateTest runs on), built on the same test object manager and
 * Comparator, which the core class keeps private. On top of it:
 *  - each request can run as a different customer, or as a guest;
 *  - each request has its own expected substrings, and the second response
 *    can be checked for substrings it must not contain (another customer's
 *    data);
 *  - GraphQL errors fail the case unless it allows them;
 *  - tokens are issued before the measured window, so only the request
 *    itself is compared, as a worker would serve it;
 *  - execute() runs an unmeasured request, to seed data through the public API.
 *
 * Every object the test object manager builds is registered with the core
 * Resetter, exactly as Magenx\AppServer\ObjectManager\Factory\Compiled does in
 * the worker, and reset.json files of all modules (this module's included)
 * apply. So a case that passes here is reset the way the worker resets it.
 */
class StateDiff
{
    private const CONTENT_TYPE = 'application/json';

    private readonly ObjectManagerInterface $objectManagerBeforeTest;
    private readonly ObjectManager $objectManagerForTest;
    private readonly Comparator $comparator;
    private readonly State $appState;
    private readonly ?string $previousArea;

    public function __construct()
    {
        $this->objectManagerBeforeTest = Bootstrap::getObjectManager();
        $this->objectManagerForTest = new ObjectManager($this->objectManagerBeforeTest);
        $this->objectManagerForTest->getFactory()->setObjectManager($this->objectManagerForTest);
        AppObjectManager::setInstance($this->objectManagerForTest);
        Bootstrap::setObjectManager($this->objectManagerForTest);
        $this->comparator = $this->objectManagerForTest->create(Comparator::class);
        $this->appState = $this->objectManagerForTest->get(State::class);
        $this->previousArea = $this->appState->getAreaCode();
        $this->appState->setAreaCode(Area::AREA_GRAPHQL);
        $this->objectManagerForTest->_resetState();
    }

    public function getTestObjectManager(): ObjectManager
    {
        return $this->objectManagerForTest;
    }

    /**
     * Restore the object manager and area the test framework had before.
     */
    public function tearDown(): void
    {
        $this->appState->setAreaCode($this->previousArea);
        $this->objectManagerBeforeTest->getFactory()->setObjectManager($this->objectManagerBeforeTest);
        AppObjectManager::setInstance($this->objectManagerBeforeTest);
        Bootstrap::setObjectManager($this->objectManagerBeforeTest);
    }

    /**
     * Run one case from Cases\CompanionCases (variables already resolved).
     *
     * @param array<string, mixed> $case
     * @return string[] the two raw responses
     */
    public function run(array $case, TestCase $test): array
    {
        $operationName = self::operationName($case['query']);
        $variables = $case['variables'] ?? [];
        $variables2 = $case['variables2'] ?? $variables;
        [$auth1, $auth2] = $case['auth'];
        [$expected1, $expected2] = $case['expected'];
        $errorsAllowed = (bool) ($case['errors'] ?? false);

        $output1 = $this->measuredRequest($case['query'], $variables, $operationName, $auth1, $test, true);
        $this->assertResponse($test, $output1, (array) $expected1, [], $errorsAllowed, 'first request');

        $output2 = $this->measuredRequest($case['query'], $variables2, $operationName, $auth2, $test, false);
        $this->assertResponse(
            $test,
            $output2,
            (array) $expected2,
            $case['forbidden'] ?? [],
            $errorsAllowed,
            'second request'
        );

        return [$output1, $output2];
    }

    /**
     * Run a request outside the comparison, e.g. to seed data. State is reset around it.
     *
     * @param array<string, mixed> $variables
     * @param array{email: string, password: string}|null $credentials
     * @return array<string, mixed> the decoded response
     */
    public function execute(string $query, array $variables = [], ?array $credentials = null): array
    {
        $token = $credentials === null ? null : $this->issueToken($credentials);
        $this->objectManagerForTest->_resetState();
        $output = $this->dispatch($query, $variables, self::operationName($query), $token);
        $this->objectManagerForTest->_resetState();

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The operation name of a single-operation document.
     */
    public static function operationName(string $query): string
    {
        if (!preg_match('/^\s*(?:query|mutation)\s+(\w+)/', $query, $match)) {
            throw new \InvalidArgumentException('Every state-test operation needs a name: ' . $query);
        }
        return $match[1];
    }

    /**
     * One request inside the comparison window, as GraphQlStateDiff::request() does it.
     *
     * @param array<string, mixed> $variables
     * @param array{email: string, password: string}|null $credentials
     */
    private function measuredRequest(
        string $query,
        array $variables,
        string $operationName,
        ?array $credentials,
        TestCase $test,
        bool $firstRequest
    ): string {
        $token = $credentials === null ? null : $this->issueToken($credentials);

        $this->objectManagerForTest->_resetState();
        $this->comparator->rememberObjectsStateBefore($firstRequest);
        $output = $this->dispatch($query, $variables, $operationName, $token);
        $this->objectManagerForTest->_resetState();
        $this->comparator->rememberObjectsStateAfter($firstRequest);

        $result = $this->comparator->compareBetweenRequests($operationName);
        $test->assertEmpty(
            $result,
            sprintf(
                '%s: %d objects changed state during request. Details: %s',
                $operationName,
                count($result),
                var_export($result, true)
            )
        );
        $result = $this->comparator->compareConstructedAgainstCurrent($operationName);
        $test->assertEmpty(
            $result,
            sprintf(
                '%s: %d objects changed state since constructed. Details: %s',
                $operationName,
                count($result),
                var_export($result, true)
            )
        );

        return $output;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function dispatch(string $query, array $variables, string $operationName, ?string $token): string
    {
        $request = $this->objectManagerForTest->get(RequestInterface::class);
        $request->setContent(json_encode([
            'query' => $query,
            'variables' => $variables ?: new \stdClass(),
            'operationName' => $operationName,
        ], JSON_THROW_ON_ERROR));
        $request->setMethod('POST');
        $request->setPathInfo('/graphql');
        $request->getHeaders()->addHeaders(['content_type' => self::CONTENT_TYPE]);
        if ($token !== null) {
            $request->getHeaders()->addHeaders(['Authorization' => 'Bearer ' . $token]);
        }

        $unusedResponse = $this->objectManagerForTest->create(HttpResponse::class);
        $httpApp = $this->objectManagerForTest->create(
            HttpApp::class,
            ['request' => $request, 'response' => $unusedResponse]
        );

        return (string) $httpApp->launch()->getContent();
    }

    /**
     * @param array{email: string, password: string} $credentials
     */
    private function issueToken(array $credentials): string
    {
        return $this->objectManagerForTest->get(CustomerTokenServiceInterface::class)
            ->createCustomerAccessToken($credentials['email'], $credentials['password']);
    }

    /**
     * @param string[] $expected
     * @param string[] $forbidden
     */
    private function assertResponse(
        TestCase $test,
        string $output,
        array $expected,
        array $forbidden,
        bool $errorsAllowed,
        string $which
    ): void {
        foreach ($expected as $needle) {
            $test->assertStringContainsString($needle, $output, "Unexpected $which response.");
        }
        foreach ($forbidden as $needle) {
            $test->assertStringNotContainsString(
                $needle,
                $output,
                "The $which leaked data of the previous request's customer."
            );
        }
        if (!$errorsAllowed) {
            $decoded = json_decode($output, true);
            $test->assertIsArray($decoded, "The $which did not return JSON: $output");
            $test->assertArrayNotHasKey('errors', $decoded, "The $which returned GraphQL errors: $output");
        }
    }
}
