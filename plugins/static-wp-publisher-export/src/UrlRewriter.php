<?php
/**
 * Context-aware HTML URL rewriting.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export;

use DOMDocument;
use DOMElement;

final class UrlRewriter {
	public function rewriteHtml( string $html, string $relativeFile, string $sourceBase, string $mode, string $targetBase, bool $noindex ): string {
		$document = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return $html;
		}

		foreach ( array( 'href', 'src', 'poster', 'action', 'formaction', 'cite', 'data' ) as $attribute ) {
			foreach ( $document->getElementsByTagName( '*' ) as $element ) {
				if ( $element instanceof DOMElement && $element->hasAttribute( $attribute ) ) {
					$element->setAttribute( $attribute, $this->rewriteUrl( $element->getAttribute( $attribute ), $relativeFile, $sourceBase, $mode, $targetBase ) );
				}
			}
		}
		foreach ( array( 'srcset', 'imagesrcset', 'data-srcset' ) as $attribute ) {
			foreach ( $document->getElementsByTagName( '*' ) as $element ) {
				if ( ! $element instanceof DOMElement || ! $element->hasAttribute( $attribute ) ) {
					continue;
				}
				$candidates = array();
				foreach ( explode( ',', $element->getAttribute( $attribute ) ) as $candidate ) {
					$parts        = preg_split( '/\\s+/', trim( $candidate ), 2 );
					$candidates[] = $this->rewriteUrl( (string) ( $parts[0] ?? '' ), $relativeFile, $sourceBase, $mode, $targetBase ) . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
				}
				$element->setAttribute( $attribute, implode( ', ', $candidates ) );
			}
		}

		if ( $noindex ) {
			$this->addNoindex( $document );
		}

		$output = $document->saveHTML();
		return false === $output ? $html : preg_replace( '/^<\\?xml encoding="UTF-8">/', '', $output ) ?? $output;
	}

	public function rewriteCss( string $css, string $relativeFile, string $sourceBase, string $mode, string $targetBase ): string {
		return preg_replace_callback(
			'/url\\(\\s*([\'\"]?)([^)\'\"]+)\\1\\s*\\)/i',
			fn ( array $matches ): string => 'url(' . $matches[1] . $this->rewriteUrl( trim( $matches[2] ), $relativeFile, $sourceBase, $mode, $targetBase ) . $matches[1] . ')',
			$css
		) ?? $css;
	}

	public function rewriteUrl( string $url, string $relativeFile, string $sourceBase, string $mode, string $targetBase ): string {
		$url = trim( $url );
		if ( '' === $url || str_starts_with( $url, '#' ) || preg_match( '#^(data|mailto|tel|javascript):#i', $url ) ) {
			return $url;
		}

		$source = rtrim( $sourceBase, '/' );
		$path   = null;
		if ( str_starts_with( $url, $source ) ) {
			$path = '/' . ltrim( substr( $url, strlen( $source ) ), '/' );
		} elseif ( str_starts_with( $url, '/' ) ) {
			$path = $url;
		}
		if ( null === $path ) {
			return $url;
		}

		if ( 'publishable' === $mode ) {
			return rtrim( $targetBase, '/' ) . '/' . ltrim( $path, '/' );
		}

		$fragment = '';
		$query    = '';
		if ( str_contains( $path, '#' ) ) {
			list( $path, $fragment ) = explode( '#', $path, 2 );
			$fragment = '#' . $fragment;
		}
		if ( str_contains( $path, '?' ) ) {
			list( $path, $query ) = explode( '?', $path, 2 );
			$query = '?' . $query;
		}

		$from = trim( str_replace( '\\', '/', dirname( $relativeFile ) ), './' );
		$ups  = '' === $from ? 0 : count( array_filter( explode( '/', $from ) ) );
		return str_repeat( '../', $ups ) . ltrim( $path, '/' ) . $query . $fragment;
	}

	private function addNoindex( DOMDocument $document ): void {
		$head = $document->getElementsByTagName( 'head' )->item( 0 );
		if ( ! $head ) {
			return;
		}
		foreach ( $document->getElementsByTagName( 'meta' ) as $meta ) {
			if ( $meta instanceof DOMElement && 'robots' === strtolower( $meta->getAttribute( 'name' ) ) ) {
				$meta->setAttribute( 'content', 'noindex, nofollow' );
				return;
			}
		}
		$meta = $document->createElement( 'meta' );
		$meta->setAttribute( 'name', 'robots' );
		$meta->setAttribute( 'content', 'noindex, nofollow' );
		$head->appendChild( $meta );
	}
}
