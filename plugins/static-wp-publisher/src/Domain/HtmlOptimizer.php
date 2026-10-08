<?php
/**
 * Speed optimizations applied to a static copy before it is stored.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

/**
 * Works on a token stream (comments, raw-text elements, tags, text) so script and style
 * bodies are never mistaken for markup. Every change is additive or attribute-level and
 * preserves document order; the original is returned unchanged when the document has
 * no <head> ... </head> or was already optimized.
 */
final class HtmlOptimizer {
	public const MARKER = 'data-swpp-opt';

	private const TOKEN = '~<!--.*?-->|<(script|style|textarea|title|noscript|template)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</\1\s*>|</?[a-zA-Z][a-zA-Z0-9:-]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~si';

	private const BACKGROUND_CANDIDATES = 3;

	/** @var list<array{type:string,html:string,name:string,closing:bool}> */
	private array $segments = array();

	private OptimizerAssets $assets;

	/** @var array<string,?string> */
	private array $cssCache = array();

	public function optimize( string $html, OptimizerOptions $options, OptimizerAssets $assets ): string {
		if ( ! $options->optimize && ! $options->combineCss ) {
			return $html;
		}
		if ( str_contains( $html, self::MARKER ) ) {
			return $html;
		}
		$this->assets   = $assets;
		$this->cssCache = array();
		$this->tokenize( $html );

		$head_open  = $this->find( 'head', false );
		$head_close = $this->find( 'head', true );
		if ( null === $head_open || null === $head_close || $head_close < $head_open ) {
			return $html;
		}

		$preloads = array();
		if ( $options->optimize ) {
			$preloads = $this->optimizeImages( $head_close, $options->eagerImages );
			$preloads = array_merge( $preloads, $this->backgroundPreloads( $head_open, $head_close ) );
			$this->swapFontDisplay();
		}
		if ( $options->combineCss ) {
			$this->combineStylesheets( $head_open, $head_close );
		}
		$this->insertPreloads( array_slice( $this->unique( $preloads ), 0, OptimizerOptions::MAX_PRELOADS ), $head_open, $head_close );

		return implode( '', array_column( $this->segments, 'html' ) );
	}

