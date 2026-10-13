<?php
/**
 * Raised when an export run exceeds its resource budget.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export\Domain;

use RuntimeException;

final class ExportLimitExceeded extends RuntimeException {}
