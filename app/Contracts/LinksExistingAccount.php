<?php

namespace App\Contracts;

use App\Extensions\Servers\ModuleResult;
use App\Models\Service;

/**
 * A server module that lets staff link a service to an account that already exists on the panel,
 * for example a VPS made by hand (the "Link existing account" form on the admin service page).
 */
interface LinksExistingAccount
{
    /**
     * Fields of the staff form.
     *
     * @return array<string, array{label: string, type: string, help?: string, required?: bool}>
     */
    public function linkFields(): array;

    /**
     * Check the account and link it. On success, data['module_data'] holds the values core adds to
     * the service's module_data.
     *
     * @param  array<string, string>  $input
     */
    public function linkExistingAccount(Service $service, array $input): ModuleResult;
}
