<?php

use Database\Seeders\EmailTemplateTranslations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The built-in email templates in Mongolian, for sites that update.
 *
 * Only templates that still have Nuvabill's English text get them, and only when they have no Mongolian
 * version yet, so it is safe to run again and during an automatic update. Other languages are left
 * alone. This only adds rows: it does not switch Mongolian on, so clients get these emails only after
 * staff turn Mongolian on in Settings → General.
 */
return new class extends Migration
{
    private const LOCALE = 'mn';

    public function up(): void
    {
        EmailTemplateTranslations::install(null, [self::LOCALE]);
    }

    /**
     * Removes the shipped Mongolian translations again. A translation staff changed stays.
     */
    public function down(): void
    {
        $shipped = EmailTemplateTranslations::all()[self::LOCALE] ?? [];

        foreach (DB::table('email_templates')->whereIn('key', array_keys($shipped))->get(['id', 'key']) as $template) {
            [$subject, $body] = $shipped[$template->key];

            DB::table('email_template_translations')
                ->where('email_template_id', $template->id)
                ->where('locale', self::LOCALE)
                ->where('subject', $subject)
                ->where('body', $body)
                ->delete();
        }
    }
};
