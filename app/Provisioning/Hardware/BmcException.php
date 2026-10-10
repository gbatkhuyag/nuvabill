<?php

namespace App\Provisioning\Hardware;

use RuntimeException;

/**
 * A BMC call that did not work. The message is always written here, short and in plain English,
 * and safe to show to staff: it never holds the BMC login, a raw HTTP or process error, or the
 * answer body. It never wraps the original exception either, so nothing raw can reach report(),
 * the logs or the activity log.
 */
final class BmcException extends RuntimeException
{
    /** The BMC did not answer, or could not be reached at its address. */
    public const UNREACHABLE = 'unreachable';

    /** The BMC refused the user name or password. */
    public const AUTH = 'auth';

    /** The BMC's certificate is not trusted yet, so no login may be sent. */
    public const NOT_TRUSTED = 'not_trusted';

    /** The BMC showed another certificate than the one staff trusted. */
    public const PIN_MISMATCH = 'pin_mismatch';

    /** The BMC said the action is not needed now, for example "already on". */
    public const CONFLICT = 'conflict';

    /** The BMC, or this site, cannot do this. */
    public const UNSUPPORTED = 'unsupported';

    /** The BMC or Nuvabill refused the request. */
    public const REFUSED = 'refused';

    /**
     * @param  string  $reason  One of the constants above.
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function unreachable(?string $message = null): self
    {
        return new self(self::UNREACHABLE, $message ?? __('We could not reach the BMC. Check its address and that this site can reach it.'));
    }

    public static function auth(): self
    {
        return new self(self::AUTH, __('The BMC refused the user name or password.'));
    }

    public static function notTrusted(?string $message = null): self
    {
        return new self(self::NOT_TRUSTED, $message ?? __('Trust the BMC certificate first: run Test connection and check it.'));
    }

    public static function pinMismatch(): self
    {
        return new self(self::PIN_MISMATCH, __("The BMC's certificate changed. Check it on the BMC and trust it again."));
    }

    public static function conflict(string $message): self
    {
        return new self(self::CONFLICT, $message);
    }

    public static function unsupported(?string $message = null): self
    {
        return new self(self::UNSUPPORTED, $message ?? __('Not supported by this server.'));
    }

    public static function refused(string $message): self
    {
        return new self(self::REFUSED, $message);
    }
}
