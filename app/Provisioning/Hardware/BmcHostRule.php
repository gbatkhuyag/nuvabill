<?php

namespace App\Provisioning\Hardware;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation for the BMC host staff type on the machine form: an IP address or a host name that
 * BmcAddress allows. Host names are checked again, with every address they resolve to, each time
 * Nuvabill calls the BMC.
 */
final class BmcHostRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = is_string($value) ? BmcAddress::validateHost($value) : __('Enter a valid IP address or host name.');

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
