<?php

namespace App\Billing;

use App\Contracts\PaymentGateway;
use App\Contracts\SendsRefundsOnce;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Gateways\RefundResult;
use App\Extensions\Gateways\RefundUnclear;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Sends money paid on an invoice back: through the gateway when asked and the gateway can do it,
 * into the wallet for wallet payments, or only recorded. CreditNotes decides how much and why.
 */
class RefundIssuer
{
    public function __construct(
        private ExtensionManager $extensions,
        private Wallet $wallet,
    ) {}

    /**
     * Refund what no credit note took back yet, with a credit note, and mark the invoice Refunded.
     * Kept for add-ons written before 0.6.7; new code uses CreditNotes::issue().
     *
     * @throws RuntimeException When a gateway refuses a refund, or another refund of this invoice is running.
     */
    public function refund(Invoice $invoice, bool $throughGateway): Invoice
    {
        app(CreditNotes::class)->issue($invoice, $invoice->creditableAmount(), CreditNote::METHOD_REFUND, __('Refund'), null, $throughGateway);

        return $invoice->fresh();
    }

    /**
     * Send back part or all of what was paid on the invoice: each payment gets a matching refund
     * transaction (a negative amount), oldest payment first. Credit notes call this; the invoice
     * status is theirs to change.
     *
     * Payments already refunded are skipped, so running this again after a gateway error is safe.
     * A refund whose answer was lost is saved on its payment and sent again as it was (same key and
     * amount) by gateways that keep refund keys, so it is not sent back twice.
     *
     * @param  bool  $throughGateway  Send the money back through each payment's gateway where it supports refunds.
     *                                Other payments are only recorded as refunded.
     * @return int The amount refunded, in minor units.
     *
     * @throws RuntimeException When a gateway refuses a refund, or another refund of this invoice is running.
     */
    public function refundAmount(Invoice $invoice, int $amount, bool $throughGateway): int
    {
        // Each payment can take two gateway calls of up to 30 seconds (an unclear try sent again, then the rest).
        $lock = Cache::lock("invoice-refund-{$invoice->id}", 300);

        if (! $lock->get()) {
            throw new RuntimeException(__('This invoice is already being refunded. Try again in a minute.'));
        }

        try {
            $invoice = $invoice->fresh(['transactions']);

            if ($invoice->status !== InvoiceStatus::Paid) {
                throw new InvalidArgumentException('Only paid invoices can be refunded.');
            }

            $refunds = $invoice->transactions->where('type', 'refund');
            $remaining = min($amount, $invoice->total + (int) $refunds->sum('amount'));
            $refunded = 0;

            foreach ($invoice->transactions->where('type', 'payment')->sortBy('id') as $payment) {
                if ($remaining <= 0) {
                    break;
                }

                $alreadyRefunded = -(int) $refunds->filter(fn (Transaction $refund): bool => ($refund->meta['refund_of'] ?? null) === $payment->id)->sum('amount');
                $part = min($payment->amount - $alreadyRefunded, $remaining);

                if ($part > 0) {
                    $sent = $this->refundPayment($payment, $part, $throughGateway, $payment->amount - $alreadyRefunded);
                    $remaining -= $sent;
                    $refunded += $sent;
                }
            }
        } finally {
            $lock->release();
        }

        return $refunded;
    }

    /**
     * Whether this payment's money can be sent back through its gateway.
     */
    public function canRefundThroughGateway(Transaction $payment): bool
    {
        return $payment->gateway === Wallet::GATEWAY || $this->refundingGateway($payment) !== null;
    }

    /**
     * Whether a refund of this payment got no answer from the gateway and is not settled yet.
     */
    public function hasUnclearRefund(Transaction $payment): bool
    {
        return $this->pendingRefund($payment) !== null;
    }

