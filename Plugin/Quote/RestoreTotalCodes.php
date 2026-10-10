<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AppServer\Plugin\Quote;

use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use Magento\Quote\Model\Quote\Address\Total\Collector;

/**
 * Gives quote totals back the code that the request reset cleared.
 *
 * Collector builds each total once and sets its code, the key the total is
 * registered under. TotalsCollectorList keeps that collector for the life of
 * the worker, and core AbstractTotal::_resetState() sets every total's code
 * to null after each request. Only totals that override _resetState() to set
 * it again (core Shipping) keep theirs; the rest, Stripe's InitialFee and
 * StripeFlatFees among them, would collect and fetch under a null code from
 * the second request on. _resetState() cannot take a plugin, so the code is
 * put back here, where every collect and fetch gets the totals.
 *
 * Under PHP-FPM nothing resets the totals and this changes nothing.
 */
class RestoreTotalCodes
{
    /**
     * Set a null total code back to the code the total is registered under.
     *
     * @param Collector $subject
     * @param AbstractTotal[] $result
     * @return AbstractTotal[]
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetCollectors(Collector $subject, array $result): array
    {
        foreach ($result as $code => $total) {
            if ($total->getCode() === null) {
                $total->setCode($code);
            }
        }
        return $result;
    }
}
