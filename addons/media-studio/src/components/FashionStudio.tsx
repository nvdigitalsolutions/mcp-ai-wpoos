/**
 * NV oOS Media Studio — Fashion Studio mode.
 *
 * SPA surface for the AI fashion production pipeline:
 *   - input image from the Media Library (wp.media) or the `src` shortcode attr
 *   - industry transform set (on-model, model-swap, face-swap, background,
 *     recolor, packshot, detail-repair, try-on) driven by `/ai/capabilities`
 *   - cost-review confirm flow + one-time disclosure acknowledgment
 *   - result grid with re-roll and compliance chips (provider, model,
 *     disclosure, watermark, white-background check)
 *
 * Lazy-loaded from App.tsx so image-editor-only pages never pay for this
 * mode's bundle weight.
 *
 * @since 0.2.0
 */

import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
	aiApi,
	type AiApiErrorShape,
	type Capabilities,
	type FashionModel,
	type GenerateResult,
	type Preset,
	type ProcessedPayload,
	type ReviewInfo,
} from '../hooks/useAiApi';
import { FashionBatchPanel } from './FashionBatchPanel';
import '../styles/fashion-studio.css';

interface FashionStudioProps {
	src?: string;
	toolkit?: string;
}

interface SourceImage {
	attachment_id: number;
	url: string;
	title: string;
	ai_generated: boolean;
}

interface WpMediaFrame {
	on: ( event: string, callback: () => void ) => void;
	open: () => void;
	state: () => {
		get: ( key: string ) => { toJSON: () => Array< { id: number; url: string; title: string } > | undefined };
	};
}

interface WpGlobal {
	media?: ( options: { title: string; library: { type: string }; multiple: boolean } ) => WpMediaFrame;
}

const ASPECT_RATIOS = [
	{ value: 'auto', label: __( 'Auto', 'nvoos-media-studio' ) },
	{ value: 'square', label: '1:1' },
	{ value: 'portrait', label: '3:4' },
	{ value: 'landscape', label: '4:3' },
	{ value: 'story', label: '9:16' },
	{ value: 'widescreen', label: '16:9' },
];

const BACKGROUND_STYLES = [
	{ value: 'studio', label: __( 'Studio', 'nvoos-media-studio' ) },
	{ value: 'lifestyle', label: __( 'Lifestyle', 'nvoos-media-studio' ) },
	{ value: 'gradient', label: __( 'Gradient', 'nvoos-media-studio' ) },
	{ value: 'editorial', label: __( 'Editorial', 'nvoos-media-studio' ) },
];

const IDENTITY_TRANSFORMS = [ 'model-swap', 'face-swap', 'try-on' ];

const TRANSFORM_LABELS: Record< string, string > = {
	'on-model': __( 'On-model', 'nvoos-media-studio' ),
	'model-swap': __( 'Model swap', 'nvoos-media-studio' ),
	'face-swap': __( 'Face swap', 'nvoos-media-studio' ),
	background: __( 'Background', 'nvoos-media-studio' ),
	recolor: __( 'Recolor', 'nvoos-media-studio' ),
	packshot: __( 'Packshot', 'nvoos-media-studio' ),
	'detail-repair': __( 'Detail repair', 'nvoos-media-studio' ),
	'try-on': __( 'Try-on', 'nvoos-media-studio' ),
};

function reviewReasonLabel( reason: string ): string {
	switch ( reason ) {
		case 'consent_transform':
			return __( 'Face transforms require a disclosure acknowledgment before running.', 'nvoos-media-studio' );
		case 'unknown_pricing':
			return __( 'Provider pricing is unknown — review required before running.', 'nvoos-media-studio' );
		case 'per_image_ceiling':
			return __( 'Per-image cost exceeds the review ceiling.', 'nvoos-media-studio' );
		case 'per_job_ceiling':
			return __( 'Estimated job cost exceeds the review ceiling.', 'nvoos-media-studio' );
		case 'hard_cap':
			return __( 'Estimated cost exceeds the hard cap — blocked.', 'nvoos-media-studio' );
		default:
			return __( 'This generation requires review before running.', 'nvoos-media-studio' );
	}
}

