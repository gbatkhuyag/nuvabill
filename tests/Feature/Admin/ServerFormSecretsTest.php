<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A secret staff type into a form (a server API token, a BMC login, a root password) is never kept
 * in the session after a form error, so the form shown again cannot write it back into the page.
 */
class ServerFormSecretsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETS = [
        'api_token' => 'typed-root-token-4f9a2c',
        'password' => 'typed-root-password-81b7',
        'bmc_username' => 'typed-bmc-user-3e1d',
        'bmc_password' => 'typed-bmc-password-6c0f',
        'root_password' => 'typed-server-password-9a2b',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
    }

    public function test_a_typed_api_token_is_not_kept_or_shown_again_after_a_form_error(): void
    {
        $this->from(route('admin.servers.create'))
            ->post(route('admin.servers.store'), self::SECRETS + [
                'name' => '',
                'module' => 'cpanel',
                'hostname' => 'whm.example.com',
                'port' => '2087',
                'use_ssl' => '1',
                'username' => 'root',
            ])
            ->assertRedirect(route('admin.servers.create'))
            ->assertSessionHasErrors('name');

        $old = session('_old_input');
        $this->assertSame('whm.example.com', $old['hostname'] ?? null, 'Other fields are still kept.');

        foreach (array_keys(self::SECRETS) as $field) {
            $this->assertArrayNotHasKey($field, $old, $field);
        }

        $page = $this->get(route('admin.servers.create'))->assertOk()->assertSee('whm.example.com');

        foreach (self::SECRETS as $value) {
            $page->assertDontSee($value);
        }
    }
}
