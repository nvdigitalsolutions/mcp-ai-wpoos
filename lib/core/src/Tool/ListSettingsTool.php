<?php
/**
 * List Settings tool — lists all available plugin settings.
 *
 * Uses SettingsStoreInterface::all() to enumerate all configuration keys
 * and their current values. Framework-agnostic.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\SettingsStoreInterface;

class ListSettingsTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'list_settings';
	}

	public function getName(): string {
		return 'List Settings';
	}

	public function getDescription(): string {
		return 'Lists all available plugin or application settings with their current values. API keys are redacted for security.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$all = $this->settings->all();

		// Redact sensitive keys (API keys, secrets).
		$redacted = array();
		foreach ( $all as $key => $value ) {
			if ( $this->isSensitiveKey( $key ) ) {
				$redacted[ $key ] = $this->redact( $value );
			} else {
				$redacted[ $key ] = $value;
			}
		}

		return $this->success(
			sprintf( '%d settings found.', count( $redacted ) ),
			array(
				'settings' => $redacted,
				'count'    => count( $redacted ),
			),
		);
	}

	/**
	 * Determine if a key contains sensitive data.
	 */
	private function isSensitiveKey( string $key ): bool {
		$sensitive = array( 'api_key', 'secret', 'password', 'token', 'credential' );
		foreach ( $sensitive as $fragment ) {
			if ( false !== stripos( $key, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Redact a sensitive value.
	 */
	private function redact( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '[empty]';
		}

		$len = strlen( $value );
		if ( $len <= 8 ) {
			return str_repeat( '*', $len );
		}

		return substr( $value, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $value, -4 );
	}
}
