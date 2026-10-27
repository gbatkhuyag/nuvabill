<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated servers: the power history of each machine (who switched what, when, and whether it
 * worked), written by core for every power action of any driver, and the automatic install an
 * add-on runs while a machine is being set up.
 *
 * Safe to run again, also after it stopped halfway on MySQL or MariaDB. No data is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('machine_power_events')) {
            Schema::create('machine_power_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('service_id')->nullable();
                $table->nullableMorphs('actor');
                $table->string('actor_kind', 16);
                $table->string('action', 32);
                $table->string('result', 16);
                $table->string('message', 255)->nullable();
                $table->string('driver', 40)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['machine_id', 'id']);
            });
        }

        Schema::table('machines', function (Blueprint $table) {
            if (! Schema::hasColumn('machines', 'setup_by')) {
                $table->string('setup_by', 64)->nullable();
            }

            if (! Schema::hasColumn('machines', 'setup_started_at')) {
                $table->timestamp('setup_started_at')->nullable();
            }

            if (! Schema::hasColumn('machines', 'setup_error')) {
                $table->string('setup_error', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_power_events');

        Schema::table('machines', function (Blueprint $table) {
            foreach (['setup_by', 'setup_started_at', 'setup_error'] as $column) {
                if (Schema::hasColumn('machines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
