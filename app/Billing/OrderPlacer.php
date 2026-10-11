<?php

namespace App\Billing;

use App\Domains\DomainProvisioner;
use App\Enums\AutoSetup;
use App\Enums\DomainStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Events\OrderPlaced;
use App\Jobs\ProvisionService;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Domain;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceAddon;
use App\Models\ServiceResourceOption;
use App\Provisioning\Hardware\MachineInventory;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns a checked-out cart into an order, pending services and domains, and the first invoice.
 */
class OrderPlacer
{
    public function __construct(
        private InvoiceManager $invoices,
        private PaymentRecorder $payments,
        private TemplateMailer $mailer,
        private FraudChecker $fraud,
        private Wallet $wallet,
    ) {}

    /**
     * Each product line becomes a pending service with its add-ons and its extras (resource rows,
     * priced as the cart line says), and one invoice bills them all. A product sold from the
     * hardware inventory reserves its machine here. Coupons only ever take money off the plan
     * price, never off add-ons or extras.
     *
     * @param  Collection<int, CartLine>  $lines  Lines from the cart. With a coupon, they already carry their discounts.
     * @param  string|null  $ipCountry  The visitor's country from a trusted proxy, for the fraud check.
     *
     * @throws SoldOut When the order wants more of a product than is left (or no machine is free); nothing is made then.
     * @throws ResourceSoldOut When the order wants more steps of an extra than its stock has left; nothing is made then.
     * @throws CouponUnavailable When the coupon stopped working in the meantime; nothing is made then.
     */
    public function place(Client $client, Collection $lines, ?string $ipAddress = null, ?string $ipCountry = null, ?Coupon $coupon = null): Order
    {
        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Cannot place an empty order.');
        }

        if ($coupon === null && $lines->contains(fn (CartLine $line): bool => $line->discount > 0)) {
            throw new InvalidArgumentException('Discounted cart lines need their coupon.');
        }

        $fraudReasons = $this->fraud->reasons($client, $ipAddress, $ipCountry);

