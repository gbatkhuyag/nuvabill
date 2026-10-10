<?php

namespace App\Contracts;

use App\Models\Product;
use App\Models\Service;

/**
 * A server module that allows only some plan changes, for example only to products the service's
 * machine can be sold as.
 */
interface LimitsPlanChanges
{
    /**
     * Whether the service may move to the target product.
     */
    public function allowsPlanChange(Service $service, Product $target): bool;
}
