<?php
/**
 * State-comparator filter list for the stack this worker serves: properties
 * the comparator flags although they carry no request state. Copied next to
 * the core lists like state-filter-list-mageos.php.
 *
 * - AbstractDb::$_uniqueFields: filled from the resource model's own
 *   definition on its first save, the same for every request.
 * - The _code of totals that set it only in their constructor. Core
 *   AbstractTotal::_resetState() clears it, and _resetState() cannot take a
 *   plugin, so Plugin\Quote\RestoreTotalCodes puts it back from the collector
 *   on the next use instead. The comparator looks between requests, when the
 *   code is still empty.
 * - Stripe InitialFee's lazily built helper, and its subscriptions setting,
 *   read once per process. Both are totals' private state that reset.json
 *   cannot reach (the class has its own _resetState()); the setting is
 *   stale until the worker recycles (STATE_AUDIT.md).
 *
 * - QueryParser::$parsedQueries: the parsed document per query text, the
 *   same for every customer. Core counts it from the first measured request
 *   on (the parser is usually built there); a seed or token warm-up request
 *   builds it earlier, and the comparator then reports the measured query's
 *   entry as left behind. (In the worker it holds one entry per allowlisted
 *   query text.)
 *
 * Classes that are not installed never match. No Magenx\ class belongs here
 * (CompanionSchemaCoverageTest fails on one): fix those with _resetState() or
 * etc/reset.json instead.
 *
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

return [
    'parents' => [
        'Magento\Framework\Model\ResourceModel\Db\AbstractDb' => ['_uniqueFields' => null],
        'Magento\Framework\GraphQl\Query\QueryParser' => ['parsedQueries' => null],
        'StripeIntegration\Payments\Model\InitialFee' => [
            '_code' => null,
            'initialFeeHelper' => null,
            'isSubscriptionsEnabled' => null,
        ],
        'StripeIntegration\Tax\Model\Quote\Total\StripeFlatFees' => ['_code' => null],
    ],
];
