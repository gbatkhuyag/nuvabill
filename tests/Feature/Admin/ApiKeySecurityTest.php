<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * An API key is a lasting way back in: making one asks for the password, and a new password
 * (reset, own change or set by a manager) deletes the old keys.
 */
class ApiKeySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_key_needs_the_right_password(): void
    {
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->post(route('admin.profile.api-keys.store'), ['name' => 'Accounting'])
            ->assertSessionHasErrors('current_password')->assertSessionMissing('new_api_key');
        $this->post(route('admin.profile.api-keys.store'), ['name' => 'Accounting', 'current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password')->assertSessionMissing('new_api_key');

        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_creating_a_key_shares_the_staff_reauth_limit(): void
    {
        $this->signInAdmin();

        foreach (range(1, 6) as $try) {
            $this->post(route('admin.profile.api-keys.store'), ['name' => 'Accounting', 'current_password' => 'wrong-password-'.$try])
                ->assertSessionHasErrors('current_password');
        }

        $this->post(route('admin.profile.api-keys.store'), ['name' => 'Accounting', 'current_password' => 'password'])->assertStatus(429);
        $this->postJson(route('admin.profile.passkeys.options'), ['current_password' => 'password'])->assertStatus(429);
        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_a_password_reset_deletes_all_keys_of_that_staff_member(): void
    {
        $admin = Admin::factory()->create(['name' => 'Mer', 'email' => 'mer@example.test']);
        [, $plain] = ApiToken::issue($admin, 'Accounting', true);
        ApiToken::issue($admin, 'Backup', false);
        [, $otherPlain] = ApiToken::issue(Admin::factory()->create(['name' => 'Las']), 'Las tool', false);
        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plain}"])->assertOk();

        // The key request signs the guard in for that request only; the reset comes from a fresh browser.
        Auth::forgetGuards();
        $token = Password::broker('admins')->createToken($admin);
        $this->post(route('admin.password.update'), ['token' => $token, 'email' => 'mer@example.test', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHas('status', 'Your password was changed and your API keys were deleted. Sign in with the new one.');

        $this->assertSame(0, $admin->apiTokens()->count());
        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plain}"])->assertUnauthorized();
        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$otherPlain}"])->assertOk();

        $log = ActivityLog::query()->where('action', 'api.keys_revoked')->sole();
        $this->assertStringStartsWith('2 API key(s) of Mer deleted', $log->description);
        $this->assertSame($admin->id, $log->actor_id);
    }

    public function test_an_own_password_change_deletes_the_keys_when_the_box_is_ticked(): void
    {
        $admin = $this->signInAdmin();
        ApiToken::issue($admin, 'Accounting', true);

        $this->put(route('admin.profile.password'), ['current_password' => 'password', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1', 'revoke_api_keys' => '1'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Password changed. Your API keys were deleted.');

        $this->assertSame(0, $admin->apiTokens()->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'api.keys_revoked')->count());
    }

    public function test_an_own_password_change_keeps_the_keys_when_the_box_is_unticked(): void
    {
        $admin = $this->signInAdmin();
        [, $plain] = ApiToken::issue($admin, 'Accounting', true);

        $this->put(route('admin.profile.password'), ['current_password' => 'password', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1', 'revoke_api_keys' => '0'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Password changed.');

        $this->assertSame(1, $admin->apiTokens()->count());
        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plain}"])->assertOk();
        $this->assertSame(0, ActivityLog::query()->where('action', 'api.keys_revoked')->count());
    }

    public function test_the_profile_shows_the_box_ticked(): void
    {
        $this->signInAdmin();

        $this->get(route('admin.profile.edit'))->assertOk()
            ->assertSee('Also delete my API keys')
            ->assertSee('id="revoke-api-keys" type="checkbox" name="revoke_api_keys" value="1" checked', false)
            ->assertSee('id="api-key-password"', false);
    }

    public function test_an_own_password_change_without_the_box_deletes_the_keys(): void
    {
        $admin = $this->signInAdmin();
        ApiToken::issue($admin, 'Accounting', true);

        $this->put(route('admin.profile.password'), ['current_password' => 'password', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Password changed. Your API keys were deleted.');

        $this->assertSame(0, $admin->apiTokens()->count());
    }

    public function test_the_box_stays_ticked_after_another_form_on_the_page_fails(): void
    {
        $this->signInAdmin();

        $this->from(route('admin.profile.edit'))
            ->post(route('admin.profile.api-keys.store'), ['name' => 'Accounting', 'current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password');

        $this->get(route('admin.profile.edit'))->assertOk()
            ->assertSee('id="revoke-api-keys" type="checkbox" name="revoke_api_keys" value="1" checked', false);
    }

    public function test_the_new_key_is_not_stored_in_plain_text_in_the_session(): void
    {
        $this->signInAdmin();

        $this->from(route('admin.profile.edit'))
            ->post(route('admin.profile.api-keys.store'), ['name' => 'Accounting', 'current_password' => 'password'])
            ->assertSessionHas('new_api_key');

        $stored = (string) session('new_api_key');
        $plain = Crypt::decryptString($stored);
        $this->assertNotSame($plain, $stored);
        $this->assertNotNull(ApiToken::findByPlainText($plain));
        $this->get(route('admin.profile.edit'))->assertOk()->assertSee($plain);
    }

    public function test_a_password_set_in_staff_settings_deletes_that_staff_members_keys(): void
    {
        $owner = $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $staff = Admin::factory()->create(['name' => 'Las']);
        [, $plain] = ApiToken::issue($staff, 'Las tool', true);
        ApiToken::issue($owner, 'Raz tool', true);

        // Saving without a new password keeps the keys.
        $this->put(route('admin.settings.staff.update', $staff), $this->form($staff, ['name' => 'Las B']))->assertSessionHasNoErrors();
        $this->assertSame(1, $staff->apiTokens()->count());

        $this->put(route('admin.settings.staff.update', $staff), $this->form($staff, ['password' => 'a-new-password-1']))->assertSessionHasNoErrors();
        $this->assertSame(0, $staff->apiTokens()->count());
        $this->assertSame(1, $owner->apiTokens()->count());
        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plain}"])->assertUnauthorized();
        $this->assertSame($owner->id, ActivityLog::query()->where('action', 'api.keys_revoked')->sole()->actor_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(Admin $admin, array $overrides = []): array
    {
        return $overrides + [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_id' => $admin->role_id,
            'is_active' => 1,
            'password' => '',
        ];
    }
}
