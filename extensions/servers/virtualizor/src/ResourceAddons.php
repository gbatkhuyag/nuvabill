<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * The note of a VPS made with extras, and the payment flag from before 2.0.
 *
 * A create with extras is noted before it is sent (requested, then created, then verified), so a
 * request without a clear answer is never sent twice: the next try looks for the VPS it may have
 * made. The note keeps the totals the VPS must have and the extras they came from.
 *
 * Before 2.0 the extras came from product add-ons named in the "resource_addons" product setting.
 * The 2.0 update moves them to resource options (sliders) with a migration; only the name of that
 * setting is still read here, for products the migration could not move.
 */
final class ResourceAddons
{
    /**
     * The product setting with the add-on JSON from before 2.0.
     */
    public const FIELD = 'resource_addons';

    /**
     * Module data key: the note of a create with extras, so a request is never sent twice.
     */
    public const KEY = 'virtualizor_resource_addons_v1';

    /**
     * Module data key from before 2.0: the last create was refused only because the order invoice
     * with the add-ons was not paid yet. Services still waiting this way start once it is paid. Core
     * now holds creates with unpaid extras itself (OrderPaymentGate).
     */
    public const WAITING = 'virtualizor_waits_for_payment';

    /**
     * Exception code of a create refused only because its extras were not paid yet, from before 2.0.
     * Nothing throws it any more: core holds such creates before the module runs.
     */
    public const UNPAID = 402;

    /**
     * The create request was sent, and it is not known yet whether it made a VPS.
     */
    public const REQUESTED = 'requested';

    /**
     * Virtualizor made the VPS; it is not checked yet.
     */
    public const CREATED = 'created';

    /**
     * The VPS was checked against the paid extras.
     */
    public const VERIFIED = 'verified';

    /**
     * The note of a create with extras, or null when there is none or nothing was sent yet. Notes
     * from before version 1.1.0 keep their state next to a signed "snapshot"; they are read too.
     * "server_id" is the Nuvabill server the request went to; older notes have none. "addons" are
     * the add-on IDs of notes from before 2.0, and "extras" the extra units per kind of later notes.
     *
     * @return array{state: string, vpsid: string|null, server_id: int|null, hostname: string|null, email: string|null, totals: array<string, int>, addons: list<int>, extras: array<string, int>, pool: int|null, requested_at: int|null}|null
     */
    public static function record(Service $service): ?array
    {
        $raw = ((array) $service->module_data)[self::KEY] ?? null;

        if (! is_array($raw)) {
            return null;
        }

        $old = is_array($raw['state'] ?? null);
        $note = $old ? $raw['state'] : $raw;
        $state = match ((string) ($old ? ($note['status'] ?? '') : ($note['state'] ?? ''))) {
            'requested' => self::REQUESTED,
            'candidate', 'created' => self::CREATED,
            'verified' => self::VERIFIED,
            default => null,
        };

        if ($state === null) {
            return null;
        }

        $vpsId = $note['vpsid'] ?? null;
        $vpsId = (is_int($vpsId) || is_string($vpsId)) && ctype_digit((string) $vpsId) ? (string) $vpsId : null;
        $snapshot = $old ? (array) ($raw['snapshot'] ?? []) : [];
        $totals = $old ? (array) ($snapshot['totals'] ?? []) : (array) ($raw['totals'] ?? []);
        $addons = $old ? array_column((array) ($snapshot['selected'] ?? []), 'product_addon_id') : (array) ($raw['addons'] ?? []);
        $extras = $old ? [] : array_filter((array) ($raw['extras'] ?? []), fn (mixed $units, mixed $kind): bool => is_string($kind) && is_int($units), ARRAY_FILTER_USE_BOTH);
        $pool = $old ? ($snapshot['policy']['public_pool_id'] ?? null) : ($raw['pool'] ?? null);

        return [
            // A VPS that was made but has no ID is as unclear as a request without an answer.
            'state' => $vpsId === null ? self::REQUESTED : $state,
            'vpsid' => $vpsId,
            'server_id' => ! $old && is_numeric($raw['server_id'] ?? null) && (int) $raw['server_id'] > 0 ? (int) $raw['server_id'] : null,
            'hostname' => is_string($note['hostname'] ?? null) ? $note['hostname'] : null,
            'email' => ! $old && is_string($raw['email'] ?? null) && $raw['email'] !== '' ? $raw['email'] : null,
            'totals' => array_map('intval', array_intersect_key(array_filter($totals, 'is_numeric'), ResourceTotals::FIELDS)),
            'addons' => array_values(array_map('intval', array_filter($addons, 'is_numeric'))),
            'extras' => $extras,
            'pool' => is_numeric($pool) && (int) $pool > 0 ? (int) $pool : null,
            'requested_at' => is_numeric($raw['requested_at'] ?? null) ? (int) $raw['requested_at'] : null,
        ];
    }

