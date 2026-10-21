/**
 * WorkflowsPage — workflow list + visual builder (Proposal 084 Phase 3b).
 *
 * W10.2a/b: full workflow CRUD over the existing base REST
 * (mcp-ai/v1/orchestration/workflows) with the ReactFlow canvas.
 * W10.2c: the Presets tab loads SA workflow presets through the
 * mcp-ai-pro/v1/workflow-presets bridge into the canvas as a draft graph
 * (saved back through the standard PUT).
 */

import { useEffect, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useWorkflowBuilder } from '../../hooks/useWorkflowBuilder';
import { useBootstrap } from '../../hooks/useBootstrap';
import { WorkflowCanvasWithProvider } from './WorkflowCanvas';
import { WorkflowPresetsClient, type WorkflowPresetSummary } from '../../api/workflow-presets';
import type { WorkflowGraph } from '../../api/workflow-builder';

function Badge( { label }: { label: string } ): JSX.Element {
	return (
		<span className="nvoos-pro-spa-diagnostics__badge nvoos-pro-spa-diagnostics__badge--ok">
			{ label }
		</span>
	);
}

export function WorkflowsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const {
		workflows,
		selected,
		loading,
		saving,
		error,
		actionError,
		refresh,
		selectWorkflow,
		createWorkflow,
		removeWorkflow,
		executeWorkflow,
		saveGraph,
	} = useWorkflowBuilder(
		runtime
			? { endpoint: runtime.endpoints.workflowBuilder ?? '', nonce: runtime.nonce }
			: { endpoint: '', nonce: '' }
	);

	const [ busy, setBusy ] = useState< string | null >( null );
	const [ showForm, setShowForm ] = useState< boolean >( false );
	const [ form, setForm ] = useState( { name: '', tags: '' } );

	// W10.2c — workflow presets (Pro surface; empty when Pro is absent).
	const [ view, setView ] = useState< 'canvas' | 'presets' >( 'canvas' );
	const [ presetCategories, setPresetCategories ] = useState< Record< string, string > >( {} );
	const [ presets, setPresets ] = useState< WorkflowPresetSummary[] >( [] );
	const [ presetCategory, setPresetCategory ] = useState< string >( '' );
	const [ presetError, setPresetError ] = useState< string | null >( null );
	const [ canvasOverride, setCanvasOverride ] = useState< WorkflowGraph | null >( null );

	const presetsClient = useMemo( () => {
		if ( ! runtime || ! runtime.endpoints.workflowPresets ) {
			return null;
		}
		return new WorkflowPresetsClient( {
			endpoint: runtime.endpoints.workflowPresets,
			nonce: runtime.nonce,
		} );
	}, [ runtime ] );

	useEffect( () => {
		if ( ! presetsClient ) {
			return;
		}
		let cancelled = false;
		presetsClient
			.list()
			.then( ( data ) => {
				if ( ! cancelled ) {
					setPresetCategories( data.categories );
					setPresets( data.presets );
				}
			} )
			.catch( ( err: unknown ) => {
				if ( ! cancelled ) {
					setPresetError( err instanceof Error ? err.message : String( err ) );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ presetsClient ] );

	if ( bootstrapLoading || loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Workflows', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading workflows…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Workflows', 'nvoos-pro-spa' ) }</h2>
				{ error && <p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">{ error }</p> }
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => {
						void refresh();
					} }
				>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</div>
		);
	}

	const submitForm = async (): Promise< void > => {
		setBusy( 'create' );
		const tags = form.tags
			.split( ',' )
			.map( ( tag ) => tag.trim() )
			.filter( Boolean );
		const created = await createWorkflow( form.name.trim(), tags );
		setBusy( null );
		if ( created ) {
			setForm( { name: '', tags: '' } );
			setShowForm( false );
		}
	};

	const handleSelect = ( id: number ): void => {
		setCanvasOverride( null );
		void selectWorkflow( id );
	};

	const loadPreset = async ( id: string ): Promise< void > => {
		if ( ! presetsClient || ! selected ) {
			return;
		}
		setBusy( `preset-${ id }` );
		setPresetError( null );
		try {
			const preset = await presetsClient.install( id );
			if ( preset ) {
				setCanvasOverride( { nodes: preset.nodes, edges: preset.edges } );
				setView( 'canvas' );
			}
		} catch ( err ) {
			setPresetError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setBusy( null );
		}
	};

	const visiblePresets = presetCategory
		? presets.filter( ( preset ) => preset.category === presetCategory )
		: presets;

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Workflows', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Compose multi-step AI automation pipelines.', 'nvoos-pro-spa' ) }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => {
						void refresh();
					} }
				>
					{ __( 'Refresh', 'nvoos-pro-spa' ) }
				</button>
			</header>

			{ ( error || actionError || presetError ) && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ presetError ?? actionError ?? error }</p>
				</div>
			) }

			<div className="nvoos-pro-spa-token-manager__stats" role="tablist" aria-label={ __( 'Workflow views', 'nvoos-pro-spa' ) }>
				<button
					type="button"
					role="tab"
					aria-selected={ view === 'canvas' }
					className="nvoos-pro-spa-token-manager__action"
					onClick={ () => setView( 'canvas' ) }
				>
					{ __( 'Canvas', 'nvoos-pro-spa' ) }
				</button>
				<button
					type="button"
					role="tab"
					aria-selected={ view === 'presets' }
					className="nvoos-pro-spa-token-manager__action"
					onClick={ () => setView( 'presets' ) }
				>
					{ __( 'Presets', 'nvoos-pro-spa' ) } ({ presets.length })
				</button>
			</div>

			<div className="nvoos-pro-spa-workflows-page__layout">
				<aside className="nvoos-pro-spa-diagnostics__card" aria-label={ __( 'Workflow list', 'nvoos-pro-spa' ) }>
					<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Workflows', 'nvoos-pro-spa' ) }</h3>
					<p>
						<button
							type="button"
							className="nvoos-pro-spa-token-manager__action"
							onClick={ () => setShowForm( ( value ) => ! value ) }
						>
							{ showForm ? __( 'Hide form', 'nvoos-pro-spa' ) : __( 'New workflow', 'nvoos-pro-spa' ) }
						</button>
					</p>

					{ showForm && (
						<div role="form" aria-label={ __( 'New workflow form', 'nvoos-pro-spa' ) }>
							<label className="nvoos-pro-spa-diagnostics__muted">
								{ __( 'Name', 'nvoos-pro-spa' ) }
								<input
									type="text"
									className="nvoos-pro-spa-assistants-form__input"
									value={ form.name }
									onChange={ ( event ) => setForm( { ...form, name: event.target.value } ) }
								/>
							</label>
							<label className="nvoos-pro-spa-diagnostics__muted">
								{ __( 'Tags (comma-separated)', 'nvoos-pro-spa' ) }
								<input
									type="text"
									className="nvoos-pro-spa-assistants-form__input"
									value={ form.tags }
									onChange={ ( event ) => setForm( { ...form, tags: event.target.value } ) }
								/>
							</label>
							<p>
								<button
									type="button"
									className="nvoos-pro-spa-token-manager__action"
									disabled={ busy !== null || ! form.name.trim() }
									onClick={ () => void submitForm() }
								>
									{ __( 'Create workflow', 'nvoos-pro-spa' ) }
								</button>
							</p>
						</div>
					) }

					{ workflows.length === 0 ? (
						<p className="nvoos-pro-spa-diagnostics__muted">
							{ __( 'No workflows yet.', 'nvoos-pro-spa' ) }
						</p>
					) : (
						<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Workflows', 'nvoos-pro-spa' ) }>
							{ workflows.map( ( workflow ) => (
								<div key={ workflow.id } className="nvoos-pro-spa-tools-list__row" role="listitem">
									<div className="nvoos-pro-spa-tools-list__cell" role="cell">
										<button
											type="button"
											className="nvoos-pro-spa-token-manager__action"
											onClick={ () => handleSelect( workflow.id ) }
										>
											<strong className="nvoos-pro-spa-tools-list__name">{ workflow.title }</strong>
										</button>
										<code className="nvoos-pro-spa-tools-list__slug">v{ workflow.version }</code>
									</div>
								</div>
							) ) }
						</div>
					) }
				</aside>

				<main className="nvoos-pro-spa-diagnostics__card" aria-label={ __( 'Workflow builder', 'nvoos-pro-spa' ) }>
					{ view === 'presets' ? (
						<>
							<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Presets', 'nvoos-pro-spa' ) }</h3>
							{ Object.keys( presetCategories ).length > 0 && (
								<p>
									<select
										className="nvoos-pro-spa-assistants-form__select"
										value={ presetCategory }
										onChange={ ( event ) => setPresetCategory( event.target.value ) }
									>
										<option value="">{ __( 'All categories', 'nvoos-pro-spa' ) }</option>
										{ Object.entries( presetCategories ).map( ( [ slug, label ] ) => (
											<option key={ slug } value={ slug }>
												{ label }
											</option>
										) ) }
									</select>
								</p>
							) }
							{ visiblePresets.length === 0 ? (
								<p className="nvoos-pro-spa-diagnostics__muted">
									{ __( 'No presets available.', 'nvoos-pro-spa' ) }
								</p>
							) : (
								<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Workflow presets', 'nvoos-pro-spa' ) }>
									{ visiblePresets.map( ( preset ) => (
										<div key={ preset.id } className="nvoos-pro-spa-tools-list__row" role="listitem">
											<div className="nvoos-pro-spa-tools-list__cell" role="cell">
												<strong className="nvoos-pro-spa-tools-list__name">{ preset.name }</strong>
												<code className="nvoos-pro-spa-tools-list__slug">
													{ presetCategories[ preset.category ] ?? preset.category }
												</code>
											</div>
											<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
												{ preset.description || '—' }
											</div>
											<div className="nvoos-pro-spa-tools-list__cell" role="cell">
												<button
													type="button"
													className="nvoos-pro-spa-token-manager__action"
													disabled={ busy !== null || ! selected }
													onClick={ () => void loadPreset( preset.id ) }
												>
													{ __( 'Load into canvas', 'nvoos-pro-spa' ) }
												</button>
											</div>
										</div>
									) ) }
								</div>
							) }
						</>
					) : ! selected ? (
						<p className="nvoos-pro-spa-diagnostics__muted">
							{ __( 'Select a workflow to inspect its graph.', 'nvoos-pro-spa' ) }
						</p>
					) : (
						<>
							<div className="nvoos-pro-spa-tools-page__header">
								<h3 className="nvoos-pro-spa-diagnostics__card-title">{ selected.title }</h3>
								<Badge label={ `v${ selected.version }` } />
								{ selected.tags.map( ( tag ) => (
									<code key={ tag } className="nvoos-pro-spa-tools-list__slug">
										{ tag }
									</code>
								) ) }
							</div>

							{ selected.description && (
								<p className="nvoos-pro-spa-diagnostics__muted">{ selected.description }</p>
							) }

							<WorkflowCanvasWithProvider
								key={ selected.id + ( canvasOverride ? ':draft' : '' ) }
								workflow={ { ...selected, graph: canvasOverride ?? selected.graph } }
								saving={ saving }
								onSave={ async ( graph ) => {
									await saveGraph( selected.id, graph );
								} }
							/>

							<p>
								<button
									type="button"
									className="nvoos-pro-spa-token-manager__action"
									disabled={ busy !== null }
									onClick={ () => {
										setBusy( `run-${ selected.id }` );
										void executeWorkflow( selected.id ).finally( () => setBusy( null ) );
									} }
								>
									{ __( 'Run now', 'nvoos-pro-spa' ) }
								</button>
								<button
									type="button"
									className="nvoos-pro-spa-token-manager__action nvoos-pro-spa-token-manager__action--danger"
									disabled={ busy !== null }
									onClick={ () => {
										// eslint-disable-next-line no-alert -- parity with the wp-admin confirm().
										if ( window.confirm( __( 'Delete this workflow?', 'nvoos-pro-spa' ) ) ) {
											setBusy( `delete-${ selected.id }` );
											void removeWorkflow( selected.id ).finally( () => setBusy( null ) );
										}
									} }
								>
									{ __( 'Delete', 'nvoos-pro-spa' ) }
								</button>
							</p>
						</>
					) }
				</main>
			</div>
		</div>
	);
}
