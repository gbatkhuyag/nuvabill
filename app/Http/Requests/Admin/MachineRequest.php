<?php

namespace App\Http\Requests\Admin;

use App\Enums\BmcType;
use App\Enums\MachineStatus;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Provisioning\Hardware\BmcAddress;
use App\Provisioning\Hardware\BmcHostRule;
use App\Provisioning\Hardware\Drivers\PowerDrivers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The machine form (Admin → Hardware): what the machine is, the products it is sold as, its IP
 * addresses and its BMC login.
 *
 * The BMC login is never shown again once saved. It is required for a new machine with power
 * control, and again whenever the BMC type, address or port changes, so a saved login is never sent
 * to a new address. IP lines are checked here and kept in ipLines() for the controller.
 */
class MachineRequest extends FormRequest
{
    /** Most lines one IP list may have. */
    public const MAX_IP_LINES = 512;

    /** One IP line: an address, an optional prefix and an optional gateway, as "203.0.113.10/29 via 203.0.113.9". */
    private const IP_LINE = '/^(?<ip>[0-9A-Fa-f:.]+)(?:\/(?<prefix>\d{1,3}))?(?:\s+via\s+(?<gw>[0-9A-Fa-f:.]+))?$/D';

    /** IP errors shown per field at most, so a long pasted list does not flood the page. */
    private const MAX_ERRORS_PER_FIELD = 5;

