<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Staff who change their own email or password in staff settings type their current password,
 * so a stolen session cannot take the account over there.
 */
class StaffOwnSignInTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_your_own_password_or_email_needs_your_current_password(): void
    {
        $admin = Admin::factory()->create(['name' => 'Raz', 'email' => 'raz@example.test']);
        $this->signInAdmin($admin);

        $this->put(route('admin.settings.staff.update', $admin), $this->form($admin, ['password' => 'a-new-long-password']))
            ->assertSessionHasErrors('current_password');
        $this->put(route('admin.settings.staff.update', $admin), $this->form($admin, ['email' => 'taken@example.test', 'current_password' => 'wrong-password']))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
        $this->assertSame('raz@example.test', $admin->fresh()->email);

        $this->put(route('admin.settings.staff.update', $admin), $this->form($admin, ['email' => 'raz.new@example.test', 'password' => 'a-new-long-password', 'current_password' => 'password']))
            ->assertSessionHasNoErrors();

        $this->assertSame('raz.new@example.test', $admin->fresh()->email);
        $this->assertTrue(Hash::check('a-new-long-password', $admin->fresh()->password));
    }

    public function test_other_changes_to_your_own_account_and_changes_to_others_need_no_password(): void
    {
        $admin = Admin::factory()->create(['name' => 'Raz']);
        $other = Admin::factory()->create(['name' => 'Las', 'role_id' => $admin->role_id]);
        $this->signInAdmin($admin);

        $this->put(route('admin.settings.staff.update', $admin), $this->form($admin, ['name' => 'Raz R', 'email' => strtoupper($admin->email)]))
            ->assertSessionHasNoErrors();
        $this->put(route('admin.settings.staff.update', $other), $this->form($other, ['password' => 'a-new-long-password']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Raz R', $admin->fresh()->name);
        $this->assertTrue(Hash::check('a-new-long-password', $other->fresh()->password));
    }

    public function test_wrong_passwords_are_limited(): void
    {
        $admin = Admin::factory()->create(['name' => 'Raz']);
        $this->signInAdmin($admin);

        foreach (range(1, 6) as $try) {
            $this->put(route('admin.settings.staff.update', $admin), $this->form($admin, ['password' => 'a-new-long-password', 'current_password' => 'wrong-'.$try]));
        }

        // The right password is refused too until the minute is over, so it cannot be guessed.
        $this->put(route('admin.settings.staff.update', $admin), $this->form($admin, ['password' => 'a-new-long-password', 'current_password' => 'password']))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
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
