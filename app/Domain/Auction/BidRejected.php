<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use RuntimeException;

/** Thrown when a bid cannot be accepted (item closed, amount too low, etc.). */
final class BidRejected extends RuntimeException {}
