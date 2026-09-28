<?php
/**
 * Resolves the user-facing publication state of one URL.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final readonly class PageStatus {
	public const STATIC_COPY = 'static';
	public const UPDATING    = 'updating';
	public const STALE       = 'stale';
	public const EXPOSED     = 'exposed';
	public const QUEUED      = 'queued';
	public const GENERATING  = 'generating';
	public const RETRYING    = 'retrying';
	public const DYNAMIC     = 'dynamic';
	public const ERROR       = 'error';
	public const MISSING     = 'missing';

	public function __construct(
		public string $key,
		public string $detail = '',
	) {}

	/**
	 * @param array{status:string,attempts:int,last_error:string,updated_at:string}|null $job Latest job for the URL.
	 * @param array{published_at:string}|null                                          $artifact Current static copy.
	 */
	public static function resolve( ?array $job, ?array $artifact, bool $password_protected ): self {
		$status = null === $job ? null : $job['status'];
		$error  = null === $job ? '' : $job['last_error'];

		if ( null !== $artifact ) {
			if ( $password_protected ) {
				return new self( self::EXPOSED, 'Password-protected page still has a public static copy.' );
			}
			if ( 'pending' === $status || 'running' === $status ) {
				return new self( self::UPDATING );
			}
			if ( null !== $job && 'failed' === $job['status'] && $job['updated_at'] > $artifact['published_at'] ) {
				return new self( self::STALE, $error );
			}
			return new self( self::STATIC_COPY );
		}

		return match ( $status ) {
			'running' => new self( self::GENERATING ),
			'pending' => $job['attempts'] > 0 ? new self( self::RETRYING, $error ) : new self( self::QUEUED ),
			'skipped' => new self( self::DYNAMIC, $error ),
			'failed'  => new self( self::ERROR, $error ),
			default   => $password_protected ? new self( self::DYNAMIC, 'Page is password protected.' ) : new self( self::MISSING ),
		};
	}

	public const GROUP_STATIC    = 'static';
	public const GROUP_PENDING   = 'pending';
	public const GROUP_ATTENTION = 'attention';
	public const GROUP_DYNAMIC   = 'dynamic';
	public const GROUP_MISSING   = 'missing';

	/** Dashboard tab for this state. Only an up-to-date copy counts as "static". */
	public function group(): string {
		return match ( $this->key ) {
			self::STATIC_COPY => self::GROUP_STATIC,
			self::UPDATING, self::QUEUED, self::GENERATING, self::RETRYING => self::GROUP_PENDING,
			self::STALE, self::EXPOSED, self::ERROR => self::GROUP_ATTENTION,
			self::DYNAMIC => self::GROUP_DYNAMIC,
			default => self::GROUP_MISSING,
		};
	}

	/** True for pages that should become static (everything except WordPress-only pages). */
	public function isPublishable(): bool {
		return self::DYNAMIC !== $this->key && self::EXPOSED !== $this->key;
	}

	/** True when anonymous visitors currently receive the static copy. */
	public function isServedStatically(): bool {
		return in_array( $this->key, array( self::STATIC_COPY, self::UPDATING, self::STALE, self::EXPOSED ), true );
	}
}
