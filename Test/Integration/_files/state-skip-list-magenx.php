<?php
/**
 * State-comparator skip list for the stack this worker serves, keyed by
 * operation name like the core list: services the comparator may skip in
 * that operation. Copied next to the core lists with the filter lists.
 *
 * - mergeCarts (core GraphQlCustomerMutationsTest): Stripe's quote
 *   BeforeSave plugin is first built when the merged cart is saved, in the
 *   second request. It holds only its injected dependency.
 *
 * No Magenx\ class belongs here (CompanionSchemaCoverageTest fails on one).
 *
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

return [
    'mergeCarts' => [
        'StripeIntegration\Payments\Plugin\Quote\BeforeSave' => null,
    ],
];
