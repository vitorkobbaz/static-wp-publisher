<?php
/**
 * Authenticated REST API.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Http;

use SWPP\Core\Application\Inventory;
use SWPP\Core\Application\Publisher;
use SWPP\Core\Application\Queue;
use SWPP\Core\Infrastructure\Storage;
use WP_REST_Request;
use WP_REST_Response;

final class RestController {
	public function __construct(
		private readonly Queue $queue,
		private readonly Publisher $publisher,
		private readonly Inventory $inventory,
		private readonly Storage $storage,
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$permission = static fn (): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'swpp/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			'swpp/v1',
			'/enqueue',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'enqueue' ),
				'permission_callback' => $permission,
				'args'                => array(
					'url' => array(
						'required' => true,
						'type'     => 'string',
						'format'   => 'uri',
					),
				),
			)
		);
		register_rest_route(
			'swpp/v1',
			'/build',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'build' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			'swpp/v1',
			'/process',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'process' ),
				'permission_callback' => $permission,
			)
		);
	}

	public function status(): WP_REST_Response {
		$next_worker = wp_next_scheduled( 'swpp_process_queue' );
		return new WP_REST_Response(
			array(
				'queue'          => $this->queue->counts(),
				'published_root' => $this->storage->publishedRoot(),
				'enabled'        => ! empty( get_option( 'swpp_settings', array() )['enabled'] ),
				'next_worker'    => false !== $next_worker ? $next_worker : null,
			)
		);
	}

	public function enqueue( WP_REST_Request $request ): WP_REST_Response {
		$url   = esc_url_raw( (string) $request->get_param( 'url' ) );
		$added = $this->queue->enqueue( $url, 'rest' );
		return new WP_REST_Response(
			array(
				'queued' => $added,
				'url'    => $url,
			),
			$added ? 201 : 200
		);
	}

	public function build(): WP_REST_Response {
		return new WP_REST_Response( array( 'queued' => $this->inventory->enqueueAll() ), 202 );
	}

	public function process(): WP_REST_Response {
		$job = $this->queue->claim();
		if ( null === $job ) {
			return new WP_REST_Response(
				array(
					'processed' => false,
					'message'   => 'Queue is empty.',
				)
			);
		}
		$result = $this->publisher->publish( $job->url );
		if ( $result->success ) {
			$this->queue->complete( $job );
		} else {
			$this->queue->fail( $job, $result->message );
		}
		return new WP_REST_Response(
			array(
				'processed' => true,
				'success'   => $result->success,
				'message'   => $result->message,
				'url'       => $job->url,
			)
		);
	}
}
