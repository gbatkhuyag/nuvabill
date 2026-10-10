<?php

namespace App\Extensions\Servers\Panel;

use App\Models\Service;
use App\Support\Cloudflare;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Who is asking, for server panel reads and actions, sign-ins and consoles. Core builds it from
 * the signed-in client's request; modules get it as a value and never read request() themselves.
 * It holds no personal data beyond the client's IP, which modules may only use for the sign-in
 * binding of their own panel or their own rate limits.
 */
final readonly class ClientContext
{
    /**
     * @param  int  $clientId  The signed-in client, never a value from the request input.
     * @param  string  $ip  The client's IP. A forwarded header is read only from trusted proxies.
     * @param  bool  $ipTrusted  False when the IP is not valid, or is a Cloudflare edge because the proxies are not trusted.
     * @param  string  $returnUrl  The absolute address of the client's service page.
     * @param  string  $locale  The language the client is using now.
     */
    public function __construct(
        public int $clientId,
        public string $ip,
        public bool $ipTrusted,
        public string $returnUrl,
        public string $locale,
    ) {}

    /**
     * The context of the signed-in client on this request.
     *
     * @throws AuthenticationException When no client is signed in.
     */
    public static function fromRequest(Request $request, Service $service): self
    {
        $clientId = $request->user('web')?->getAuthIdentifier();

        if (! is_int($clientId) && ! (is_string($clientId) && ctype_digit($clientId))) {
            throw new AuthenticationException;
        }

        $ip = (string) $request->ip();
        $valid = filter_var($ip, FILTER_VALIDATE_IP) !== false;
        $cloudflareEdge = $valid && $request->headers->has('CF-Ray') && IpUtils::checkIp($ip, Cloudflare::IP_RANGES);

        return new self(
            clientId: (int) $clientId,
            ip: $ip,
            ipTrusted: $valid && ! $cloudflareEdge,
            returnUrl: route('client.services.show', $service),
            locale: app()->getLocale(),
        );
    }
}
