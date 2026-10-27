<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Marketplace\Store\ConnectedSites;
use App\Security\PublicAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * The store's "Connect {site} to the Nuvabill marketplace?" page. Staff of a Nuvabill site land here
 * after clicking "Connect to marketplace", confirm, and go back to their site. The store calls the
 * site itself to hand over the key, so nobody can connect a site they do not run.
 */
class SiteConnectController extends Controller
{
    public function show(Request $request): View
    {
        $input = $this->input($request);

        return view('theme::marketplace.connect', $input);
    }

    public function store(Request $request, ConnectedSites $sites): RedirectResponse
    {
        $input = $this->input($request);

        try {
            $result = $sites->connect($input['site'], $input['state'], $input['ip']);
        } catch (RuntimeException $exception) {
            return redirect()->route('marketplace.connect', array_filter($input))->with('error', $exception->getMessage());
        }

        return redirect()->away($result['url']);
    }

    /**
     * @return array{site: string, state: string, ip: ?string}
     */
    private function input(Request $request): array
    {
        $site = strtolower((string) $request->input('site', ''));
        $state = (string) $request->input('state', '');
        $ip = (string) $request->input('ip', '');

        abort_unless(PublicAddress::isInternetHost($site) && preg_match('/^[A-Za-z0-9_-]{43,128}$/', $state), 404);

        return ['site' => $site, 'state' => $state, 'ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null];
    }
}
