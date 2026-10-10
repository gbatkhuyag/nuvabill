<?php

namespace App\Billing;

use App\Models\Coupon;
use App\Models\Domain;
use App\Models\InvoiceItem;
use App\Models\ResourceChangeItem;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use Carbon\CarbonImmutable;

/**
 * Builds invoice lines for services, their extras and domains so orders and renewals describe
 * periods the same way.
 */
class LineItems
{
    /**
     * Longest description an invoice line holds (the column holds 255 characters).
     */
    public const DESCRIPTION_MAX = 255;

    /**
     * @return array{type: string, description: string, amount: int, service_id: int, period_start: CarbonImmutable, period_end: CarbonImmutable|null}
     */
    public static function servicePeriod(Service $service, CarbonImmutable $start, int $amount): array
    {
        $cycle = $service->billing_cycle;
        // The day the service started keeps renewals on it after a short month (see BillingCycle::advance).
        $end = $cycle->isRecurring() ? $cycle->advance($start, $service->registration_date?->day)->subDay() : null;

        return [
            'type' => InvoiceItem::TYPE_SERVICE,
            'description' => self::describe($service->product->name, $service->domain, $end !== null ? ' ('.$start->format('d M Y').' - '.$end->format('d M Y').')' : ''),
            'amount' => $amount,
            'service_id' => $service->id,
            'period_start' => $start,
            'period_end' => $end,
        ];
    }

    /**
     * A domain registration, transfer or renewal for the given years from the start date.
     *
     * @param  string  $type  One of the InvoiceItem::TYPE_DOMAIN_* constants.
     * @return array{type: string, description: string, amount: int, domain_id: int, period_start: CarbonImmutable, period_end: CarbonImmutable}
     */
    public static function domainPeriod(Domain $domain, string $type, int $years, int $amount, CarbonImmutable $start): array
    {
        $end = $start->addYearsNoOverflow($years)->subDay();

        $label = match ($type) {
            InvoiceItem::TYPE_DOMAIN_TRANSFER => __('Domain transfer'),
            InvoiceItem::TYPE_DOMAIN_RENEW => __('Domain renewal'),
            default => __('Domain registration'),
        };

        $period = trans_choice(':count year|:count years', $years, ['count' => $years]);

        return [
            'type' => $type,
            'description' => self::describe($label, $domain->name, ' ('.$period.($type === InvoiceItem::TYPE_DOMAIN_RENEW ? ', '.$start->format('d M Y').' - '.$end->format('d M Y') : '').')'),
            'amount' => $amount,
            'domain_id' => $domain->id,
            'period_start' => $start,
            'period_end' => $end,
        ];
    }

    /**
     * @return array{type: string, description: string, amount: int, service_id: int}
     */
    public static function setupFee(Service $service, int $amount): array
    {
        return [
            'type' => InvoiceItem::TYPE_SETUP,
            'description' => self::describe(__('Setup fee'), $service->product->name),
            'amount' => $amount,
            'service_id' => $service->id,
        ];
    }

    /**
     * A product add-on billed with its service for the same period as the service line.
     *
     * @return array{type: string, description: string, amount: int, service_id: int, period_start: CarbonImmutable, period_end: CarbonImmutable|null}
     */
    public static function addonPeriod(Service $service, string $name, CarbonImmutable $start, int $amount): array
    {
        $period = self::servicePeriod($service, $start, $amount);
        $end = $period['period_end'];

        return [
            'type' => InvoiceItem::TYPE_ADDON,
            'description' => self::describe($name, $service->domain, $end !== null ? ' ('.$start->format('d M Y').' - '.$end->format('d M Y').')' : ''),
            'amount' => $amount,
            'service_id' => $service->id,
            'period_start' => $start,
            'period_end' => $end,
        ];
    }

    /**
     * A service's extras (one resource row) billed for the same period as the service line, such as
     * "RAM +4 GB - vps1.example.com (10 Oct 2026 - 9 Nov 2026)". A renewal that bills a change
     * planned for that day passes the new number of steps. A row that is not saved yet (a kind a
     * planned change adds) gives a line without service_resource_option_id.
     *
     * @return array{type: string, description: string, amount: int, service_id: int, service_resource_option_id: int|null, period_start: CarbonImmutable, period_end: CarbonImmutable|null}
     */
    public static function resourcePeriod(Service $service, ServiceResourceOption $row, CarbonImmutable $start, int $amount, ?int $steps = null): array
    {
        $period = self::servicePeriod($service, $start, $amount);
        $end = $period['period_end'];

        return [
            'type' => InvoiceItem::TYPE_RESOURCE,
            'description' => self::describe(self::rowText($row, $steps), $service->domain, $end !== null ? ' ('.$start->format('d M Y').' - '.$end->format('d M Y').')' : ''),
            'amount' => $amount,
            'service_id' => $service->id,
            'service_resource_option_id' => $row->getKey(),
            'period_start' => $start,
            'period_end' => $end,
        ];
    }