export function FashionStudio( { src }: FashionStudioProps ) {
	const [ capabilities, setCapabilities ] = useState< Capabilities | null >( null );
	const [ presets, setPresets ] = useState< Preset[] >( [] );
	const [ models, setModels ] = useState< FashionModel[] >( [] );
	const [ loadError, setLoadError ] = useState( '' );

	const [ source, setSource ] = useState< SourceImage | null >( null );
	const [ manualId, setManualId ] = useState( '' );

	const [ transform, setTransform ] = useState( 'on-model' );
	const [ description, setDescription ] = useState( '' );
	const [ color, setColor ] = useState( '#c0392b' );
	const [ backgroundStyle, setBackgroundStyle ] = useState( 'studio' );
	const [ aspectRatio, setAspectRatio ] = useState( 'auto' );
	const [ identityId, setIdentityId ] = useState( 0 );
	const [ count, setCount ] = useState( 1 );
	const [ seed, setSeed ] = useState( '' );

	const [ busy, setBusy ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ results, setResults ] = useState< GenerateResult[] >( [] );
	const [ processedById, setProcessedById ] = useState< Record< number, ProcessedPayload > >( {} );
	const [ profileById, setProfileById ] = useState< Record< number, string > >( {} );

	const [ pendingReview, setPendingReview ] = useState< ReviewInfo | null >( null );
	const [ ackNeeded, setAckNeeded ] = useState( false );
	const [ lastArgs, setLastArgs ] = useState< Parameters< typeof aiApi.generate >[ 0 ] | null >( null );

	const statusRef = useRef< HTMLParagraphElement >( null );

	const announce = useCallback( ( message: string ) => {
		setStatus( message );
		if ( statusRef.current ) {
			statusRef.current.textContent = message;
		}
	}, [] );

	useEffect( () => {
		let cancelled = false;
		Promise.all( [ aiApi.capabilities(), aiApi.presets(), aiApi.models() ] )
			.then( ( [ caps, presetList, modelList ] ) => {
				if ( cancelled ) {
					return;
				}
				setCapabilities( caps );
				setPresets( presetList );
				setModels( modelList );
			} )
			.catch( ( error: Error ) => {
				if ( ! cancelled ) {
					setLoadError( error.message );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	// When a shortcode `src` URL is supplied, surface it as a non-library source.
	useEffect( () => {
		if ( src && ! source ) {
			setSource( {
				attachment_id: 0,
				url: src,
				title: __( 'Shortcode source', 'nvoos-media-studio' ),
				ai_generated: false,
			} );
		}
	}, [ src, source ] );

	const openLibrary = useCallback( () => {
		const wp = ( window as unknown as { wp?: WpGlobal } ).wp;
		if ( ! wp?.media ) {
			announce( __( 'Media Library picker is unavailable in this context.', 'nvoos-media-studio' ) );
			return;
		}
		const frame = wp.media( {
			title: __( 'Choose a source image', 'nvoos-media-studio' ),
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const selection = frame.state().get( 'selection' ).toJSON();
			const attachment = selection?.[ 0 ];
			if ( ! attachment ) {
				return;
			}
			setBusy( true );
			announce( __( 'Loading source image…', 'nvoos-media-studio' ) );
			aiApi
				.importAttachment( attachment.id )
				.then( ( imported ) => {
					setSource( {
						attachment_id: imported.attachment_id,
						url: imported.url,
						title: imported.title,
						ai_generated: imported.ai_generated,
					} );
					announce( __( 'Source image loaded.', 'nvoos-media-studio' ) );
				} )
				.catch( ( error: Error ) => announce( error.message ) )
				.finally( () => setBusy( false ) );
		} );
		frame.open();
	}, [ announce ] );

	const importById = useCallback( () => {
		const id = parseInt( manualId, 10 );
		if ( ! id || id < 1 ) {
			announce( __( 'Enter a valid attachment ID.', 'nvoos-media-studio' ) );
			return;
		}
		setBusy( true );
		announce( __( 'Loading source image…', 'nvoos-media-studio' ) );
		aiApi
			.importAttachment( id )
			.then( ( imported ) => {
				setSource( {
					attachment_id: imported.attachment_id,
					url: imported.url,
					title: imported.title,
					ai_generated: imported.ai_generated,
				} );
				announce( __( 'Source image loaded.', 'nvoos-media-studio' ) );
			} )
			.catch( ( error: Error ) => announce( error.message ) )
			.finally( () => setBusy( false ) );
	}, [ manualId, announce ] );

	const buildArgs = useCallback(
		( overrides: Partial< Parameters< typeof aiApi.generate >[ 0 ] > = {} ) => ( {
			attachment_id: source?.attachment_id ?? 0,
			transform,
			description,
			color,
			background_style: backgroundStyle,
			aspect_ratio: aspectRatio,
			identity_id: identityId > 0 ? identityId : undefined,
			count,
			seed: seed !== '' ? parseInt( seed, 10 ) : undefined,
			...overrides,
		} ),
		[ source, transform, description, color, backgroundStyle, aspectRatio, identityId, count, seed ]
	);

	const runGenerate = useCallback(
		async ( args: Parameters< typeof aiApi.generate >[ 0 ] ) => {
			setBusy( true );
			setPendingReview( null );
			setAckNeeded( false );
			setLastArgs( args );
			announce( __( 'Generating…', 'nvoos-media-studio' ) );
			try {
				const result = await aiApi.generate( args );
				setResults( ( current ) => [ result, ...current ] );
				announce( __( 'Generation complete.', 'nvoos-media-studio' ) );
			} catch ( error ) {
				const apiError = error as AiApiErrorShape;
				if ( apiError.code === 'nvoos_ms_review_required' && apiError.review ) {
					setPendingReview( apiError.review );
					announce( reviewReasonLabel( apiError.review.reason ) );
				} else if ( apiError.code === 'nvoos_ms_ack_required' ) {
					setAckNeeded( true );
					announce( apiError.message );
				} else {
					announce( apiError.message ?? __( 'Generation failed.', 'nvoos-media-studio' ) );
				}
			} finally {
				setBusy( false );
			}
		},
		[ announce ]
	);

	const handleGenerate = useCallback( () => {
		if ( ! source || source.attachment_id < 1 ) {
			announce(
				__(
					'Pick a source image from the Media Library first (shortcode URLs cannot be transformed).',
					'nvoos-media-studio'
				)
			);
			return;
		}
		void runGenerate( buildArgs() );
	}, [ source, buildArgs, runGenerate, announce ] );

	const handleConfirm = useCallback( () => {
		void runGenerate( buildArgs( { confirmed: true } ) );
	}, [ buildArgs, runGenerate ] );

	const handleAck = useCallback( () => {
		void runGenerate( buildArgs( { acknowledged: true } ) );
	}, [ buildArgs, runGenerate ] );

	const handleReroll = useCallback(
		( result: GenerateResult ) => {
			const nextSeed = Math.floor( Math.random() * 2147483647 );
			setSeed( String( nextSeed ) );
			void runGenerate( {
				...( lastArgs ?? buildArgs() ),
				transform: result.transform,
				seed: nextSeed,
			} );
		},
		[ lastArgs, buildArgs, runGenerate ]
	);

	const applyPreset = useCallback( ( preset: Preset ) => {
		setTransform( preset.transform );
		if ( preset.background ) {
			setBackgroundStyle( preset.background );
		}
		announce( __( 'Preset applied.', 'nvoos-media-studio' ) );
	}, [ announce ] );

	const handleProcess = useCallback(
		( result: GenerateResult ) => {
			const profileSlugs = Object.keys( capabilities?.profiles ?? {} );
			if ( profileSlugs.length === 0 ) {
				announce( __( 'No marketplace profiles are available.', 'nvoos-media-studio' ) );
				return;
			}
			const profile = profileById[ result.attachment_id ] ?? profileSlugs[ 0 ];
			setBusy( true );
			announce( __( 'Processing for marketplace…', 'nvoos-media-studio' ) );
			aiApi
				.runPipeline( result.attachment_id, profile )
				.then( ( processed ) => {
					setProcessedById( ( current ) => ( { ...current, [ result.attachment_id ]: processed } ) );
					announce( __( 'Marketplace output ready.', 'nvoos-media-studio' ) );
				} )
				.catch( ( error: Error ) => announce( error.message ) )
				.finally( () => setBusy( false ) );
		},
		[ capabilities, profileById, announce ]
	);

	const defaultProfileSlug = Object.keys( capabilities?.profiles ?? {} )[ 0 ] ?? '';

	if ( loadError ) {
		return <p className="nvoos-ms-error" role="alert">{ loadError }</p>;
	}

	const selectedTransform = capabilities?.transforms[ transform ];
	const consentTransform = Boolean( selectedTransform?.requires_consent );

	return (
		<div className="nvoos-ms-fashion">
			{ /* Source panel */ }
			<section className="nvoos-ms-fs-panel" aria-label={ __( 'Source image', 'nvoos-media-studio' ) }>
				<h2 className="nvoos-ms-fs-heading">{ __( 'Source image', 'nvoos-media-studio' ) }</h2>
				<div className="nvoos-ms-fs-source">
					{ source ? (
						<div className="nvoos-ms-fs-source-info">
							<img className="nvoos-ms-fs-thumb" src={ source.url } alt={ source.title } />
							<div>
								<p className="nvoos-ms-fs-source-title">{ source.title }</p>
								{ source.attachment_id > 0 ? (
									<p className="nvoos-ms-fs-source-meta">
										ID { source.attachment_id }
										{ source.ai_generated
											? ` · ${ __( 'AI-generated', 'nvoos-media-studio' ) }`
											: '' }
									</p>
								) : (
									<p className="nvoos-ms-fs-source-meta">
										{ __( 'Shortcode source — open from the library to transform.', 'nvoos-media-studio' ) }
									</p>
								) }
							</div>
						</div>
					) : (
						<p className="nvoos-ms-fs-hint">
							{ __( 'Pick a source image from the Media Library, or enter an attachment ID.', 'nvoos-media-studio' ) }
						</p>
					) }
					<div className="nvoos-ms-fs-source-actions">
						<button type="button" className="nvoos-ms-toolbar-btn" onClick={ openLibrary } disabled={ busy }>
							{ __( 'Open Media Library', 'nvoos-media-studio' ) }
						</button>
						<label className="nvoos-ms-fs-inline">
							<span className="screen-reader-text">{ __( 'Attachment ID', 'nvoos-media-studio' ) }</span>
							<input
								type="number"
								min={ 1 }
								value={ manualId }
								onChange={ ( event ) => setManualId( event.target.value ) }
								placeholder={ __( 'Attachment ID', 'nvoos-media-studio' ) }
							/>
						</label>
						<button type="button" className="nvoos-ms-toolbar-btn" onClick={ importById } disabled={ busy }>
							{ __( 'Load', 'nvoos-media-studio' ) }
						</button>
					</div>
				</div>
			</section>

			{ /* Presets */ }
			{ presets.length > 0 && (
				<section className="nvoos-ms-fs-panel" aria-label={ __( 'Presets', 'nvoos-media-studio' ) }>
					<h2 className="nvoos-ms-fs-heading">{ __( 'Presets', 'nvoos-media-studio' ) }</h2>
					<div className="nvoos-ms-fs-presets">
						{ presets.map( ( preset ) => (
							<button
								key={ preset.slug }
								type="button"
								className="nvoos-ms-toolbar-btn"
								onClick={ () => applyPreset( preset ) }
							>
								{ preset.label }
							</button>
						) ) }
					</div>
				</section>
			) }

			{ /* Transform panel */ }
			<section className="nvoos-ms-fs-panel" aria-label={ __( 'Transform', 'nvoos-media-studio' ) }>
				<h2 className="nvoos-ms-fs-heading">{ __( 'Transform', 'nvoos-media-studio' ) }</h2>
				<div className="nvoos-ms-fs-transforms" role="group" aria-label={ __( 'Transform options', 'nvoos-media-studio' ) }>
					{ Object.entries( TRANSFORM_LABELS ).map( ( [ slug, label ] ) => {
						const info = capabilities?.transforms[ slug ];
						const disabled = ! info?.available;
						return (
							<button
								key={ slug }
								type="button"
								className={
									'nvoos-ms-fs-transform-btn' +
									( transform === slug ? ' nvoos-ms-fs-transform-btn--active' : '' )
								}
								aria-pressed={ transform === slug }
								disabled={ disabled }
								title={ disabled ? __( 'Backend unavailable', 'nvoos-media-studio' ) : undefined }
								onClick={ () => setTransform( slug ) }
							>
								{ label }
							</button>
						);
					} ) }
				</div>

				<div className="nvoos-ms-fs-options">
					<label className="nvoos-ms-fs-field">
						<span>{ __( 'Guidance', 'nvoos-media-studio' ) }</span>
						<textarea
							rows={ 2 }
							maxLength={ 500 }
							value={ description }
							onChange={ ( event ) => setDescription( event.target.value ) }
							placeholder={ __( 'Optional: style, scene, or garment details…', 'nvoos-media-studio' ) }
						/>
					</label>

					{ transform === 'recolor' && (
						<label className="nvoos-ms-fs-field">
							<span>{ __( 'Target color', 'nvoos-media-studio' ) }</span>
							<input type="color" value={ color } onChange={ ( event ) => setColor( event.target.value ) } />
						</label>
					) }

					{ transform === 'background' && (
						<label className="nvoos-ms-fs-field">
							<span>{ __( 'Background style', 'nvoos-media-studio' ) }</span>
							<select
								value={ backgroundStyle }
								onChange={ ( event ) => setBackgroundStyle( event.target.value ) }
							>
								{ BACKGROUND_STYLES.map( ( option ) => (
									<option key={ option.value } value={ option.value }>
										{ option.label }
									</option>
								) ) }
							</select>
						</label>
					) }

					{ IDENTITY_TRANSFORMS.includes( transform ) && models.length > 0 && (
						<label className="nvoos-ms-fs-field">
							<span>{ __( 'Model identity', 'nvoos-media-studio' ) }</span>
							<select
								value={ identityId }
								onChange={ ( event ) => setIdentityId( parseInt( event.target.value, 10 ) ) }
							>
								<option value={ 0 }>{ __( '— None (prompt guidance only) —', 'nvoos-media-studio' ) }</option>
								{ models.map( ( model ) => (
									<option key={ model.id } value={ model.id }>
										{ model.name }
										{ model.consent_status !== 'granted'
											? ` (${ __( 'consent required', 'nvoos-media-studio' ) })`
											: '' }
									</option>
								) ) }
							</select>
							{ identityId > 0 && (
								<span className="nvoos-ms-fs-hint">
									{ models.find( ( model ) => model.id === identityId )?.consent_status !== 'granted'
										? __(
												'This identity has not granted consent — face transforms will be rejected until an administrator grants it.',
												'nvoos-media-studio'
										  )
										: __( 'Consent granted — usable for face transforms.', 'nvoos-media-studio' ) }
								</span>
							) }
						</label>
					) }

					<label className="nvoos-ms-fs-field">
						<span>{ __( 'Aspect ratio', 'nvoos-media-studio' ) }</span>
						<select value={ aspectRatio } onChange={ ( event ) => setAspectRatio( event.target.value ) }>
							{ ASPECT_RATIOS.map( ( option ) => (
								<option key={ option.value } value={ option.value }>
									{ option.label }
								</option>
							) ) }
						</select>
					</label>

					<label className="nvoos-ms-fs-field">
						<span>{ __( 'Planned variants (cost preview)', 'nvoos-media-studio' ) }</span>
						<select value={ count } onChange={ ( event ) => setCount( parseInt( event.target.value, 10 ) ) }>
							{ [ 1, 2, 3, 4 ].map( ( value ) => (
								<option key={ value } value={ value }>
									{ value }
								</option>
							) ) }
						</select>
						<span className="nvoos-ms-fs-hint">
							{ __( 'Runs one generation per click; the count feeds the batch cost estimate (batch jobs ship in Phase 2).', 'nvoos-media-studio' ) }
						</span>
					</label>

					<label className="nvoos-ms-fs-field">
						<span>{ __( 'Seed (optional)', 'nvoos-media-studio' ) }</span>
						<input
							type="number"
							min={ 0 }
							value={ seed }
							onChange={ ( event ) => setSeed( event.target.value ) }
						/>
					</label>
				</div>

				{ consentTransform && (
					<p className="nvoos-ms-fs-consent-note">
						{ __(
							'Face transforms always require a disclosure acknowledgment and carry a visible AI-generated watermark on output.',
							'nvoos-media-studio'
						) }
					</p>
				) }

				<button
					type="button"
					className="nvoos-ms-toolbar-btn nvoos-ms-toolbar-btn--primary"
					onClick={ handleGenerate }
					disabled={ busy || ! source }
				>
					{ busy ? __( 'Generating…', 'nvoos-media-studio' ) : __( 'Generate', 'nvoos-media-studio' ) }
				</button>

				{ pendingReview && (
					<div className="nvoos-ms-fs-confirm" role="dialog" aria-label={ __( 'Review required', 'nvoos-media-studio' ) }>
						<p>{ reviewReasonLabel( pendingReview.reason ) }</p>
						{ pendingReview.estimate_usd !== null && (
							<p>
								{ __( 'Estimated cost:', 'nvoos-media-studio' ) } ${ ' ' }
								{ pendingReview.estimate_usd.toFixed( 2 ) }
								{ pendingReview.per_image_usd !== null
									? ` (${ pendingReview.per_image_usd.toFixed( 2 ) } ${ __( 'per image', 'nvoos-media-studio' ) })`
									: '' }
							</p>
						) }
						{ pendingReview.blocked ? (
							<p className="nvoos-ms-error" role="alert">
								{ __( 'This job is blocked by the configured hard cap.', 'nvoos-media-studio' ) }
							</p>
						) : (
							<button
								type="button"
								className="nvoos-ms-toolbar-btn nvoos-ms-toolbar-btn--primary"
								onClick={ handleConfirm }
								disabled={ busy }
							>
								{ __( 'Confirm and run', 'nvoos-media-studio' ) }
							</button>
						) }
						<button
							type="button"
							className="nvoos-ms-toolbar-btn"
							onClick={ () => setPendingReview( null ) }
							disabled={ busy }
						>
							{ __( 'Cancel', 'nvoos-media-studio' ) }
						</button>
					</div>
				) }

				{ ackNeeded && (
					<div className="nvoos-ms-fs-confirm" role="dialog" aria-label={ __( 'Disclosure acknowledgment', 'nvoos-media-studio' ) }>
						<p>
							{ __(
								'I understand that AI face transforms produce synthetic content, that outputs carry provenance metadata and a visible AI-generated watermark, and that disclosure obligations (e.g. EU AI Act Art. 50) apply when publishing.',
								'nvoos-media-studio'
							) }
						</p>
						<button
							type="button"
							className="nvoos-ms-toolbar-btn nvoos-ms-toolbar-btn--primary"
							onClick={ handleAck }
							disabled={ busy }
						>
							{ __( 'Acknowledge and run', 'nvoos-media-studio' ) }
						</button>
						<button
							type="button"
							className="nvoos-ms-toolbar-btn"
							onClick={ () => setAckNeeded( false ) }
							disabled={ busy }
						>
							{ __( 'Cancel', 'nvoos-media-studio' ) }
						</button>
					</div>
				) }
			</section>

			{ /* Results */ }
			<section className="nvoos-ms-fs-panel" aria-label={ __( 'Results', 'nvoos-media-studio' ) }>
				<h2 className="nvoos-ms-fs-heading">{ __( 'Results', 'nvoos-media-studio' ) }</h2>
				{ results.length === 0 ? (
					<p className="nvoos-ms-fs-hint">
						{ __( 'Generated variants appear here with compliance details.', 'nvoos-media-studio' ) }
					</p>
				) : (
					<ul className="nvoos-ms-fs-results">
						{ results.map( ( result ) => (
							<li key={ `${ result.attachment_id }-${ result.transform }` } className="nvoos-ms-fs-result">
								<img className="nvoos-ms-fs-result-img" src={ result.url } alt={ result.transform } />
								<div className="nvoos-ms-fs-chip" aria-label={ __( 'Compliance details', 'nvoos-media-studio' ) }>
									<span className="nvoos-ms-fs-chip-item">
										{ __( 'Provider', 'nvoos-media-studio' ) }: { result.provider }
									</span>
									{ result.model && (
										<span className="nvoos-ms-fs-chip-item">
											{ __( 'Model', 'nvoos-media-studio' ) }: { result.model }
										</span>
									) }
									<span className="nvoos-ms-fs-chip-item">
										{ __( 'Disclosure', 'nvoos-media-studio' ) }: { result.disclosure }
									</span>
									{ result.watermarked && (
										<span className="nvoos-ms-fs-chip-item">
											{ __( 'Watermarked', 'nvoos-media-studio' ) }
										</span>
									) }
									{ result.estimate_usd !== null && (
										<span className="nvoos-ms-fs-chip-item">
											${ ' ' }
											{ result.estimate_usd.toFixed( 2 ) }
										</span>
									) }
									{ result.white_background && (
										<span className="nvoos-ms-fs-chip-item">
											{ result.white_background.is_white
												? __( 'White background ✓', 'nvoos-media-studio' )
												: __( 'White background — needs review', 'nvoos-media-studio' ) }
										</span>
									) }
								</div>
								<div className="nvoos-ms-fs-result-actions">
									<button
										type="button"
										className="nvoos-ms-toolbar-btn"
										onClick={ () => handleReroll( result ) }
										disabled={ busy }
									>
										{ __( 'Re-roll', 'nvoos-media-studio' ) }
									</button>
									<a
										className="nvoos-ms-toolbar-btn"
										href={ result.url }
										download
										aria-label={ __( 'Download variant', 'nvoos-media-studio' ) }
									>
										{ __( 'Download', 'nvoos-media-studio' ) }
									</a>
								</div>
								{ Object.keys( capabilities?.profiles ?? {} ).length > 0 && (
									<div className="nvoos-ms-fs-result-actions">
										<label className="nvoos-ms-fs-field">
											<span className="screen-reader-text">
												{ __( 'Marketplace profile', 'nvoos-media-studio' ) }
											</span>
											<select
												value={ profileById[ result.attachment_id ] ?? defaultProfileSlug }
												onChange={ ( event ) =>
													setProfileById( ( current ) => ( {
														...current,
														[ result.attachment_id ]: event.target.value,
													} ) )
												}
											>
												{ Object.entries( capabilities?.profiles ?? {} ).map( ( [ slug, profile ] ) => (
													<option key={ slug } value={ slug }>
														{ profile.label }
													</option>
												) ) }
											</select>
										</label>
										<button
											type="button"
											className="nvoos-ms-toolbar-btn"
											onClick={ () => handleProcess( result ) }
											disabled={ busy }
										>
											{ __( 'Process for marketplace', 'nvoos-media-studio' ) }
										</button>
									</div>
								) }
								{ processedById[ result.attachment_id ] && (
									<div className="nvoos-ms-fs-chip">
										<span className="nvoos-ms-fs-chip-item">
											{ processedById[ result.attachment_id ].profile }
										</span>
										{ processedById[ result.attachment_id ].white_background && (
											<span className="nvoos-ms-fs-chip-item">
												{ processedById[ result.attachment_id ].white_background?.is_white
													? __( 'White background ✓', 'nvoos-media-studio' )
													: __( 'White background — needs review', 'nvoos-media-studio' ) }
											</span>
										) }
										<a
											className="nvoos-ms-fs-chip-item"
											href={ processedById[ result.attachment_id ].url }
											download
										>
											{ __( 'Download output', 'nvoos-media-studio' ) }
										</a>
									</div>
								) }
							</li>
						) ) }
					</ul>
				) }
			</section>

			{ capabilities?.batch && (
				<FashionBatchPanel
					transform={ transform }
					description={ description }
					color={ color }
					backgroundStyle={ backgroundStyle }
					aspectRatio={ aspectRatio }
					identityId={ identityId }
					profiles={ capabilities.profiles }
				/>
			) }

			{ /* Live status for assistive tech */ }
			<p className="nvoos-ms-status" ref={ statusRef } role="status" aria-live="polite">
				{ status }
			</p>
		</div>
	);
}
