<?php
/**
 * Delete Setting tool — removes a plugin setting (resets to default).
 *
 * Uses SettingsStoreInterface::delete(). Framework-agnostic.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\SettingsStoreInterface;

class DeleteSettingTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'delete_setting';
	}

	public function getName(): string {
		return 'Delete Setting';
	}

	public function getDescription(): string {
		return 'Removes a plugin setting, restoring it to its default value. Use list_settings to discover keys.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key' => array(
					'type'        => 'string',
					'description' => 'The setting key to delete.',
				),
			),
			'required'             => array( 'key' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$key = $this->stringParam( $arguments, 'key' );
		if ( '' === $key ) {
			return $this->errors->validationFailed(
				'key is required.',
				array( 'key' => array( 'A setting key is required.' ) ),
			);
		}

		$this->settings->delete( $key );

		return $this->success(
			sprintf( 'Setting "%s" deleted (restored to default).', $key ),
			array( 'key' => $key ),
		);
	}
}
