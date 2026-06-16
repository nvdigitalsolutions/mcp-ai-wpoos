/**
 * RightPanel — Context-sensitive right panel showing entity details.
 *
 * Currently renders model and profile selectors; will expand to show
 * thread details, tool info, etc. as the selected entity changes.
 */

import { type JSX, useCallback, useMemo } from 'react';
import { __ } from '@wordpress/i18n';

import { useUIStore } from '../../stores/uiStore';
import { useModelStore, type ModelPreference } from '../../stores/modelStore';

export function RightPanel(): JSX.Element {
	const rightPanelOpen = useUIStore( ( s ) => s.rightPanelOpen );
	const toggleRightPanel = useUIStore( ( s ) => s.toggleRightPanel );

	const model = useModelStore( ( s ) => s.model );
	const profile = useModelStore( ( s ) => s.profile );
	const availableModels = useModelStore( ( s ) => s.availableModels );
	const availableProfiles = useModelStore( ( s ) => s.availableProfiles );
	const setModel = useModelStore( ( s ) => s.setModel );
	const setProfile = useModelStore( ( s ) => s.setProfile );

	// ---- derived ----
	const hasAvailableModels = availableModels.length > 0;
	const hasAvailableProfiles = availableProfiles.length > 0;

	// ---- unique key for model select options ----
	const modelOptions = useMemo(
		() =>
			availableModels.map( ( m ) => ( {
				key: `${ m.provider }::${ m.model }`,
				label: `${ m.provider } / ${ m.model }`,
				value: m,
			} ) ),
		[ availableModels ]
	);

	// ---- callbacks ----
	const handleModelChange = useCallback(
		( e: React.ChangeEvent< HTMLSelectElement > ) => {
			const selectedKey = e.target.value;
			const found = modelOptions.find( ( opt ) => opt.key === selectedKey );
			if ( found ) {
				setModel( found.value );
			}
		},
		[ modelOptions, setModel ]
	);

	const handleProfileChange = useCallback(
		( e: React.ChangeEvent< HTMLSelectElement > ) => {
			setProfile( e.target.value );
		},
		[ setProfile ]
	);

	const handleClose = useCallback( () => {
		toggleRightPanel();
	}, [ toggleRightPanel ] );

	const handleKeyDown = useCallback(
		( e: React.KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				toggleRightPanel();
			}
		},
		[ toggleRightPanel ]
	);

	// ---- render ----
	return (
		<aside
			className={ [
				'nvoos-pro-spa-right-panel',
				rightPanelOpen
					? 'nvoos-pro-spa-right-panel--open'
					: 'nvoos-pro-spa-right-panel--closed',
			]
				.filter( Boolean )
				.join( ' ' ) }
			id="nvoos-pro-spa-right-panel"
			role="complementary"
			aria-label={ __( 'Details panel', 'nvoos-pro-spa' ) }
			aria-hidden={ ! rightPanelOpen }
			onKeyDown={ handleKeyDown }
		>
			{ /* ---- header ---- */ }
			<div className="nvoos-pro-spa-right-panel__header">
				<h2 className="nvoos-pro-spa-right-panel__title">
					{ __( 'Details', 'nvoos-pro-spa' ) }
				</h2>
				<button
					type="button"
					className="nvoos-pro-spa-right-panel__close"
					onClick={ handleClose }
					aria-label={ __( 'Close details panel', 'nvoos-pro-spa' ) }
				>
					×
				</button>
			</div>

			{ /* ---- body ---- */ }
			<div className="nvoos-pro-spa-right-panel__body">
				{ /* -- Model selector -- */ }
				<fieldset className="nvoos-pro-spa-right-panel__fieldset">
					<legend className="nvoos-pro-spa-right-panel__legend">
						{ __( 'Model', 'nvoos-pro-spa' ) }
					</legend>

					{ hasAvailableModels ? (
						<select
							className="nvoos-pro-spa-right-panel__select"
							id="nvoos-pro-spa-model-select"
							value={ `${ model.provider }::${ model.model }` }
							onChange={ handleModelChange }
							aria-label={ __(
								'Select AI model',
								'nvoos-pro-spa'
							) }
						>
							{ modelOptions.map( ( opt ) => (
								<option key={ opt.key } value={ opt.key }>
									{ opt.label }
								</option>
							) ) }
						</select>
					) : (
						<p className="nvoos-pro-spa-right-panel__value">
							{ model.provider }
							{ ' / ' }
							{ model.model }
						</p>
					) }
				</fieldset>

				{ /* -- Profile selector -- */ }
				<fieldset className="nvoos-pro-spa-right-panel__fieldset">
					<legend className="nvoos-pro-spa-right-panel__legend">
						{ __( 'Profile', 'nvoos-pro-spa' ) }
					</legend>

					{ hasAvailableProfiles ? (
						<select
							className="nvoos-pro-spa-right-panel__select"
							id="nvoos-pro-spa-profile-select"
							value={ profile }
							onChange={ handleProfileChange }
							aria-label={ __(
								'Select profile',
								'nvoos-pro-spa'
							) }
						>
							{ availableProfiles.map( ( p ) => (
								<option key={ p } value={ p }>
									{ p }
								</option>
							) ) }
						</select>
					) : (
						<p className="nvoos-pro-spa-right-panel__value">
							{ profile }
						</p>
					) }
				</fieldset>

				{ /* -- Placeholder for future context-sensitive content -- */ }
				<div className="nvoos-pro-spa-right-panel__placeholder">
					<p>
						{ __(
							'Select an entity to view details.',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
			</div>
		</aside>
	);
}
