<?php
/**
 * State-comparator skip list for the stack this worker serves, keyed by
 * operation name like the core list: services the comparator may skip in
 * that operation. Copied next to the core lists with the filter lists.
 *
 * - Every operation: the DI config reader (SchemaLocator, Reader\Dom), which
 *   the object manager builds only when an area's DI config misses the
 *   cache, e.g. after a fixture or seed cleaned it. It reads XML files and
 *   holds no request state.
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
    '*' => [
        'Magento\Framework\ObjectManager\Config\SchemaLocator' => null,
        'Magento\Framework\ObjectManager\Config\Reader\Dom' => null,
    ],
    'mergeCarts' => [
        'StripeIntegration\Payments\Plugin\Quote\BeforeSave' => null,
    ],
];
