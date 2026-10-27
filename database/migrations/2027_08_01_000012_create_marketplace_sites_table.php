<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The marketplace store only: sites that connected to the marketplace ("Connect to marketplace"),
 * one row per web address. Only a SHA-256 hash of each site's key is kept, never the key. The
 * Cloudflare rule that lets the site's server address through is noted, so it can be removed again.
 *
 * Made on every copy, so the store can be switched on without another migration. Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_sites')) {
            Schema::create('marketplace_sites', function (Blueprint $table) {
                $table->id();
                $table->string('host', 190)->unique();
                $table->string('ip', 45);
                $table->string('ip_family', 4);
                $table->char('key_hash', 64)->unique();
                $table->timestamp('key_created_at')->nullable();
                // The key before the last change keeps working for a day, in case the site never got the new one.
                $table->char('previous_key_hash', 64)->nullable()->index();
                $table->timestamp('previous_key_expires_at')->nullable();
                $table->string('status', 10)->default('pending')->index();
                // When the site started to wait for staff: waiting sites end after 14 days.
                $table->timestamp('pending_since')->nullable();
                // Staff removed the site. Connecting again then waits for staff, until they approve it.
                $table->timestamp('revoked_at')->nullable();
                $table->string('cloudflare_rule_id', 64)->nullable();
                $table->boolean('ip_matches_dns')->default(false);
                // The store reached the site's own server at this address over HTTPS, so the address belongs to the site.
                $table->boolean('ip_reached_directly')->default(false);
                $table->timestamp('last_seen_at')->nullable();
                $table->string('last_ip', 45)->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
                // Why the site waits for staff, as a code (see MarketplaceSite::reasonText()).
                $table->string('pending_reason', 100)->nullable();
                $table->timestamps();

                $table->index(['ip', 'status']);
            });
        }

        // Cloudflare rules the store could not remove yet (Cloudflare down, token expired). Tried again daily.
        if (! Schema::hasTable('marketplace_rule_removals')) {
            Schema::create('marketplace_rule_removals', function (Blueprint $table) {
                $table->id();
                $table->string('rule_id', 64)->unique();
                $table->string('ip', 45);
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_rule_removals');
        Schema::dropIfExists('marketplace_sites');
    }
};