    /**
     * Save the note before the next step. The root password of a create about to be sent is kept
     * in it, encrypted, until the VPS is checked: a pending service never shows it, and a VPS found
     * after a create without a clear answer still gets it. A checked note keeps no password.
     * A made VPS's ID goes into the module data at once, so staff can always find it.
     *
     * @param  array<string, mixed>  $record
     * @return array{state: string, vpsid: string|null, server_id: int|null, hostname: string|null, email: string|null, totals: array<string, int>, addons: list<int>, extras: array<string, int>, pool: int|null, requested_at: int|null}
     */
    public static function remember(Service $service, array $record, ?string $password = null): array
    {
        // A noted create no longer waits for the payment: it is never sent again by itself.
        $data = Arr::except((array) $service->module_data, self::WAITING);
        $note = Arr::only($record, ['state', 'vpsid', 'server_id', 'hostname', 'email', 'totals', 'addons', 'extras', 'pool', 'requested_at']);
        $secret = $password !== null ? Crypt::encryptString($password) : (is_array($data[self::KEY] ?? null) ? ($data[self::KEY]['secret'] ?? null) : null);

        if (is_string($secret) && ($note['state'] ?? null) !== self::VERIFIED) {
            $note['secret'] = $secret;
        }

        $data[self::KEY] = $note;

        if (filled($record['vpsid'] ?? null)) {
            $data['vpsid'] = (string) $record['vpsid'];
        }

        $service->module_data = $data;
        $service->save();

        return self::record($service) ?? throw new RuntimeException(__('The add-on note of this VPS could not be saved.'));
    }

    /**
     * The root password kept in the note, or null when there is none.
     */
    public static function password(Service $service): ?string
    {
        $note = ((array) $service->module_data)[self::KEY] ?? null;
        $secret = is_array($note) ? ($note['secret'] ?? null) : null;

        if (! is_string($secret)) {
            return null;
        }

        try {
            return Crypt::decryptString($secret);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Drop the note: no VPS was made. A service still waiting keeps no root password either, also
     * not one an older version put on it before the request.
     */
    public static function forget(Service $service): void
    {
        $service->module_data = Arr::except((array) $service->module_data, self::KEY);

        if ($service->status === ServiceStatus::Pending) {
            $service->password = null;
        }

        $service->save();
    }

    /**
     * Note that the create waits for the order invoice to be paid. Nothing was sent for it.
     */
    public static function waitForPayment(Service $service): void
    {
        $service->module_data = array_merge((array) $service->module_data, [self::WAITING => true]);
        $service->save();
    }

    /**
     * Drop the payment note, before a create tries anything. Saved at once, so it never outlives a
     * create that sends a request, also when that create is stopped before it ends.
     */
    public static function stopWaiting(Service $service): void
    {
        $data = (array) $service->module_data;

        if (! array_key_exists(self::WAITING, $data)) {
            return;
        }

        $service->module_data = Arr::except($data, self::WAITING);
        $service->save();
    }

    /**
     * Whether the last create waits only for the order invoice to be paid: it was refused for that
     * before anything was sent, and no create request was noted since.
     */
    public static function waitsForPayment(Service $service): bool
    {
        $data = (array) $service->module_data;

        return ($data[self::WAITING] ?? null) === true && ! array_key_exists(self::KEY, $data);
    }
}
