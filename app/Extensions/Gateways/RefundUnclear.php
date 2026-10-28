<?php

namespace App\Extensions\Gateways;

use RuntimeException;

/**
 * The gateway gave no clear answer to a refund: it may or may not have sent the money back.
 */
class RefundUnclear extends RuntimeException {}