        // Up to three tries: on MySQL and MariaDB two checkouts of different products can still lock
        // each other out (the gaps between saved services are locked too). The database then stops
        // one of them; it is rolled back in full and made again.
        $order = DB::transaction(function () use ($client, $lines, $ipAddress, $fraudReasons, &$coupon): Order {
            // First, before anything is read: the client and coupon, then the products in stock, their
            // machines, the extras in stock and what holds their stock are locked, in the order plan
            // and resource changes use too (see lockCoupon and reserveStock). MySQL and MariaDB then
            // count the stock from the newest rows, not from an older snapshot.
            $checksCoupon = $coupon !== null && $lines->contains(fn (CartLine $line): bool => $line->discount > 0);
            $lockedCoupon = $checksCoupon ? $this->lockCoupon($client, $coupon) : null;

            $this->reserveStock($lines);

            // Then the coupon's limits are checked again, under its lock.
            if ($checksCoupon) {
                $coupon = $this->checkCoupon($client, $lockedCoupon);
            }

            $today = CarbonImmutable::today();

            $order = Order::create([
                'number' => $this->uniqueOrderNumber(),
                'client_id' => $client->id,
                'status' => OrderStatus::Pending,
                'needs_review' => $fraudReasons !== [],
                'fraud_reasons' => $fraudReasons ?: null,
                'currency' => $client->currency,
                'ip_address' => $ipAddress,
            ]);

            $items = [];
            $nameservers = $this->nameserversFor($lines);

            foreach ($lines as $line) {
                if ($line->isDomain()) {
                    $items = [...$items, ...$this->domainItems($client, $order, $line, $nameservers, $today, $coupon)];

                    continue;
                }

                $keepsDiscount = $coupon !== null && $line->discount > 0 && $coupon->isRecurring();

                $service = Service::create([
                    'client_id' => $client->id,
                    'order_id' => $order->id,
                    'product_id' => $line->product->id,
                    'server_id' => $line->product->server_id,
                    'domain' => $line->domain,
                    'status' => ServiceStatus::Pending,
                    'billing_cycle' => $line->cycle,
                    'currency' => $client->currency,
                    'first_payment_amount' => $line->dueToday(),
                    'recurring_amount' => $line->cycle->isRecurring() ? $line->price : 0,
                    'registration_date' => $today,
                    'next_due_date' => $today,
                    'coupon_id' => $keepsDiscount ? $coupon->id : null,
                    'coupon_payments_left' => $keepsDiscount && $coupon->recurring === Coupon::RECURRING_COUNT ? $coupon->recurring_count - 1 : null,
                ]);

                $service->setRelation('product', $line->product);

                if ($line->product->uses_machines) {
                    // The free linked machine with the lowest id, under the locks reserveStock() took.
                    app(MachineInventory::class)->reserveFor($service);
                }

                $items[] = LineItems::servicePeriod($service, $today, $line->price);

                if ($line->setupFee > 0) {
                    $items[] = LineItems::setupFee($service, $line->setupFee);
                }

                foreach ($line->addons as $addon) {
                    $service->addons()->create([
                        'product_addon_id' => $addon['id'],
                        'name' => $addon['name'],
                        'recurring_amount' => $line->cycle->isRecurring() ? $addon['price'] : 0,
                        'status' => ServiceAddon::STATUS_ACTIVE,
                    ]);

                    $items[] = LineItems::addonPeriod($service, $addon['name'], $today, $addon['price']);

                    if ($addon['setup_fee'] > 0) {
                        $items[] = ['type' => InvoiceItem::TYPE_ADDON, 'description' => __('Setup fee').' - '.$addon['name'], 'amount' => $addon['setup_fee'], 'service_id' => $service->id];
                    }
                }

                $items = [...$items, ...$this->resourceItems($service, $line, $today)];

                if ($coupon !== null && $line->discount > 0) {
                    $items[] = LineItems::discount($coupon, $line->discount, $service);
                }
            }

            $invoice = $this->invoices->create($client, $items, dueAt: $today);
            $discount = (int) $lines->sum(fn (CartLine $line): int => $line->discount);
            $order->update(['invoice_id' => $invoice->id, 'total' => $invoice->total, 'coupon_id' => $discount > 0 ? $coupon?->id : null, 'discount' => $discount]);

            if ($coupon !== null && $discount > 0) {
                // Counted only while uses are left, so the limit holds even where rows cannot be locked.
                $counted = Coupon::query()->whereKey($coupon->id)
                    ->where(fn ($query) => $query->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'))
                    ->increment('uses');

                if ($counted === 0) {
                    throw CouponUnavailable::because(__('This coupon has been used up.'));
                }

                $coupon->redemptions()->create(['client_id' => $client->id, 'order_id' => $order->id, 'invoice_id' => $invoice->id, 'amount' => $discount, 'currency' => $client->currency]);
            }

            return $order;
        }, 3);

        $order->load('invoice', 'services.product', 'domains');

        Activity::log('order.placed', "Order #{$order->number} placed", $order, $client);

        if ($order->needs_review) {
            Activity::log('order.review', "Order #{$order->number} needs a review: ".implode(' ', $fraudReasons), $order, $client);
        } else {
            foreach ($order->services as $service) {
                if ($service->product->auto_setup === AutoSetup::OnOrder) {
                    ProvisionService::dispatch($service);
                }
            }
        }

        if ($order->invoice->total === 0) {
            $this->payments->settleFreeInvoice($order->invoice);
        } elseif ($this->wallet->applyAutomatically($order->invoice) > 0) {
            $order->invoice->refresh();
        }

        $this->mailer->send('order.confirmation', $client, TemplateMailer::invoiceContext($order->invoice) + [
            'order' => ['number' => $order->number, 'total' => money($order->total, $order->currency)],
        ]);

        $this->mailer->sendToStaff('admin.new_order', [
            'client' => TemplateMailer::clientContext($client),
            'order' => ['number' => $order->number, 'total' => money($order->total, $order->currency)],
            'admin_url' => route('admin.orders.show', $order),
        ]);

        $order->refresh();

        OrderPlaced::dispatch($order);

        return $order;
    }