    /**
     * The checked IP addresses of the machine, in form order.
     *
     * @var list<array{address: string, version: int, prefix: ?int, gateway: ?string, kind: string}>
     */
    private array $ips = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $machine = $this->machine();

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('machines', 'name')->ignore($machine?->getKey())],
            'location' => ['nullable', 'string', 'max:100'],
            'rack' => ['nullable', 'string', 'max:50'],
            'rack_unit' => ['nullable', 'string', 'max:20'],
            'cpu' => ['nullable', 'string', 'max:190'],
            'cpu_cores' => ['nullable', 'integer', 'between:1,1024'],
            'ram_gb' => ['nullable', 'integer', 'between:1,65536'],
            'storage' => ['nullable', 'string', 'max:255'],
            'port_speed' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'product_ids' => ['nullable', 'array', 'max:500'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('uses_machines', true)],
            'primary_ip' => ['nullable', 'string', 'max:45'],
            'primary_prefix' => ['nullable', 'integer', 'between:0,128'],
            'primary_gateway' => ['nullable', 'string', 'max:45'],
            'included_ips' => ['nullable', 'string', 'max:20000'],
            'extra_ips' => ['nullable', 'string', 'max:20000'],
            'bmc_type' => ['required', 'string', Rule::in($this->bmcTypes())],
            'bmc_host' => [Rule::requiredIf(fn (): bool => $this->input('bmc_type') !== BmcType::None->value), 'nullable', 'string', 'max:190', new BmcHostRule],
            'bmc_port' => ['nullable', 'integer', 'between:1,65535'],
            'bmc_username' => ['nullable', 'string', 'max:190'],
            'bmc_password' => ['nullable', 'string', 'max:190'],
            'bmc_tls_mode' => ['required', Rule::in(['pinned', 'verified'])],
            'bmc_ipmi_cipher' => ['nullable', 'integer', Rule::in([3, 17])],
            // A new machine may start in maintenance; later its status only moves through its own buttons.
            'status' => $machine === null ? ['nullable', Rule::in([MachineStatus::Available->value, MachineStatus::Maintenance->value])] : ['exclude'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Name'),
            'location' => __('Location'),
            'rack' => __('Rack'),
            'rack_unit' => __('Rack unit'),
            'cpu' => __('CPU'),
            'cpu_cores' => __('CPU cores'),
            'ram_gb' => __('RAM (GB)'),
            'storage' => __('Storage'),
            'port_speed' => __('Port speed'),
            'notes' => __('Staff notes'),
            'product_ids' => __('Products'),
            'product_ids.*' => __('Products'),
            'primary_ip' => __('Main IP address'),
            'primary_prefix' => __('Prefix'),
            'primary_gateway' => __('Gateway'),
            'included_ips' => __('Included IP addresses'),
            'extra_ips' => __('Extra IP addresses'),
            'bmc_type' => __('Power control'),
            'bmc_host' => __('BMC address'),
            'bmc_port' => __('BMC port'),
            'bmc_username' => __('BMC user name'),
            'bmc_password' => __('BMC password'),
            'bmc_tls_mode' => __('Certificate check'),
            'bmc_ipmi_cipher' => __('IPMI cipher suite'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->checkLogin($validator);
                $this->ips = $this->checkIps($validator);
            },
        ];
    }

    /**
     * The machine's IP addresses from the form, checked and in canonical form.
     *
     * @return list<array{address: string, version: int, prefix: ?int, gateway: ?string, kind: string}>
     */
    public function ipLines(): array
    {
        return $this->ips;
    }

    /**
     * Whether the BMC type, address or port differs from the saved machine (always for a new one).
     */
    public function bmcAddressChanged(): bool
    {
        $machine = $this->machine();

        if ($machine === null) {
            return true;
        }

        $type = (string) $this->input('bmc_type');

        return $type !== (string) $machine->bmc_type
            || trim((string) $this->input('bmc_host')) !== (string) $machine->bmc_host
            || ((int) $this->input('bmc_port') ?: self::defaultPort($type)) !== ((int) $machine->bmc_port ?: self::defaultPort((string) $machine->bmc_type));
    }

    /**
     * The port a BMC type uses when none is set, so an empty port and the usual one are the same.
     */
    private static function defaultPort(string $type): int
    {
        return match ($type) {
            BmcType::Redfish->value => 443,
            BmcType::Ipmi->value => 623,
            default => 0,
        };
    }

    /**
     * The machine being edited, or null on the "Add machine" form.
     */
    public function machine(): ?Machine
    {
        $machine = $this->route('machine');

        return $machine instanceof Machine ? $machine : null;
    }

    /**
     * The power control types staff may pick: none, the drivers this site can use now, and the
     * machine's saved type, so a machine whose driver was switched off can still be edited.
     *
     * @return list<string>
     */
    private function bmcTypes(): array
    {
        $types = [BmcType::None->value, ...array_keys(app(PowerDrivers::class)->available())];
        $saved = $this->machine()?->bmc_type;

        if (is_string($saved) && $saved !== '') {
            $types[] = $saved;
        }

        return array_values(array_unique($types));
    }

    /**
     * A new machine with power control needs its BMC login, and so does a machine whose BMC type,
     * address or port changed. Blank fields otherwise keep the saved login.
     */
    private function checkLogin(Validator $validator): void
    {
        if ($this->input('bmc_type') === BmcType::None->value) {
            return;
        }

        $machine = $this->machine();
        $missing = blank($this->input('bmc_username')) || blank($this->input('bmc_password'));

        if (! $missing) {
            return;
        }

        if ($machine === null) {
            $validator->errors()->add('bmc_username', __('Enter the user name and password of the BMC.'));

            return;
        }

        if ($this->bmcAddressChanged()) {
            $validator->errors()->add('bmc_username', __('The BMC address or type changed. Enter its user name and password again.'));

            return;
        }

        // A machine that has no saved login yet needs both now.
        if (blank($machine->bmc_username) || blank($machine->bmc_password)) {
            $validator->errors()->add('bmc_username', __('Enter the user name and password of the BMC.'));
        }
    }

    /**
     * Read and check the main, included and extra IP addresses: valid, usable, the prefix fits the
     * IP version, each listed once, and not on another machine.
     *
     * @return list<array{address: string, version: int, prefix: ?int, gateway: ?string, kind: string}>
     */
    private function checkIps(Validator $validator): array
    {
        $lines = [];
        $errors = [];
        $add = function (string $field, string $message) use (&$errors): void {
            $errors[$field][] = $message;
        };

        $primary = trim((string) $this->input('primary_ip'));

        if ($primary !== '') {
            $line = $this->ipLine($primary, $this->input('primary_prefix'), $this->input('primary_gateway'));

            if (is_string($line)) {
                $add('primary_ip', $line);
            } else {
                $lines[] = $line + ['kind' => MachineIp::KIND_PRIMARY, 'field' => 'primary_ip'];
            }
        }

        foreach (['included_ips' => MachineIp::KIND_INCLUDED, 'extra_ips' => MachineIp::KIND_EXTRA] as $field => $kind) {
            $rows = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $this->input($field)) ?: []), fn (string $row): bool => $row !== ''));

            if (count($rows) > self::MAX_IP_LINES) {
                $add($field, __('Enter at most :count addresses.', ['count' => self::MAX_IP_LINES]));

                continue;
            }

            foreach ($rows as $index => $row) {
                if (preg_match(self::IP_LINE, $row, $match) !== 1) {
                    $add($field, __('Line :line: write it as 203.0.113.10/29 via 203.0.113.9.', ['line' => $index + 1]));

                    continue;
                }

                $line = $this->ipLine($match['ip'], ($match['prefix'] ?? '') !== '' ? $match['prefix'] : null, ($match['gw'] ?? '') !== '' ? $match['gw'] : null);

                if (is_string($line)) {
                    $add($field, __('Line :line: :problem', ['line' => $index + 1, 'problem' => $line]));
                } else {
                    $lines[] = $line + ['kind' => $kind, 'field' => $field];
                }
            }
        }

        $seen = [];

        foreach ($lines as $line) {
            if (isset($seen[$line['address']])) {
                $add($line['field'], __(':ip is listed twice.', ['ip' => $line['address']]));
            }

            $seen[$line['address']] = $line['field'];
        }

        if ($seen !== []) {
            $taken = MachineIp::query()
                ->with('machine:id,name')
                ->whereIn('address', array_keys($seen))
                ->when($this->machine(), fn ($query, Machine $machine) => $query->where('machine_id', '!=', $machine->getKey()))
                ->get(['id', 'machine_id', 'address']);

            foreach ($taken as $ip) {
                $add($seen[$ip->address], __(':ip is already on machine :machine.', ['ip' => $ip->address, 'machine' => (string) $ip->machine?->name]));
            }
        }

        foreach ($errors as $field => $messages) {
            foreach (array_slice(array_values(array_unique($messages)), 0, self::MAX_ERRORS_PER_FIELD) as $message) {
                $validator->errors()->add($field, $message);
            }
        }

        return array_values(array_map(fn (array $line): array => [
            'address' => $line['address'],
            'version' => $line['version'],
            'prefix' => $line['prefix'],
            'gateway' => $line['gateway'],
            'kind' => $line['kind'],
        ], $lines));
    }

    /**
     * One checked address with its prefix and gateway, or why it cannot be used.
     *
     * @return array{address: string, version: int, prefix: ?int, gateway: ?string}|string
     */
    private function ipLine(string $ip, mixed $prefix, mixed $gateway): array|string
    {
        $address = self::canonical($ip);

        if ($address === null) {
            return __(':ip is not a valid IP address.', ['ip' => mb_substr($ip, 0, 45)]);
        }

        // The same ranges BMC addresses refuse: loopback, link-local, multicast, unspecified and
        // reserved, also when hidden in an IPv6 form. A machine never has such an address.
        if (BmcAddress::validateHost($address) !== null) {
            return __(':ip cannot be used for a server.', ['ip' => $address]);
        }

        $version = str_contains($address, ':') ? 6 : 4;
        $prefix = is_numeric($prefix) ? (int) $prefix : null;

        if ($prefix !== null && ($prefix < 0 || $prefix > ($version === 4 ? 32 : 128))) {
            return __('The prefix of :ip must be between 0 and :max.', ['ip' => $address, 'max' => $version === 4 ? 32 : 128]);
        }

        $via = null;

        if ($gateway !== null && trim((string) $gateway) !== '') {
            $via = self::canonical((string) $gateway);

            if ($via === null || (str_contains($via, ':') ? 6 : 4) !== $version) {
                return __('The gateway of :ip must be a valid IPv:version address.', ['ip' => $address, 'version' => $version]);
            }
        }

        return ['address' => $address, 'version' => $version, 'prefix' => $prefix, 'gateway' => $via];
    }

    /**
     * An IP address in its short standard form, or null when it is not one.
     */
    private static function canonical(string $ip): ?string
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);

        return $packed === false ? null : (inet_ntop($packed) ?: null);
    }
}
