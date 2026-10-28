<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Servers: plain HTTP to a control panel now needs its own box, "Allow plain HTTP". Every server
 * that was already set to plain HTTP gets the box ticked, so it keeps working after the update.
 * Site health keeps listing those servers.
 *
 * Safe to run again, also after it stopped halfway on MySQL or MariaDB: the column is only added
 * when it is missing, and the update only ticks the box for servers with HTTPS off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('servers')) {
            return;
        }

        if (! Schema::hasColumn('servers', 'allow_plain_http')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->boolean('allow_plain_http')->default(false);
            });
        }

        DB::table('servers')->where('use_ssl', false)->update(['allow_plain_http' => true]);
    }

    public function down(): void
    {
        if (Schema::hasTable('servers') && Schema::hasColumn('servers', 'allow_plain_http')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropColumn('allow_plain_http');
            });
        }
    }
};
