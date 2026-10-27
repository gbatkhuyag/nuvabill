<?php

namespace App\Marketplace\Store;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The marketplace store only: Cloudflare IP Access rules ("allow") on the store's zone, so Bot
 * Fight Mode lets the servers of connected sites call the store's API. Bot Fight Mode cannot be
 * skipped with WAF rules; an IP Access rule is the free exception.
 *
 * Needs the zone ID and an API token with only "Zone > Firewall Services > Edit" on that one zone.
 * The token is kept encrypted and never logged or shown again.
 */
class CloudflareAccess
{
    private const API = 'https://api.cloudflare.com/client/v4';

    /**
     * Cloudflare's error code for "a rule for this address already exists".
     */
    private const DUPLICATE = 10009;

    public function isConfigured(): bool
    {
        return $this->zone() !== '' && $this->token() !== '';
    }

    /**
     * Let an address through. Returns the rule's ID, also when a rule for the address existed
     * already, or null when Cloudflare is not set up or refused.
     */
    public function allow(string $ip, string $family, string $note): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $target = $family === 'ipv6' ? 'ip6' : 'ip';

        try {
            $response = $this->http()->post($this->rulesUrl(), [
                'mode' => 'whitelist',
                'configuration' => ['target' => $target, 'value' => $ip],
                'notes' => mb_substr($note, 0, 500),
            ]);

            if ($response->successful() && is_string($response->json('result.id'))) {
                return $response->json('result.id');
            }

            if ($this->isDuplicate($response)) {
                return $this->find($ip, $target);
            }

            Log::warning('Cloudflare did not add the access rule for a marketplace site.', ['status' => $response->status(), 'errors' => $this->errors($response)]);
        } catch (Throwable $exception) {
            Log::warning('Cloudflare could not be reached to add an access rule.', ['error' => class_basename($exception)]);
        }

        return null;
    }

    /**
     * Remove a rule. True when it is gone (also when it was gone already).
     */
    public function remove(string $ruleId): bool
    {
        if (! $this->isConfigured() || ! preg_match('/^[A-Za-z0-9]{1,64}$/', $ruleId)) {
            return false;
        }

        try {
            $response = $this->http()->delete($this->rulesUrl().'/'.$ruleId);
        } catch (Throwable $exception) {
            Log::warning('Cloudflare could not be reached to remove an access rule.', ['error' => class_basename($exception)]);

            return false;
        }

        if ($response->successful() || $response->status() === 404) {
            return true;
        }

        Log::warning('Cloudflare did not remove the access rule of a marketplace site.', ['status' => $response->status(), 'errors' => $this->errors($response)]);

        return false;
    }

    /**
     * Check the zone ID and token: null when they work, otherwise what went wrong.
     */
    public function test(?string $zone = null, ?string $token = null): ?string
    {
        $zone ??= $this->zone();
        $token ??= $this->token();

        if ($zone === '' || $token === '') {
            return __('Enter the zone ID and the API token first.');
        }

        try {
            $response = $this->http($token)->get(self::API.'/zones/'.rawurlencode($zone));
        } catch (Throwable) {
            return __('Cloudflare could not be reached. Try again in a few minutes.');
        }

        if ($response->successful() && $response->json('success') === true) {
            return null;
        }

        return __('Cloudflare refused: :reason', ['reason' => $this->errors($response) ?: __('error :status', ['status' => $response->status()])]);
    }

    /**
     * The ID of the rule that already lets this address through, when it is one this store made.
     * A rule someone added by hand is used but never noted, so it is never removed by the store.
     */
    private function find(string $ip, string $target): ?string
    {
        $response = $this->http()->get($this->rulesUrl(), [
            'configuration.target' => $target,
            'configuration.value' => $ip,
            'per_page' => 5,
        ]);

        foreach ((array) $response->json('result') as $rule) {
            if (is_array($rule) && ($rule['configuration']['value'] ?? null) === $ip && is_string($rule['id'] ?? null)) {
                return ($rule['mode'] ?? null) === 'whitelist' && str_starts_with((string) ($rule['notes'] ?? ''), 'Nuvabill site') ? $rule['id'] : null;
            }
        }

        return null;
    }

    private function isDuplicate(Response $response): bool
    {
        foreach ((array) $response->json('errors') as $error) {
            if ((int) ($error['code'] ?? 0) === self::DUPLICATE || str_contains(strtolower((string) ($error['message'] ?? '')), 'duplicate')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cloudflare's error messages, short, for the log and staff. The token is never part of them.
     */
    private function errors(Response $response): string
    {
        return mb_substr(collect((array) $response->json('errors'))
            ->map(fn ($error): string => is_array($error) ? trim(($error['code'] ?? '').' '.($error['message'] ?? '')) : '')
            ->filter()
            ->implode('; '), 0, 300);
    }

    private function rulesUrl(): string
    {
        return self::API.'/zones/'.rawurlencode($this->zone()).'/firewall/access_rules/rules';
    }

    private function http(?string $token = null): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(15)->withToken($token ?? $this->token());
    }

    private function zone(): string
    {
        return trim((string) setting('marketplace.cloudflare_zone_id'));
    }

    private function token(): string
    {
        return trim((string) setting('marketplace.cloudflare_token'));
    }
}