    /**
     * @param  int  $available  What is left to refund of this payment, in minor units.
     * @return int The amount refunded, in minor units.
     */
    private function refundPayment(Transaction $payment, int $amount, bool $throughGateway, int $available): int
    {
        if ($throughGateway && $payment->gateway === Wallet::GATEWAY) {
            return $this->refundToWallet($payment, $amount);
        }

        $gateway = $throughGateway ? $this->refundingGateway($payment) : null;

        if ($gateway instanceof SendsRefundsOnce) {
            return $this->refundOnce($payment, $gateway, $amount, $available);
        }

        // Only a gateway that keeps refund keys can send an unclear refund again. Staff who record
        // the refund without the gateway have checked it there, so that settles it.
        if ($throughGateway && $this->pendingRefund($payment->refresh()) !== null) {
            throw new RuntimeException($this->checkAtGatewayMessage());
        }

        $result = $gateway?->refund($payment, $amount);
        $refunded = $result?->amount ?? $amount;

        DB::transaction(function () use ($payment, $refunded, $result): void {
            $this->recordRefund($payment, $refunded, $result);
            $this->savePendingRefund($payment, null);
        });

        return $refunded;
    }

    /**
     * Send a refund through a gateway that keeps refund keys. The try is saved on the payment before
     * it is sent. When its answer is lost, that same try (same key, same amount) is sent again
     * first, whatever amount is asked for now, so the gateway sends the money back only once.
     * Stripe keeps keys for about a day and PayPal for a few hours (refundKeyHours()); an older
     * unclear try is not sent again, and staff check it at the gateway.
     *
     * @return int The amount refunded, in minor units.
     */
    private function refundOnce(Transaction $payment, PaymentGateway&SendsRefundsOnce $gateway, int $amount, int $available): int
    {
        $payment->refresh();
        $refunded = 0;
        $pending = $this->pendingRefund($payment);

        if ($pending !== null) {
            $since = rescue(fn (): CarbonImmutable => CarbonImmutable::parse($pending['since']), null, false);

            if ($since === null || $since->lt(now()->subHours(max(0, $gateway->refundKeyHours()))) || $pending['amount'] > $available) {
                throw new RuntimeException($this->checkAtGatewayMessage());
            }

            // Sending the open try again would refund more than asked for now, and the credit note
            // would then show less than the client got back.
            if ($pending['amount'] > $amount) {
                throw new RuntimeException(__('An earlier refund of :amount got no answer from the payment service. Refund at least :amount, so that same refund is sent again first.', [
                    'amount' => money($pending['amount'], $payment->currency),
                ]));
            }

            $refunded = $this->sendRefund($payment, $gateway, $pending, replay: true);
            $amount -= $refunded;
            $available -= $refunded;
        }

        $amount = min($amount, $available);

        if ($amount > 0) {
            // The random part gives a try that was refused its own new key next time.
            $attempt = [
                'key' => 'payment-'.$payment->id.'-refund-'.($this->refundCount($payment) + 1).'-'.$amount.'-'.Str::lower(Str::random(10)),
                'amount' => $amount,
                'gateway' => (string) $payment->gateway,
                'since' => now()->toIso8601String(),
            ];
            $this->savePendingRefund($payment, $attempt);
            $refunded += $this->sendRefund($payment, $gateway, $attempt);
        }

        return $refunded;
    }

    /**
     * @param  array{key: string, amount: int, gateway: string, since: string}  $attempt
     * @param  bool  $replay  Whether this sends again a try whose answer was lost.
     * @return int The amount refunded, in minor units.
     */
    private function sendRefund(Transaction $payment, PaymentGateway&SendsRefundsOnce $gateway, array $attempt, bool $replay = false): int
    {
        try {
            $result = $gateway->refundOnce($payment, $attempt['amount'], $attempt['key']);
        } catch (RefundUnclear|ConnectionException $exception) {
            // The try stays saved, so the next refund sends it again instead of a new one.
            report($exception);

            throw new RefundUnclear(__('The payment service did not answer, so the refund may have gone through. You can try again: the same refund is sent again, so the client is not refunded twice.'), previous: $exception);
        } catch (Throwable $exception) {
            // A clear refusal of a new try: no money went back, so the next try is a new one. A try sent
            // again stays saved: the error may come before the gateway saw it (no access token, for
            // example), and the first send may still have gone through. Staff settle it at the gateway.
            if (! $replay) {
                $this->savePendingRefund($payment, null);
            }

            throw $exception;
        }

        DB::transaction(function () use ($payment, $result, $attempt): void {
            $this->recordRefund($payment, $result->amount, $result, $attempt['key']);
            $this->savePendingRefund($payment, null);
        });

        return $result->amount;
    }

