<?php
/**
 * Declarative capability registry for generative-media models.
 *
 * Reads includes/data/media-model-catalog.json once per request and exposes
 * selection and pre-flight validation helpers. This class performs no network
 * I/O, reads no options, and writes nothing: it is safe on every request,
 * including Base-only installs with no provider credentials configured.
 *
 * Scope boundary: each tool remains authoritative for the payload it submits
 * to its provider. This registry is authoritative for (a) which model suits an
 * intent and (b) whether a set of arguments is inside the model's declared
 * limits. It validates only the capability fields it models; tool-specific
 * arguments it does not recognise are passed through untouched so the tool can
 * validate them itself.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and queries the generative-media model capability registry.
 */
class WP_MCP_AI_Media_Model_Catalog {

	/**
	 * Path to the registry, relative to the plugin root.
	 */
	const DATA_RELATIVE_PATH = 'includes/data/media-model-catalog.json';

	/**
	 * Recognised model kinds.
	 */
	const KINDS = array( 'image', 'video', 'audio' );

	/**
	 * Recognised audio-surface policies.
	 */
	const AUDIO_POLICIES = array( 'none', 'optional', 'fixed' );

	/**
	 * Recognised price/quality bands, cheapest last.
	 */
	const TIERS = array( 'premium', 'standard', 'budget' );

	/**
	 * Canonical capability fields this registry validates.
	 *
	 * Anything outside this list is forwarded untouched (the owning tool
	 * validates its own arguments).
	 */
	const CAPABILITY_FIELDS = array(
		'aspect_ratio',
		'resolution',
		'duration',
		'batch_size',
		'seed',
		'reference_images',
		'reference_videos',
		'reference_audio',
	);

	/**
	 * Decoded registry, keyed by model id. Null until first read.
	 *
	 * @var array{version:string,updated_at:string,models:array<string,array>}|null
	 */
	protected static $catalog = null;

	/**
	 * Drop the in-request cache. Intended for tests and for filter-driven rebuilds.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$catalog = null;
	}

	/**
	 * Absolute path to the registry file.
	 *
	 * @return string
	 */
	public static function get_data_path() {
		/**
		 * Filters the generative-media model registry path.
		 *
		 * @param string $path Absolute path to the JSON registry.
		 */
		return (string) apply_filters( 'wp_mcp_ai_media_model_catalog_path', WP_MCP_AI_PATH . self::DATA_RELATIVE_PATH );
	}

