<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Billing\Wallet;
use App\Contracts\PaymentGateway;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Gateways\RefundResult;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableGateway('stripe', ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_test']);
        $this->enableGateway('paypal', ['mode' => 'sandbox', 'client_id' => 'client-id', 'client_secret' => 'client-secret']);
        $this->signInAdmin();
    }

    public function test_staff_refund_a_stripe_payment_through_stripe(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'amount' => 1399, 'status' => 'succeeded'])]);
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');

        $this->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Send the money back through the payment gateway');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('status', 'Invoice refunded. Credit note CN-0001 was made.');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/refunds'
            && $request['payment_intent'] === 'pi_test_1'
            && (int) $request['amount'] === 1399);

        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertDatabaseHas('transactions', ['invoice_id' => $invoice->id, 'type' => 'refund', 'gateway' => 'stripe', 'reference' => 're_1', 'amount' => -1399]);
    }

    public function test_staff_refund_a_paypal_capture_through_paypal(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token-1']),
            'api-m.sandbox.paypal.com/v2/payments/captures/*' => Http::response(['id' => 'REFUND1', 'status' => 'COMPLETED'], 201),
        ]);
        $invoice = $this->paidInvoice(899, 'paypal', 'CAPTURE1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('status');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE1/refund'
            && $request['amount'] === ['currency_code' => 'USD', 'value' => '8.99']);

        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertDatabaseHas('transactions', ['invoice_id' => $invoice->id, 'type' => 'refund', 'reference' => 'REFUND1', 'amount' => -899]);
    }

    public function test_a_bank_transfer_refund_is_only_recorded(): void
    {
        Http::fake();
        $invoice = $this->paidInvoice(1000, 'banktransfer', 'BANK-REF-1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('status');

        Http::assertNothingSent();
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertDatabaseHas('transactions', ['invoice_id' => $invoice->id, 'type' => 'refund', 'reference' => null, 'amount' => -1000]);
    }

    public function test_staff_can_record_a_refund_without_calling_the_gateway(): void
    {
        Http::fake();
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '0']);

        Http::assertNothingSent();
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
    }

    public function test_a_refused_refund_keeps_the_invoice_paid_and_can_be_retried(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::sequence()
            ->push(['id' => 're_a', 'amount' => 500, 'status' => 'succeeded'])
            ->push(['error' => ['message' => 'Card network error']], 402)
            ->push(['id' => 're_b', 'amount' => 899, 'status' => 'succeeded'])]);

        $invoice = Invoice::factory()->create(['total' => 1399]);
        app(PaymentRecorder::class)->record($invoice, 500, 'stripe', 'pi_a');
        app(PaymentRecorder::class)->record($invoice, 899, 'stripe', 'pi_b');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('error', 'The refund did not finish: Stripe could not refund the payment: Card network error');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(1, $invoice->transactions()->where('type', 'refund')->count());

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('status');

        Http::assertSentCount(3);
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertSame(-1399, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
    }

    public function test_an_overpayment_is_refunded_only_up_to_the_invoice_total(): void
    {
        Http::fake();
        $invoice = Invoice::factory()->create(['total' => 1000]);
        app(PaymentRecorder::class)->record($invoice, 1200, 'banktransfer');

        $this->post(route('admin.invoices.refund', $invoice));

        $this->assertSame(-1000, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
    }

    public function test_an_unpaid_invoice_cannot_be_refunded(): void
    {
        $invoice = Invoice::factory()->create();

        $this->post(route('admin.invoices.refund', $invoice))
            ->assertSessionHas('error', 'Only paid invoices can be refunded.');

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
    }

    public function test_stripe_refund_sends_an_idempotency_key(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'amount' => 1399, 'status' => 'succeeded'])]);
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('status');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('Idempotency-Key')[0] ?? '', 'nuvabill-')
            && filled($request['metadata']['attempt'] ?? null));
        $this->assertArrayNotHasKey('refund_pending', $this->payment($invoice)->meta ?? []);
    }

    public function test_paypal_refund_sends_a_request_id(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token-1']),
            'api-m.sandbox.paypal.com/v2/payments/captures/*' => Http::response(['id' => 'REFUND1', 'status' => 'COMPLETED'], 201),
        ]);
        $invoice = $this->paidInvoice(899, 'paypal', 'CAPTURE1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            $id = $request->header('PayPal-Request-Id')[0] ?? '';

            return str_ends_with($request->url(), '/refund') && str_starts_with($id, 'nuvabill-') && strlen($id) <= 108;
        });
    }

    public function test_a_lost_stripe_answer_is_retried_with_the_same_key(): void
    {
        $calls = 0;
        Http::fake(['api.stripe.com/v1/refunds' => function (Request $request) use (&$calls) {
            if (++$calls === 1) {
                return Http::failedConnection('Timed out')($request);
            }

            return Http::response(['id' => 're_1', 'amount' => 1399, 'status' => 'succeeded']);
        }]);
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('error', 'The refund did not finish: The payment service did not answer, so the refund may have gone through. You can try again: the same refund is sent again, so the client is not refunded twice.');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(1399, $this->payment($invoice)->meta['refund_pending']['amount']);
        $this->get(route('admin.invoices.show', $invoice))->assertSee('The payment service did not answer an earlier refund');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('status');

        $keys = $this->sentKeys();
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame(1, $invoice->transactions()->where('type', 'refund')->count());
        $this->assertArrayNotHasKey('refund_pending', $this->payment($invoice)->meta);
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
    }

    public function test_retry_after_a_lost_answer_replays_the_same_amount_even_if_staff_change_it(): void
    {
        $calls = 0;
        Http::fake(['api.stripe.com/v1/refunds' => function (Request $request) use (&$calls) {
            if (++$calls === 1) {
                return Http::failedConnection('Timed out')($request);
            }

            return Http::response(['id' => 're_'.$calls, 'amount' => (int) $request['amount'], 'status' => 'succeeded']);
        }]);
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '5.00', 'method' => 'refund', 'through_gateway' => '1'])
            ->assertSessionHas('error');
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '7.00', 'method' => 'refund', 'through_gateway' => '1'])
            ->assertSessionHas('status');

        $requests = $this->refundRequests();
        $this->assertCount(3, $requests);
        $this->assertSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'));
        $this->assertSame(500, (int) $requests[1]['amount']);
        $this->assertNotSame($requests[1]->header('Idempotency-Key'), $requests[2]->header('Idempotency-Key'));
        $this->assertSame(200, (int) $requests[2]['amount']);
        $this->assertSame(-700, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
        $this->assertSame(700, (int) $invoice->creditNotes()->sum('total'));
    }

    public function test_a_smaller_retry_after_a_lost_answer_is_refused_so_the_credit_note_matches(): void
    {
        $calls = 0;
        Http::fake(['api.stripe.com/v1/refunds' => function (Request $request) use (&$calls) {
            if (++$calls === 1) {
                return Http::failedConnection('Timed out')($request);
            }

            return Http::response(['id' => 're_'.$calls, 'amount' => (int) $request['amount'], 'status' => 'succeeded']);
        }]);
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '5.00', 'method' => 'refund', 'through_gateway' => '1'])
            ->assertSessionHas('error');
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '3.00', 'method' => 'refund', 'through_gateway' => '1'])
            ->assertSessionHas('error');

        $this->assertCount(1, $this->refundRequests(), 'Nothing more is sent while the open try is bigger than the new amount.');
        $this->assertSame(0, (int) $invoice->creditNotes()->count());

        // The same amount again sends the open try again, and the credit note matches it.
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '5.00', 'method' => 'refund', 'through_gateway' => '1'])
            ->assertSessionHas('status');
        $this->assertSame(500, (int) $invoice->creditNotes()->sum('total'));
        $this->assertSame(-500, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
    }

    public function test_money_sent_back_after_a_lost_answer_never_exceeds_the_credit_notes(): void
    {
        $calls = 0;
        Http::fake(['api.stripe.com/v1/refunds' => function (Request $request) use (&$calls) {
            if (++$calls === 1) {
                return Http::failedConnection('Timed out')($request);
            }

            return Http::response(['id' => 're_'.$calls, 'amount' => (int) $request['amount'], 'status' => 'succeeded']);
        }]);
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '5.00', 'method' => 'refund', 'through_gateway' => '1'])->assertSessionHas('error');
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '5.00', 'method' => 'refund', 'through_gateway' => '1'])->assertSessionHas('status');
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '3.00', 'method' => 'refund', 'through_gateway' => '1'])->assertSessionHas('status');

        // Each key is one refund at Stripe, however often it was sent.
        $atStripe = collect($this->refundRequests())->unique(fn (Request $request): string => $request->header('Idempotency-Key')[0])->sum(fn (Request $request): int => (int) $request['amount']);

        $this->assertSame(800, $atStripe);
        $this->assertSame(800, (int) $invoice->creditNotes()->sum('total'));
        $this->assertSame(-800, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
    }

    public function test_a_clear_refusal_clears_the_pending_refund(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::sequence()
            ->push(['error' => ['message' => 'Charge already refunded']], 400)
            ->push(['id' => 're_1', 'amount' => 1399, 'status' => 'succeeded'])]);
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('error', 'The refund did not finish: Stripe could not refund the payment: Charge already refunded');
        $this->assertArrayNotHasKey('refund_pending', $this->payment($invoice)->meta ?? []);

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('status');

        $keys = $this->sentKeys();
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_an_unclear_refund_older_than_the_gateway_keeps_keys_is_not_sent_again(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::failedConnection('Timed out')]);
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('error');
        $this->travel(2)->days();

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('error', 'The refund did not finish: An earlier refund of this payment got no answer from the payment service. Check in the payment service whether it went through, then record the refund here without sending it through the gateway.');
        Http::assertSentCount(1);

        // Staff checked it in Stripe and record it themselves.
        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '0'])->assertSessionHas('status');
        Http::assertSentCount(1);
        $this->assertArrayNotHasKey('refund_pending', $this->payment($invoice)->meta);
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
    }

    public function test_a_wallet_refund_and_its_record_are_saved_together(): void
    {
        $client = Client::factory()->create(['credit' => 1000]);
        $invoice = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 1000]]);
        app(Wallet::class)->pay($invoice);
        $entries = CreditTransaction::query()->count();

        $fail = true;
        Transaction::creating(function (Transaction $transaction) use (&$fail): void {
            if ($fail && $transaction->type === 'refund') {
                throw new RuntimeException('Database write failed');
            }
        });

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('error');

        $this->assertSame(0, $client->fresh()->credit);
        $this->assertSame($entries, CreditTransaction::query()->count());

        $fail = false;
        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('status');

        $this->assertSame(1000, $client->fresh()->credit);
        $this->assertSame($entries + 1, CreditTransaction::query()->count());
    }

    public function test_gateways_without_the_new_contract_still_refund(): void
    {
        $gateway = $this->mock(PaymentGateway::class, function (MockInterface $mock): void {
            $mock->shouldReceive('supportsRefunds')->andReturn(true);
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('refund')->once()->andReturn(new RefundResult(1000, 'legacy-refund-1'));
        });
        $this->partialMock(ExtensionManager::class, fn (MockInterface $mock) => $mock->shouldReceive('gateway')->with('legacy')->andReturn($gateway));
        Http::fake();
        $invoice = $this->paidInvoice(1000, 'legacy', 'legacy-pay-1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('status');

        $this->assertDatabaseHas('transactions', ['invoice_id' => $invoice->id, 'type' => 'refund', 'reference' => 'legacy-refund-1', 'amount' => -1000]);
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
    }

    public function test_a_resend_that_fails_before_paypal_sees_it_keeps_the_pending_refund(): void
    {
        $tokens = 0;
        $refunds = 0;
        Http::fake([
            // The second token request fails, so the resend never reaches PayPal.
            'api-m.sandbox.paypal.com/v1/oauth2/token' => function () use (&$tokens) {
                return ++$tokens === 2 ? Http::response([], 503) : Http::response(['access_token' => 'token-'.$tokens]);
            },
            'api-m.sandbox.paypal.com/v2/payments/captures/*' => function (Request $request) use (&$refunds) {
                return ++$refunds === 1 ? Http::failedConnection('Timed out')($request) : Http::response(['id' => 'REFUND1', 'status' => 'COMPLETED'], 201);
            },
        ]);
        $invoice = $this->paidInvoice(899, 'paypal', 'CAPTURE1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('error');
        $key = $this->payment($invoice)->meta['refund_pending']['key'];

        Cache::flush();
        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('error');
        $this->assertSame($key, $this->payment($invoice)->meta['refund_pending']['key']);

        Cache::flush();
        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('status');

        $ids = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/refund'))
            ->map(fn (array $pair): string => $pair[0]->header('PayPal-Request-Id')[0])
            ->values()
            ->all();
        $this->assertCount(2, $ids);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame(1, $invoice->transactions()->where('type', 'refund')->count());
        $this->assertArrayNotHasKey('refund_pending', $this->payment($invoice)->meta);
    }

    public function test_the_wallet_cannot_be_credited_while_a_refund_is_unclear(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::failedConnection('Timed out')]);
        $this->setSettings(['wallet.enabled' => true]);
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');
        $invoice->client->update(['currency' => $invoice->currency]);

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '5.00', 'method' => 'refund', 'through_gateway' => '1'])->assertSessionHas('error');

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '13.99', 'method' => 'wallet'])
            ->assertSessionHasErrors(['amount' => 'A refund of this invoice got no answer from the payment service and may have gone through. Settle that refund before you credit the wallet.']);
        $this->assertSame(0, $invoice->client->fresh()->credit);
        $this->assertSame(0, $invoice->creditNotes()->count());
    }

    private function payment(Invoice $invoice): Transaction
    {
        return $invoice->transactions()->where('type', 'payment')->firstOrFail();
    }

    /**
     * @return list<Request>
     */
    private function refundRequests(): array
    {
        return Http::recorded(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/refunds')
            ->map(fn (array $pair): Request => $pair[0])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function sentKeys(): array
    {
        return array_map(fn (Request $request): string => $request->header('Idempotency-Key')[0], $this->refundRequests());
    }

    private function paidInvoice(int $total, string $gateway, string $reference): Invoice
    {
        $invoice = Invoice::factory()->create(['total' => $total]);
        app(PaymentRecorder::class)->record($invoice, $total, $gateway, $reference);

        return $invoice->fresh();
    }
}
