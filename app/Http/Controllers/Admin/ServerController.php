<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\UsesMachineInventory;
use App\Extensions\ExtensionManager;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Provisioning\Provisioner;
use App\Provisioning\ServerTransport;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ServerController extends Controller
{
    /**
     * Each server with the accounts that count towards its limit, the same count that decides
     * whether it takes a new account.
     */
    public function index(): View
    {
        return view('admin.servers.index', [
            'servers' => Server::query()->withCount('accounts')->orderBy('name')->get(),
        ]);
    }

    /**
     * "?module=cpanel" picks the module, for the links on the Extensions page.
     */
    public function create(Request $request, ExtensionManager $extensions): View
    {
        $module = self::modules($extensions)->has((string) $request->query('module')) ? (string) $request->query('module') : null;

        return $this->form(new Server(['use_ssl' => true, 'is_active' => true, 'username' => 'root', 'module' => $module]), $extensions);
    }

    public function store(Request $request, ExtensionManager $extensions): RedirectResponse
    {
        $server = Server::create($this->validated($request, $extensions));
        Activity::log('server.created', "Server {$server->name} added", $server);

        return redirect()->route('admin.servers.index')->with('status', __('Server added. Use “Test connection” to check the details.'));
    }

    public function edit(Server $server, ExtensionManager $extensions): View
    {
        return $this->form($server, $extensions);
    }

    public function update(Request $request, Server $server, ExtensionManager $extensions): RedirectResponse
    {
        $data = $this->validated($request, $extensions);
        $server->fill(Arr::except($data, ['password', 'api_token']));

        // A saved password or token is only ever sent to the server it was entered for. When the
        // address or module changes, both must be entered again, so an old secret never goes to a new host.
        $addressChanged = $server->isDirty(['module', 'hostname', 'port', 'use_ssl', 'allow_plain_http', 'username']);

        if ($addressChanged && blank($data['password'] ?? null) && blank($data['api_token'] ?? null) && (filled($server->password) || filled($server->api_token))) {
            throw ValidationException::withMessages(['api_token' => __('The server address, port, SSL, user name or module changed. Enter the API token or password again.')]);
        }

        foreach (['password', 'api_token'] as $secret) {
            if (filled($data[$secret] ?? null)) {
                $server->{$secret} = $data[$secret];
            } elseif ($addressChanged) {
                $server->{$secret} = null;
            }
        }

        $server->save();
        Activity::log('server.updated', "Server {$server->name} updated", $server);

        return redirect()->route('admin.servers.index')->with('status', __('Server saved.'));
    }

    public function destroy(Server $server): RedirectResponse
    {
        if ($server->services()->exists()) {
            return back()->with('error', __('Services still use this server. Move them or turn the server off instead.'));
        }

        $server->delete();

        return redirect()->route('admin.servers.index')->with('status', __('Server removed.'));
    }

    public function test(Server $server, Provisioner $provisioner): RedirectResponse
    {
        $result = $provisioner->testConnection($server);

        return back()->with($result->success ? 'status' : 'error', $result->message);
    }

    /**
     * Server modules a server record can be made for: not the ones whose services run on machines
     * from Admin → Hardware, which never use a server record.
     *
     * @return Collection<string, string>
     */
    private static function modules(ExtensionManager $extensions): Collection
    {
        return $extensions->serverModuleNames()->reject(function (string $name, string $slug) use ($extensions): bool {
            try {
                return $extensions->serverModule($slug) instanceof UsesMachineInventory;
            } catch (Throwable) {
                return false;
            }
        });
    }

    private function form(Server $server, ExtensionManager $extensions): View
    {
        $modules = self::modules($extensions);

        return view('admin.servers.form', [
            'server' => $server,
            'modules' => $modules->all(),
            'help' => $modules->keys()->mapWithKeys(fn (string $slug): array => [$slug => [
                'text' => $extensions->serverModule($slug)->serverHelp(),
                'port' => $extensions->serverModule($slug)->defaultPort(),
            ]])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ExtensionManager $extensions): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'module' => ['required', Rule::in(self::modules($extensions)->keys()->all())],
            'hostname' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+$/'],
            'ip_address' => ['nullable', 'ip'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'use_ssl' => ['boolean'],
            'allow_plain_http' => ['boolean'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:190'],
            'api_token' => ['nullable', 'string', 'max:2000'],
            'max_accounts' => ['nullable', 'integer', 'min:1'],
            'nameservers' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ], ['hostname.regex' => __('Enter only the host name, for example server1.example.com, without https:// or a path.')]);

        $this->checkPlainHttp($data);
        $data['nameservers'] = collect(preg_split('/[\s,]+/', (string) ($data['nameservers'] ?? '')))->filter()->values()->all() ?: null;

        return $data;
    }

    /**
     * Plain HTTP sends the password or API token without encryption, so it needs its own box and
     * only works for a server on a private network or this machine. With HTTPS on, the box is cleared.
     *
     * @param  array<string, mixed>  $data
     */
    private function checkPlainHttp(array &$data): void
    {
        $data['use_ssl'] = (bool) ($data['use_ssl'] ?? true);

        if ($data['use_ssl']) {
            $data['allow_plain_http'] = false;

            return;
        }

        if (! (bool) ($data['allow_plain_http'] ?? false)) {
            throw ValidationException::withMessages(['use_ssl' => __('Turn on "Connect with HTTPS". Plain HTTP needs the box "Allow plain HTTP (private network only)".')]);
        }

        foreach (['hostname', 'ip_address'] as $field) {
            if (filled($data[$field] ?? null) && ! ServerTransport::isPrivateHost((string) $data[$field])) {
                throw ValidationException::withMessages([$field => __('Plain HTTP only works for a server on a private network. Turn on "Connect with HTTPS" for a server on the internet.')]);
            }
        }

        $data['allow_plain_http'] = true;
    }
}