	/** Splits the document into ordered segments; text between tokens is kept verbatim. */
	private function tokenize( string $html ): void {
		$this->segments = array();
		$offset         = 0;
		if ( false === preg_match_all( self::TOKEN, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			// PCRE limits reached on an unusual document: treat it as opaque text.
			$this->segments[] = $this->segment( 'text', $html );
			return;
		}
		foreach ( $matches as $match ) {
			$start = $match[0][1];
			if ( $start > $offset ) {
				$this->segments[] = $this->segment( 'text', substr( $html, $offset, $start - $offset ) );
			}
			$token = $match[0][0];
			if ( str_starts_with( $token, '<!--' ) ) {
				$this->segments[] = $this->segment( 'comment', $token );
			} elseif ( isset( $match[1] ) ) {
				$this->segments[] = $this->segment( 'raw', $token, strtolower( $match[1][0] ) );
			} else {
				preg_match( '~^</?([a-zA-Z][a-zA-Z0-9:-]*)~', $token, $name );
				$this->segments[] = $this->segment( 'tag', $token, strtolower( $name[1] ?? '' ), str_starts_with( $token, '</' ) );
			}
			$offset = $start + strlen( $token );
		}
		if ( $offset < strlen( $html ) ) {
			$this->segments[] = $this->segment( 'text', substr( $html, $offset ) );
		}
	}

	/** @return array{type:string,html:string,name:string,closing:bool} */
	private function segment( string $type, string $html, string $name = '', bool $closing = false ): array {
		return array(
			'type'    => $type,
			'html'    => $html,
			'name'    => $name,
			'closing' => $closing,
		);
	}

	private function find( string $name, bool $closing ): ?int {
		foreach ( $this->segments as $index => $segment ) {
			if ( 'tag' === $segment['type'] && $name === $segment['name'] && $closing === $segment['closing'] ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * Lazy-loads images after the leading ones, adds async decoding and missing
	 * dimensions, and returns the preload for the image WordPress marked high priority.
	 *
	 * @return list<array{href:string,srcset:string,sizes:string}>
	 */
	private function optimizeImages( int $head_close, int $eager ): array {
		$preloads = array();
		$position = 0;
		foreach ( $this->segments as $index => $segment ) {
			if ( $index <= $head_close || 'tag' !== $segment['type'] || 'img' !== $segment['name'] || $segment['closing'] ) {
				continue;
			}
			$tag = HtmlTag::parse( $segment['html'] );
			$src = null === $tag ? null : $tag->get( 'src' );
			if ( null === $tag || null === $src || '' === $src || str_starts_with( $src, 'data:' ) ) {
				continue;
			}

			$priority = 'high' === strtolower( (string) $tag->get( 'fetchpriority' ) ) && array() === $preloads;
			if ( $priority ) {
				if ( 'lazy' === strtolower( (string) $tag->get( 'loading' ) ) ) {
					$tag->remove( 'loading' );
				}
				$preloads[] = array(
					'href'   => $src,
					'srcset' => (string) $tag->get( 'srcset' ),
					'sizes'  => (string) $tag->get( 'sizes' ),
				);
			} elseif ( $position >= $eager && ! $tag->has( 'loading' ) ) {
				$tag->set( 'loading', 'lazy' );
			}
			if ( ! $tag->has( 'decoding' ) ) {
				$tag->set( 'decoding', 'async' );
			}
			if ( ! $tag->has( 'width' ) && ! $tag->has( 'height' ) ) {
				$size = $this->assets->imageSize( $src );
				if ( null !== $size ) {
					$tag->set( 'width', (string) $size[0] );
					$tag->set( 'height', (string) $size[1] );
				}
			}
			$this->segments[ $index ]['html'] = $tag->toHtml();
			++$position;
		}
		return $preloads;
	}

	/**
	 * Page-builder sections (Elementor) paint their hero image as a CSS background that
	 * the browser only discovers after downloading the stylesheet. Looks up the
	 * background of the first sections in the page's own local Elementor stylesheets.
	 *
	 * @return list<array{href:string,srcset:string,sizes:string}>
	 */
	private function backgroundPreloads( int $head_open, int $head_close ): array {
		$sheets = array();
		for ( $i = $head_open + 1; $i < $head_close; ++$i ) {
			$tag = $this->stylesheetTag( $i );
			$url = null === $tag ? null : $tag->get( 'href' );
			if ( null !== $url && str_contains( $url, '/elementor/css/' ) ) {
				$sheets[] = $url;
			}
		}
		if ( array() === $sheets ) {
			return array();
		}

		$preloads = array();
		$checked  = 0;
		foreach ( $this->segments as $index => $segment ) {
			if ( $index <= $head_close || 'tag' !== $segment['type'] || $segment['closing'] || ! str_contains( $segment['html'], 'background_background' ) ) {
				continue;
			}
			$tag      = HtmlTag::parse( $segment['html'] );
			$id       = null === $tag ? '' : (string) $tag->get( 'data-id' );
			$settings = null === $tag ? '' : (string) $tag->get( 'data-settings' );
			if ( 1 !== preg_match( '~^[a-z0-9]{1,32}$~i', $id ) || ! str_contains( $settings, '"background_background"' ) ) {
				continue;
			}
			foreach ( $sheets as $sheet ) {
				$image = $this->backgroundOf( $id, $sheet );
				if ( null !== $image ) {
					$preloads[] = array(
						'href'   => $image,
						'srcset' => '',
						'sizes'  => '',
					);
					break;
				}
			}
			if ( ++$checked >= self::BACKGROUND_CANDIDATES ) {
				break;
			}
		}
		return $preloads;
	}

	/** First unconditional background-image of `.elementor-element-<id>` in a stylesheet. */
	private function backgroundOf( string $id, string $sheet ): ?string {
		$css = $this->stylesheet( $sheet );
		if ( null === $css ) {
			return null;
		}
		$css = self::withoutAtBlocks( (string) preg_replace( '~/\*.*?\*/~s', '', $css ) );
		if ( false === preg_match_all( '~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER ) ) {
			return null;
		}
		foreach ( $rules as $rule ) {
			$selector = $rule[1];
			if ( 1 !== preg_match( '~\.elementor-element-' . preg_quote( $id, '~' ) . '(?![a-zA-Z0-9_-])~', $selector ) || str_contains( $selector, ':hover' ) ) {
				continue;
			}
			if ( 1 === preg_match( '~background-image\s*:\s*url\(\s*([\'"]?)(.+?)\1\s*\)~i', $rule[2], $found ) ) {
				$url = AssetUrl::resolve( $sheet, $found[2] );
				return $this->assets->isLocal( $url ) ? $url : null;
			}
		}
		return null;
	}

	/** Drops @media/@supports/@container/@keyframes blocks (conditional or irrelevant rules). */
	private static function withoutAtBlocks( string $css ): string {
		$out    = '';
		$length = strlen( $css );
		$i      = 0;
		while ( $i < $length ) {
			if ( '@' === $css[ $i ] && 1 === preg_match( '~\G@(?:media|supports|container|keyframes|-webkit-keyframes|layer|document)\b[^{;]*\{~i', $css, $m, 0, $i ) ) {
				$depth = 1;
				$i    += strlen( $m[0] );
				while ( $i < $length && $depth > 0 ) {
					if ( '{' === $css[ $i ] ) {
						++$depth;
					} elseif ( '}' === $css[ $i ] ) {
						--$depth;
					}
					++$i;
				}
				continue;
			}
			$out .= $css[ $i ];
			++$i;
		}
		return $out;
	}

	/** Makes inline @font-face rules swap in a fallback font instead of hiding text. */
	private function swapFontDisplay(): void {
		foreach ( $this->segments as $index => $segment ) {
			if ( 'raw' !== $segment['type'] || 'style' !== $segment['name'] || ! str_contains( strtolower( $segment['html'] ), '@font-face' ) ) {
				continue;
			}
			$this->segments[ $index ]['html'] = (string) preg_replace_callback(
				'~@font-face\s*\{[^}]*\}~i',
				static function ( array $face ): string {
					if ( 1 === preg_match( '~font-display\s*:\s*(auto|block)\b~i', $face[0] ) ) {
						return (string) preg_replace( '~font-display\s*:\s*(auto|block)\b~i', 'font-display: swap', $face[0] );
					}
					if ( 1 !== preg_match( '~font-display\s*:~i', $face[0] ) ) {
						return (string) preg_replace( '~\{~', '{font-display:swap;', $face[0], 1 );
					}
					return $face[0];
				},
				$segment['html']
			);
		}
	}

	/**
	 * Replaces each run of consecutive local stylesheets with one combined file. A run
	 * ends at anything that could change the cascade between two files: inline styles,
	 * scripts, other markup, a different media query, or a sheet that cannot be combined.
	 */
	private function combineStylesheets( int $head_open, int $head_close ): void {
		$run   = array();
		$media = '';
		for ( $i = $head_open + 1; $i <= $head_close; ++$i ) {
			$segment = $this->segments[ $i ];
			if ( 'comment' === $segment['type'] || ( 'text' === $segment['type'] && '' === trim( $segment['html'] ) ) ) {
				continue;
			}
			$tag = $this->stylesheetTag( $i );
			$css = null;
			if ( null !== $tag ) {
				$css = $this->stylesheet( (string) $tag->get( 'href' ) );
				if ( null !== $css && 1 === preg_match( '~@import\b~i', $css ) ) {
					$css = null;
				}
			}
			if ( null === $tag || null === $css ) {
				$this->flushRun( $run, $media );
				$run = array();
				continue;
			}
			$sheet_media = strtolower( trim( (string) ( $tag->get( 'media' ) ?? 'all' ) ) );
			$sheet_media = '' === $sheet_media ? 'all' : $sheet_media;
			if ( array() !== $run && $sheet_media !== $media ) {
				$this->flushRun( $run, $media );
				$run = array();
			}
			$media = $sheet_media;
			$run[] = array(
				'index' => $i,
				'href'  => (string) $tag->get( 'href' ),
				'css'   => $css,
			);
		}
	}

	/** @param list<array{index:int,href:string,css:string}> $run */
	private function flushRun( array $run, string $media ): void {
		if ( count( $run ) < 2 ) {
			return;
		}
		$bundle = '';
		foreach ( $run as $sheet ) {
			$bundle .= '/* ' . str_replace( '*/', '', $sheet['href'] ) . " */\n" . self::rebase( $sheet['css'], $sheet['href'] ) . "\n";
		}
		$url = $this->assets->storeStylesheet( $bundle );
		if ( null === $url ) {
			return;
		}
		foreach ( $run as $position => $sheet ) {
			$this->segments[ $sheet['index'] ]['html'] = 0 === $position
				? sprintf( '<link rel="stylesheet" href="%1$s" media="%2$s" %3$s="css" />', htmlspecialchars( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), htmlspecialchars( $media, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), self::MARKER )
				: '';
		}
	}

	/** Makes url() references absolute so they keep working from the combined file's location. */
	public static function rebase( string $css, string $href ): string {
		$css = (string) preg_replace( '~@charset\s+[^;]+;~i', '', $css );
		return (string) preg_replace_callback(
			'~url\(\s*([\'"]?)(.*?)\1\s*\)~i',
			static fn( array $m ): string => 'url(' . $m[1] . AssetUrl::resolve( $href, $m[2] ) . $m[1] . ')',
			$css
		);
	}

	/** The stylesheet <link> at a segment index, or null. */
	private function stylesheetTag( int $index ): ?HtmlTag {
		$segment = $this->segments[ $index ];
		if ( 'tag' !== $segment['type'] || 'link' !== $segment['name'] || $segment['closing'] ) {
			return null;
		}
		$tag = HtmlTag::parse( $segment['html'] );
		if ( null === $tag ) {
			return null;
		}
		$rel = preg_split( '~\s+~', strtolower( trim( (string) $tag->get( 'rel' ) ) ) );
		if ( ! is_array( $rel ) || ! in_array( 'stylesheet', $rel, true ) || in_array( 'alternate', $rel, true ) || $tag->has( 'disabled' ) || '' === (string) $tag->get( 'href' ) ) {
			return null;
		}
		return $tag;
	}

	private function stylesheet( string $url ): ?string {
		if ( ! array_key_exists( $url, $this->cssCache ) ) {
			$this->cssCache[ $url ] = $this->assets->stylesheet( $url );
		}
		return $this->cssCache[ $url ];
	}

	/**
	 * @param list<array{href:string,srcset:string,sizes:string}> $preloads
	 * @return list<array{href:string,srcset:string,sizes:string}>
	 */
	private function unique( array $preloads ): array {
		$seen = array();
		$out  = array();
		foreach ( $preloads as $preload ) {
			if ( ! isset( $seen[ $preload['href'] ] ) ) {
				$seen[ $preload['href'] ] = true;
				$out[]                    = $preload;
			}
		}
		return $out;
	}

	/**
	 * Inserts image preloads early in <head>: after <meta charset> when present.
	 *
	 * @param list<array{href:string,srcset:string,sizes:string}> $preloads
	 */
	private function insertPreloads( array $preloads, int $head_open, int $head_close ): void {
		if ( array() === $preloads ) {
			return;
		}
		$html = '';
		foreach ( $preloads as $preload ) {
			$attributes = array(
				'rel'           => 'preload',
				'as'            => 'image',
				'href'          => $preload['href'],
				'imagesrcset'   => $preload['srcset'],
				'imagesizes'    => $preload['sizes'],
				'fetchpriority' => 'high',
				self::MARKER    => 'preload',
			);
			$parts      = array();
			foreach ( $attributes as $name => $value ) {
				if ( '' !== $value ) {
					$parts[] = $name . '="' . htmlspecialchars( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"';
				}
			}
			$html .= "\n<link " . implode( ' ', $parts ) . ' />';
		}

		$anchor = $head_open;
		for ( $i = $head_open + 1; $i < $head_close; ++$i ) {
			$segment = $this->segments[ $i ];
			if ( 'tag' === $segment['type'] && 'meta' === $segment['name'] && 1 === preg_match( '~\bcharset\s*=~i', $segment['html'] ) ) {
				$anchor = $i;
				break;
			}
		}
		$this->segments[ $anchor ]['html'] .= $html;
	}
}
