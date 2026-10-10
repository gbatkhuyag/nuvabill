<?php

namespace App\Contracts;

/**
 * A server module whose changePackage() is slow, for example a cloud server that must be resized.
 * Provisioner runs it in a queued job (ChangeServicePackage, timeout 900 seconds) instead of inside
 * the request that applied the plan change, which may be a payment webhook.
 */
interface QueuesPackageChanges {}
