<?php
/**
 * Generate Email Template tool — builds responsive MJML email markup.
 *
 * Ported from `WP_MCP_AI_Tool_Generate_Email_Template` under Proposal 090-D
 * cluster 1. The markup work is pure: components in, MJML out, via
 * `MjmlTemplateBuilder`. Compiling that markup to email HTML needs a JavaScript
 * engine, so it is optional here — provide an `MjmlCompilerInterface` and the
 * `html` / `both` formats work, omit it and the tool still returns MJML.
 *
 * ## Differences from the WordPress tool
 *
 * - Results use the canonical core envelope: the WordPress fields
 *   (`template_type`, `html`, `mjml`, `subject`, `preview_text`) appear under
 *   `data` instead of at the top level.
 * - `wp_kses_post()` is a WordPress policy, so component `content` is injected
 *   verbatim here. A runtime that allows untrusted authors must sanitise before
 *   calling `execute()`, exactly as the WordPress service does.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\MjmlCompilerInterface;
use Nvoos\Core\Domain\Service\Mjml\MjmlTemplateBuilder;
use Nvoos\Core\Support\Text;

class GenerateEmailTemplateTool extends AbstractTool {

	private const DEFAULT_FONT_FAMILY      = 'Arial, Helvetica, sans-serif';
	private const DEFAULT_BACKGROUND_COLOR = '#f4f4f4';
	private const DEFAULT_CONTAINER_WIDTH  = '600px';

	/**
	 * Optional compiler. Null means this runtime can only emit MJML.
	 */
	private readonly ?MjmlCompilerInterface $compiler;

	public function __construct(
		ErrorFactoryInterface $errors,
		?MjmlCompilerInterface $compiler = null,
	) {
		parent::__construct( $errors );

		$this->compiler = $compiler;
	}

	public function getSlug(): string {
		return 'generate_email_template';
	}

	public function getName(): string {
		return 'Generate Email Template';
	}

	public function getDescription(): string {
		return 'Generate responsive email templates using MJML. Create professional, mobile-friendly emails with cross-client compatibility. Perfect for newsletters, marketing campaigns, transactional emails, and notifications.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'template_type'   => array(
					'type'        => 'string',
					'enum'        => array( 'newsletter', 'marketing', 'transactional', 'notification', 'custom' ),
					'description' => 'Type of email template to generate',
					'default'     => 'newsletter',
				),
				'subject'         => array(
					'type'        => 'string',
					'description' => 'Email subject line',
				),
				'preview_text'    => array(
					'type'        => 'string',
					'description' => 'Preview text shown in email clients',
				),
				'components'      => array(
					'type'        => 'array',
					'description' => 'Array of email components (header, text, button, image, etc.)',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'type'       => array( 'type' => 'string' ),
							'content'    => array( 'type' => 'string' ),
							'attributes' => array( 'type' => 'object' ),
						),
					),
				),
				'branding'        => array(
					'type'        => 'object',
					'description' => 'Branding options (logo, colors, fonts)',
					'properties'  => array(
						'logo_url'        => array( 'type' => 'string' ),
						'primary_color'   => array( 'type' => 'string' ),
						'secondary_color' => array( 'type' => 'string' ),
						'font_family'     => array( 'type' => 'string' ),
						'company_name'    => array( 'type' => 'string' ),
						'company_address' => array( 'type' => 'string' ),
					),
				),
				'footer_links'    => array(
					'type'        => 'array',
					'description' => 'Footer links (unsubscribe, privacy policy, etc.)',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'text' => array( 'type' => 'string' ),
							'url'  => array( 'type' => 'string' ),
						),
					),
				),
				'container_width' => array(
					'type'        => 'string',
					'description' => 'Email container width (default: 600px)',
					'default'     => '600px',
				),
				'output_format'   => array(
					'type'        => 'string',
					'enum'        => array( 'html', 'mjml', 'both' ),
					'description' => 'Output format: html (compiled), mjml (source), or both',
					'default'     => 'html',
				),
				'minify'          => array(
					'type'        => 'boolean',
					'description' => 'Minify HTML output for smaller file size',
					'default'     => false,
				),
			),
			'required'   => array( 'components' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		unset( $context );

		$components = $this->arrayParam( $arguments, 'components' );

		if ( array() === $components ) {
			return $this->errors->validationFailed(
				'Email components are required.',
				array( 'components' => array( 'Provide at least one email component.' ) )
			);
		}

		/** @var array<int, array<string, mixed>> $components */
		$components = array_values( array_filter( $components, 'is_array' ) );

		if ( array() === $components ) {
			return $this->errors->validationFailed(
				'Email components are required.',
				array( 'components' => array( 'Provide at least one email component object.' ) )
			);
		}

		$branding     = $this->objectParam( $arguments, 'branding' );
		$output_format = $this->stringParam( $arguments, 'output_format', 'html' );
		$template_type = $this->stringParam( $arguments, 'template_type', 'custom' );
		$subject       = $this->stringParam( $arguments, 'subject' );
		$preview_text  = $this->stringParam( $arguments, 'preview_text' );

		/** @var array<int, array<string, mixed>> $footer_links */
		$footer_links = array_values( array_filter( $this->arrayParam( $arguments, 'footer_links' ), 'is_array' ) );

		$builder = new MjmlTemplateBuilder();

		$options = array(
			'title'            => $this->singleLine( $subject ),
			'preview_text'     => $this->singleLine( $preview_text ),
			'container_width'  => $this->singleLine( $this->stringParam( $arguments, 'container_width', self::DEFAULT_CONTAINER_WIDTH ) ),
			'font_family'      => self::DEFAULT_FONT_FAMILY,
			'background_color' => self::DEFAULT_BACKGROUND_COLOR,
		);

		if ( isset( $branding['font_family'] ) ) {
			$options['font_family'] = $this->singleLine( (string) $branding['font_family'] );
		}

		$mjml = $builder->build( $components, $options );

		if ( array() !== $branding ) {
			$mjml = $builder->withBrandingHeader( $mjml, $branding );
		}

		if ( array() !== $footer_links ) {
			$mjml = $builder->withFooter( $mjml, $footer_links, $branding );
		}

		$data = array(
			'template_type' => $template_type,
			'subject'       => '' === $subject ? null : $subject,
			'preview_text'  => '' === $preview_text ? null : $preview_text,
		);

		if ( 'mjml' === $output_format ) {
			$data['mjml'] = $mjml;

			return $this->success( 'Email template generated successfully.', $data );
		}

		if ( null === $this->compiler || ! $this->compiler->isAvailable() ) {
			return $this->errors->create(
				'mjml_compile_unavailable',
				'MJML compilation is not available in this runtime. Request output_format "mjml" for the template source, or configure an MJML compiler.',
				array(
					'status'  => 501,
					'package' => 'mjml',
					'mjml'    => $mjml,
				)
			);
		}

		try {
			$html = $this->compiler->compile(
				$mjml,
				array(
					'minify'          => $this->boolParam( $arguments, 'minify' ),
					'beautify'        => ! $this->boolParam( $arguments, 'minify' ),
					'validationLevel' => 'soft',
				)
			);
		} catch ( \RuntimeException $exception ) {
			return $this->errors->create(
				'mjml_compilation_failed',
				'MJML compilation failed: ' . $exception->getMessage(),
				array(
					'status' => 500,
					'mjml'   => $mjml,
				)
			);
		}

		$data['html'] = $html;

		if ( 'both' === $output_format ) {
			$data['mjml'] = $mjml;
		}

		return $this->success( 'Email template generated successfully.', $data );
	}

	/**
	 * Collapse a value to a single line, matching `sanitize_text_field()` for the
	 * header-level options (no tags, no control characters, single spaces).
	 */
	private function singleLine( string $value ): string {
		return Text::singleLine( $value );
	}
}
