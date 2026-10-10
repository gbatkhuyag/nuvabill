<?php

namespace App\Contracts;

/**
 * A server module whose services run on machines from the hardware inventory (Admin → Hardware)
 * instead of on a Server record. Its products count stock from free machines linked to them, a new
 * order reserves one, and Provisioner does not pick a server for its services.
 */
interface UsesMachineInventory {}
