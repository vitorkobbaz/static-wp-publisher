<?php
/**
 * Export request value object.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export\Domain;

use InvalidArgumentException;

final readonly class ExportRequest {
	public function __construct(
		public string $mode,
		public string $targetBase,
		public bool $addNoindex,
		public bool $createZip,
	) {
		if ( ! in_array( $mode, array( 'relocatable', 'publishable' ), true ) ) {
			throw new InvalidArgumentException( 'Unknown export mode.' );
		}
		if ( 'publishable' === $mode && '' === $targetBase ) {
			throw new InvalidArgumentException( 'A target URL is required for publishable exports.' );
		}
		if ( '' !== $targetBase ) {
			$parts = parse_url( $targetBase );
			if (
				false === $parts ||
				! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ||
				empty( $parts['host'] ) ||
				isset( $parts['user'] ) ||
				isset( $parts['pass'] ) ||
				isset( $parts['query'] ) ||
				isset( $parts['fragment'] )
			) {
				throw new InvalidArgumentException( 'The target URL must be an HTTP(S) base URL without credentials, a query, or a fragment.' );
			}
		}
	}
}
