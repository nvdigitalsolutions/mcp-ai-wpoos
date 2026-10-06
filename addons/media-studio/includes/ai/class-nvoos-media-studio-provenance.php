<?php
/**
 * NV oOS Media Studio — Provenance & Compliance (Phase 4)
 *
 * Collects the AI provenance recorded in `_nvoos_ai_*` attachment meta and
 * embeds it as IPTC 2025.1 XMP fields (machine-readable disclosure per the
 * EU AI Act Art. 50 / California SB 942 posture), then optionally requests
 * C2PA signing from a configured signing service.
 *
 * Ordering matters: XMP embedding invalidates any existing C2PA signature,
 * so signing (when configured) always runs AFTER the XMP write.
 *
 * @package NV_oOS_Media_Studio
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provenance service.
 *
 * @since 0.5.0
 */
class NV_oOS_Media_Studio_Provenance {

	/**
	 * Meta key set by a successful C2PA signing round-trip.
	 *
	 * @var string
	 */
	const META_C2PA_SIGNED = '_nvoos_c2pa_signed';

	/**
	 * Whether a C2PA signing service URL is configured.
	 *
	 * @return bool
	 */
	public static function is_c2pa_configured() {
		$settings = array();
		if ( class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			$settings = NV_oOS_Media_Studio_AI_Service::get_settings();
		}
		$url = isset( $settings['c2pa_sign_url'] ) ? trim( (string) $settings['c2pa_sign_url'] ) : '';
		return '' !== $url && 0 === strpos( $url, 'https://' );
	}

	/**
	 * Build the IPTC 2025.1 field set for an AI-generated asset.
	 *
	 * AIPromptInformation is intentionally omitted: prompts are retained only
	 * as a hash (`_nvoos_ai_prompt_hash`), never in plaintext (privacy-aware
	 * default; the IPTC standard marks the field optional).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $user_id       Acting user ID.
	 * @return array Field name => value.
	 */
	public static function build_fields( $attachment_id, $user_id = 0 ) {
		$attachment_id = absint( $attachment_id );

		$provider  = sanitize_text_field( (string) get_post_meta( $attachment_id, '_nvoos_ai_provider', true ) );
		$model     = sanitize_text_field( (string) get_post_meta( $attachment_id, '_nvoos_ai_model', true ) );
		$transform = sanitize_key( (string) get_post_meta( $attachment_id, '_nvoos_ai_transform', true ) );
		$hash      = sanitize_text_field( (string) get_post_meta( $attachment_id, '_nvoos_ai_prompt_hash', true ) );

		if ( '' === $provider ) {
			$provider = 'unknown';
		}

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$user    = $user_id ? get_userdata( $user_id ) : null;
		$writer  = $user ? $user->display_name : __( 'NV oOS Media Studio', 'nvoos-media-studio' );

		$fields = array(
			'DigitalSourceType'   => NV_oOS_Media_Studio_XMP_Writer::DST_TRAINED_ALGORITHMIC,
			'AISystemUsed'        => $provider,
			'AISystemVersionUsed' => $model,
			'AIPromptWriterName'  => $writer,
			'photoshop:Credit'    => sprintf(
				/* translators: %s: provider slug */
				__( 'Generated with %s via NV oOS Media Studio', 'nvoos-media-studio' ),
				$provider
			),
			'photoshop:Source'    => '' !== $transform ? $transform : 'ai-generation',
			'xmpRights:Marked'    => 'True',
		);

		if ( '' !== $hash ) {
			$fields['photoshop:TransmissionReference'] = 'prompt-hash:' . $hash;
		}

		/**
		 * Filter the XMP fields before embedding (privacy stripping, extensions).
		 *
		 * @param array $fields        Field name => value.
		 * @param int   $attachment_id Attachment ID.
		 * @param int   $user_id       Acting user ID.
		 */
		return apply_filters( 'nvoos_media_studio_xmp_fields', $fields, $attachment_id, $user_id );
	}

