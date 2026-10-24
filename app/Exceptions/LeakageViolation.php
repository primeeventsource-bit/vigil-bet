<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a feature vector would be built from information that did not
 * exist before the game started. A backtest that leaks looks brilliant and
 * is worthless, so this fails hard rather than warning.
 */
class LeakageViolation extends RuntimeException {}
