<?php

use Database\Seeders\EmailTemplateTranslations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The 0.7.0 email templates (resources, server changes and dedicated servers) in every language.
 *
 * Only templates that still have Nuvabill's English text get them, and only in languages they do not
 * have yet, so it is safe to run again and during an automatic update.
 */
return new class extends Migration
{
    private const TEMPLATE_KEYS = [
        'service.resources_changed', 'service.server_change', 'service.dedicated_ready', 'admin.resources_failed',
        'admin.resources_by_hand', 'admin.machine_install', 'admin.machine_wipe', 'admin.machine_alert',
    ];

    public function up(): void
    {
        EmailTemplateTranslations::install(self::TEMPLATE_KEYS);
    }

    /**
     * Removes the shipped translations again. A translation staff changed stays.
     */
    public function down(): void
    {
        $shipped = EmailTemplateTranslations::all();

        foreach (DB::table('email_templates')->whereIn('key', self::TEMPLATE_KEYS)->get(['id', 'key']) as $template) {
            foreach ($shipped as $locale => $translations) {
                if (! isset($translations[$template->key])) {
                    continue;
                }

                [$subject, $body] = $translations[$template->key];

                DB::table('email_template_translations')
                    ->where('email_template_id', $template->id)
                    ->where('locale', $locale)
                    ->where('subject', $subject)
                    ->where('body', $body)
                    ->delete();
            }
        }
    }
};