    /**
     * @param  list<string>  $nameservers
     * @return list<array<string, mixed>>
     */
    private function domainItems(Client $client, Order $order, CartLine $line, array $nameservers, CarbonImmutable $today, ?Coupon $coupon): array
    {
        $domain = Domain::create([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'name' => $line->domain,
            'tld' => $line->tldPrice->tld,
            'registrar' => $line->tldPrice->registrar,
            'order_type' => $line->domainAction,
            'status' => DomainStatus::Pending,
            'years' => $line->years,
            'currency' => $client->currency,
            'first_payment_amount' => $line->dueToday(),
            'recurring_amount' => $line->tldPrice->priceFor('renew', $line->years),
            'nameservers' => $nameservers,
            'epp_code' => $line->eppCode,
        ]);

        $type = $line->domainAction === Domain::TYPE_TRANSFER ? InvoiceItem::TYPE_DOMAIN_TRANSFER : InvoiceItem::TYPE_DOMAIN_REGISTER;

        return array_values(array_filter([
            LineItems::domainPeriod($domain, $type, $line->years, $line->price, $today),
            $coupon !== null && $line->discount > 0 ? LineItems::discount($coupon, $line->discount, domain: $domain) : null,
        ]));
    }

    /**
     * Point new domains at the hosting server ordered with them, or at the default nameservers.
     *
     * @param  Collection<int, CartLine>  $lines
     * @return list<string>
     */
    private function nameserversFor(Collection $lines): array
    {
        $fromServer = $lines->first(fn (CartLine $line): bool => ! $line->isDomain() && $line->product?->server?->nameservers)?->product->server->nameservers;

        return array_values(array_filter((array) ($fromServer ?: DomainProvisioner::defaultNameservers())));
    }

    /**
     * The cart checked the coupon before the order started, but another checkout may have used it
     * since. The client and the coupon are locked until the order is made. Run inside the order's
     * database transaction, before anything else is read. Returns null when the coupon is gone.
     */
    private function lockCoupon(Client $client, Coupon $coupon): ?Coupon
    {
        // Client first, then coupon, then products (in id order), then services. Plan changes keep the
        // same order, with the change's own row before the client, so no two sales wait for each
        // other in a circle.
        Client::query()->whereKey($client->id)->lockForUpdate()->first();

        return Coupon::query()->lockForUpdate()->find($coupon->id);
    }

    /**
     * Check the locked coupon's limits again (uses left, uses per client, new clients only).
     *
     * @throws CouponUnavailable
     */
    private function checkCoupon(Client $client, ?Coupon $locked): Coupon
    {
        $reason = $locked === null ? __('This coupon has ended.') : $locked->unavailableReason($client, $client->currency);

        if ($reason !== null) {
            throw CouponUnavailable::because($reason);
        }

        return $locked;
    }

    /**
     * Products with a stock limit (or sold from the hardware inventory), extras with a stock limit,
     * and what holds their stock are locked until the order is made, so two checkouts at once cannot
     * both take the last one. Run inside the order's database transaction, before anything is read.
     *
     * Locks follow the one order every checkout and change uses (see Product::lockedStockLeft()):
     * the products in id order, then the free machines of hardware products, then the extras'
     * options in id order, then the products' services, then the services' extras rows.
     *
     * @param  Collection<int, CartLine>  $lines
     *
     * @throws SoldOut
     * @throws ResourceSoldOut
     */
    private function reserveStock(Collection $lines): void
    {
        $products = $lines->reject(fn (CartLine $line): bool => $line->isDomain());
        $wanted = $products->countBy(fn (CartLine $line): int => $line->product->id);

        if ($wanted->isEmpty()) {
            return;
        }

        // All the products first, in id order, so two carts with the same products lock them in the
        // same order.
        $limited = Product::query()
            ->whereKey($wanted->keys()->all())
            ->where(fn (Builder $query): Builder => $query->whereNotNull('stock')->orWhere('uses_machines', true))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        [$hardware, $counted] = $limited->partition(fn (Product $product): bool => $product->uses_machines);

        // Hardware products count (and lock) their free machines first.
        foreach ($hardware as $product) {
            $this->checkProductStock($product, $wanted[$product->id]);
        }

        $options = $this->lockResourceOptions($products);

        // Then the other products count (and lock) their services, and the options their rows.
        foreach ($counted as $product) {
            $this->checkProductStock($product, $wanted[$product->id]);
        }

        $this->checkResourceStock($options);
    }

