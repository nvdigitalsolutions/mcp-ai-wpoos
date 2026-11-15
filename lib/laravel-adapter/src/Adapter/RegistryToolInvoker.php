<?php

/**
 * Tool invoker over the core tool registry.
 *
 * The retrieval harness needs three primitives and nothing else, so this is the
 * whole runtime seam: resolve the slug, run it, and collapse every failure mode
 * (missing tool, thrown exception, provider error envelope) to `null`.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolInvokerInterface;
use Throwable;

final class RegistryToolInvoker implements ToolInvokerInterface
{
	public function __construct(
		private readonly ToolRegistry $registry,
		private readonly ErrorFactoryInterface $errors,
	) {
	}

	public function invoke(string $slug, array $arguments, array $context): mixed
	{
		$tool = $this->registry->get($slug);

		if (null === $tool) {
			return null;
		}

		try {
			$result = $tool->execute($arguments, $context);
		} catch (Throwable) {
			return null;
		}

		return $this->errors->isError($result) ? null : $result;
	}
}
