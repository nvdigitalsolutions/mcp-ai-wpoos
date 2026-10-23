/**
 * PortabilityPage — assistant export/import (NV oOS bundle format).
 *
 * Export: pick assistants (or all), download the JSON bundle.
 * Import: paste a bundle or upload one, choose the conflict mode, dry-run,
 * and read the import report. Replaces the wp-admin Assistant Portability
 * page in the console (Proposal 084 Phase 1); the wp-admin page stays behind
 * the wp_mcp_ai_admin_page_enabled_portability filter until parity is
 * verified.
 */

import { useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { usePortability } from '../../hooks/usePortability';
import { useBootstrap } from '../../hooks/useBootstrap';
import { useAssistantStore } from '../../stores/assistantStore';

export function PortabilityPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { exporting, importing, error, importReport, exportAssistants, importAssistants } =
		usePortability(
			runtime
				? { endpoint: runtime.endpoints.portability ?? '', nonce: runtime.nonce }
				: { endpoint: '', nonce: '' }
		);
	const assistants = useAssistantStore( ( s ) => s.assistants );

	const [ selected, setSelected ] = useState< Set< number > >( new Set() );
	const [ includeA2a, setIncludeA2a ] = useState< boolean >( true );
	const [ importJson, setImportJson ] = useState< string >( '' );
	const [ mode, setMode ] = useState< 'skip' | 'overwrite' | 'duplicate' >( 'skip' );
	const [ dryRun, setDryRun ] = useState< boolean >( true );
	const [ attachmentId, setAttachmentId ] = useState< number >( 0 );

	const toggleSelected = ( id: number ): void => {
		setSelected( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( id ) ) {
				next.delete( id );
			} else {
				next.add( id );
			}
			return next;
		} );
	};

	const handleExport = async (): Promise< void> => {
		const bundle = await exportAssistants(
			selected.size > 0 ? Array.from( selected ) : undefined,
			includeA2a
		);
		if ( ! bundle ) {
			return;
		}
		const blob = new Blob( [ JSON.stringify( bundle, null, 2 ) ], {
			type: 'application/json',
		} );
		const url = URL.createObjectURL( blob );
		const link = document.createElement( 'a' );
		link.href = url;
		link.download = 'nvoos-assistants.json';
		link.click();
		URL.revokeObjectURL( url );
	};

	const handleImportFile = async ( file: File ): Promise< void > => {
		if ( ! runtime ) {
			return;
		}
		const { ToolsClient } = await import( '../../api/tools' );
		try {
			const media = await ToolsClient.uploadMedia(
				runtime.endpoints.upload,
				runtime.nonce,
				file,
				file.name || 'nvoos-assistants.json'
			);
			setAttachmentId( media.id );
		} catch {
			// Upload failed — surface the server's payload error through the
			// import path (empty payload is rejected with a clear message).
			setAttachmentId( 0 );
			await importAssistants( { json: '', mode, dry_run: dryRun } );
		}
	};

	if ( bootstrapLoading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Assistant Portability', 'nvoos-pro-spa' ) }
				</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Assistant Portability', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Assistant Portability', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __(
						'Export assistants as portable bundles and import them back.',
						'nvoos-pro-spa'
					) }
				</p>
			</header>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
				</div>
			) }

			<section className="nvoos-pro-spa-tools-page__controls" aria-label={ __( 'Export assistants', 'nvoos-pro-spa' ) }>
				<h3 className="nvoos-pro-spa-tools-page__label">{ __( 'Export', 'nvoos-pro-spa' ) }</h3>
				<p>
					{ selected.size === 0
						? __( 'No assistants selected — exporting all.', 'nvoos-pro-spa' )
						: `${ selected.size } ${ __( 'assistants selected', 'nvoos-pro-spa' ) }` }
				</p>
				<ul
					className="nvoos-pro-spa-tools-list nvoos-pro-spa-tools-list--list"
					aria-label={ __( 'Assistants to export', 'nvoos-pro-spa' ) }
				>
					{ assistants.map( ( assistant ) => (
						<li key={ assistant.id } className="nvoos-pro-spa-tools-list__row">
							<label>
								<input
									type="checkbox"
									checked={ selected.has( assistant.id ) }
									onChange={ () => toggleSelected( assistant.id ) }
								/>{ ' ' }
								<strong>{ assistant.title }</strong>
								<code className="nvoos-pro-spa-tools-list__slug">#{ assistant.id }</code>
							</label>
						</li>
					) ) }
				</ul>
				<label>
					<input
						type="checkbox"
						checked={ includeA2a }
						onChange={ ( e ) => setIncludeA2a( e.target.checked ) }
					/>{ ' ' }
					{ __( 'Embed an A2A agent card per assistant', 'nvoos-pro-spa' ) }
				</label>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--primary"
					disabled={ exporting }
					onClick={ () => void handleExport() }
				>
					{ exporting ? __( 'Exporting…', 'nvoos-pro-spa' ) : __( 'Download bundle', 'nvoos-pro-spa' ) }
				</button>
			</section>

			<section className="nvoos-pro-spa-tools-page__controls" aria-label={ __( 'Import assistants', 'nvoos-pro-spa' ) }>
				<h3 className="nvoos-pro-spa-tools-page__label">{ __( 'Import', 'nvoos-pro-spa' ) }</h3>
				<textarea
					className="nvoos-pro-spa-tools-page__input"
					rows={ 6 }
					value={ importJson }
					onChange={ ( e ) => setImportJson( e.target.value ) }
					placeholder={ __( 'Paste an NV oOS assistant bundle JSON…', 'nvoos-pro-spa' ) }
					aria-label={ __( 'Import bundle JSON', 'nvoos-pro-spa' ) }
				/>
				<input
					type="file"
					accept="application/json,.json"
					onChange={ ( e ) => {
						const file = e.target.files?.[ 0 ];
						if ( file ) {
							void handleImportFile( file );
						}
					} }
				/>
				<label htmlFor="nvoos-pro-spa-portability-mode">
					{ __( 'Conflict mode', 'nvoos-pro-spa' ) }
				</label>
				<select
					id="nvoos-pro-spa-portability-mode"
					className="nvoos-pro-spa-tools-page__select"
					value={ mode }
					onChange={ ( e ) => setMode( e.target.value as 'skip' | 'overwrite' | 'duplicate' ) }
				>
					<option value="skip">{ __( 'Skip existing', 'nvoos-pro-spa' ) }</option>
					<option value="overwrite">{ __( 'Overwrite existing', 'nvoos-pro-spa' ) }</option>
					<option value="duplicate">{ __( 'Duplicate', 'nvoos-pro-spa' ) }</option>
				</select>
				<label>
					<input
						type="checkbox"
						checked={ dryRun }
						onChange={ ( e ) => setDryRun( e.target.checked ) }
					/>{ ' ' }
					{ __( 'Dry run (validate without writing)', 'nvoos-pro-spa' ) }
				</label>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--primary"
					disabled={ importing || ( ! importJson.trim() && attachmentId === 0 ) }
					onClick={ () =>
						void importAssistants( {
							json: importJson.trim() || undefined,
							attachment_id: attachmentId > 0 ? attachmentId : undefined,
							mode,
							dry_run: dryRun,
						} )
					}
				>
					{ importing ? __( 'Importing…', 'nvoos-pro-spa' ) : __( 'Import', 'nvoos-pro-spa' ) }
				</button>
				{ importReport && (
					<pre className="nvoos-pro-spa-slash__result" role="status">
						{ JSON.stringify( importReport, null, 2 ) }
					</pre>
				) }
			</section>
		</div>
	);
}
