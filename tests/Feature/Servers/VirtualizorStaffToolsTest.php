<?php

namespace Tests\Feature\Servers;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Admin\ServiceAccessController;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Role;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Virtualizor 2.0 staff tools: "Test panel sign-in" (every check, never a sign-in session),
 * "Link existing VPS" for a VPS made by hand, and the guards of a new VPS: a linked service is
 * never given a second VPS, and a VPS is never filed under someone else's Virtualizor user.
 */
class VirtualizorStaffToolsTest extends TestCase
{
    use FakesVirtualizor, RefreshDatabase;

    public function test_the_access_check_runs_every_check_without_a_sign_in_or_a_secret(): void
    {
        $this->fakeVirtualizor(['sso' => ['token_key' => 'sessvkD9MBpOg0aFkHfD', 'sid' => 'efgBrcHtvyVEGcvaeI9q2A9X4kaOAU18']]);
        $service = $this->vpsService(['client_login' => '1']);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->post(route('admin.services.access-check', $service))
            ->assertRedirect(route('admin.services.show', $service).'#client-access')
            ->assertSessionHas('status');

        $rows = session(ServiceAccessController::FLASH)['rows'];
        $this->assertSame(
            ['Client panel sign-in', 'Server settings', 'Client panel port', 'Server hostname', 'Service', 'VPS in Virtualizor', 'Virtualizor user', 'End-user port', 'Ownership', 'Console', 'Client IP address', 'IPv6'],
            array_column($rows, 'label'),
        );
        $this->assertSame(['ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'skip'], array_column($rows, 'status'));
        $this->assertSame([], $this->sent('sso'));

        $page = $this->get(route('admin.services.show', $service))->assertOk()->assertSee('Every VPS of this Virtualizor user belongs to this client.');

        foreach ([self::API_PASSWORD, self::API_KEY, 'Vnc7pass', 'RootSecret99'] as $secret) {
            $this->assertStringNotContainsString($secret, $page->getContent());
            $this->assertStringNotContainsString($secret, (string) json_encode($rows));
        }

        $this->assertTrue(ActivityLog::query()->where('action', 'service.access_checked')->exists());
    }

    public function test_the_access_check_says_which_step_fails(): void
    {
        $this->fakeVirtualizor(['users' => ['users' => ['58' => ['uid' => '58', 'email' => self::CLIENT_EMAIL, 'type' => '1']]]]);
        $service = $this->vpsService(['client_login' => ''], server: $this->master(['hostname' => '203.0.113.50']));

        $rows = collect(app(Provisioner::class)->checkClientAccess($service))->keyBy('label');

        $this->assertSame('fail', $rows['Client panel sign-in']['status']);
        $this->assertSame('warn', $rows['Server hostname']['status']);
        $this->assertSame('ok', $rows['VPS in Virtualizor']['status']);
        $this->assertSame('fail', $rows['Virtualizor user']['status']);
        $this->assertStringContainsString('is an admin or cloud user', $rows['Virtualizor user']['detail']);
        $this->assertSame(['skip', 'skip', 'skip'], [$rows['End-user port']['status'], $rows['Ownership']['status'], $rows['Console']['status']]);
    }

    public function test_staff_link_a_vps_that_was_made_by_hand(): void
    {
        $this->fakeVirtualizor([
            'vps' => fn (array $query): array => ['vs' => [$query['vpsid'] => $this->vpsRow((string) $query['vpsid'], ['ips' => ['5' => '203.0.113.90']])]],
        ]);
        $client = $this->client('Mer Las', ['email' => self::CLIENT_EMAIL]);
        $service = $this->vpsService(attributes: ['status' => ServiceStatus::Pending, 'module_data' => null], client: $client);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->post(route('admin.services.link', $service), ['vpsid' => 'abc'])->assertSessionHas('error', 'Enter the VPS ID as a number.');
        $this->post(route('admin.services.link', $service), ['vpsid' => '90'])
            ->assertRedirect(route('admin.services.show', $service).'#client-access')
            ->assertSessionHas('status', 'Linked to VPS #90 of Virtualizor user 58.');

        $service->refresh();
        $this->assertSame('90', $service->module_data['vpsid']);
        $this->assertSame('58', $service->module_data['uid']);
        $this->assertSame(['203.0.113.90', '2001:db8:7::1'], $service->module_data['ips']);
        $this->assertSame(self::VPS_UUID, $service->module_data['vps_uuid']);
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.account_linked')->exists());
        $this->assertSame([], $this->sent('sso'));
    }

    public function test_a_link_is_refused_for_a_vps_in_use_a_special_user_or_another_email(): void
    {
        $service = $this->vpsService(attributes: ['status' => ServiceStatus::Pending, 'module_data' => null]);
        // The same master as another server record.
        $this->vpsService(attributes: ['module_data' => ['vpsid' => '91']], server: $this->master(['hostname' => 'VZ.example.test']), client: $this->client('Raz'));
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $link = fn (array $input) => $this->post(route('admin.services.link', $service), $input);

        $this->fakeVirtualizor();
        $link(['vpsid' => '91'])->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'VPS #91 is already linked to service #'));

        $this->fakeVirtualizor(['vps' => fn (array $query): array => ['vs' => [$query['vpsid'] => $this->vpsRow((string) $query['vpsid'], ['uid' => '0'])]]]);
        $link(['vpsid' => '92'])->assertSessionHas('error', 'VPS #92 has no Virtualizor user. Give it a user in Virtualizor first.');

        $this->fakeVirtualizor(['users' => ['users' => ['58' => ['uid' => '58', 'email' => self::CLIENT_EMAIL, 'type' => '2']]]]);
        $link(['vpsid' => '92'])->assertSessionHas('error', 'Virtualizor user 58 is an admin or cloud user. Only VPS of normal end users can be linked.');

        $this->fakeVirtualizor(['vps' => ['vs' => []]]);
        $link(['vpsid' => '93'])->assertSessionHas('error', 'Virtualizor has no VPS #93.');

        // The user's email is not the client's: only with the box ticked.
        $this->fakeVirtualizor();
        $link(['vpsid' => '92'])->assertSessionHas('error', "The email of Virtualizor user 58 is not this client's email. Tick the box to link the VPS anyway.");
        $this->assertNull($service->fresh()->module_data);
        $link(['vpsid' => '92', 'confirm_email_mismatch' => '1'])->assertSessionHas('status');
        $this->assertSame('92', $service->fresh()->module_data['vpsid']);
    }

