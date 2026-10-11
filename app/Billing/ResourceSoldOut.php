<?php

namespace App\Billing;

use App\Models\ResourceOption;
use RuntimeException;

/**
 * An order asked for more steps of an extra (such as IPv4 addresses) than its stock has left.
 * Nothing of the order was made.
 */
class ResourceSoldOut extends RuntimeException
{
    public function __construct(public readonly ResourceOption $option, public readonly int $left)
    {
        parent::__construct($left > 0
            ? trans_choice('Only :count more step of :resource is left. Choose fewer.|Only :count more steps of :resource are left. Choose fewer.', $left, ['count' => $left, 'resource' => $option->displayLabel()])
            : __(':resource is sold out right now.', ['resource' => $option->displayLabel()]));
    }
}
