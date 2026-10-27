<?php

namespace App\Http\Controllers\Marketplace\Admin;

use App\Http\Controllers\Controller;
use App\Marketplace\Store\CloudflareAccess;
use App\Marketplace\Store\ConnectedSites;
use App\Models\Admin;
use App\Models\MarketplaceSite;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Store staff see the sites connected to the marketplace, allow those that wait, remove sites,
 * and set up the Cloudflare token that lets their servers through.
 */
class SiteController extends Controller
{
    public function __construct(private ConnectedSites $sites) {}

    public function index(Request $request, CloudflareAccess $cloudflare): View
    {
        $status = in_array($request->query('status'), [MarketplaceSite::STATUS_ACTIVE, MarketplaceSite::STATUS_PENDING, MarketplaceSite::STATUS_REVOKED, MarketplaceSite::STATUS_EXPIRED], true) ? (string) $request->query('status') : '';
        $term = trim($request->string('q')->toString());

        return view('admin.store.sites', [
            'sites' => MarketplaceSite::query()
                ->when($status !== '', fn ($query) => $query->where('status', $status))
                ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->where('host', 'like', '%'.strtolower($term).'%')->orWhere('ip', 'like', "%{$term}%")))
                ->orderByRaw("case when status = 'pending' then 0 else 1 end")
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
            'status' => $status,
            'term' => $term,
            'pendingCount' => MarketplaceSite::query()->where('status', MarketplaceSite::STATUS_PENDING)->count(),
            'cloudflareReady' => $cloudflare->isConfigured(),
            'zoneId' => (string) setting('marketplace.cloudflare_zone_id'),
            'hasToken' => filled(setting('marketplace.cloudflare_token')),
            'pendingRemovals' => $this->sites->pendingRemovals(),
            'limits' => self::currentLimits(),
            'canManageSettings' => $request->user('admin')?->hasPermission('settings.manage') === true,
        ]);
    }

    public function approve(Request $request, MarketplaceSite $site): RedirectResponse
    {
        abort_unless($site->status === MarketplaceSite::STATUS_PENDING, 404);

        $admin = $request->user('admin');
        $this->sites->approve($site, $admin instanceof Admin ? $admin : null);
        Activity::log('marketplace.site_approved', "Site {$site->host} allowed to reach the marketplace from {$site->ip}", $site);

        return back()->with('status', $site->cloudflare_rule_id !== null || ! app(CloudflareAccess::class)->isConfigured()
            ? __(':site is active.', ['site' => $site->host])
            : __(':site is active, but Cloudflare did not add the rule. See the error log.', ['site' => $site->host]));
    }

    public function revoke(MarketplaceSite $site): RedirectResponse
    {
        abort_unless($site->isUsable(), 404);

        $this->sites->revoke($site);
        Activity::log('marketplace.site_revoked', "Site {$site->host} removed from the marketplace; its key no longer works", $site);

        return back()->with('status', __(':site was removed. Its key no longer works.', ['site' => $site->host]));
    }

    /**
     * The limits on connecting.
     */
    public function limits(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate(array_map(fn (): array => ['required', 'integer', 'min:1', 'max:100000'], self::LIMITS));

        $settings->setMany(collect(self::LIMITS)->mapWithKeys(fn (string $setting, string $field): array => [$setting => (int) $data[$field]])->all());
        Activity::log('marketplace.limits', 'Limits for connecting sites to the marketplace saved');

        return back()->with('status', __('Limits saved.'));
    }

    /**
     * Form field => setting.
     */
    private const LIMITS = [
        'daily_limit' => 'marketplace.connect_daily_limit',
        'domain_daily_limit' => 'marketplace.connect_domain_daily_limit',
        'hosts_per_domain' => 'marketplace.connect_hosts_per_domain',
        'sites_per_ip' => 'marketplace.connect_sites_per_ip',
    ];

    /**
     * @return array<string, int>
     */
    private static function currentLimits(): array
    {
        return collect(self::LIMITS)->map(fn (string $setting): int => (int) setting($setting))->all();
    }

    public function cloudflare(Request $request, Settings $settings, CloudflareAccess $cloudflare): RedirectResponse
    {
        $data = $request->validate([
            'zone_id' => ['nullable', 'string', 'regex:/^[a-f0-9]{32}$/'],
            // Named api_token so a form error never sends it back into the page (see bootstrap/app.php).
            'api_token' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]+$/'],
            'action' => ['nullable', Rule::in(['save', 'test'])],
        ], ['zone_id.regex' => __('The zone ID is 32 letters and digits, from the zone\'s Overview page in Cloudflare.')]);

        $zone = (string) ($data['zone_id'] ?? '');
        // An empty token field keeps the saved token.
        $token = filled($data['api_token'] ?? null) ? (string) $data['api_token'] : (string) setting('marketplace.cloudflare_token');

        if (($data['action'] ?? 'save') === 'test') {
            $error = $cloudflare->test($zone, $token);

            return back()->withInput($request->only('zone_id'))->with($error === null ? 'status' : 'error', $error ?? __('Cloudflare accepted the zone ID and token.'));
        }

        // Rules in the old zone could then never be removed: keep the zone while the store has rules there.
        if ($zone !== (string) setting('marketplace.cloudflare_zone_id') && $this->sites->hasRules()) {
            return back()->withInput($request->only('zone_id'))->with('error', __('Cloudflare still has rules for connected sites in this zone. Remove those sites first, or keep the zone ID. You can change the token.'));
        }

        $settings->setMany(['marketplace.cloudflare_zone_id' => $zone, 'marketplace.cloudflare_token' => $zone === '' ? '' : $token]);
        Activity::log('marketplace.cloudflare', 'Cloudflare settings for connected sites saved');
        // Active sites that connected before Cloudflare was set up get their rules now, and rules that
        // could not be removed before are removed.
        $added = $this->sites->syncRules();
        $this->sites->retryRemovals();

        return back()->with('status', $added > 0
            ? trans_choice('Cloudflare settings saved. :count site was let through.|Cloudflare settings saved. :count sites were let through.', $added, ['count' => $added])
            : __('Cloudflare settings saved.'));
    }
}
