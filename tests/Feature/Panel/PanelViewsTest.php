<?php

namespace Tests\Feature\Panel;

use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Panel\PanelSection;
use App\Models\Admin;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * The screens of the client server panel: the tabbed panel on the service page, its section views,
 * the console page and the staff "Client access" card. The panel's scripts have their own tests in
 * tests/js (server-panel, panel-chart and vps-panel).
 */
class PanelViewsTest extends TestCase
{
    use RefreshDatabase, UsesPanelFake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPanelFake();
    }

    public function test_the_tabs_follow_the_declared_order_with_tab_roles_and_only_the_first_is_open(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $html = $this->get(route('client.services.show', $service))->assertOk()->getContent();

        $this->assertStringContainsString('role="tablist"', $html);
        preg_match_all('/<button[^>]*role="tab"[^>]*id="tab-([a-z0-9-]+)"[^>]*aria-controls="panel-([a-z0-9-]+)"[^>]*aria-selected="(true|false)"/', $html, $tabs);
        $this->assertSame(['overview', 'console', 'graphs', 'network', 'reinstall', 'settings'], $tabs[1]);
        $this->assertSame($tabs[1], $tabs[2]);
        $this->assertSame(['true', 'false', 'false', 'false', 'false', 'false'], $tabs[3]);

        preg_match_all('/<div[^>]*role="tabpanel"[^>]*id="panel-([a-z0-9-]+)"[^>]*aria-labelledby="tab-([a-z0-9-]+)"[^>]*>/', $html, $panels);
        $this->assertSame($tabs[1], $panels[1]);
        $this->assertSame($panels[1], $panels[2]);
        $this->assertStringNotContainsString('display: none', $panels[0][0], 'The first tab is open at first paint.');
        $this->assertStringContainsString('style="display: none"', $panels[0][1]);
        $this->assertStringContainsString('x-data="liveStats(', $panels[0][0]);
        $this->assertStringContainsString('x-data="panelSection(', $panels[0][2]);

        // Switched-off and undeclared sections get no tab, and nothing names them.
        $this->assertStringNotContainsString('panel-snapshots', $html);
        $this->assertStringNotContainsString('Snapshots', $html);
    }

    public function test_the_header_has_the_power_buttons_the_console_and_the_sign_in_button(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $html = $this->get(route('client.services.show', $service))->assertOk()->getContent();

        preg_match_all('/data-action="([a-z0-9-]+)"/', $html, $power);
        $this->assertSame(['start', 'stop', 'restart', 'poweroff'], $power[1]);
        $this->assertMatchesRegularExpression('/formaction="'.preg_quote(route('client.services.sections.action', [$service, 'power', 'poweroff']), '/').'"\s+data-action="poweroff"\s+data-confirm="[^"]+"\s+data-destructive/', $html);
        // Without JavaScript nobody asks "Are you sure?", so a forced action has its own box.
        $this->assertStringContainsString('<noscript>', $html);
        $this->assertStringContainsString('name="confirm" value="1"', $html);

        $this->assertMatchesRegularExpression('/<form method="POST" action="'.preg_quote(route('client.services.console.open', $service), '/').'" target="_blank">/', $html);
        $this->assertMatchesRegularExpression('/<form method="POST" action="'.preg_quote(route('client.services.login', $service), '/').'" target="_blank" rel="noopener noreferrer">/', $html);
        $this->assertStringContainsString('Open Panel fake panel', $html);
        $this->assertStringContainsString('vpsPanel(', $html);
    }

    public function test_panel_views_never_write_remote_data_as_html(): void
    {
        $views = [
            ...File::allFiles(resource_path('../themes/nova/views/client/services/panel')),
            new \SplFileInfo(base_path('themes/nova/views/client/services/server-panel.blade.php')),
            new \SplFileInfo(base_path('themes/nova/views/client/services/console.blade.php')),
        ];

        foreach ($views as $view) {
            $source = (string) file_get_contents($view->getPathname());

            $this->assertStringNotContainsString('x-html', $source, $view->getFilename());
        }

        foreach (['server-panel.js', 'panel-chart.js', 'console.js'] as $script) {
            $source = (string) file_get_contents(resource_path('js/'.$script));

            foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval('] as $sink) {
                $this->assertStringNotContainsString($sink, $source, "{$script} uses {$sink}");
            }
        }
    }

    public function test_without_javascript_the_settings_forms_still_post_and_secrets_need_javascript(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $html = $this->get(route('client.services.show', $service))->assertOk()->getContent();

        // The tabs are shown one under the other.
        $this->assertStringContainsString('.server-panel .tab-panel{display:grid!important}', $html);

        // Showing console details is a reveal: it needs the page's script.
        $console = $this->panelHtml($html, 'console');
        $this->assertStringContainsString('<noscript><p class="muted">Turn on JavaScript to do this.</p></noscript>', $console);
        $this->assertStringContainsString('data-js-only', $console);

        foreach (['graphs', 'network', 'reinstall'] as $section) {
            $this->assertStringContainsString('Turn on JavaScript to see this.', $this->panelHtml($html, $section), $section);
        }

        // The settings forms are plain forms to the section actions.
        $settings = $this->panelHtml($html, 'settings');
        $this->assertStringContainsString('action="'.route('client.services.sections.action', [$service, 'settings', 'hostname']).'"', $settings);
        $this->assertStringContainsString('action="'.route('client.services.sections.action', [$service, 'settings', 'root-password']).'"', $settings);
        $this->assertStringContainsString('name="password_confirmation"', $settings);
        $this->assertStringNotContainsString('Turn on JavaScript', $settings);

        // A plain post comes back to the tab with its message.
        $this->post(route('client.services.sections.action', [$service, 'settings', 'hostname']), ['hostname' => 'web1.example.test'])
            ->assertRedirect(route('client.services.show', $service).'#panel-settings');
        $this->get(route('client.services.show', $service))->assertSee('Done.');
    }

    public function test_the_overview_shows_a_skeleton_when_the_module_has_no_first_paint_data(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $overview = $this->panelHtml($this->get(route('client.services.show', $service))->assertOk()->getContent(), 'overview');

        $this->assertStringContainsString('class="skeleton-block"', $overview);
        $this->assertStringContainsString('Turn on JavaScript to see this.', $overview);
        $this->assertSame([], PanelFakeModule::calls('readPanelSection'), 'The page itself never reads a section.');
    }

    public function test_the_overview_first_paint_comes_from_the_modules_own_panel_data(): void
    {
        $service = $this->panelService();
        $panel = [
            'view' => 'theme::client.services.server-panel',
            'data' => [
                'hostname' => 'vps1.example.test',
                'os' => 'Ubuntu 24.04',
                'ips' => ['192.0.2.10', '2001:db8::10', ['bad']],
                'state' => 'running',
                'cpu' => 12.5,
                'ram' => ['used' => 1024, 'total' => 2048, 'unit' => 'MB'],
                'disk' => ['used' => 10, 'total' => 0, 'unit' => 'GB'],
                'network' => ['in' => 2048, 'out' => 100],
                'templates' => ['77' => 'Secret template list'],
                'vnc' => ['password' => 'never-shown'],
            ],
            'actions' => [],
            'state' => 'running',
            'login' => null,
            'console' => null,
            'power' => [],
            'sections' => [[
                'key' => 'overview', 'label' => 'Overview', 'icon' => 'server', 'view' => 'theme::client.services.panel.overview',
                'read_url' => route('client.services.sections.show', [$service, 'overview']), 'poll' => 10, 'actions' => [],
            ]],
            'status_url' => route('client.services.panel-status', $service),
            'resources_url' => route('client.services.show', $service).'#resources',
        ];

        $html = view('theme::client.services.server-panel', ['service' => $service, 'serverPanel' => $panel])->render();

        $this->assertStringContainsString('vps1.example.test', $html);
        $this->assertStringContainsString('<bdi>Ubuntu 24.04</bdi>', $html);
        $this->assertStringContainsString('<span dir="ltr">12.5%</span>', $html);
        $this->assertStringContainsString('<span dir="ltr">1,024 / 2,048 MB</span>', $html);
        $this->assertStringContainsString('<span dir="ltr">10 GB / Unlimited</span>', $html);
        $this->assertStringContainsString('2.0 KB/s', $html);
        $this->assertStringContainsString('2001:db8::10', $html);
        $this->assertStringContainsString('Change resources', $html);
        $this->assertStringContainsString(str_replace('"', chr(92).'u0022', '"initial":{"state":"running","cpu":12.5'), $html, 'The overview starts with this data.');
        // Only the standard figures reach the page: not the module's other panel data.
        $this->assertStringNotContainsString('Secret template list', $html);
        $this->assertStringNotContainsString('never-shown', $html);
    }

    public function test_an_older_theme_that_includes_the_panel_without_server_panel_still_opens(): void
    {
        $service = $this->panelService();
        // What an older theme's own client/services/show view passes: panel, actions and result only.
        $old = ['service' => $service, 'panel' => ['hostname' => 'vps1.example.test', 'state' => 'running'], 'actions' => [], 'result' => null];

        $html = view('theme::client.services.server-panel', $old)->render();

        $this->assertStringContainsString('vps1.example.test', $html);
        $this->assertStringContainsString('vps-panel', $html);
        $this->assertStringNotContainsString('role="tablist"', $html);

        // Without even the panel data, a short notice instead of an error.
        $this->assertStringContainsString('Update your theme to show the server panel.', view('theme::client.services.server-panel', ['service' => $service])->render());
        $this->assertStringContainsString('Update your theme to show the server panel.', view('theme::client.services.server-panel', ['service' => $service, 'serverPanel' => 'x'])->render());
    }

    public function test_a_module_section_uses_its_own_view_and_is_left_out_without_one(): void
    {
        PanelFakeModule::$behaviour['sections'] = [PanelSection::standard('overview'), PanelFakeModule::customSection()];
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        // Its view namespace is not loaded: the tab is left out and the page still works.
        $this->get(route('client.services.show', $service))->assertOk()->assertDontSee('id="tab-notes"', false);

        app(ExtensionManager::class)->registerViews();
        $this->get(route('client.services.show', $service))->assertOk()
            ->assertSee('id="tab-notes"', false)
            ->assertSee('data-panel-fake-notes', false);
    }

    public function test_the_console_page_holds_no_secret_and_offers_a_new_start_when_expired(): void
    {
        $service = $this->panelService();
        // The console ticket belongs to one browser session.
        $this->actingAs($this->client, 'web')->withCredentials()->withCookie((string) config('session.cookie'), Str::random(40));

        $location = $this->post(route('client.services.console.open', $service))->assertStatus(303)->headers->get('Location');
        $page = $this->get($location)->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('data-console', false)
            ->assertSee('data-redeem="'.route('client.services.console.redeem', $service).'"', false)
            ->assertSee('Ctrl+Alt+Del')
            ->assertSee('<a href="https://github.com/novnc/noVNC" target="_blank" rel="noopener noreferrer">noVNC</a> (MPL-2.0)', false)
            ->assertDontSee(PanelFakeModule::CONSOLE_PASSWORD)
            ->assertDontSee('console.example.test')
            ->assertDontSee('x-data', false);
        $this->assertStringContainsString('console-', implode(' ', $this->viteEntries($page->getContent())));
        $this->assertStringNotContainsString('app-', implode(' ', $this->viteEntries($page->getContent())), 'The console page loads noVNC only, without the site script.');

        $this->get(route('client.services.console', [$service, 'ticket' => str_repeat('x', 40)]))->assertOk()
            ->assertSee('This console link has expired.')
            ->assertSee('Start the console again')
            ->assertDontSee('data-console ', false)
            ->assertSee('action="'.route('client.services.console.open', $service).'"', false);
    }

    public function test_staff_see_the_client_access_card_with_the_check_results_and_the_link_form(): void
    {
        $service = $this->panelService();
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.services.show', $service))->assertOk()
            ->assertSee('id="client-access"', false)
            ->assertSee('Test panel sign-in')
            ->assertSee('action="'.route('admin.services.link', $service).'"', false)
            ->assertSee('name="vps_id"', false)
            ->assertSee('The ID in the fake panel.')
            ->assertDontSee('passed, ');

        $this->post(route('admin.services.access-check', $service));
        $this->get(route('admin.services.show', $service))->assertOk()
            ->assertSee('1 passed, 0 to check, 0 failed')
            ->assertSee('Panel answers')
            ->assertSee('The fake panel does not check this.')
            ->assertSee('Skipped');

        // A failed link keeps the typed value and shows the error at its field.
        $this->post(route('admin.services.link', $service), ['vps_id' => str_repeat('9', 300)]);
        $card = $this->get(route('admin.services.show', $service))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<details\s+open\s*>/', $card);
        $this->assertMatchesRegularExpression('/id="link-vps_id"[^>]*value="9{300}"[^>]*aria-invalid="true"/', $card);

        $plain = Service::factory()->create(['client_id' => $this->client->id]);
        $this->get(route('admin.services.show', $plain))->assertOk()->assertDontSee('id="client-access"', false);
    }

    /**
     * The HTML of one tab panel, up to the next one.
     */
    private function panelHtml(string $html, string $section): string
    {
        $start = strpos($html, 'id="panel-'.$section.'"');
        $this->assertNotFalse($start, "No panel {$section}.");
        $next = strpos($html, 'role="tabpanel"', $start + 20);

        return substr($html, $start, $next === false ? null : $next - $start);
    }

    /**
     * The built script and style files a page loads.
     *
     * @return list<string>
     */
    private function viteEntries(string $html): array
    {
        preg_match_all('#/build/assets/([A-Za-z0-9_.-]+)#', $html, $matches);

        return $matches[1];
    }
}
