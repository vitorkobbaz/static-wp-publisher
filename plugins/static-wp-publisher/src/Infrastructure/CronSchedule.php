<?php
/**
 * Shared worker schedule registration.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Infrastructure;

final class CronSchedule {
	/**
	 * @param array<string,array<string,int|string>> $schedules Existing schedules.
	 * @return array<string,array<string,int|string>>
	 */
	public static function add( array $schedules ): array {
		$schedules['swpp_every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Static WP Publisher)', 'static-wp-publisher' ),
		);
		return $schedules;
	}
}
