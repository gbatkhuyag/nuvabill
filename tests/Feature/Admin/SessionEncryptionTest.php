<?php

namespace Tests\Feature\Admin;

use App\Health\Checks\SettingsChecks;
use App\Health\Repairs;
use App\Health\Status;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\EncryptedStore;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Session data is encrypted on new sites. Older sites get a site health item with a one-click
 * fix, which signs everyone out once.
 */
class SessionEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_sites_encrypt_session_data(): void
    {
        $this->assertMatchesRegularExpression('/^SESSION_ENCRYPT=true$/m', (string) file_get_contents(base_path('.env.example')));
    }

    public function test_site_health_warns_and_the_fix_writes_the_env_file(): void
    {
        config(['session.encrypt' => false]);
        $check = collect(app(SettingsChecks::class)->run())->firstWhere('id', 'settings.encrypt_sessions');

        $this->assertSame(Status::Warning, $check->status);
        $this->assertSame('settings.encrypt_sessions', $check->fix['action']);
        $this->assertStringContainsString('signed out', $check->fix['confirm']);

        $env = tempnam(sys_get_temp_dir(), 'nb-env');
        file_put_contents($env, "APP_NAME=Nuvabill\nSESSION_ENCRYPT=false\nSESSION_LIFETIME=120\n");

        try {
            app()->make(Repairs::class, ['envPath' => $env])->run($check->fix, Admin::factory()->create(['name' => 'Mer']));

            $this->assertSame("APP_NAME=Nuvabill\nSESSION_ENCRYPT=true\nSESSION_LIFETIME=120\n", file_get_contents($env));
        } finally {
            @unlink($env);
        }

        config(['session.encrypt' => true]);
        $this->assertSame(Status::Passed, collect(app(SettingsChecks::class)->run())->firstWhere('id', 'settings.encrypt_sessions')->status);
    }

    public function test_a_session_saved_before_encryption_is_read_as_empty(): void
    {
        $id = Str::random(40);
        $handler = new ArraySessionHandler(120);
        $handler->write($id, serialize(['_token' => 'old-token', 'login_admin' => 7]));

        $store = new EncryptedStore('nuvabill_session', $handler, app('encrypter'), $id);
        $store->start();

        $this->assertFalse($store->has('login_admin'));
        $this->assertNotSame('old-token', $store->token());
    }
}
