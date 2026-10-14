<?php
/**
 * Contract for add-ons that publish an export somewhere else.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Export;

use SWPP\Core\Export\Domain\ExportResult;

/**
 * Registered with the swpp_deploy_targets filter. The free plugin ships none: its
 * exports are downloaded as a ZIP or left in the private export directory.
 */
interface DeployTarget {
	/** Stable identifier: lowercase letters, digits, hyphens and underscores. */
	public function id(): string;

	/** Name shown to administrators. */
	public function label(): string;

	/**
	 * Publishes a completed export directory.
	 *
	 * @param list<string>|null $changedPaths Relative paths that changed since the last
	 *                                        deployment, or null to send everything.
	 * @return array{success:bool,message:string}
	 */
	public function deploy( ExportResult $result, ?array $changedPaths = null ): array;
}
