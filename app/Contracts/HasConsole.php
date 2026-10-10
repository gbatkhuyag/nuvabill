<?php

namespace App\Contracts;

use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\ConsoleSession;
use App\Extensions\Servers\Panel\PanelException;
use App\Models\Service;

/**
 * A server module that gives clients a console in the browser. Core shows it with the bundled
 * noVNC on its own page; the address and password reach the browser only by a single-use ticket.
 */
interface HasConsole
{
    /**
     * A fresh console session.
     *
     * @throws PanelException When the console is not available.
     */
    public function consoleSession(Service $service, ClientContext $context): ConsoleSession;
}