    /**
     * @throws SoldOut
     */
    private function checkProductStock(Product $product, int $wanted): void
    {
        $left = $product->lockedStockLeft();

        if ($left !== null && $wanted > $left) {
            throw new SoldOut($product, $left);
        }
    }

    /**
     * Lock the options with a stock limit of the extras the order wants, in id order, and return
     * how many steps the order wants of each.
     *
     * @param  Collection<int, CartLine>  $lines
     * @return array<int, array{option: ResourceOption, steps: int}> option id => the locked option and the steps wanted
     */
    private function lockResourceOptions(Collection $lines): array
    {
        $wanted = [];

        foreach ($lines as $line) {
            foreach ($line->resources as $resource) {
                $wanted[$resource['option_id']] = ($wanted[$resource['option_id']] ?? 0) + $resource['steps'];
            }
        }

        if ($wanted === []) {
            return [];
        }

        $options = ResourceOption::query()->whereKey(array_keys($wanted))->whereNotNull('stock')->orderBy('id')->lockForUpdate()->get();

        return $options->mapWithKeys(fn (ResourceOption $option): array => [$option->id => ['option' => $option, 'steps' => $wanted[$option->id]]])->all();
    }

    /**
     * Count the stock left of each locked option from the newest saved rows (locking them).
     *
     * @param  array<int, array{option: ResourceOption, steps: int}>  $wanted
     *
     * @throws ResourceSoldOut
     */
    private function checkResourceStock(array $wanted): void
    {
        foreach ($wanted as ['option' => $option, 'steps' => $steps]) {
            $left = $option->lockedStockLeft();

            if ($left !== null && $steps > $left) {
                throw new ResourceSoldOut($option, $left);
            }
        }
    }

    /**
     * The extras of one product line: a resource row per kind on the new service, and its invoice
     * lines (the first period, and the setup fee when there is one). A cycle that does not renew
     * bills the extras once, so the row's renewal price is 0.
     *
     * @return list<array<string, mixed>>
     */
    private function resourceItems(Service $service, CartLine $line, CarbonImmutable $today): array
    {
        $items = [];
        $options = $line->product->relationLoaded('resourceOptions') ? $line->product->resourceOptions->keyBy('id') : collect();

        foreach ($line->resources as $resource) {
            $row = $service->resources()->create([
                'resource_option_id' => $resource['option_id'],
                'kind' => $resource['kind'],
                'unit' => $resource['unit'],
                'step_size' => $resource['step_size'],
                'steps' => $resource['steps'],
                'recurring_amount' => $line->cycle->isRecurring() ? $resource['price'] : 0,
                'status' => ServiceResourceOption::STATUS_ACTIVE,
            ]);

            if ($options->has($resource['option_id'])) {
                $row->setRelation('option', $options->get($resource['option_id']));
            }

            $items[] = LineItems::resourcePeriod($service, $row, $today, $resource['price']);

            if ($resource['setup_fee'] > 0) {
                $items[] = LineItems::resourceSetupFee($service, $row, $resource['setup_fee']);
            }
        }

        return $items;
    }

    private function uniqueOrderNumber(): string
    {
        do {
            $number = (string) random_int(1000000, 9999999);
        } while (Order::query()->where('number', $number)->exists());

        return $number;
    }
}