    private function recordRefund(Transaction $payment, int $amount, ?RefundResult $result, ?string $attemptKey = null): Transaction
    {
        return Transaction::create([
            'client_id' => $payment->client_id,
            'invoice_id' => $payment->invoice_id,
            'gateway' => $payment->gateway,
            'reference' => $result?->reference,
            'type' => 'refund',
            'amount' => -$amount,
            'currency' => $payment->currency,
            'meta' => ['refund_of' => $payment->id, 'through_gateway' => $result !== null]
                + ($attemptKey === null ? [] : ['refund_attempt' => $attemptKey])
                + ($result->meta ?? []),
            'paid_at' => now(),
        ]);
    }

    /**
     * The refund of this payment whose answer was lost, if there is one.
     *
     * @return array{key: string, amount: int, gateway: string, since: string}|null
     */
    private function pendingRefund(Transaction $payment): ?array
    {
        $pending = $payment->meta['refund_pending'] ?? null;

        if (! is_array($pending) || ! is_string($pending['key'] ?? null) || $pending['key'] === '' || ! is_int($pending['amount'] ?? null) || $pending['amount'] <= 0) {
            return null;
        }

        return [
            'key' => $pending['key'],
            'amount' => $pending['amount'],
            'gateway' => (string) ($pending['gateway'] ?? ''),
            'since' => (string) ($pending['since'] ?? ''),
        ];
    }

    /**
     * @param  array{key: string, amount: int, gateway: string, since: string}|null  $attempt  Null clears it.
     */
    private function savePendingRefund(Transaction $payment, ?array $attempt): void
    {
        $meta = (array) ($payment->meta ?? []);

        if ($attempt === null && ! array_key_exists('refund_pending', $meta)) {
            return;
        }

        if ($attempt === null) {
            unset($meta['refund_pending']);
        } else {
            $meta['refund_pending'] = $attempt;
        }

        $payment->forceFill(['meta' => $meta])->save();
    }

    private function refundCount(Transaction $payment): int
    {
        return Transaction::query()
            ->where('invoice_id', $payment->invoice_id)
            ->where('type', 'refund')
            ->get(['meta'])
            ->filter(fn (Transaction $refund): bool => ($refund->meta['refund_of'] ?? null) === $payment->id)
            ->count();
    }

    private function checkAtGatewayMessage(): string
    {
        return __('An earlier refund of this payment got no answer from the payment service. Check in the payment service whether it went through, then record the refund here without sending it through the gateway.');
    }

    /**
     * Money paid from the wallet goes back into it. The wallet credit and its refund record are
     * saved together, so a retry after an error cannot credit the wallet twice.
     */
    private function refundToWallet(Transaction $payment, int $amount): int
    {
        $payment->loadMissing('invoice.client');

        DB::transaction(function () use ($payment, $amount): void {
            $entry = $this->wallet->change($payment->invoice->client, $amount, __('Refund of invoice :number', ['number' => $payment->invoice->displayNumber()]), $payment->invoice);

            Transaction::create([
                'client_id' => $payment->client_id,
                'invoice_id' => $payment->invoice_id,
                'gateway' => Wallet::GATEWAY,
                'reference' => 'wallet-'.$entry->id,
                'type' => 'refund',
                'amount' => -$amount,
                'currency' => $payment->currency,
                'meta' => ['refund_of' => $payment->id, 'through_gateway' => true],
                'paid_at' => now(),
            ]);
        });

        return $amount;
    }

    private function refundingGateway(Transaction $payment): ?PaymentGateway
    {
        if (blank($payment->reference)) {
            return null;
        }

        try {
            $gateway = $this->extensions->gateway($payment->gateway);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $gateway->supportsRefunds() && $gateway->isConfigured() ? $gateway : null;
    }
}
