<?php
/** @package Nvoos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
class GetSiteSummaryTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s ) {
		parent::__construct( $e );}
	public function getSlug(): string {
		return 'get_site_summary'; }
	public function getName(): string {
		return 'Get Site Summary'; }
	public function getDescription(): string {
		return 'Returns a summary of site configuration including active providers, default model, and feature flags.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
		); }
	public function getRequiredCapability(): string {
		return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		return $this->success(
			'Site summary retrieved.',
			array(
				'default_provider' => $this->s->getDefaultProvider(),
				'default_model'    => $this->s->getDefaultModel(),
				'request_timeout'  => $this->s->getRequestTimeout(),
				'features'         => array(
					'rate_limiting'     => $this->s->isEnabled( 'rate_limiting' ),
					'acp_server'        => $this->s->isEnabled( 'acp_server' ),
					'chat_memory'       => $this->s->isEnabled( 'chat_memory' ),
					'multi_agent_teams' => $this->s->isEnabled( 'multi_agent_teams' ),
				),
			)
		);
	}
}