	/**
	 * Build fields for a derived (processed/edited) asset.
	 *
	 * Derived assets are marked compositeSynthetic; when their source is
	 * AI-generated, the source AI fields carry over per the plan.
	 *
	 * @param int $attachment_id Derived attachment ID.
	 * @param int $user_id       Acting user ID.
	 * @return array Field name => value.
	 */
	public static function build_derived_fields( $attachment_id, $user_id = 0 ) {
		$attachment_id = absint( $attachment_id );
		$source_id     = absint( get_post_meta( $attachment_id, NV_oOS_Media_Studio_Output_Pipeline::META_DERIVED_FROM, true ) );
		$profile       = sanitize_key( (string) get_post_meta( $attachment_id, NV_oOS_Media_Studio_Output_Pipeline::META_PROFILE, true ) );

		$fields = array(
			'DigitalSourceType'  => NV_oOS_Media_Studio_XMP_Writer::DST_COMPOSITE_SYNTHETIC,
			'AISystemUsed'       => 'NV oOS Media Studio Output Pipeline',
			'AIPromptWriterName' => '',
			'photoshop:Credit'   => __( 'Processed with NV oOS Media Studio', 'nvoos-media-studio' ),
			'photoshop:Source'   => '' !== $profile ? $profile : 'media-studio-pipeline',
			'xmpRights:Marked'   => 'True',
		);

		// Carry the generative provenance forward when the source was AI output.
		if ( $source_id > 0 && get_post_meta( $source_id, '_nvoos_ai_generated', true ) ) {
			$source_fields = self::build_fields( $source_id, $user_id );
			$carry         = array();
			foreach ( array( 'AISystemUsed', 'AISystemVersionUsed', 'AIPromptWriterName', 'photoshop:TransmissionReference' ) as $key ) {
				if ( isset( $source_fields[ $key ] ) && '' !== $source_fields[ $key ] ) {
					$carry[ $key ] = $source_fields[ $key ];
				}
			}
			// Derived descriptors (DigitalSourceType, Credit, Source) stay first;
			// the generative AI system fields fill in from the source.
			$fields = array_merge( $fields, $carry );
		}

		/**
		 * Filter the XMP fields for derived assets before embedding.
		 *
		 * @param array $fields        Field name => value.
		 * @param int   $attachment_id Derived attachment ID.
		 * @param int   $user_id       Acting user ID.
		 */
		return apply_filters( 'nvoos_media_studio_xmp_derived_fields', $fields, $attachment_id, $user_id );
	}

	/**
	 * Record provenance for an AI-generated attachment:
	 * embed XMP, then best-effort C2PA signing (after the XMP write).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $user_id       Acting user ID.
	 * @return array{xmp_embedded:bool,c2pa_signed:bool,c2pa_reason:string}
	 */
	public static function record( $attachment_id, $user_id = 0 ) {
		$attachment_id = absint( $attachment_id );
		$result        = array(
			'xmp_embedded' => false,
			'c2pa_signed'  => false,
			'c2pa_reason'  => '',
		);

		$fields = self::build_fields( $attachment_id, $user_id );
		if ( class_exists( 'NV_oOS_Media_Studio_XMP_Writer' ) && NV_oOS_Media_Studio_XMP_Writer::supports_mime( get_post_mime_type( $attachment_id ) ) ) {
			$embedded = NV_oOS_Media_Studio_XMP_Writer::embed( $attachment_id, $fields );
			if ( is_wp_error( $embedded ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_warning( 'Media Studio XMP embedding failed: ' . $embedded->get_error_message(), array( 'attachment_id' => $attachment_id ) );
			}
			$result['xmp_embedded'] = true === $embedded;
		}

		$signed                = self::maybe_sign_c2pa( $attachment_id );
		$result['c2pa_signed'] = ! empty( $signed['signed'] );
		$result['c2pa_reason'] = isset( $signed['reason'] ) ? sanitize_text_field( $signed['reason'] ) : '';

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'media_studio_provenance',
				sprintf( 'Media Studio provenance recorded for attachment %d', $attachment_id ),
				array(
					'attachment_id' => $attachment_id,
					'xmp_embedded'  => $result['xmp_embedded'],
					'c2pa_signed'   => $result['c2pa_signed'],
					'c2pa_reason'   => $result['c2pa_reason'],
				)
			);
		}