    public function test_the_tools_need_the_services_permission_and_are_locked_in_the_demo(): void
    {
        $this->fakeVirtualizor();
        $service = $this->vpsService(attributes: ['status' => ServiceStatus::Pending, 'module_data' => null]);

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz', 'role_id' => Role::factory()->with(['clients.view'])]));
        $this->post(route('admin.services.link', $service), ['vpsid' => '90'])->assertForbidden();
        $this->post(route('admin.services.access-check', $service))->assertForbidden();

        config(['nuvabill.demo' => true]);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Mer Las']));
        $this->post(route('admin.services.link', $service), ['vpsid' => '90'])->assertSessionHas('error', 'This is turned off in the demo, so it keeps working for every visitor.');

        $this->assertSame([], $this->api->calls);
        $this->assertNull($service->fresh()->module_data);
    }

    public function test_a_linked_service_is_never_given_a_second_vps(): void
    {
        $this->fakeVirtualizor(['addvs' => ['done' => 1, 'vs_info' => ['vpsid' => '95', 'uid' => '58']]]);
        $service = $this->vpsService(attributes: ['status' => ServiceStatus::Pending, 'module_data' => ['vpsid' => '90', 'uid' => '58']]);

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertSame('This service is already linked to VPS #90. Set its status to Active instead of creating a new VPS.', $result->message);
        Http::assertNothingSent();
    }

    public function test_a_new_vps_is_only_filed_under_an_existing_user_with_the_clients_confirmed_email(): void
    {
        $users = [];
        $this->fakeVirtualizor([
            'users' => function (array $query) use (&$users): array {
                return ['users' => $users];
            },
            'addvs' => ['done' => 1, 'vs_info' => ['vpsid' => '95', 'uid' => '58', 'ips' => ['203.0.113.95']]],
        ]);
        $client = $this->client('Mer Las', ['email' => 'Mer.Las@Example.test']);
        $create = fn () => app(Provisioner::class)->create($this->vpsService(attributes: ['status' => ServiceStatus::Pending, 'module_data' => null], client: $client));

        // No user with the email: Virtualizor makes one.
        $users = ['61' => ['uid' => '61', 'email' => 'someone.else@example.test', 'type' => '0']];
        $this->assertTrue($create()->success);
        $this->assertSame(['email' => 'mer.las@example.test'], $this->sent('users')[0]['data']);
        $this->assertCount(1, $this->sent('addvs'));

        // A user with the email while the client has not confirmed it: nothing is made.
        $users['58'] = ['uid' => '58', 'email' => self::CLIENT_EMAIL, 'type' => '0'];
        $result = $create();
        $this->assertFalse($result->success);
        $this->assertStringStartsWith('A Virtualizor user with this client\'s email already exists, and the client has not confirmed this email yet', $result->message);
        $this->assertCount(1, $this->sent('addvs'));

        // Confirmed: the VPS goes to that user.
        $client->forceFill(['email_verified_at' => now()])->save();
        $this->assertTrue($create()->success);
        $this->assertCount(2, $this->sent('addvs'));

        // An admin or cloud user with the email: never.
        $users['58']['type'] = '1';
        $result = $create();
        $this->assertFalse($result->success);
        $this->assertSame('A Virtualizor admin or cloud user has this client\'s email. Change the client\'s email or that user in Virtualizor. No VPS was made.', $result->message);
        $this->assertCount(2, $this->sent('addvs'));

        // When the lookup fails, nothing is made, so a later try is safe.
        $this->fakeVirtualizor(['users' => ['error' => ['Too many requests']], 'addvs' => ['done' => 1, 'vs_info' => ['vpsid' => '96']]]);
        $this->assertFalse($create()->success);
        $this->assertSame([], $this->sent('addvs'));
    }

    public function test_the_link_form_fields_need_no_server(): void
    {
        $this->fakeVirtualizor();
        $service = $this->vpsService();

        $fields = app(Provisioner::class)->linkFields($service);

        $this->assertSame(['vpsid', 'confirm_email_mismatch'], array_keys($fields));
        $this->assertSame('checkbox', $fields['confirm_email_mismatch']['type']);
        $this->assertTrue($fields['vpsid']['required']);
        $this->assertSame([], $this->api->calls);
    }
}
