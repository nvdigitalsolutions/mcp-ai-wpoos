<?php
/**
 * Registers tools from the readiness manifest.
 *
 * Before 090-M the registry was seeded from a hand-maintained catalogue, so a
 * tool joined the runtime only when someone remembered to add it. That is why
 * the HTTP client and settings store were bound for months while the tools
 * needing them stayed unregistered.
 *
 * This registrar is the policy half: the manifest says which slugs may be
 * served, and reflection supplies the constructor arguments from the container.
 * A tool that cannot be built is skipped with a reason, never fatal — a single
 * broken constructor must not take the host down at boot.
 *
 * Skipped tools are the actionable output: they mean the manifest claims
 * `ready` for something the container cannot satisfy.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tool;

use Illuminate\Contracts\Container\Container;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolInterface;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

final class ManifestToolRegistrar {

	/**
	 * @param Container     $container Service container.
	 * @param ToolReadiness $manifest  Readiness decisions.
	 */
	public function __construct(
		private readonly Container $container,
		private readonly ToolReadiness $manifest,
	) {
	}

	/**
	 * Register every servable tool the registry does not already hold.
	 *
	 * @param ToolRegistry $registry Target registry.
	 * @return array{registered: string[], skipped: array<string, string>}
	 */
	public function registerInto( ToolRegistry $registry ): array {
		$registered = array();
		$skipped    = array();

		foreach ( $this->manifest->servable() as $slug => $row ) {
			if ( $registry->has( $slug ) ) {
				continue;
			}

			$class = (string) ( $row['class'] ?? '' );

			if ( '' === $class || ! class_exists( $class ) ) {
				$skipped[ $slug ] = 'class not found';
				continue;
			}

			try {
				$tool = $this->build( $class );
			} catch ( Throwable $error ) {
				$skipped[ $slug ] = $error->getMessage();
				continue;
			}

			if ( ! $tool instanceof ToolInterface ) {
				$skipped[ $slug ] = 'not a ToolInterface';
				continue;
			}

			$registry->register( $tool );
			$registered[] = $slug;
		}

		return array(
			'registered' => $registered,
			'skipped'    => $skipped,
		);
	}

	/**
	 * Construct a tool, resolving each constructor parameter from the container.
	 *
	 * @param class-string $class Tool class.
	 * @throws \RuntimeException When a parameter cannot be resolved.
	 */
	private function build( string $class ): object {
		$reflection  = new ReflectionClass( $class );
		$constructor = $reflection->getConstructor();

		if ( null === $constructor ) {
			return $reflection->newInstance();
		}

		$arguments = array();

		foreach ( $constructor->getParameters() as $parameter ) {
			$type = $parameter->getType();

			if ( ! $type instanceof ReflectionNamedType || $type->isBuiltin() ) {
				if ( $parameter->isDefaultValueAvailable() ) {
					$arguments[] = $parameter->getDefaultValue();
					continue;
				}

				throw new \RuntimeException( 'cannot resolve $' . $parameter->getName() );
			}

			$name = $type->getName();

			// An unbound optional collaborator means "this host has no such
			// service", which the tool contract allows — use the documented
			// default rather than failing the whole registration.
			if ( ! $this->container->bound( $name ) && ! class_exists( $name ) ) {
				if ( $parameter->isDefaultValueAvailable() ) {
					$arguments[] = $parameter->getDefaultValue();
					continue;
				}

				throw new \RuntimeException( 'service not bound: ' . $name );
			}

			$arguments[] = $this->container->make( $name );
		}

		return $reflection->newInstanceArgs( $arguments );
	}
}
