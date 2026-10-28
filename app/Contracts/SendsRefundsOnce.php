<?php

namespace App\Contracts;

use App\Extensions\Gateways\RefundResult;
use App\Extensions\Gateways\RefundUnclear;
use App\Models\Transaction;
use RuntimeException;

/**
 * A gateway that sends each refund with a key of its own, so the same refund sent twice (no answer,
 * then a retry) sends the money back once. Nuvabill saves the try on the payment before it sends it,
 * and when the answer is lost it sends that same try again, with the same key and amount.
 * Gateways without it are refunded with PaymentGateway::refund(), as before.
 */
interface SendsRefundsOnce
{
    /**
     * Send part or all of a payment back, under this attempt key.
     *
     * @param  int  $amount  In minor units.
     * @param  string  $attemptKey  The same for every send of the same refund.
     *
     * @throws RefundUnclear When the gateway gave no clear answer (no connection, a server error,
     *                       too many requests), so the money may have been sent back.
     * @throws RuntimeException When the gateway refused the refund, so no money was sent back.
     */
    public function refundOnce(Transaction $payment, int $amount, string $attemptKey): RefundResult;

    /**
     * How many hours the gateway remembers a key. An unclear refund older than this is not sent
     * again, as the gateway could take it as a new refund; staff check it at the gateway instead.
     */
    public function refundKeyHours(): int;
}
