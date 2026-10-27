<?php

namespace App\Security;

use App\Models\Admin;
use App\Support\Demo;

/**
 * Whether this staff session passed a second factor: a code from the authenticator app or a
 * recovery code at sign-in, a passkey, a fresh code on Admin → Profile, or the step-up check
 * (TwoFactorStepUpController). Two-factor login on the account alone is not enough: a stolen
 * session of staff without it could turn it on with its own app. A session restored from a
 * remember-me cookie has no marker and is asked for a code first.
 */
final class StaffSecondFactor
{
    public const SESSION_KEY = 'admin.second_factor';

    /**
     * Note that this session passed a second factor for the staff member. Call after the session
     * id is regenerated, so the marker never sits on an id someone else may know.
     */
    public static function mark(Admin $admin): void
    {
        session()->put(self::SESSION_KEY, (int) $admin->id);
    }

    /**
     * Whether the staff member has two-factor login on and this session passed it (always in the demo).
     */
    public static function passed(Admin $admin): bool
    {
        if (Demo::isEnabled()) {
            return true;
        }

        return $admin->hasTwoFactorEnabled() && session()->get(self::SESSION_KEY) === (int) $admin->id;
    }
}
