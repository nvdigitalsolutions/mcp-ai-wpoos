<?php
/**
 * The package host's own health probes.
 *
 * `get_site_health` is served everywhere the manifest is, but health checks are
 * host facts. The Laravel app answers six questions (database, cache, queue,
 * scheduler, providers, security) because it knows them; the package host knows
 * two: whether the manifest's tools actually registered, and whether a default
 * model is configured. Reporting a probe this host cannot run would be worse
 * than reporting fewer probes.
 *
 * The app replaces this binding in `AppServiceProvider`, which is the pattern
 * the other overridable contracts use.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Health;

use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\HealthReportInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

final class PackageHealthReport implements HealthReportInterface {

	/**
	 * @param ToolRegistry           $registry  Registered tools.
	 * @param ToolReadiness          $readiness Manifest decisions.
	 * @param SettingsStoreInterface $settings  Default provider and model.
	 */
	public function __construct(
		private readonly ToolRegistry $registry,
		private readonly ToolReadiness $readiness,
		private readonly SettingsStoreInterface $settings,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function probes(): array {
		return array( $this->tools(), $this->settings() );
	}

	/**
	 * Did every servable manifest row register?
	 *
	 * The registry can hold more than the manifest (aliases and host-registered
	 * tools), so this is a lower bound, not an equality: fewer registered than
	 * servable is the only failure state.
	 *
	 * @return array{id: string, label: string, status: string, summary: string, detail: string}
	 */
	private function tools(): array {
		$registered = $this->registry->count();
		$servable   = \count( $this->readiness->servable() );
		$complete   = $registered >= $servable;

		return array(
			'id'      => 'tools',
			'label'   => 'Tool registration',
			'status'  => $complete ? HealthReportInterface::GOOD : HealthReportInterface::RECOMMENDED,
			'summary' => \sprintf( '%d of %d servable tools registered.', $registered, $servable ),
			'detail'  => $complete
				? 'Every servable manifest row was constructed by the container.'
				: 'A servable manifest row could not be constructed; see the skipped-tools list.',
		);
	}

	/**
	 * Is a default provider and model configured?
	 *
	 * Flags only. The provider slug and model id are public identifiers; no
	 * credential value is read.
	 *
	 * @return array{id: string, label: string, status: string, summary: string, detail: string}
	 */
	private function settings(): array {
		$provider   = \trim( $this->settings->getDefaultProvider() );
		$model      = \trim( $this->settings->getDefaultModel() );
		$configured = '' !== $provider && '' !== $model;

		return array(
			'id'      => 'settings',
			'label'   => 'Default model',
			'status'  => $configured ? HealthReportInterface::GOOD : HealthReportInterface::RECOMMENDED,
			'summary' => $configured
				? \sprintf( 'Default provider %s, model %s.', $provider, $model )
				: 'No default provider or model is configured.',
			'detail'  => 'Configuration flags only; no credential value is read.',
		);
	}
}
