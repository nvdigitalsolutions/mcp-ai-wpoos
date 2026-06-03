<?php
/**
 * Dispatch Event tool — fires a domain event through the event dispatcher.
 *
 * Uses EventDispatcherInterface — the last interface without tool coverage.
 * Framework-agnostic.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\EventDispatcherInterface;

class DispatchEventTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly EventDispatcherInterface $events,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'dispatch_event';
	}

	public function getName(): string {
		return 'Dispatch Event';
	}

	public function getDescription(): string {
		return 'Fires a custom domain event with an arbitrary payload. Any registered listeners for the event name will be invoked. Useful for triggering workflows, webhooks, or cross-system integrations.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'event'   => array(
					'type'        => 'string',
					'description' => 'The event name to dispatch (e.g., custom.data_updated).',
				),
				'payload' => array(
					'type'        => 'object',
					'description' => 'Arbitrary payload data to pass to event listeners.',
				),
			),
			'required'             => array( 'event' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$eventName = $this->stringParam( $arguments, 'event' );
		if ( '' === $eventName ) {
			return $this->errors->validationFailed(
				'event is required.',
				array( 'event' => array( 'An event name is required.' ) ),
			);
		}

		$payload = $arguments['payload'] ?? array();
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		// Wrap the payload in a generic domain event.
		$event = new class($eventName, $payload) {
			public function __construct(
				public readonly string $name,
				public readonly array $data,
			) {}
		};

		try {
			$this->events->dispatch( $event );
		} catch ( \Throwable $e ) {
			return $this->errors->create(
				'dispatch_failed',
				'Failed to dispatch event: ' . $e->getMessage(),
			);
		}

		return $this->success(
			sprintf( 'Event "%s" dispatched.', $eventName ),
			array(
				'event'      => $eventName,
				'dispatched' => true,
			),
		);
	}
}
