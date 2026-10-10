<?php

namespace App\Provisioning;

use App\Enums\InvoiceStatus;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceResourceOption;

/**
 * The core payment wait for paid extras (resource options): a server with extras the client has not
 * paid for yet is not made. It works the same for every module that sells resource options, so no
 * module needs its own check.
 */
final class OrderPaymentGate
{
    /**
     * Module data key on a service whose last setup was refused only because its extras were not
     * paid yet. Provisioner::waitsForPayment() reads it, so the setup runs again once the order
     * invoice is paid.
     */
    public const WAITING = 'resources_wait_for_payment';

    /**
     * Whether the service came with an order, has extras that cost money (active rows with a renewal
     * price, or extras lines on the order invoice such as setup fees), and that order is not settled
     * yet: its invoice is missing, not paid or another client's, the invoice has a credit note, or
     * the order waits for a staff review. A service without an order (made by staff or imported)
     * never waits here.
     */
    public static function extrasUnpaid(Service $service): bool
    {
        $order = $service->order()->with('invoice')->first();
        $invoiceId = $order?->invoice_id === null ? null : (int) $order->invoice_id;

        if ($order === null || ! self::extrasCostMoney($service, $invoiceId)) {
            return false;
        }

        $invoice = $order->invoice;

        return $invoice === null
            || (int) $invoice->client_id !== (int) $service->client_id
            || $invoice->status !== InvoiceStatus::Paid
            || $invoice->creditNotes()->exists()
            || $order->needs_review;
    }

    /**
     * Whether the service has extras that cost money: an active row with a renewal price, or an
     * extras line for the service on the order invoice (a setup fee, or the price of a cycle that
     * does not renew).
     */
    private static function extrasCostMoney(Service $service, ?int $invoiceId): bool
    {
        $recurring = ServiceResourceOption::query()
            ->where('service_id', $service->id)
            ->where('status', ServiceResourceOption::STATUS_ACTIVE)
            ->where('steps', '>', 0)
            ->where('recurring_amount', '>', 0)
            ->exists();

        return $recurring || ($invoiceId !== null && InvoiceItem::query()
            ->where('invoice_id', $invoiceId)
            ->where('service_id', $service->id)
            ->where('type', InvoiceItem::TYPE_RESOURCE)
            ->where('amount', '>', 0)
            ->exists());
    }
}
