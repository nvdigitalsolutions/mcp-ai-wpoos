<?php
/**
 * NV oOS Design System — Email Auditor
 *
 * Validates email templates against the industry checklist from the research
 * doc: structure, security, accessibility (WCAG 2.2 AA-relevant checks),
 * dark mode, and the 100 KB size budget.
 *
 * Gates:
 *   - BLOCK (severity 'error'): structural/security defects — generation and
 *     activation are refused.
 *   - WARN  (severity 'warning'): accessibility/compat defects — activation
 *     is refused unless explicitly forced.
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email template auditor.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Email_Auditor {

	/**
	 * Size budget in bytes (Gmail clips at 102 KB; keep a safety margin).
	 *
	 * @var int
	 */
	const MAX_SIZE_BYTES = 102400;

	/**
	 * Warning threshold in bytes.
	 *
	 * @var int
	 */
	const WARN_SIZE_BYTES = 81920;

	/**
	 * WCAG AA contrast ratio for normal text.
	 *
	 * @var float
	 */
	const MIN_CONTRAST = 4.5;

	/**
	 * Audit a template.
	 *
	 * @param string $html        Template HTML.
	 * @param array  $token_pairs Optional contrast pairs: array( array( fg, bg ), … ) with hex values.
	 * @param string $scope       'full' or 'body'.
	 * @return array{score: int, passes_required: bool, results: array<int, array{code: string, severity: string, message: string}>}
	 */
	public function audit( $html, $token_pairs = array(), $scope = 'full' ) {
		$results = array();

		$this->check_security( $html, $results );
		if ( 'full' === $scope ) {
			$this->check_structure( $html, $results );
		}
		$this->check_accessibility( $html, $results );
		$this->check_contrast( $token_pairs, $results );
		$this->check_dark_mode( $html, $results );
		$this->check_size( $html, $results );

		return $this->summarize( $results );
	}

	/**
	 * Run the structural + security gates only (used by the AI pipeline
	 * before persisting a generated template).
	 *
	 * @param string $html Template HTML.
	 * @return bool True when no BLOCK-gate failures were found.
	 */
	public function required_gates_pass( $html ) {
		$audit   = $this->audit( $html );
		$errors  = array_filter(
			$audit['results'],
			function ( $result ) {
				return 'error' === $result['severity'];
			}
		);
		return 0 === count( $errors );
	}

	// -----------------------------------------------------------------------
	// Individual checks.
	// -----------------------------------------------------------------------

	/**
	 * Security gate: disallowed tags, attributes, and URL schemes.
	 *
	 * @param string $html    Template HTML.
	 * @param array  $results Results accumulator (by reference).
	 * @return void
	 */
	private function check_security( $html, &$results ) {
		$blocked_patterns = array(
			'code_security_script'   => array( '<script', 'error', __( 'Template contains a <script> element, which is never executed in email and is stripped by clients.', 'nvoos-design-system' ) ),
			'code_security_iframe'   => array( '<iframe', 'error', __( 'Template contains an <iframe>, which is not supported in email.', 'nvoos-design-system' ) ),
			'code_security_form'     => array( '<form', 'error', __( 'Template contains a <form> element, which is not supported in email.', 'nvoos-design-system' ) ),
			'code_security_link_tag' => array( '<link', 'error', __( 'Template references an external stylesheet via <link>, which is stripped by email clients.', 'nvoos-design-system' ) ),
			'code_security_handler'  => array( ' on[a-z]+=', 'error', __( 'Template contains inline event handlers (on* attributes), which are stripped and a security hazard.', 'nvoos-design-system' ) ),
			'code_security_js_url'   => array( 'javascript:', 'error', __( 'Template contains a javascript: URL, which is a security hazard.', 'nvoos-design-system' ) ),
			'code_security_external' => array( '<object', 'error', __( 'Template contains an <object> element, which is not supported in email.', 'nvoos-design-system' ) ),
			'code_security_embed'    => array( '<embed', 'error', __( 'Template contains an <embed> element, which is not supported in email.', 'nvoos-design-system' ) ),
		);

		foreach ( $blocked_patterns as $code => $check ) {
			if ( false !== stripos( $html, $check[0] ) ) {
				$results[] = array(
					'code'     => $code,
					'severity' => $check[1],
					'message'  => $check[2],
				);
			} else {
				$results[] = array(
					'code'     => $code,
					'severity' => 'pass',
					'message'  => __( 'OK', 'nvoos-design-system' ),
				);
			}
		}
	}

	/**
	 * Structure gate: doctype, body, sentinel, table roles, width budget.
	 *
	 * @param string $html    Template HTML.
	 * @param array  $results Results accumulator (by reference).
	 * @return void
	 */
	private function check_structure( $html, &$results ) {
		// Doctype.
		if ( false === stripos( $html, '<!doctype' ) ) {
			$results[] = array(
				'code'     => 'structure_doctype',
				'severity' => 'error',
				'message'  => __( 'Template is missing a <!DOCTYPE html> declaration.', 'nvoos-design-system' ),
			);
		} else {
			$results[] = array( 'code' => 'structure_doctype', 'severity' => 'pass', 'message' => __( 'OK', 'nvoos-design-system' ) );
		}

		// Body.
		if ( false === stripos( $html, '<body' ) ) {
			$results[] = array(
				'code'     => 'structure_body',
				'severity' => 'error',
				'message'  => __( 'Template is missing a <body> element.', 'nvoos-design-system' ),
			);
		} else {
			$results[] = array( 'code' => 'structure_body', 'severity' => 'pass', 'message' => __( 'OK', 'nvoos-design-system' ) );
		}

		// Sentinel.
		if ( false === strpos( $html, NV_oOS_Design_System_Email_Renderer::SENTINEL_PREFIX ) ) {
			$results[] = array(
				'code'     => 'structure_sentinel',
				'severity' => 'warning',
				'message'  => __( 'Template is missing the nds-email-wrapper sentinel (it is added at render time).', 'nvoos-design-system' ),
			);
		} else {
			$results[] = array( 'code' => 'structure_sentinel', 'severity' => 'pass', 'message' => __( 'OK', 'nvoos-design-system' ) );
		}

		// Table roles.
		if ( preg_match_all( '/<table\b[^>]*>/i', $html, $tables ) ) {
			$missing_role = 0;
			foreach ( $tables[0] as $table_tag ) {
				if ( false === stripos( $table_tag, 'role=' ) ) {
					++$missing_role;
				}
			}

			if ( $missing_role > 0 ) {
				$results[] = array(
					'code'     => 'structure_table_roles',
					'severity' => 'warning',
					'message'  => sprintf(
						/* translators: %d: number of layout tables missing role="presentation" */
						_n(
							'%d layout table is missing role="presentation" (screen readers will announce it as data).',
							'%d layout tables are missing role="presentation" (screen readers will announce them as data).',
							$missing_role,
							'nvoos-design-system'
						),
						$missing_role
					),
				);
			} else {
				$results[] = array( 'code' => 'structure_table_roles', 'severity' => 'pass', 'message' => __( 'All tables declare a role.', 'nvoos-design-system' ) );
			}
		}

		// Width budget (fixed widths above 600px break mobile layouts).
		if ( preg_match_all( '/width\s*[:=]\s*"?([0-9]{3,4})"?/i', $html, $widths ) ) {
			$oversized = array_filter(
				$widths[1],
				function ( $w ) {
					return (int) $w > 600;
				}
			);

			if ( ! empty( $oversized ) ) {
				$results[] = array(
					'code'     => 'structure_width',
					'severity' => 'warning',
					'message'  => __( 'Template uses fixed widths above 600px, which will break on mobile clients.', 'nvoos-design-system' ),
				);
			} else {
				$results[] = array( 'code' => 'structure_width', 'severity' => 'pass', 'message' => __( 'Widths are within the 600px budget.', 'nvoos-design-system' ) );
			}
		}
	}

	/**
	 * Accessibility checks: lang, alt text, heading structure, font size.
	 *
	 * @param string $html    Template HTML.
	 * @param array  $results Results accumulator (by reference).
	 * @return void
	 */
	private function check_accessibility( $html, &$results ) {
		// Language attribute.
		if ( false === stripos( $html, '<html' ) || false === stripos( $html, ' lang=' ) ) {
			$results[] = array(
				'code'     => 'a11y_lang',
				'severity' => 'warning',
				'message'  => __( 'Template does not declare a lang attribute (screen readers fall back to the client locale).', 'nvoos-design-system' ),
			);
		} else {
			$results[] = array( 'code' => 'a11y_lang', 'severity' => 'pass', 'message' => __( 'OK', 'nvoos-design-system' ) );
		}

		// Image alt text.
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $images ) ) {
			$missing_alt = 0;
			foreach ( $images[0] as $img_tag ) {
				if ( false === stripos( $img_tag, 'alt=' ) ) {
					++$missing_alt;
				}
			}

			if ( $missing_alt > 0 ) {
				$results[] = array(
					'code'     => 'a11y_images_alt',
					'severity' => 'warning',
					'message'  => sprintf(
						/* translators: %d: number of images missing alt text */
						_n(
							'%d image is missing alt text (WCAG 1.1.1).',
							'%d images are missing alt text (WCAG 1.1.1).',
							$missing_alt,
							'nvoos-design-system'
						),
						$missing_alt
					),
				);
			} else {
				$results[] = array( 'code' => 'a11y_images_alt', 'severity' => 'pass', 'message' => __( 'All images declare alt text.', 'nvoos-design-system' ) );
			}
		}

		// Heading structure.
		if ( ! preg_match( '/<h[1-3]\b/i', $html ) ) {
			$results[] = array(
				'code'     => 'a11y_headings',
				'severity' => 'warning',
				'message'  => __( 'Template has no h1–h3 headings; screen-reader users cannot skim the message structure.', 'nvoos-design-system' ),
			);
		} else {
			$results[] = array( 'code' => 'a11y_headings', 'severity' => 'pass', 'message' => __( 'OK', 'nvoos-design-system' ) );
		}

		// Minimum font size (body text below 13px fails readability guidance).
		if ( preg_match_all( '/font-size\s*:\s*([0-9]+)px/i', $html, $sizes ) ) {
			$too_small = array_filter(
				$sizes[1],
				function ( $s ) {
					return (int) $s < 13;
				}
			);

			if ( ! empty( $too_small ) ) {
				$results[] = array(
					'code'     => 'a11y_font_size',
					'severity' => 'warning',
					'message'  => __( 'Template uses font sizes below 13px in at least one block; WCAG guidance recommends ≥16px for body text.', 'nvoos-design-system' ),
				);
			} else {
				$results[] = array( 'code' => 'a11y_font_size', 'severity' => 'pass', 'message' => __( 'OK', 'nvoos-design-system' ) );
			}
		}
	}

	/**
	 * Contrast check against the token palette.
	 *
	 * @param array $pairs   Array of array( fg, bg ) hex pairs.
	 * @param array $results Results accumulator (by reference).
	 * @return void
	 */
	private function check_contrast( $pairs, &$results ) {
		if ( empty( $pairs ) ) {
			$pairs = $this->default_contrast_pairs();
		}

		$failures = array();

		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || 2 !== count( $pair ) ) {
				continue;
			}

			$ratio = self::contrast_ratio( $pair[0], $pair[1] );
			if ( null === $ratio ) {
				continue; // Non-hex values (var()/rgb()) — skipped.
			}

			if ( $ratio < self::MIN_CONTRAST ) {
				$failures[] = sprintf(
					/* translators: 1: foreground, 2: background, 3: ratio */
					__( '%1$s on %2$s (%3$s:1)', 'nvoos-design-system' ),
					$pair[0],
					$pair[1],
					number_format_i18n( $ratio, 2 )
				);
			}
		}

		if ( ! empty( $failures ) ) {
			$results[] = array(
				'code'     => 'a11y_contrast',
				'severity' => 'warning',
				'message'  => sprintf(
					/* translators: %s: comma-separated failing pairs */
					__( 'Contrast below WCAG AA (4.5:1) for: %s.', 'nvoos-design-system' ),
					implode( ', ', $failures )
				),
			);
		} else {
			$results[] = array( 'code' => 'a11y_contrast', 'severity' => 'pass', 'message' => __( 'All evaluated colour pairs meet 4.5:1.', 'nvoos-design-system' ) );
		}
	}

	/**
	 * Dark-mode check.
	 *
	 * @param string $html    Template HTML.
	 * @param array  $results Results accumulator (by reference).
	 * @return void
	 */
	private function check_dark_mode( $html, &$results ) {
		if ( false === stripos( $html, 'prefers-color-scheme' ) ) {
			$results[] = array(
				'code'     => 'dark_mode',
				'severity' => 'warning',
				'message'  => __( 'Template has no prefers-color-scheme overrides; Gmail/Apple Mail dark-mode users will see the light palette.', 'nvoos-design-system' ),
			);
		} else {
			$results[] = array( 'code' => 'dark_mode', 'severity' => 'pass', 'message' => __( 'OK', 'nvoos-design-system' ) );
		}
	}

	/**
	 * Size budget check.
	 *
	 * @param string $html    Template HTML.
	 * @param array  $results Results accumulator (by reference).
	 * @return void
	 */
	private function check_size( $html, &$results ) {
		$size = strlen( $html );

		if ( $size > self::MAX_SIZE_BYTES ) {
			$results[] = array(
				'code'     => 'size_budget',
				'severity' => 'error',
				'message'  => sprintf(
					/* translators: 1: template size in KB, 2: budget in KB */
					__( 'Template is %1$s KB — over the %2$s KB budget (Gmail clips messages larger than 102 KB).', 'nvoos-design-system' ),
					number_format_i18n( $size / 1024, 1 ),
					number_format_i18n( self::MAX_SIZE_BYTES / 1024 )
				),
			);
		} elseif ( $size > self::WARN_SIZE_BYTES ) {
			$results[] = array(
				'code'     => 'size_budget',
				'severity' => 'warning',
				'message'  => sprintf(
					/* translators: 1: template size in KB, 2: warning threshold in KB */
					__( 'Template is %1$s KB — approaching the %2$s KB warning threshold.', 'nvoos-design-system' ),
					number_format_i18n( $size / 1024, 1 ),
					number_format_i18n( self::WARN_SIZE_BYTES / 1024 )
				),
			);
		} else {
			$results[] = array( 'code' => 'size_budget', 'severity' => 'pass', 'message' => __( 'OK', 'nvoos-design-system' ) );
		}
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * Summarize raw results into a scorecard.
	 *
	 * @param array $results Raw check results.
	 * @return array{score: int, passes_required: bool, results: array}
	 */
	private function summarize( $results ) {
		$errors   = 0;
		$warnings = 0;
		$passes   = 0;

		foreach ( $results as $result ) {
			switch ( $result['severity'] ) {
				case 'error':
					++$errors;
					break;
				case 'warning':
					++$warnings;
					break;
				default:
					++$passes;
					break;
			}
		}

		$total = max( 1, $errors + $warnings + $passes );
		$score = (int) round( ( ( $passes * 2 ) + $warnings ) / ( $total * 2 ) * 100 );

		return array(
			'score'           => $score,
			'passes_required' => 0 === $errors,
			'results'         => array_values( $results ),
		);
	}

	/**
	 * Default contrast pairs from the email token group.
	 *
	 * @return array<int, array<int, string>>
	 */
	public function default_contrast_pairs() {
		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$values   = $registry->get_group_values( 'emails' );

		$pairs = array(
			array( isset( $values['email_body_text'] ) ? $values['email_body_text'] : '#1a2420', isset( $values['email_card_bg'] ) ? $values['email_card_bg'] : '#ffffff' ),
			array( isset( $values['email_accent'] ) ? $values['email_accent'] : '#c9b96e', isset( $values['email_header_bg'] ) ? $values['email_header_bg'] : '#0f1e18' ),
			array( isset( $values['email_accent_2'] ) ? $values['email_accent_2'] : '#4d8a7b', isset( $values['email_header_bg'] ) ? $values['email_header_bg'] : '#0f1e18' ),
			array( isset( $values['email_muted'] ) ? $values['email_muted'] : '#2e4a3e', isset( $values['email_header_bg'] ) ? $values['email_header_bg'] : '#0f1e18' ),
		);

		return $pairs;
	}

	/**
	 * WCAG contrast ratio between two hex colors.
	 *
	 * Supports #rgb and #rrggbb. Returns null for non-hex values.
	 *
	 * @param string $color1 Foreground hex color.
	 * @param string $color2 Background hex color.
	 * @return float|null Ratio (1–21), or null when either color is not a hex literal.
	 */
	public static function contrast_ratio( $color1, $color2 ) {
		$l1 = self::relative_luminance( $color1 );
		$l2 = self::relative_luminance( $color2 );

		if ( null === $l1 || null === $l2 ) {
			return null;
		}

		$lighter = max( $l1, $l2 );
		$darker  = min( $l1, $l2 );

		return ( $lighter + 0.05 ) / ( $darker + 0.05 );
	}

	/**
	 * WCAG relative luminance of a hex color.
	 *
	 * @param string $hex Hex color (#rgb or #rrggbb).
	 * @return float|null Luminance (0–1), or null when unparseable.
	 */
	public static function relative_luminance( $hex ) {
		$hex = ltrim( trim( $hex ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return null;
		}

		$channels = array();
		foreach ( str_split( $hex, 2 ) as $channel ) {
			$value = hexdec( $channel ) / 255;
			$channels[] = $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}
}
