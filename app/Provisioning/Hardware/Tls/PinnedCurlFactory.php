<?php

namespace App\Provisioning\Hardware\Tls;

use App\Provisioning\Hardware\BmcException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use Psr\Http\Message\RequestInterface;

/**
 * Makes the curl handles of BMC requests, with the BMC's key pin and the answer size limit set on
 * each handle.
 *
 * Guzzle 8 refuses CURLOPT_PINNEDPUBLICKEY and CURLOPT_MAXFILESIZE in its "curl" request option, so
 * they are set here, on the handle Guzzle made, before the request is sent. A request must say
 * whether a pin applies (PIN_OPTION: "sha256//…", or false for a certificate checked by a trusted
 * CA; never null, which Guzzle drops). A request that does not say, or a pin curl does not accept,
 * is refused before any byte is sent. Handles are never kept for reuse, so a pin never stays on a
 * handle for another request.
 */
final class PinnedCurlFactory implements CurlFactoryInterface
{
    /**
     * Request option: "sha256//{base64}" to check the BMC's key, or false in verified mode.
     */
    public const PIN_OPTION = 'nuvabill_bmc_pin';

    /**
     * Request option: the largest answer in bytes.
     */
    public const MAX_BODY_OPTION = 'nuvabill_bmc_max_body';

    private readonly CurlFactory $curl;

    public function __construct()
    {
        $this->curl = new CurlFactory(0);
    }

    public function create(RequestInterface $request, array $options): EasyHandle
    {
        if (! array_key_exists(self::PIN_OPTION, $options)) {
            throw BmcException::notTrusted();
        }

        $pin = $options[self::PIN_OPTION];

        if ($pin !== false && (! is_string($pin) || preg_match('#^sha256//[A-Za-z0-9+/]{43}=$#D', $pin) !== 1)) {
            throw BmcException::notTrusted();
        }

        $easy = $this->curl->create($request, $options);
        $applied = $pin === false || curl_setopt($easy->handle, CURLOPT_PINNEDPUBLICKEY, $pin);

        if ($applied && isset($options[self::MAX_BODY_OPTION])) {
            $max = (int) $options[self::MAX_BODY_OPTION];
            $applied = curl_setopt($easy->handle, CURLOPT_MAXFILESIZE, $max)
                // Before curl 8.4 the limit above holds only when the size is sent in advance, so a
                // chunked answer is also stopped as it arrives (curl error 42).
                && curl_setopt($easy->handle, CURLOPT_NOPROGRESS, false)
                && curl_setopt($easy->handle, CURLOPT_XFERINFOFUNCTION, static fn (mixed $handle, int $downloadTotal, int $downloaded, int $uploadTotal, int $uploaded): int => $downloaded > $max ? 1 : 0);
        }

        if (! $applied) {
            $this->curl->release($easy);

            throw BmcException::unsupported(PinningSupport::unavailableMessage());
        }

        return $easy;
    }

    public function release(EasyHandle $easy): void
    {
        $this->curl->release($easy);
    }
}
