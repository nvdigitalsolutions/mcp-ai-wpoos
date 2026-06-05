<?php
/**
 * Deep Research — multi-step research using web search + AI model.
 *
 * Orchestrates a research workflow: search → analyze → synthesize.
 * The agentic loop handles the actual tool execution; this tool
 * returns the framework and instructions.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class DeepResearchTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'deep_research';
	}

	public function getName(): string {
		return 'Deep Research';
	}

	public function getDescription(): string {
		return 'Performs multi-step research by searching the web and synthesizing findings using an AI model.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query' => array(
					'type'        => 'string',
					'description' => 'Research query',
				),
				'depth' => array(
					'type'        => 'integer',
					'description' => 'Research depth (1-5). Default: 2.',
					'minimum'     => 1,
					'maximum'     => 5,
					'default'     => 2,
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$query = $this->stringParam( $arguments, 'query' );
		if ( '' === $query ) {
			return $this->errors->validationFailed(
				'query is required.',
				array( 'query' => array( 'Research query is required.' ) ),
			);
		}

		$depth = $this->intParam( $arguments, 'depth', 2 );

		// Determine default search provider from settings.
		$searchProvider = $this->settings->get( 'default_provider', 'openai' );

		return $this->success(
			'Deep research initiated.',
			array(
				'query'        => $query,
				'depth'        => $depth,
				'provider'     => $searchProvider,
				'steps'        => array( 'search', 'analyze', 'synthesize' ),
				'instructions' => sprintf(
					'Use web_search to gather sources, then synthesize findings across %d iterations.',
					$depth,
				),
			)
		);
	}
}
