<?php

use Database\Seeders\DefaultDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The client server panel: per product, staff switch each panel feature (graphs, console, firewall
 * and so on) on or off, and clients get an email when something changes on their server.
 *
 * Only adds things, so it is safe to run again and during an automatic update.
 */
return new class extends Migration
{
    private const TEMPLATE_KEYS = ['service.server_change'];

    public function up(): void
    {
        // Section key => on or off. Null means the module's own defaults.
        if (! Schema::hasColumn('products', 'panel_sections')) {
            Schema::table('products', function (Blueprint $table) {
                $table->json('panel_sections')->nullable();
            });
        }

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

    public function down(): void
    {
        DB::table('email_templates')->whereIn('key', self::TEMPLATE_KEYS)->delete();

        if (Schema::hasColumn('products', 'panel_sections')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('panel_sections');
            });
        }
    }
};
