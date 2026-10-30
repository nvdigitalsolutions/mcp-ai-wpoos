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
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';
import { WorkflowCanvasWithProvider } from './WorkflowCanvas';
import { WorkflowPresetsClient, type WorkflowPresetSummary } from '../../api/workflow-presets';
import type { WorkflowGraph } from '../../api/workflow-builder';
import { nodeExecutePayload, type CanvasWorkflowNode } from './workflow-graph';
import {
	WorkflowDslClient,
	DslFindingsError,
	downloadDsl,
	hasBlockers,
	type DslFinding,
} from '../../api/workflow-dsl';
import { WorkflowDslImport } from './WorkflowDslImport';

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
		runs,
		lastNodeResult,
		loading,
		saving,
		error,
		actionError,
		refresh,
		selectWorkflow,
		createWorkflow,
		removeWorkflow,
		executeWorkflow,
		executeNode,
		saveGraph,
	} = useWorkflowBuilder(
		runtime
			? {
					endpoint: runtime.endpoints.workflowBuilder ?? '',
					nonce: runtime.nonce,
					nodesEndpoint: runtime.endpoints.workflowNodes ?? '',
					runsEndpoint: runtime.endpoints.workflowRuns ?? '',
			  }
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

	// Proposal 091 (Track A) — DSL export/import surface on the workflow REST.
	const [ dslFindings, setDslFindings ] = useState< DslFinding[] | null >( null );
	const [ dslFeedback, setDslFeedback ] = useState< string | null >( null );
	const [ dslFeedbackTone, setDslFeedbackTone ] = useState< 'error' | 'success' >( 'error' );
	const [ dslBusy, setDslBusy ] = useState< boolean >( false );
	const [ dslOpen, setDslOpen ] = useState< boolean >( false );

	const presetsClient = useMemo( () => {
		if ( ! runtime || ! runtime.endpoints.workflowPresets ) {
			return null;
		}
		return new WorkflowPresetsClient( {
			endpoint: runtime.endpoints.workflowPresets,
			nonce: runtime.nonce,
		} );
	}, [ runtime ] );

	const dslClient = useMemo( () => {
		if ( ! runtime || ! runtime.endpoints.workflowBuilder ) {
			return null;
		}
		return new WorkflowDslClient( {
			endpoint: runtime.endpoints.workflowBuilder,
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

	const handleRunNode = ( node: CanvasWorkflowNode ): void => {
		const payload = nodeExecutePayload( node, String( selected?.id ?? '' ) );
		if ( ! payload ) {
			return;
		}
		setBusy( `run-node-${ node.id }` );
		void executeNode( payload ).finally( () => setBusy( null ) );
	};

	const setDslError = ( message: string ): void => {
		setDslFeedbackTone( 'error' );
		setDslFeedback( message );
	};

	const handleExportWorkflow = async ( id: number ): Promise< void > => {
		if ( ! dslClient ) {
			return;
		}
		setBusy( `export-${ id }` );
		setDslFeedback( null );
		try {
			const dsl = await dslClient.exportWorkflow( id );
			downloadDsl( dsl, `workflow-${ id }.json` );
		} catch ( err ) {
			setDslError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setBusy( null );
		}
	};

	const handleExportPreset = async ( id: string ): Promise< void > => {
		if ( ! dslClient ) {
			return;
		}
		setBusy( `preset-export-${ id }` );
		setDslFeedback( null );
		try {
			const dsl = await dslClient.exportPreset( id );
			downloadDsl( dsl, `preset-${ id }.json` );
		} catch ( err ) {
			setDslError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setBusy( null );
		}
	};

	const handleDslSubmit = async ( text: string, source: 'native' | 'dify' ): Promise< void > => {
		if ( ! dslClient ) {
			return;
		}
		setDslBusy( true );
		setDslFeedback( null );
		setDslFindings( null );
		let parsed: unknown;
		try {
			parsed = JSON.parse( text );
		} catch ( parseErr ) {
			setDslError( __( 'The pasted DSL is not valid JSON.', 'nvoos-pro-spa' ) );
			setDslBusy( false );
			return;
		}
		try {
			// Dry run first — findings with blockers stop the import before
			// anything is written (parity with the wp-admin DAG builder flow).
			const dryRun = await dslClient.importDsl( parsed, { dryRun: true, source } );
			const findings = dryRun.findings ?? [];
			if ( findings.length > 0 ) {
				setDslFindings( findings );
				if ( hasBlockers( findings ) ) {
					setDslError( __( 'Dry run reported blockers — import was not started.', 'nvoos-pro-spa' ) );
					return;
				}
			}
			// eslint-disable-next-line no-alert -- parity with the wp-admin confirm().
			if ( ! window.confirm( __( 'Dry run passed. Import this workflow?', 'nvoos-pro-spa' ) ) ) {
				return;
			}
			const result = await dslClient.importDsl( parsed, { dryRun: false, source } );
			setDslFindings( result.findings ?? null );
			setDslFeedbackTone( 'success' );
			setDslFeedback( __( 'Workflow imported.', 'nvoos-pro-spa' ) );
			await refresh();
		} catch ( err ) {
			if ( err instanceof DslFindingsError ) {
				setDslFindings( err.findings );
				setDslError( err.message );
			} else {
				setDslError( err instanceof Error ? err.message : String( err ) );
			}
		} finally {
			setDslBusy( false );
		}
	};

	const visiblePresets = presetCategory
			? presets.filter( ( preset ) => preset.category === presetCategory )
			: presets;

	// Every hook below must run on every render — the loading/error early
	// returns would otherwise change the hook count between renders
	// (React error #310, "Rendered more hooks than during the previous
	// render"). The memos only read hook state, so computing them before
	// the early returns is free.
	const workflowColumns = useMemo< DataTableColumn[] >(
		() => [ { key: 'workflow', label: __( 'Workflow', 'nvoos-pro-spa' ) } ],
		[]
	);

	const workflowRows = useMemo< DataTableRow[] >(
		() =>
			workflows.map( ( workflow ) => ( {
				id: workflow.id,
				cells: [
					<>
						<button
							type="button"
							className="nvoos-pro-spa-token-manager__action"
							onClick={ () => handleSelect( workflow.id ) }
						>
							<strong className="nvoos-pro-spa-tools-list__name">{ workflow.title }</strong>
						</button>
						<code className="nvoos-pro-spa-tools-list__slug">v{ workflow.version }</code>
						<button
							type="button"
							className="nvoos-pro-spa-token-manager__action"
							disabled={ busy !== null || ! dslClient }
							onClick={ () => void handleExportWorkflow( workflow.id ) }
						>
							{ __( 'Export', 'nvoos-pro-spa' ) }
						</button>
					</>,
				],
			} ) ),
		[ workflows, busy, dslClient ]
	);

	const presetColumns = useMemo< DataTableColumn[] >(
		() => [
			{
				key: 'preset',
				label: __( 'Preset', 'nvoos-pro-spa' ),
				width: 'minmax(180px, 1fr)',
			},
			{
				key: 'description',
				label: __( 'Description', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--desc',
				width: 'minmax(220px, 2fr)',
			},
			{ key: 'actions', label: __( 'Actions', 'nvoos-pro-spa' ), width: '160px' },
		],
		[]
	);

	const presetRows = useMemo< DataTableRow[] >(
		() =>
			visiblePresets.map( ( preset ) => ( {
				id: preset.id,
				cells: [
					<>
						<strong className="nvoos-pro-spa-tools-list__name">{ preset.name }</strong>
						<code className="nvoos-pro-spa-tools-list__slug">
							{ presetCategories[ preset.category ] ?? preset.category }
						</code>
					</>,
					<span className="nvoos-pro-spa-tools-list__desc-clamp">
						{ preset.description || '—' }
					</span>,
					<>
						<button
							type="button"
							className="nvoos-pro-spa-token-manager__action"
							disabled={ busy !== null || ! selected }
							onClick={ () => void loadPreset( preset.id ) }
						>
							{ __( 'Load into canvas', 'nvoos-pro-spa' ) }
						</button>
						<button
							type="button"
							className="nvoos-pro-spa-token-manager__action"
							disabled={ busy !== null || ! dslClient }
							onClick={ () => void handleExportPreset( preset.id ) }
						>
							{ __( 'Export', 'nvoos-pro-spa' ) }
						</button>
					</>,
				],
			} ) ),
		[ visiblePresets, presetCategories, busy, selected, dslClient ]
	);

	const runColumns = useMemo< DataTableColumn[] >(
		() => [
			{ key: 'run', label: __( 'Run', 'nvoos-pro-spa' ), width: '160px' },
			{
				key: 'started',
				label: __( 'Started', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--desc',
				width: 'minmax(200px, 2fr)',
			},
		],
		[]
	);

	const runRows = useMemo< DataTableRow[] >(
		() =>
			runs.map( ( run ) => ( {
				id: run.id,
				cells: [
					<>
						<strong className="nvoos-pro-spa-tools-list__name">#{ run.id }</strong>
						<Badge label={ typeof run.status === 'string' ? run.status : 'unknown' } />
					</>,
					typeof run.started_at === 'string' && run.started_at
						? run.started_at
						: '—',
				],
			} ) ),
		[ runs ]
	);

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

			{ ( error || actionError || presetError || ( dslOpen ? null : dslFeedback ) ) && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ presetError ?? actionError ?? ( dslOpen ? null : dslFeedback ) ?? error }</p>
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
									className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--primary"
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
						<DataTable
							columns={ workflowColumns }
							rows={ workflowRows }
							caption={ __( 'Workflows', 'nvoos-pro-spa' ) }
						/>
					) }

					<p>
						<button
							type="button"
							className="nvoos-pro-spa-token-manager__action"
							disabled={ ! dslClient }
							onClick={ () => setDslOpen( ( value ) => ! value ) }
						>
							{ dslOpen
								? __( 'Hide import panel', 'nvoos-pro-spa' )
								: __( 'Import DSL…', 'nvoos-pro-spa' ) }
						</button>
					</p>

					{ dslOpen && dslClient && (
						<WorkflowDslImport
							onSubmit={ ( text, source ) => void handleDslSubmit( text, source ) }
							busy={ dslBusy }
							findings={ dslFindings }
							feedback={ dslFeedback }
							feedbackTone={ dslFeedbackTone }
						/>
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
								<DataTable
									columns={ presetColumns }
									rows={ presetRows }
									caption={ __( 'Workflow presets', 'nvoos-pro-spa' ) }
								/>
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
								onRunNode={ handleRunNode }
								nodeRunning={ busy !== null }
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

							{ lastNodeResult && (
								<p className="nvoos-pro-spa-diagnostics__muted">{ lastNodeResult }</p>
							) }

							<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Run history', 'nvoos-pro-spa' ) }</h3>
							{ runs.length === 0 ? (
								<p className="nvoos-pro-spa-diagnostics__muted">
									{ __( 'No runs recorded yet.', 'nvoos-pro-spa' ) }
								</p>
							) : (
								<DataTable
									columns={ runColumns }
									rows={ runRows }
									caption={ __( 'Run history', 'nvoos-pro-spa' ) }
								/>
							) }
						</>
					) }
				</main>
			</div>
		</div>
	);
}
