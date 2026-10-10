<?php

use Database\Seeders\DefaultDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated servers on the host's own hardware (Admin → Hardware): machines with their specs, IPs
 * and BMC login, the products each machine can be sold as, and a flag on products whose stock comes
 * from free machines. Staff who manage services may switch the power of dedicated servers, and staff
 * who manage products and servers may also manage the machines.
 *
 * Safe to run again, also after it stopped halfway on MySQL or MariaDB. No data is rewritten.
 */
return new class extends Migration
{
    private const TEMPLATE_KEYS = ['service.dedicated_ready', 'admin.machine_install', 'admin.machine_wipe', 'admin.machine_alert'];

    /**
     * Permission => roles that get it: those with any of these permissions.
     *
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'hardware.manage' => ['products.manage'],
        'hardware.power' => ['products.manage', 'services.manage'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('machines')) {
            Schema::create('machines', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('location', 100)->nullable();
                $table->string('rack', 50)->nullable();
                $table->string('rack_unit', 20)->nullable();
                $table->string('cpu', 190)->nullable();
                $table->unsignedSmallInteger('cpu_cores')->nullable();
                $table->unsignedInteger('ram_gb')->nullable();
                $table->string('storage', 255)->nullable();
                $table->string('port_speed', 50)->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('available');
                // The service that holds the machine now, and the one before it (for the wipe audit).
                $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedBigInteger('last_service_id')->nullable();
                $table->timestamp('provisioned_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamp('wiped_at')->nullable();
                $table->timestamp('power_check_at')->nullable();
                $table->string('bmc_type', 20)->default('none');
                $table->string('bmc_host', 190)->nullable();
                $table->unsignedInteger('bmc_port')->nullable();
                // Encrypted with the app key (text, as encrypted values are long).
                $table->text('bmc_username')->nullable();
                $table->text('bmc_password')->nullable();
                $table->string('bmc_tls_mode', 10)->default('pinned');
                $table->string('bmc_tls_pin', 64)->nullable();
                $table->string('bmc_tls_fingerprint', 95)->nullable();
                $table->unsignedTinyInteger('bmc_ipmi_cipher')->nullable();
                $table->string('bmc_system_path', 190)->nullable();
                $table->string('bmc_reset_target', 255)->nullable();
                $table->json('bmc_reset_types')->nullable();
                $table->string('bmc_vendor', 100)->nullable();
                $table->string('bmc_model', 100)->nullable();
                $table->timestamp('bmc_checked_at')->nullable();
                $table->unsignedSmallInteger('bmc_failures')->default(0);
                $table->string('bmc_last_error', 255)->nullable();
                $table->string('power_state', 20)->nullable();
                $table->timestamp('power_checked_at')->nullable();
                $table->timestamps();
            });
        }

        $this->ensureIndex('machines', ['name'], 'machines_name_unique', true);
        $this->ensureIndex('machines', ['service_id'], 'machines_service_id_unique', true);
        $this->ensureIndex('machines', ['location'], 'machines_location_index');
        $this->ensureIndex('machines', ['status'], 'machines_status_index');

        if (! Schema::hasTable('machine_ips')) {
            Schema::create('machine_ips', function (Blueprint $table) {
                $table->id();
                $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
                $table->string('address', 45);
                $table->unsignedTinyInteger('version');
                $table->unsignedTinyInteger('prefix')->nullable();
                $table->string('gateway', 45)->nullable();
                $table->string('kind', 10);
                // Only for extra IPs: the service that rents it.
                $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamps();
            });
        }

        $this->ensureIndex('machine_ips', ['address'], 'machine_ips_address_unique', true);
        $this->ensureIndex('machine_ips', ['machine_id', 'kind'], 'machine_ips_machine_kind_index');

        if (! Schema::hasTable('machine_product')) {
            Schema::create('machine_product', function (Blueprint $table) {
                $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->primary(['machine_id', 'product_id']);
            });
        }

        $this->ensureIndex('machine_product', ['product_id'], 'machine_product_product_id_index');

        // Set by the product form when the product's module sells machines, so stock counts and
        // orders never need to load the module.
        if (! Schema::hasColumn('products', 'uses_machines')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('uses_machines')->default(false);
            });
        }

        $this->insertTemplates();
        $this->grantPermissions();
    }

    public function down(): void
    {
        DB::table('email_templates')->whereIn('key', self::TEMPLATE_KEYS)->delete();

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);

            if (is_array($permissions) && array_intersect(array_keys(self::GRANTS), $permissions) !== []) {
                DB::table('roles')->where('id', $role->id)->update([
                    'permissions' => json_encode(array_values(array_diff($permissions, array_keys(self::GRANTS)))),
                ]);
            }
        }

        Schema::dropIfExists('machine_product');
        Schema::dropIfExists('machine_ips');
        Schema::dropIfExists('machines');

        if (Schema::hasColumn('products', 'uses_machines')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('uses_machines');
            });
        }
    }

    /**
     * Add a named index when the table has no such index on those columns yet, for example after
     * the migration stopped between making the table and adding its index.
     *
     * @param  list<string>  $columns
     */
    private function ensureIndex(string $table, array $columns, string $name, bool $unique = false): void
    {
        if (Schema::hasIndex($table, $name) || Schema::hasIndex($table, $columns, $unique ? 'unique' : null)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $unique): void {
            $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name);
        });
    }

    /**
     * The new email templates, only where the key is missing, so a template staff edited stays.
     */
    private function insertTemplates(): void
    {
        $now = now();

        foreach (DefaultDataSeeder::templates() as $key => [$name, $subject, $body]) {
            if (in_array($key, self::TEMPLATE_KEYS, true) && ! DB::table('email_templates')->where('key', $key)->exists()) {
                DB::table('email_templates')->insert([
                    'key' => $key,
                    'name' => $name,
                    'subject' => $subject,
                    'body' => $body,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Roles that manage services may switch the power of dedicated servers. Roles that manage
     * products and servers (and can already save server API secrets) may also manage the machines
     * and their BMC logins. The owner role ("*") has every permission already.
     */
    private function grantPermissions(): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);

            if (! is_array($permissions)) {
                continue;
            }

            $missing = [];

            foreach (self::GRANTS as $permission => $holders) {
                if (array_intersect($holders, $permissions) !== [] && ! in_array($permission, $permissions, true)) {
                    $missing[] = $permission;
                }
            }

            if ($missing !== []) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values([...$permissions, ...$missing]))]);
            }
        }
    }
};
