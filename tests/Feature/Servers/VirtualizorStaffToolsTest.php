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
        // The setting is named as staff see it on the server form.
        $this->assertSame('"Connect with HTTPS" is on and the API password is set.', $rows[1]['detail']);
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

    public function test_the_access_check_names_the_https_switch_as_the_server_form_does(): void
    {
        $this->fakeVirtualizor();
        $service = $this->vpsService(['client_login' => '1'], server: $this->master(['use_ssl' => false]));

        $rows = collect(app(Provisioner::class)->checkClientAccess($service))->keyBy('label');

        $this->assertSame(['fail', 'Turn on "Connect with HTTPS" on the server.'], [$rows['Server settings']['status'], $rows['Server settings']['detail']]);
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
        $this->assertSame(['vps'], array_column($this->api->calls, 'kind'), 'Only Virtualizor\'s list of VPS is asked.');

        // When Virtualizor cannot say whether the VPS still exists, nothing is made either.
        $this->fakeVirtualizor(['vps' => ['error' => ['Too many requests']], 'addvs' => ['done' => 1, 'vs_info' => ['vpsid' => '95', 'uid' => '58']]]);
        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertFalse($result->success);
        $this->assertStringStartsWith('This service is linked to VPS #90, and Virtualizor could not say whether that VPS still exists, so no VPS was made.', $result->message);
        $this->assertSame([], $this->sent('addvs'));

        // A list that did not keep to the search could hide the VPS: nothing is made on a guess.
        foreach ([['vs' => ['12' => $this->vpsRow('12')]], ['vs' => 'none']] as $answer) {
            $this->fakeVirtualizor(['vps' => $answer, 'addvs' => ['done' => 1, 'vs_info' => ['vpsid' => '95', 'uid' => '58']]]);

            $this->assertStringEndsWith('(Its answer did not clearly show the VPS is gone.)', app(Provisioner::class)->create($service->fresh())->message);
            $this->assertSame([], $this->sent('addvs'));
        }

        $this->assertSame('90', $service->fresh()->module_data['vpsid']);
    }

    public function test_a_service_set_back_to_pending_after_its_vps_was_deleted_gets_a_new_vps(): void
    {
        $this->fakeVirtualizor([
            // Virtualizor no longer lists VPS 90: it was deleted when the service was terminated.
            'vps' => fn (array $query): array => ['vs' => $query['vpsid'] === '90' ? [] : [$query['vpsid'] => $this->vpsRow((string) $query['vpsid'])]],
            'addvs' => ['done' => 1, 'vs_info' => ['vpsid' => '95', 'uid' => '61', 'ips' => ['203.0.113.95']]],
        ]);
        $service = $this->vpsService(attributes: ['status' => ServiceStatus::Terminated, 'module_data' => [
            'vpsid' => '90',
            'uid' => '58',
            'vps_uuid' => '0a0a0a0a-1111-4222-8333-444455556666',
            'ips' => ['203.0.113.90'],
            'reinstalls' => ['month' => '2026-09', 'count' => 1],
        ]]);
        $service->update(['status' => ServiceStatus::Pending]);

        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertCount(1, $this->sent('addvs'));
        $fresh = $service->fresh();
        $this->assertSame(ServiceStatus::Active, $fresh->status);
        $this->assertSame('95', $fresh->module_data['vpsid']);
        $this->assertSame('61', $fresh->module_data['uid']);
        $this->assertSame(['203.0.113.95'], $fresh->module_data['ips']);
        $this->assertArrayNotHasKey('vps_uuid', $fresh->module_data, 'The new VPS\'s UUID is learned again.');
        $this->assertSameStoredJson(['month' => '2026-09', 'count' => 1], $fresh->module_data['reinstalls']);
        $this->assertStringContainsString('was unlinked from VPS #90, which Virtualizor no longer lists', (string) ActivityLog::query()->where('action', 'service.vps_unlinked')->value('description'));
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

    public function test_a_new_vps_is_never_filed_under_a_user_nuvabill_has_not_linked_to_this_client(): void
    {
        $this->fakeVirtualizor([
            'users' => ['users' => ['58' => ['uid' => '58', 'email' => self::CLIENT_EMAIL, 'type' => '0']]],
            'addvs' => ['done' => 1, 'vs_info' => ['vpsid' => '95', 'uid' => '58', 'ips' => ['203.0.113.95']]],
        ]);
        // The client's email is confirmed: before, that alone let the VPS go to user 58.
        $client = $this->client('Mer Las', ['email' => self::CLIENT_EMAIL, 'email_verified_at' => now()]);
        $create = fn () => app(Provisioner::class)->create($this->vpsService(attributes: ['status' => ServiceStatus::Pending, 'module_data' => null], client: $client));
        $refusal = 'A Virtualizor user with this client\'s email exists that Nuvabill has not linked to this client. Link one of the client\'s VPS to it, or change that user in Virtualizor. No VPS was made.';

        // (b) User 58 is on none of this client's services: someone else may have made it.
        $result = $create();
        $this->assertFalse($result->success);
        $this->assertSame($refusal, $result->message);
        $this->assertSame([], $this->sent('addvs'));

        // (a) User 58 holds a VPS of another client's service, even an ended one.
        $this->vpsService(attributes: ['status' => ServiceStatus::Terminated, 'module_data' => ['vpsid' => '70', 'uid' => '58']], client: $this->client('Raz'));
        $this->assertSame($refusal, $create()->message);
        $this->assertSame([], $this->sent('addvs'));

        // Also linked to this client: still not, while another client's service holds the user.
        $this->vpsService(attributes: ['module_data' => ['vpsid' => '77', 'uid' => 58]], client: $client);
        $this->assertSame($refusal, $create()->message);
        $this->assertSame([], $this->sent('addvs'));

        // A user on another master does not count.
        Service::query()->where('client_id', '!=', $client->id)->update(['server_id' => $this->master(['hostname' => 'vz2.example.test'])->id]);
        $this->assertTrue($create()->success);
        $this->assertCount(1, $this->sent('addvs'));
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
