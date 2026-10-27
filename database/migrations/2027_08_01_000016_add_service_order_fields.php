<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server modules: what the client entered in the module's order form fields (an SSH key, a
 * hostname), kept on the service encrypted with the app key.
 *
 * Safe to run again, also after it stopped halfway on MySQL or MariaDB. No data is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('services', 'order_fields')) {
            Schema::table('services', function (Blueprint $table) {
                // Encrypted, so text and not json: up to 20 fields of up to 16 KB each.
                $table->mediumText('order_fields')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('services', 'order_fields')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropColumn('order_fields');
            });
        }
    }
};
