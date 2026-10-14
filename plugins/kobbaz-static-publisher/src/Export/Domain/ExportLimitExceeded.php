<?php
/**
 * Raised when an export run exceeds its resource budget.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Export\Domain;

use RuntimeException;

final class ExportLimitExceeded extends RuntimeException {}
