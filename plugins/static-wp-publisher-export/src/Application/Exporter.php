<?php
/**
 * Portable static export service.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export\Application;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use SWPP\Core\Infrastructure\Storage;
use SWPP\Export\Domain\ExportRequest;
use SWPP\Export\Domain\ExportResult;
use SWPP\Export\Infrastructure\ZipPackager;
use SWPP\Export\Infrastructure\AssetCollector;
use SWPP\Export\UrlRewriter;

final class Exporter {
	public function __construct( private readonly UrlRewriter $rewriter, private readonly ZipPackager $zip, private readonly AssetCollector $assets ) {}

	public function export( ExportRequest $request ): ExportResult {
		try {
			$storage = new Storage();
			$source  = $storage->publishedRoot();
			if ( ! is_dir( $source ) ) {
				throw new RuntimeException( 'No published static directory exists yet.' );
			}
			$uploads = wp_upload_dir();
			if ( ! empty( $uploads['error'] ) ) {
				throw new RuntimeException( (string) $uploads['error'] );
			}
			$id          = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
			$export_root = trailingslashit( (string) $uploads['basedir'] ) . 'static-wp-publisher/exports/' . $id;
			if ( ! wp_mkdir_p( $export_root ) ) {
				throw new RuntimeException( 'Unable to create export directory.' );
			}

			$pages    = array();
			$warnings = $this->copyAndRewrite( $source, $export_root, $request, $pages );
			$warnings = array_merge( $warnings, $this->assets->collect( $pages, $export_root ) );
			$this->rewriteStylesheets( $export_root, $request );
			file_put_contents( $export_root . '/README-DEPLOYMENT.txt', $this->deploymentReadme( $request, $warnings ), LOCK_EX );
			$manifest = $this->manifest( $export_root, $request, $warnings );
			file_put_contents( $export_root . '/swpp-manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );

			$zip_path = null;
			if ( $request->createZip ) {
				$zip_path = dirname( $export_root ) . '/' . basename( $export_root ) . '.zip';
				$this->zip->create( $export_root, $zip_path );
			}

			return new ExportResult( true, 'Export completed.', $export_root, $zip_path, $warnings );
		} catch ( Throwable $error ) {
			return new ExportResult( false, $error->getMessage() );
		}
	}

	private function rewriteStylesheets( string $root, ExportRequest $request ): void {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() || 'css' !== strtolower( $item->getExtension() ) ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( rtrim( $root, '/\\' ) ) + 1 ) );
			$css      = file_get_contents( $item->getPathname() );
			if ( false === $css || false === file_put_contents( $item->getPathname(), $this->rewriter->rewriteCss( $css, $relative, home_url( '/' ), $request->mode, $request->targetBase ), LOCK_EX ) ) {
				throw new RuntimeException( 'Unable to rewrite exported stylesheet: ' . $relative );
			}
		}
	}

	/** @return list<string> */
	private function copyAndRewrite( string $source, string $destination, ExportRequest $request, array &$pages ): array {
		$warnings  = array();
		$seen      = array();
		$source    = rtrim( realpath( $source ) ?: $source, '/\\' );
		$iterator  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				throw new RuntimeException( 'Symbolic links are not permitted in portable exports.' );
			}
			$absolute = $item->getPathname();
			$relative = str_replace( '\\', '/', substr( $absolute, strlen( $source ) + 1 ) );
			$this->assertSafeRelative( $relative );
			$key = strtolower( $relative );
			if ( isset( $seen[ $key ] ) && $seen[ $key ] !== $relative ) {
				throw new RuntimeException( 'Case-insensitive path collision detected: ' . $relative );
			}
			$seen[ $key ] = $relative;
			$target       = $destination . '/' . $relative;
			if ( $item->isDir() ) {
				wp_mkdir_p( $target );
				continue;
			}
			wp_mkdir_p( dirname( $target ) );
			$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
			if ( 'html' === $extension ) {
				$html       = (string) file_get_contents( $absolute );
				$pages[]    = $html;
				$warnings   = array_merge( $warnings, $this->dynamicWarnings( $html, $relative ) );
				$rewritten  = $this->rewriter->rewriteHtml( $html, $relative, home_url( '/' ), $request->mode, $request->targetBase, $request->addNoindex );
				file_put_contents( $target, $rewritten, LOCK_EX );
			} elseif ( 'css' === $extension ) {
				$css = (string) file_get_contents( $absolute );
				file_put_contents( $target, $this->rewriter->rewriteCss( $css, $relative, home_url( '/' ), $request->mode, $request->targetBase ), LOCK_EX );
			} elseif ( ! copy( $absolute, $target ) ) {
				throw new RuntimeException( 'Unable to copy export asset: ' . $relative );
			}
		}
		return array_values( array_unique( $warnings ) );
	}

	/**
	 * @param list<string> $warnings
	 * @return array<string,mixed>
	 */
	private function manifest( string $root, ExportRequest $request, array $warnings ): array {
		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}
			$relative           = str_replace( '\\', '/', substr( $item->getPathname(), strlen( rtrim( $root, '/\\' ) ) + 1 ) );
			$hash = hash_file( 'sha256', $item->getPathname() );
			if ( false === $hash ) {
				throw new RuntimeException( 'Unable to hash exported file: ' . $relative );
			}
			$files[ $relative ] = array( 'sha256' => $hash, 'bytes' => $item->getSize() );
		}
		ksort( $files );
		return array(
			'schema'      => 1,
			'generator'   => 'Static WP Publisher Export ' . SWPP_EXPORT_VERSION,
			'created_at'  => gmdate( DATE_ATOM ),
			'mode'        => $request->mode,
			'target_base' => $request->targetBase,
			'noindex'     => $request->addNoindex,
			'warnings'    => $warnings,
			'files'       => $files,
		);
	}

	/** @return list<string> */
	private function dynamicWarnings( string $html, string $relative ): array {
		$patterns = array(
			'<form'                 => 'contains a form that requires a backend',
			'wp-comments-post.php'  => 'contains WordPress comments that will not submit',
			'wp-login.php'          => 'links to WordPress login',
			'?s='                   => 'contains WordPress search that will not run on static hosting',
			'wc-ajax='              => 'contains a WooCommerce AJAX action',
			'/checkout/'            => 'links to a checkout backend',
			'/my-account/'          => 'links to an account backend',
		);
		$warnings = array();
		foreach ( $patterns as $needle => $message ) {
			if ( false !== stripos( $html, $needle ) ) {
				$warnings[] = $relative . ': ' . $message . '.';
			}
		}
		return $warnings;
	}

	private function assertSafeRelative( string $relative ): void {
		if ( '' === $relative || str_contains( $relative, "\0" ) || str_starts_with( $relative, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
			throw new RuntimeException( 'Unsafe export path detected.' );
		}
	}

	/** @param list<string> $warnings */
	private function deploymentReadme( ExportRequest $request, array $warnings ): string {
		$lines = array(
			'Static WP Publisher deployment package',
			'=====================================',
			'',
			'Mode: ' . $request->mode,
			'Target: ' . ( $request->targetBase ?: '(relocatable preview)' ),
			'',
			'Extract this package locally and upload the extracted tree, or use a hosting panel that explicitly supports archive extraction. Uploading a ZIP over FTP does not extract it.',
			'Use HTTPS-capable SFTP or FTPS instead of plain FTP whenever possible.',
			'Redirects and correct HTTP 404 status require server configuration.',
			'',
			'Dynamic limitations:',
		);
		$lines = array_merge( $lines, $warnings ?: array( '- No known dynamic markers were detected. Manual review is still required.' ) );
		return implode( PHP_EOL, $lines ) . PHP_EOL;
	}
}