	/**
	 * Load and index the registry.
	 *
	 * @return array{version:string,updated_at:string,models:array<string,array>}
	 */
	public static function get_catalog() {
		if ( null !== self::$catalog ) {
			return self::$catalog;
		}

		$empty = array(
			'version'    => '',
			'updated_at' => '',
			'models'     => array(),
		);

		$path = self::get_data_path();
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			self::$catalog = $empty;
			return self::$catalog;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading bundled JSON registry.
		if ( false === $raw ) {
			self::$catalog = $empty;
			return self::$catalog;
		}

		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['models'] ) || ! is_array( $decoded['models'] ) ) {
			self::$catalog = $empty;
			return self::$catalog;
		}

		$indexed = array();
		foreach ( $decoded['models'] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}
			$indexed[ (string) $entry['id'] ] = $entry;
		}

		self::$catalog = array(
			'version'    => isset( $decoded['version'] ) ? (string) $decoded['version'] : '',
			'updated_at' => isset( $decoded['updated_at'] ) ? (string) $decoded['updated_at'] : '',
			'models'     => $indexed,
		);

		return self::$catalog;
	}

	/**
	 * Whether the registry loaded with at least one model.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$catalog = self::get_catalog();
		return ! empty( $catalog['models'] );
	}

	/**
	 * Query models.
	 *
	 * Supported query keys: kind, provider, tier, style, tool, mode.
	 * Unknown keys are ignored so callers can pass a normalised intent.
	 *
	 * @param array $query Filter criteria.
	 * @return array<string,array> Model records keyed by id.
	 */
	public static function get_models( $query = array() ) {
		$catalog = self::get_catalog();
		$models  = $catalog['models'];

		if ( ! is_array( $query ) ) {
			$query = array();
		}

		$kind     = isset( $query['kind'] ) ? sanitize_key( (string) $query['kind'] ) : '';
		$provider = isset( $query['provider'] ) ? sanitize_key( (string) $query['provider'] ) : '';
		$tier     = isset( $query['tier'] ) ? sanitize_key( (string) $query['tier'] ) : '';
		$style    = isset( $query['style'] ) ? sanitize_key( (string) $query['style'] ) : '';
		$tool     = isset( $query['tool'] ) ? sanitize_key( (string) $query['tool'] ) : '';
		$mode     = isset( $query['mode'] ) ? sanitize_key( (string) $query['mode'] ) : '';

		if ( '' !== $kind && in_array( $kind, self::KINDS, true ) ) {
			$models = array_filter(
				$models,
				static function ( $model ) use ( $kind ) {
					return isset( $model['kind'] ) && $model['kind'] === $kind;
				}
			);
		}

		if ( '' !== $provider && 'any' !== $provider ) {
			$models = array_filter(
				$models,
				static function ( $model ) use ( $provider ) {
					return isset( $model['provider'] ) && $model['provider'] === $provider;
				}
			);
		}

		if ( '' !== $tier && 'any' !== $tier && in_array( $tier, self::TIERS, true ) ) {
			$models = array_filter(
				$models,
				static function ( $model ) use ( $tier ) {
					return isset( $model['tier'] ) && $model['tier'] === $tier;
				}
			);
		}

		if ( '' !== $style && 'any' !== $style ) {
			$models = array_filter(
				$models,
				static function ( $model ) use ( $style ) {
					return isset( $model['styles'] ) && is_array( $model['styles'] ) && in_array( $style, $model['styles'], true );
				}
			);
		}

		if ( '' !== $tool ) {
			$models = array_filter(
				$models,
				static function ( $model ) use ( $tool ) {
					if ( isset( $model['tool'] ) && $model['tool'] === $tool ) {
						return true;
					}

					// A model may be selectable through more than one tool (the `omni`
					// model is reachable from both generate_omni_video and
					// generate_veo_video). `tool` stays the owner; `tools` lists every
					// entry point.
					return isset( $model['tools'] ) && is_array( $model['tools'] ) && in_array( $tool, $model['tools'], true );
				}
			);
		}

		if ( '' !== $mode ) {
			$models = array_filter(
				$models,
				static function ( $model ) use ( $mode ) {
					return isset( $model['modes'] ) && is_array( $model['modes'] ) && in_array( $mode, $model['modes'], true );
				}
			);
		}

		return $models;
	}

	/**
	 * Fetch one model record.
	 *
	 * @param string $id Model id.
	 * @return array|null
	 */
	public static function get_model( $id ) {
		$id      = self::normalize_id( $id );
		$catalog = self::get_catalog();

		return isset( $catalog['models'][ $id ] ) ? $catalog['models'][ $id ] : null;
	}

	/**
	 * Normalise a model id for lookup.
	 *
	 * Model ids legitimately contain dots (`cinema-studio-4.0`) and must not be
	 * passed through sanitize_key(), which strips them and turns a valid id into
	 * a miss. This mirrors sanitize_key()'s character class plus dot.
	 *
	 * @param string $id Raw id.
	 * @return string
	 */
	public static function normalize_id( $id ) {
		$id = strtolower( (string) $id );

		return (string) preg_replace( '/[^a-z0-9._-]/', '', $id );
	}

	/**
	 * Whether a model id is registered.
	 *
	 * @param string $id Model id.
	 * @return bool
	 */
	public static function has_model( $id ) {
		return null !== self::get_model( $id );
	}

	/**
	 * Read an enumerated capability field.
	 *
	 * An empty array means the model declares no such parameter (validation
	 * drops the argument); it does not mean "unrestricted".
	 *
	 * @param string $id    Model id.
	 * @param string $field One of aspect_ratios, resolutions, batch_sizes, styles, modes.
	 * @return array
	 */
	public static function get_enum( $id, $field ) {
		$model = self::get_model( $id );
		$field = sanitize_key( (string) $field );

		if ( null === $model || ! isset( $model[ $field ] ) || ! is_array( $model[ $field ] ) ) {
			return array();
		}

		return $model[ $field ];
	}

	/**
	 * Declared defaults for a model.
	 *
	 * @param string $id Model id.
	 * @return array
	 */
	public static function get_defaults( $id ) {
		$model = self::get_model( $id );
		if ( null === $model || ! isset( $model['defaults'] ) || ! is_array( $model['defaults'] ) ) {
			return array();
		}

		return $model['defaults'];
	}

	/**
	 * Declared duration range for a model.
	 *
	 * @param string $id Model id.
	 * @return array{min:int,max:int,default:int,type:string}|null
	 */
	public static function get_duration_range( $id ) {
		$model = self::get_model( $id );
		if ( null === $model || ! isset( $model['duration'] ) || ! is_array( $model['duration'] ) ) {
			return null;
		}

		$duration = $model['duration'];

		return array(
			'min'     => isset( $duration['min'] ) ? (int) $duration['min'] : 0,
			'max'     => isset( $duration['max'] ) ? (int) $duration['max'] : 0,
			'default' => isset( $duration['default'] ) ? (int) $duration['default'] : 0,
			'type'    => isset( $duration['type'] ) ? sanitize_key( (string) $duration['type'] ) : 'number',
		);
	}

	/**
	 * Maximum reference images a model accepts. 0 means it accepts none.
	 *
	 * @param string $id Model id.
	 * @return int
	 */
	public static function max_reference_images( $id ) {
		$model = self::get_model( $id );
		if ( null === $model || ! isset( $model['max_reference_images'] ) ) {
			return 0;
		}

		return max( 0, (int) $model['max_reference_images'] );
	}

	/**
	 * Normalise a recommendation intent into a known shape.
	 *
	 * @param array $intent Caller-supplied intent.
	 * @return array{kind:string,style:string,reference_images:int,duration:int,audio:string,tier:string,provider:string}
	 */
	public static function normalize_intent( $intent ) {
		$intent = is_array( $intent ) ? $intent : array();

		$kind = isset( $intent['kind'] ) ? sanitize_key( (string) $intent['kind'] ) : '';
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$kind = 'video';
		}

		$style = isset( $intent['style'] ) ? sanitize_key( (string) $intent['style'] ) : '';
		if ( '' === $style ) {
			$style = 'any';
		}

		$audio = isset( $intent['audio'] ) ? sanitize_key( (string) $intent['audio'] ) : 'any';
		if ( ! in_array( $audio, array( 'any', 'required', 'none' ), true ) ) {
			$audio = 'any';
		}

		$tier = isset( $intent['tier'] ) ? sanitize_key( (string) $intent['tier'] ) : 'any';
		if ( 'any' !== $tier && ! in_array( $tier, self::TIERS, true ) ) {
			$tier = 'any';
		}

		$provider = isset( $intent['provider'] ) ? sanitize_key( (string) $intent['provider'] ) : 'any';
		if ( '' === $provider ) {
			$provider = 'any';
		}

		return array(
			'kind'             => $kind,
			'style'            => $style,
			'reference_images' => isset( $intent['reference_images'] ) ? max( 0, (int) $intent['reference_images'] ) : 0,
			'duration'         => isset( $intent['duration'] ) ? max( 0, (int) $intent['duration'] ) : 0,
			'audio'            => $audio,
			'tier'             => $tier,
			'provider'         => $provider,
		);
	}

	/**
	 * Rank models against an intent.
	 *
	 * Hard exclusions (never merely down-ranked):
	 *  - kind / provider / tier mismatch when explicitly requested;
	 *  - a specific style the model does not serve;
	 *  - a budget-band model when a specific style was requested (so a
	 *    "realistic" brief never silently lands on a draft model);
	 *  - more reference images than the model accepts;
	 *  - an audio requirement the model cannot satisfy;
	 *  - a duration outside the model's declared range.
	 *
	 * Every result carries reasons[] so the caller can explain the choice.
	 *
	 * @param array $intent Recommendation intent.
	 * @return array<int,array{id:string,model:array,score:int,reasons:string[],warnings:string[]}>
	 */
	public static function recommend( $intent ) {
		$intent = self::normalize_intent( $intent );
		$models = self::get_models( array( 'kind' => $intent['kind'] ) );

		$tier_penalty = array(
			'premium'  => 0,
			'standard' => 6,
			'budget'   => 18,
		);

		$tier_rank = array(
			'premium'  => 0,
			'standard' => 1,
			'budget'   => 2,
		);

		$results = array();

		foreach ( $models as $id => $model ) {
			$reasons  = array();
			$warnings = array();
			$score    = 100;

			if ( 'any' !== $intent['provider'] && isset( $model['provider'] ) && $model['provider'] !== $intent['provider'] ) {
				continue;
			}

			if ( 'any' !== $intent['tier'] && isset( $model['tier'] ) && $model['tier'] !== $intent['tier'] ) {
				continue;
			}

			$styles = isset( $model['styles'] ) && is_array( $model['styles'] ) ? $model['styles'] : array();
			$tier   = isset( $model['tier'] ) ? (string) $model['tier'] : 'standard';

			if ( 'any' !== $intent['style'] ) {
				if ( ! in_array( $intent['style'], $styles, true ) ) {
					continue;
				}
				$reasons[] = sprintf(
					/* translators: 1: style slug, 2: model label */
					__( 'Matches the requested %1$s look (%2$s).', 'mcp-ai-wpoos' ),
					$intent['style'],
					isset( $model['label'] ) ? $model['label'] : $id
				);
				$score += 15;

				if ( 'budget' === $tier ) {
					continue;
				}
			}

			$max_refs = isset( $model['max_reference_images'] ) ? (int) $model['max_reference_images'] : 0;
			if ( $intent['reference_images'] > $max_refs ) {
				continue;
			}
			if ( $intent['reference_images'] > 0 ) {
				$score    += 10;
				$reasons[] = sprintf(
					/* translators: 1: number of reference images, 2: maximum the model accepts */
					__( 'Accepts %1$d reference images (model limit %2$d).', 'mcp-ai-wpoos' ),
					$intent['reference_images'],
					$max_refs
				);
			} elseif ( $max_refs > 0 ) {
				$reasons[] = sprintf(
					/* translators: %d: maximum reference images */
					__( 'Can hold up to %d reference images if consistency matters.', 'mcp-ai-wpoos' ),
					$max_refs
				);
			}

			$audio_policy = isset( $model['audio'] ) ? (string) $model['audio'] : 'none';
			if ( 'required' === $intent['audio'] && 'optional' !== $audio_policy ) {
				continue;
			}
			if ( 'required' === $intent['audio'] ) {
				$score    += 5;
				$reasons[] = __( 'Exposes an audio control that can be requested.', 'mcp-ai-wpoos' );
			}

			$duration = self::get_duration_range( $id );
			if ( $intent['duration'] > 0 ) {
				if ( null === $duration ) {
					continue;
				}
				if ( $intent['duration'] < $duration['min'] || $intent['duration'] > $duration['max'] ) {
					continue;
				}
				$reasons[] = sprintf(
					/* translators: 1: requested seconds, 2: minimum, 3: maximum */
					__( 'Supports the requested %1$d s duration (range %2$d-%3$d s).', 'mcp-ai-wpoos' ),
					$intent['duration'],
					$duration['min'],
					$duration['max']
				);
			}

			if ( isset( $duration['max'] ) && $duration['max'] <= 8 ) {
				$warnings[] = __( 'Short duration ceiling; long shots are not possible.', 'mcp-ai-wpoos' );
			}

			$score -= isset( $tier_penalty[ $tier ] ) ? $tier_penalty[ $tier ] : 6;

			$reasons[] = sprintf(
				/* translators: %s: tier slug */
				__( 'Price/quality band: %s.', 'mcp-ai-wpoos' ),
				$tier
			);

			$results[] = array(
				'id'       => (string) $id,
				'model'    => $model,
				'score'    => (int) $score,
				'reasons'  => $reasons,
				'warnings' => $warnings,
				'rank'     => isset( $tier_rank[ $tier ] ) ? $tier_rank[ $tier ] : 1,
			);
		}

		usort(
			$results,
			static function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					if ( $a['rank'] === $b['rank'] ) {
						return strcmp( $a['id'], $b['id'] );
					}
					return $a['rank'] < $b['rank'] ? -1 : 1;
				}
				return $a['score'] > $b['score'] ? -1 : 1;
			}
		);

		foreach ( $results as $index => $result ) {
			unset( $results[ $index ]['rank'] );
		}

		return array_values( $results );
	}

	/**
	 * Validate arguments against a model's declared capability limits.
	 *
	 * Unknown keys are forwarded untouched: the owning tool validates its own
	 * arguments. Only the capability fields in CAPABILITY_FIELDS are inspected.
	 *
	 * @param string $id   Model id.
	 * @param array  $args Arguments to check.
	 * @return array{model:string,kind:string,provider:string,tool:string,params:array,dropped:array<int,array>,errors:array<int,array>,warnings:string[]}|\WP_Error
	 */
	public static function validate( $id, $args ) {
		$model = self::get_model( $id );

		if ( null === $model ) {
			return new WP_Error(
				'wp_mcp_ai_media_model_unknown',
				sprintf(
					/* translators: %s: model id */
					__( 'Unknown media model "%s". Use media_model_capabilities with action "list" to see the registered ids.', 'mcp-ai-wpoos' ),
					sanitize_text_field( (string) $id )
				),
				array( 'status' => 400 )
			);
		}

		$args = is_array( $args ) ? $args : array();

		$params   = $args;
		$dropped  = array();
		$errors   = array();
		$warnings = array();

		// aspect_ratio — closed enum, or dropped when the model exposes none.
		if ( array_key_exists( 'aspect_ratio', $params ) ) {
			$allowed = isset( $model['aspect_ratios'] ) && is_array( $model['aspect_ratios'] ) ? $model['aspect_ratios'] : array();
			$value   = (string) $params['aspect_ratio'];

			if ( empty( $allowed ) ) {
				unset( $params['aspect_ratio'] );
				$dropped[] = self::drop_record( 'aspect_ratio', $value, __( 'This model exposes no aspect_ratio parameter.', 'mcp-ai-wpoos' ) );
			} elseif ( ! in_array( $value, $allowed, true ) ) {
				$replacement = self::default_for( $model, 'aspect_ratio', $allowed[0] );
				unset( $params['aspect_ratio'] );
				$dropped[] = self::drop_record(
					'aspect_ratio',
					$value,
					sprintf(
						/* translators: %s: replacement value */
						__( 'Not supported by this model; the default "%s" applies.', 'mcp-ai-wpoos' ),
						$replacement
					)
				);
			}
		}

		// resolution — closed enum, or dropped when none is exposed.
		if ( array_key_exists( 'resolution', $params ) ) {
			$allowed = isset( $model['resolutions'] ) && is_array( $model['resolutions'] ) ? $model['resolutions'] : array();
			$value   = (string) $params['resolution'];

			if ( empty( $allowed ) ) {
				unset( $params['resolution'] );
				$dropped[] = self::drop_record( 'resolution', $value, __( 'This model exposes no resolution parameter.', 'mcp-ai-wpoos' ) );
			} elseif ( ! in_array( $value, $allowed, true ) ) {
				$replacement = self::default_for( $model, 'resolution', $allowed[0] );
				$params['resolution'] = $replacement;
				$warnings[] = sprintf(
					/* translators: 1: requested value, 2: replacement value */
					__( 'Resolution "%1$s" is not supported by this model; "%2$s" was substituted.', 'mcp-ai-wpoos' ),
					$value,
					$replacement
				);
			}
		}

		// duration — numeric clamp against the declared range.
		if ( array_key_exists( 'duration', $params ) ) {
			$range     = self::get_duration_range( $id );
			$raw_value = $params['duration'];

			if ( null === $range ) {
				unset( $params['duration'] );
				$dropped[] = self::drop_record( 'duration', $raw_value, __( 'This model exposes no duration parameter.', 'mcp-ai-wpoos' ) );
			} elseif ( ! is_numeric( $raw_value ) ) {
				unset( $params['duration'] );
				$errors[] = self::drop_record( 'duration', $raw_value, __( 'Duration must be numeric.', 'mcp-ai-wpoos' ) );
			} else {
				$requested = (int) $raw_value;
				$clamped   = max( $range['min'], min( $range['max'], $requested ) );

				if ( $clamped !== $requested ) {
					$params['duration'] = $clamped;
					$warnings[]         = sprintf(
						/* translators: 1: requested seconds, 2: clamped seconds, 3: minimum, 4: maximum */
						__( 'Duration %1$d s is outside this model\'s %3$d-%4$d s range; %2$d s was substituted.', 'mcp-ai-wpoos' ),
						$requested,
						$clamped,
						$range['min'],
						$range['max']
					);
				}
			}
		}

		// batch_size — closed enum, or dropped.
		if ( array_key_exists( 'batch_size', $params ) ) {
			$allowed   = isset( $model['batch_sizes'] ) && is_array( $model['batch_sizes'] ) ? $model['batch_sizes'] : array();
			$raw_value = $params['batch_size'];

			if ( empty( $allowed ) ) {
				unset( $params['batch_size'] );
				$dropped[] = self::drop_record( 'batch_size', $raw_value, __( 'This model exposes no batch_size parameter.', 'mcp-ai-wpoos' ) );
			} elseif ( ! in_array( (int) $raw_value, array_map( 'intval', $allowed ), true ) ) {
				unset( $params['batch_size'] );
				$dropped[] = self::drop_record(
					'batch_size',
					$raw_value,
					sprintf(
						/* translators: %s: comma-separated allowed values */
						__( 'Not an allowed batch size; permitted values are %s.', 'mcp-ai-wpoos' ),
						implode( ', ', $allowed )
					)
				);
			}
		}

		// seed — presence check only.
		if ( array_key_exists( 'seed', $params ) ) {
			$supports_seed = ! empty( $model['supports_seed'] );
			$raw_value     = $params['seed'];

			if ( ! $supports_seed ) {
				unset( $params['seed'] );
				$dropped[] = self::drop_record( 'seed', $raw_value, __( 'This model does not accept a seed.', 'mcp-ai-wpoos' ) );
			} elseif ( ! is_numeric( $raw_value ) ) {
				unset( $params['seed'] );
				$errors[] = self::drop_record( 'seed', $raw_value, __( 'Seed must be numeric.', 'mcp-ai-wpoos' ) );
			} else {
				$params['seed'] = max( 0, (int) $raw_value );
			}
		}

		// Reference media counts — truncate rather than fail, but say so.
		$reference_limits = array(
			'reference_images' => isset( $model['max_reference_images'] ) ? (int) $model['max_reference_images'] : 0,
			'reference_videos' => isset( $model['max_reference_videos'] ) ? (int) $model['max_reference_videos'] : 0,
			'reference_audio'  => isset( $model['max_reference_audio'] ) ? (int) $model['max_reference_audio'] : 0,
		);

		foreach ( $reference_limits as $field => $limit ) {
			if ( ! array_key_exists( $field, $params ) || ! is_array( $params[ $field ] ) ) {
				continue;
			}

			$count = count( $params[ $field ] );

			if ( 0 === $limit ) {
				unset( $params[ $field ] );
				$dropped[] = self::drop_record( $field, $count, __( 'This model accepts no reference media.', 'mcp-ai-wpoos' ) );
			} elseif ( $count > $limit ) {
				$params[ $field ] = array_slice( array_values( $params[ $field ] ), 0, $limit );
				$warnings[]       = sprintf(
					/* translators: 1: submitted count, 2: model limit, 3: field name */
					__( '%1$d %3$s were submitted but this model accepts %2$d; the first %2$d were kept.', 'mcp-ai-wpoos' ),
					$count,
					$limit,
					$field
				);
			}
		}

		return array(
			'model'    => (string) $id,
			'kind'     => isset( $model['kind'] ) ? (string) $model['kind'] : '',
			'provider' => isset( $model['provider'] ) ? (string) $model['provider'] : '',
			'tool'     => isset( $model['tool'] ) ? (string) $model['tool'] : '',
			'params'   => $params,
			'dropped'  => $dropped,
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Build a provider-ready payload from validated arguments.
	 *
	 * Applies, in order: validation (clamp/drop), fixed-parameter injection,
	 * and per-model duration wire-type coercion.
	 *
	 * @param string $id   Model id.
	 * @param array  $args Arguments to normalise.
	 * @return array|\WP_Error
	 */
	public static function build_payload( $id, $args ) {
		$validated = self::validate( $id, $args );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$model   = self::get_model( $id );
		$payload = $validated['params'];

		if ( isset( $model['fixed'] ) && is_array( $model['fixed'] ) ) {
			foreach ( $model['fixed'] as $key => $value ) {
				$payload[ $key ] = $value;
			}
		}

		$range = self::get_duration_range( $id );
		if ( null !== $range && array_key_exists( 'duration', $payload ) ) {
			$payload['duration'] = 'string' === $range['type']
				? (string) (int) $payload['duration']
				: (int) $payload['duration'];
		}

		$validated['payload'] = $payload;

		return $validated;
	}

	/**
	 * Build the canonical drop/error record shape.
	 *
	 * @param string $field  Field name.
	 * @param mixed  $value  Rejected value.
	 * @param string $reason Human-readable reason.
	 * @return array{field:string,value:mixed,reason:string}
	 */
	protected static function drop_record( $field, $value, $reason ) {
		return array(
			'field'  => (string) $field,
			'value'  => is_scalar( $value ) ? $value : wp_json_encode( $value ),
			'reason' => (string) $reason,
		);
	}

	/**
	 * Resolve a declared default for a field, falling back to a supplied value.
	 *
	 * @param array  $model    Model record.
	 * @param string $field    Field name.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	protected static function default_for( $model, $field, $fallback ) {
		if ( isset( $model['defaults'] ) && is_array( $model['defaults'] ) && isset( $model['defaults'][ $field ] ) ) {
			return (string) $model['defaults'][ $field ];
		}

		return (string) $fallback;
	}
}