    /**
     * The one-time setup fee of a service's extras: "Setup fee - RAM +4 GB".
     *
     * @return array{type: string, description: string, amount: int, service_id: int, service_resource_option_id: int|null}
     */
    public static function resourceSetupFee(Service $service, ServiceResourceOption $row, int $amount): array
    {
        return [
            'type' => InvoiceItem::TYPE_RESOURCE,
            'description' => self::describe(__('Setup fee'), self::rowText($row)),
            'amount' => $amount,
            'service_id' => $service->id,
            'service_resource_option_id' => $row->getKey(),
        ];
    }

    /**
     * A change of a service's extras for the rest of its period, such as
     * "RAM +2 GB → +5 GB - vps1.example.com (15 Oct 2026 - 31 Oct 2026)". The amount is what the
     * days left cost (or, negative, what they give back).
     *
     * @return array{type: string, description: string, amount: int, service_id: int, service_resource_option_id: int|null, period_start: CarbonImmutable, period_end: CarbonImmutable}
     */
    public static function resourceChange(Service $service, ResourceChangeItem $item, CarbonImmutable $start, CarbonImmutable $end, int $amount): array
    {
        $rowId = $service->relationLoaded('resources')
            ? $service->resources->firstWhere('kind', $item->kind)?->getKey()
            : $service->resources()->where('kind', $item->kind)->value('id');

        return [
            'type' => InvoiceItem::TYPE_RESOURCE_CHANGE,
            'description' => self::describe(self::changeText($item), $service->domain, ' ('.$start->format('d M Y').' - '.$end->format('d M Y').')'),
            'amount' => $amount,
            'service_id' => $service->id,
            'service_resource_option_id' => $rowId === null ? null : (int) $rowId,
            'period_start' => $start,
            'period_end' => $end,
        ];
    }

    /**
     * A coupon discount on a service or domain. The amount is made negative.
     *
     * @return array{type: string, description: string, amount: int, service_id: int|null, domain_id: int|null}
     */
    public static function discount(Coupon $coupon, int $amount, ?Service $service = null, ?Domain $domain = null): array
    {
        return [
            'type' => InvoiceItem::TYPE_DISCOUNT,
            'description' => self::fit(__('Coupon :code (:discount)', ['code' => $coupon->code, 'discount' => $coupon->describe()]), self::DESCRIPTION_MAX),
            'amount' => -abs($amount),
            'service_id' => $service?->id,
            'domain_id' => $domain?->id,
        ];
    }

    /**
     * "RAM +4 GB" for a row, optionally with another number of steps than it has now.
     */
    private static function rowText(ServiceResourceOption $row, ?int $steps = null): string
    {
        if ($steps === null || $steps === $row->steps) {
            return $row->describe();
        }

        $copy = clone $row;
        $copy->steps = $steps;

        return $copy->describe();
    }

    /**
     * "RAM +2 GB → +5 GB", "Server size: CX23 → CX33" or "Backups: Off → On". A switch that counts
     * nothing (one step, no unit) reads the same as ServiceResourceOption::describe() names it.
     */
    private static function changeText(ResourceChangeItem $item): string
    {
        $option = $item->option;
        $label = $option !== null ? $option->displayLabel() : ResourceOption::kindLabel($item->kind);
        $ladder = $option?->ladder;
        $toggle = $ladder === null && $item->unit === '' && ($item->kind === 'backups' || $option?->max_steps === 1);
        $side = fn (int $steps): string => match (true) {
            $ladder !== null => ResourceKinds::format($item->unit, $steps * $item->step_size, $ladder, $steps),
            $toggle => $steps > 0 ? __('On') : __('Off'),
            default => $steps > 0 ? '+'.ResourceKinds::format($item->unit, $steps * $item->step_size) : '0',
        };

        return $label.($ladder !== null || $toggle ? ': ' : ' ').$side($item->from_steps).' → '.$side($item->to_steps);
    }

    /**
     * "Name - detail (period)" that always fits an invoice line: the period stays whole, a long
     * detail (such as a domain) is shortened first, then the name.
     */
    private static function describe(string $name, ?string $detail, string $period = ''): string
    {
        $room = max(1, self::DESCRIPTION_MAX - mb_strlen($period));
        $name = self::fit($name, $room);
        $detailRoom = $room - mb_strlen($name) - 3;

        if (filled($detail) && $detailRoom > 0) {
            $name .= ' - '.self::fit((string) $detail, $detailRoom);
        }

        return self::fit($name.$period, self::DESCRIPTION_MAX);
    }

    private static function fit(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, max(0, $max - 1)).'…' : $text;
    }
}
