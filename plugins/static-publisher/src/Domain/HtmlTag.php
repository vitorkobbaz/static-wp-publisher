<?php
/**
 * Minimal HTML start-tag model used by the static-copy optimizer.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

/**
 * Parses one start tag into ordered attributes and serializes it back. Untouched
 * attributes keep their original spelling and quoting.
 */
final class HtmlTag {
	private const ATTRIBUTE = '~\s+([^\s"\'>/=]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?~';

	/**
	 * @param list<array{name:string,raw:string,value:?string}> $attributes
	 */
	private function __construct(
		public readonly string $name,
		private array $attributes,
		private readonly bool $selfClosing,
	) {}

	public static function parse( string $raw ): ?self {
		if ( 1 !== preg_match( '~^<([a-zA-Z][a-zA-Z0-9:-]*)(.*?)(/?)>$~s', $raw, $parts ) ) {
			return null;
		}
		$attributes = array();
		if ( false !== preg_match_all( self::ATTRIBUTE, $parts[2], $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$value        = isset( $match[2] ) ? self::unquote( $match[2] ) : null;
				$attributes[] = array(
					'name'  => strtolower( $match[1] ),
					'raw'   => trim( $match[0] ),
					'value' => null === $value ? null : html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				);
			}
		}
		return new self( strtolower( $parts[1] ), $attributes, '/' === $parts[3] );
	}

	public function has( string $name ): bool {
		return null !== $this->index( $name );
	}

	/** Decoded attribute value; '' for a boolean attribute, null when absent. */
	public function get( string $name ): ?string {
		$index = $this->index( $name );
		return null === $index ? null : ( $this->attributes[ $index ]['value'] ?? '' );
	}

	public function set( string $name, string $value ): void {
		$name  = strtolower( $name );
		$entry = array(
			'name'  => $name,
			'raw'   => $name . '="' . htmlspecialchars( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"',
			'value' => $value,
		);
		$index = $this->index( $name );
		if ( null === $index ) {
			$this->attributes[] = $entry;
		} else {
			$this->attributes[ $index ] = $entry;
		}
	}

	public function remove( string $name ): void {
		$index = $this->index( $name );
		if ( null !== $index ) {
			array_splice( $this->attributes, $index, 1 );
		}
	}

	public function toHtml(): string {
		$parts = array( $this->name );
		foreach ( $this->attributes as $attribute ) {
			$parts[] = $attribute['raw'];
		}
		return '<' . implode( ' ', $parts ) . ( $this->selfClosing ? ' />' : '>' );
	}

	private function index( string $name ): ?int {
		$name = strtolower( $name );
		foreach ( $this->attributes as $index => $attribute ) {
			if ( $attribute['name'] === $name ) {
				return $index;
			}
		}
		return null;
	}

	private static function unquote( string $value ): string {
		$first = $value[0] ?? '';
		return ( '"' === $first || "'" === $first ) ? substr( $value, 1, -1 ) : $value;
	}
}