		return $result;
	}

	/**
	 * Record provenance for a pipeline-derived attachment.
	 *
	 * @param int $attachment_id Derived attachment ID.
	 * @param int $user_id       Acting user ID.
	 * @return array{xmp_embedded:bool,c2pa_signed:bool,c2pa_reason:string}
	 */
	public static function record_derived( $attachment_id, $user_id = 0 ) {
		$attachment_id = absint( $attachment_id );
		$result        = array(
			'xmp_embedded' => false,
			'c2pa_signed'  => false,
			'c2pa_reason'  => '',
		);

		$fields = self::build_derived_fields( $attachment_id, $user_id );
		if ( class_exists( 'NV_oOS_Media_Studio_XMP_Writer' ) && NV_oOS_Media_Studio_XMP_Writer::supports_mime( get_post_mime_type( $attachment_id ) ) ) {
			$embedded               = NV_oOS_Media_Studio_XMP_Writer::embed( $attachment_id, $fields );
			$result['xmp_embedded'] = true === $embedded;
		}

		$signed                = self::maybe_sign_c2pa( $attachment_id );
		$result['c2pa_signed'] = ! empty( $signed['signed'] );
		$result['c2pa_reason'] = isset( $signed['reason'] ) ? sanitize_text_field( $signed['reason'] ) : '';

		return $result;
	}

	/**
	 * Best-effort C2PA signing round-trip against the configured service.
	 *
	 * Never fatal: without a configured (https) endpoint the file simply
	 * carries the IPTC fields above, documented as a known limitation.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{signed:bool,reason:string}
	 */
	public static function maybe_sign_c2pa( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! self::is_c2pa_configured() ) {
			return array(
				'signed' => false,
				'reason' => 'not_configured',
			);
		}

		$settings = array();
		if ( class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			$settings = NV_oOS_Media_Studio_AI_Service::get_settings();
		}
		$url = esc_url_raw( trim( (string) $settings['c2pa_sign_url'] ) );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'attachment_id' => $attachment_id,
						'url'           => wp_get_attachment_url( $attachment_id ),
						'mime_type'     => get_post_mime_type( $attachment_id ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'signed' => false,
				'reason' => 'service_error',
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['signed'] ) ) {
			return array(
				'signed' => false,
				'reason' => 'service_declined',
			);
		}

		update_post_meta( $attachment_id, self::META_C2PA_SIGNED, 1 );
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'media_studio_c2pa',
				sprintf( 'Media Studio C2PA signing completed for attachment %d', $attachment_id ),
				array( 'attachment_id' => $attachment_id )
			);
		}

		return array(
			'signed' => true,
			'reason' => 'signed',
		);
	}

	/**
	 * Compliance block for the SPA capabilities payload.
	 *
	 * @return array
	 */
	public static function get_compliance_block() {
		$settings = array();
		if ( class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			$settings = NV_oOS_Media_Studio_AI_Service::get_settings();
		}

		return array(
			'disclosure'      => isset( $settings['ai_disclosure'] ) ? sanitize_key( $settings['ai_disclosure'] ) : 'metadata',
			'watermark_face'  => ! empty( $settings['watermark_face'] ),
			'xmp_support'     => array(
				'jpeg' => class_exists( 'NV_oOS_Media_Studio_XMP_Writer' ) && NV_oOS_Media_Studio_XMP_Writer::supports_mime( 'image/jpeg' ),
				'png'  => class_exists( 'NV_oOS_Media_Studio_XMP_Writer' ) && NV_oOS_Media_Studio_XMP_Writer::supports_mime( 'image/png' ),
				'webp' => class_exists( 'NV_oOS_Media_Studio_XMP_Writer' ) && NV_oOS_Media_Studio_XMP_Writer::supports_mime( 'image/webp' ),
			),
			'c2pa_configured' => self::is_c2pa_configured(),
		);
	}
}
