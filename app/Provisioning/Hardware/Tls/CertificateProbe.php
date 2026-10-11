<?php

namespace App\Provisioning\Hardware\Tls;

use App\Provisioning\Hardware\BmcException;

/**
 * Reads the TLS certificate a BMC shows, without sending any HTTP request or login, so staff can
 * check it before Nuvabill trusts it.
 */
interface CertificateProbe
{
    /**
     * @param  string  $ip  The checked IP address (BmcAddress).
     * @param  string  $serverName  The host name staff typed, sent as SNI, or the IP address.
     *
     * @throws BmcException when the BMC cannot be reached or shows no certificate.
     */
    public function fetch(string $ip, int $port, string $serverName): PeerCertificate;
}
