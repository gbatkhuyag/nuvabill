<?php

use Database\Seeders\DefaultDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resource options: sliders that add CPU, RAM, disk, IPs and other extras to a server, with a price
 * per step. A product has options, a service has rows for what it bought, and a resource change is
 * a mid-cycle change of those rows, like a plan change.
 *
 * Safe to run again, also after it stopped halfway on MySQL or MariaDB: every table, column and
 * named index is checked first. Nothing that already exists is changed.
 */
return new class extends Migration
{
    private const TEMPLATE_KEYS = ['service.resources_changed', 'admin.resources_failed', 'admin.resources_by_hand'];

    public function up(): void
    {
        if (! Schema::hasTable('resource_options')) {
            Schema::create('resource_options', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('kind', 40);
                $table->string('unit', 20)->default('');
                $table->string('label', 60)->nullable();
                $table->string('description', 200)->nullable();
                $table->unsignedInteger('step_size');
                $table->unsignedBigInteger('included')->nullable();
                $table->unsignedSmallInteger('min_steps')->default(0);
                $table->unsignedSmallInteger('max_steps');
                $table->unsignedSmallInteger('default_steps')->default(0);
                $table->unsignedInteger('stock')->nullable();
                $table->boolean('client_can_add')->default(true);
                $table->boolean('client_can_remove')->default(true);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                // A copy of the module's kind, taken when staff save the option, so store and client
                // pages never have to ask the module (or its server) what the kind can do.
                $table->boolean('can_shrink')->default(true);
                $table->boolean('restarts')->default(false);
                $table->string('note', 200)->nullable();
                $table->json('ladder')->nullable();
                $table->unsignedBigInteger('max_extra')->nullable();
                $table->timestamps();
            });
        }

        $this->ensureIndex('resource_options', ['product_id', 'kind'], 'resource_options_product_kind_unique', true);

        if (! Schema::hasTable('resource_option_prices')) {
            Schema::create('resource_option_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('resource_option_id')->constrained()->cascadeOnDelete();
                $table->string('currency', 3);
                $table->string('billing_cycle', 20);
                $table->bigInteger('price');
                $table->bigInteger('setup_fee')->default(0);
            });
        }

        $this->ensureIndex('resource_option_prices', ['resource_option_id', 'currency', 'billing_cycle'], 'resource_option_prices_cycle_unique', true);

        if (! Schema::hasTable('service_resource_options')) {
            Schema::create('service_resource_options', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_id')->constrained()->cascadeOnDelete();
                $table->foreignId('resource_option_id')->nullable()->constrained()->nullOnDelete();
                $table->string('kind', 40);
                $table->string('unit', 20)->default('');
                $table->unsignedInteger('step_size');
                $table->unsignedSmallInteger('steps')->default(0);
                $table->bigInteger('recurring_amount')->default(0);
                $table->string('status', 20)->default('active');
                $table->timestamps();
            });
        }

        $this->ensureIndex('service_resource_options', ['service_id', 'kind'], 'service_resource_options_service_kind_unique', true);
        $this->ensureIndex('service_resource_options', ['resource_option_id', 'status'], 'service_resource_options_option_status_index');

        if (! Schema::hasTable('resource_changes')) {
            Schema::create('resource_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->string('billing_cycle', 20);
                $table->string('currency', 3);
                $table->bigInteger('old_amount');
                $table->bigInteger('new_amount');
                $table->bigInteger('difference');
                $table->string('mode', 20);
                $table->string('status', 20)->default('pending');
                $table->date('apply_on')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->boolean('sets_server')->default(true);
                $table->string('server_state', 20)->default('none');
                $table->unsignedTinyInteger('server_attempts')->default(0);
                $table->unsignedTinyInteger('server_checks')->default(0);
                $table->string('server_message', 255)->nullable();
                $table->timestamp('server_done_at')->nullable();
                $table->timestamps();
            });
        }

        $this->ensureIndex('resource_changes', ['service_id', 'status'], 'resource_changes_service_status_index');
        $this->ensureIndex('resource_changes', ['status', 'apply_on'], 'resource_changes_status_apply_on_index');
        $this->ensureIndex('resource_changes', ['server_state', 'updated_at'], 'resource_changes_server_state_index');

        if (! Schema::hasTable('resource_change_items')) {
            Schema::create('resource_change_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('resource_change_id')->constrained()->cascadeOnDelete();
                $table->foreignId('resource_option_id')->nullable()->constrained()->nullOnDelete();
                $table->string('kind', 40);
                $table->string('unit', 20)->default('');
                $table->unsignedInteger('step_size');
                $table->unsignedSmallInteger('from_steps');
                $table->unsignedSmallInteger('to_steps');
                $table->bigInteger('old_amount');
                $table->bigInteger('new_amount');
                $table->bigInteger('cost')->default(0);
                $table->bigInteger('credit')->default(0);
                $table->bigInteger('setup_fee')->default(0);
            });
        }

        $this->ensureIndex('resource_change_items', ['resource_change_id'], 'resource_change_items_change_index');

        // The resource row an invoice line pays for. No foreign key: rows are never deleted on their
        // own, and this keeps the change light on large invoice tables.
        if (! Schema::hasColumn('invoice_items', 'service_resource_option_id')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->unsignedBigInteger('service_resource_option_id')->nullable();
            });
        }

        $this->ensureIndex('invoice_items', ['service_resource_option_id'], 'invoice_items_resource_row_index');

        $this->insertTemplates();
    }

    public function down(): void
    {
        DB::table('email_templates')->whereIn('key', self::TEMPLATE_KEYS)->delete();

        if (Schema::hasIndex('invoice_items', 'invoice_items_resource_row_index')) {
            Schema::table('invoice_items', fn (Blueprint $table) => $table->dropIndex('invoice_items_resource_row_index'));
        }

        if (Schema::hasColumn('invoice_items', 'service_resource_option_id')) {
            Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn('service_resource_option_id'));
        }

        Schema::dropIfExists('resource_change_items');
        Schema::dropIfExists('resource_changes');
        Schema::dropIfExists('service_resource_options');
        Schema::dropIfExists('resource_option_prices');
        Schema::dropIfExists('resource_options');
    }

    /**
     * Add a named index when the table has no index on those columns yet, for example after the
     * migration stopped between making the table and adding its index.
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
};
