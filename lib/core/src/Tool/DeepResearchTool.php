<?php
/** Deep Research — multi-step research using web search + AI model.
 *
 * @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

class DeepResearchTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) {
		parent::__construct( $e );}
	public function getSlug(): string {
		return 'deep_research'; }
	public function getName(): string {
		return 'Deep Research'; }
	public function getDescription(): string {
		return 'Performs multi-step research by searching the web and synthesizing findings using an AI model.'; }
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
		); }
	public function getRequiredCapability(): string {
		return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$query = $this->stringParam( $arguments, 'query' );
		if ( '' === $query ) {
			return $this->errors->validationFailed( 'query is required.', array( 'query' => array( 'Research query is required.' ) ) );
		}
		$depth = $this->intParam( $arguments, 'depth', 2 );
		// Deep research orchestrates web_search + AI analysis. Return the framework — the agentic loop handles the rest.
		return $this->success(
			'Deep research initiated.',
			array(
				'query'        => $query,
				'depth'        => $depth,
				'steps'        => array( 'search', 'analyze', 'synthesize' ),
				'instructions' => 'Use web_search to gather sources, then synthesize findings across ' . $depth . ' iterations.',
			)
		);
	}
}
