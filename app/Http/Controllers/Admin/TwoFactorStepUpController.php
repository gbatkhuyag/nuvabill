<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Security\SignInLimiter;
use App\Security\StaffSecondFactor;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * A signed-in staff member whose session did not pass two-factor login (a remember-me sign-in,
 * say) enters a code before server module tools (RequireStaffTwoFactor). Wrong codes count with
 * the sign-in's (SignInLimiter); too many sign the session out.
 */
class TwoFactorStepUpController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $admin = $request->user('admin');

        if (! $admin->hasTwoFactorEnabled()) {
            return redirect()->route('admin.profile.edit')->with('error', __('Turn on two-factor login in your profile to use server module tools.'));
        }

        if (StaffSecondFactor::passed($admin)) {
            return redirect()->intended(route('admin.dashboard'));
        }

        // Opened from a link (the staff card on a service page): go back there afterwards. The
        // previous page is this site's own, kept in the session.
        if (! $request->session()->has('url.intended')) {
            $request->session()->put('url.intended', url()->previous(route('admin.dashboard')));
        }

        return view('admin.two-factor-step-up');
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');

        if (! $admin->hasTwoFactorEnabled()) {
            return redirect()->route('admin.profile.edit');
        }

        $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        return SignInLimiter::oneCodeAtATime('admin', $admin->id, fn (): RedirectResponse => $this->attempt($request, $admin));
    }

    private function attempt(Request $request, Admin $admin): RedirectResponse
    {
        if (SignInLimiter::codesLocked('admin', $admin->id)) {
            return $this->stop($request);
        }

        if (! TwoFactorChallengeController::passes($admin, (string) $request->input('code'), (string) $request->input('recovery_code'))) {
            Activity::log('admin.two_factor_failed', "Wrong two-factor code for {$admin->name}", $admin);

            if (SignInLimiter::codeFailed('admin', $admin->id)) {
                Activity::log('admin.two_factor_locked', "Two-factor sign-in for {$admin->name} paused for 15 minutes after too many wrong codes", $admin);

                return $this->stop($request);
            }

            return back()->withErrors(['code' => __('That code is not right. Check the time on your phone and try the newest code.')]);
        }

        SignInLimiter::codePassed('admin', $admin->id);
        // A new session id with the raised access, so an id someone may have seen before gets nothing.
        $request->session()->regenerate();
        StaffSecondFactor::mark($admin);
        Activity::log('admin.two_factor_step_up', "{$admin->name} confirmed two-factor login for server module tools", $admin);

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Too many wrong codes: sign this session out, so it starts again from the password.
     */
    private function stop(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->withErrors(['email' => __('Too many wrong codes. Wait 15 minutes, then sign in again.')]);
    }
}
