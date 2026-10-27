<?php

namespace App\Contracts;

use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Staff\StaffContext;
use App\Extensions\Servers\Staff\StaffPanel;
use App\Models\Service;

/**
 * A server module with a card on the admin service page: read-only facts about the service and
 * staff actions of its own, such as moving a VPS to another node. Only staff with the module's
 * admin permission and a session that passed two-factor login see the card or run an action; for
 * others core does not call staffPanel(). Core checks each action is one the card offers for
 * that service, checks its fields, takes the service lock, limits it to 10 a minute, logs it with
 * the staff member's name, and keeps it locked in the demo. The manifest must list "admin-page"
 * in its permissions. Since Nuvabill 0.7.0.
 */
interface HasStaffPanel extends HasAdminPermission
{
    /**
     * The card for one service. Keep it fast: read saved state, or the server with a short timeout.
     */
    public function staffPanel(Service $service): StaffPanel;

    /**
     * Run one of the card's actions. $input holds only the action's own fields, checked.
     *
     * @param  array<string, string>  $input
     */
    public function runStaffAction(Service $service, string $action, array $input, StaffContext $staff): ModuleResult;
}
