<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thai is a new language, on by default on new sites. A site that updates keeps the languages its
 * clients could pick before: when staff never saved that choice, the old default is saved now, so
 * Thai only shows once staff turn it on in Settings → General. A new site has no staff yet, so
 * nothing is saved and it gets every language.
 */
return new class extends Migration
{
    private const KEY = 'locale.enabled';

    private const BEFORE_THAI = ['de', 'en', 'es', 'fr', 'pt_BR', 'tr', 'ru', 'ar', 'ckb', 'zh_CN'];

    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('admins') || ! DB::table('admins')->exists()) {
            return;
        }

        if (DB::table('settings')->where('key', self::KEY)->exists()) {
            return;
        }

        // Stored the way Settings::set() stores a value: JSON encoded.
        DB::table('settings')->insert([
            'key' => self::KEY,
            'value' => json_encode(self::BEFORE_THAI),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        rescue(fn () => app(Settings::class)->flush(), report: false);
    }

    /**
     * Removes the saved list again, only if staff did not change it since.
     */
    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $saved = DB::table('settings')->where('key', self::KEY)->value('value');

        if ($saved !== null && json_decode($saved, true) === self::BEFORE_THAI) {
            DB::table('settings')->where('key', self::KEY)->delete();
            rescue(fn () => app(Settings::class)->flush(), report: false);
        }
    }
};
