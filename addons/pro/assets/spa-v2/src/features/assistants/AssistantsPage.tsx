/**
 * AssistantsPage — Assistant management.
 *
 * Lists, creates, edits, and deletes AI assistant configurations.
 * Uses the useAssistants hook for CRUD operations and useBootstrap
 * for endpoint runtime config.
 */

import { useState, useCallback, useMemo } from 'react';
import type { JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useAssistants } from '../../hooks/useAssistants';
import { useBootstrap } from '../../hooks/useBootstrap';
import { useModelStore } from '../../stores/modelStore';
import type { AssistantRecord } from '../../api/assistants';

interface AssistantFormFields {
	title: string;
	model: string;
	provider: string;
	temperature: number;
	system_prompt: string;
	capabilities: string;
}

const EMPTY_FORM: AssistantFormFields = {
	title: '',
	model: '',
	provider: 'openai',
	temperature: 0.7,
	system_prompt: '',
	capabilities: '',
};

function parseCapabilities( raw: string ): string[] {
	return raw
		.split( ',' )
		.map( ( s ) => s.trim() )
		.filter( ( s ) => s.length > 0 );
}

export function AssistantsPage(): JSX.Element {
	const { runtime } = useBootstrap();
	const availableModels = useModelStore( ( s ) => s.availableModels );

	const { assistants, loading, error, fetchAssistants, createAssistant, updateAssistant, deleteAssistant } =
		useAssistants(
			runtime
				? {
						endpoint: runtime.endpoints.assistants,
						nonce: runtime.nonce,
				  }
				: { endpoint: '', nonce: '' }
		);

	const [ formOpen, setFormOpen ] = useState< boolean >( false );
	const [ editingId, setEditingId ] = useState< number | null >( null );
	const [ formFields, setFormFields ] = useState< AssistantFormFields >( EMPTY_FORM );
	const [ saving, setSaving ] = useState< boolean >( false );
	const [ deleteConfirm, setDeleteConfirm ] = useState< number | null >( null );

	const providers = useMemo< string[] >( () => {
		const set = new Set(
			availableModels.map( ( m ) => m.provider ).filter( Boolean )
		);
		return Array.from( set ).sort();
	}, [ availableModels ] );

	const openCreate = useCallback( () => {
		setFormFields( EMPTY_FORM );
		setEditingId( null );
		setFormOpen( true );
	}, [] );

	const openEdit = useCallback( ( assistant: AssistantRecord ) => {
		setFormFields( {
			title: assistant.title ?? '',
			model: assistant.model ?? '',
			provider: assistant.provider ?? 'openai',
			temperature: assistant.temperature ?? 0.7,
			system_prompt: assistant.system_prompt ?? '',
			capabilities: Array.isArray( assistant.capabilities )
				? assistant.capabilities.join( ', ' )
				: '',
		} );
		setEditingId( assistant.id );
		setFormOpen( true );
	}, [] );

	const closeForm = useCallback( () => {
		setFormOpen( false );
		setEditingId( null );
		setFormFields( EMPTY_FORM );
	}, [] );

	const handleSave = useCallback( async () => {
		if ( ! formFields.title.trim() ) return;
		setSaving( true );
		const payload: Partial< AssistantRecord > = {
			title: formFields.title.trim(),
			model: formFields.model.trim() || undefined,
			provider: formFields.provider,
			temperature: formFields.temperature,
			system_prompt: formFields.system_prompt.trim() || undefined,
			capabilities: parseCapabilities( formFields.capabilities ),
		};
		try {
			if ( editingId !== null ) {
				await updateAssistant( editingId, payload );
			} else {
				await createAssistant( payload );
			}
			closeForm();
		} finally {
			setSaving( false );
		}
	}, [ formFields, editingId, createAssistant, updateAssistant, closeForm ] );

	const handleDelete = useCallback(
		async ( id: number ) => {
			setDeleteConfirm( null );
			await deleteAssistant( id );
		},
		[ deleteAssistant ]
	);

	if ( ! runtime ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--error"
				role="alert"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Assistants', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __(
						'Runtime configuration not available. Please reload the page.',
						'nvoos-pro-spa'
					) }
				</p>
			</div>
		);
	}

	if ( loading ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--loading"
				aria-busy="true"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Assistants', 'nvoos-pro-spa' ) }
				</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading assistants…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( error ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--error"
				role="alert"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Assistants', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ error }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => void fetchAssistants() }
				>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-assistants-page">
			<header className="nvoos-pro-spa-assistants-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Assistants', 'nvoos-pro-spa' ) }
				</h2>
				<button
					type="button"
					className="nvoos-pro-spa-assistants-page__add-btn"
					onClick={ openCreate }
				>
					{ __( 'Add Assistant', 'nvoos-pro-spa' ) }
				</button>
			</header>

			{ assistants.length === 0 && ! formOpen ? (
				<div
					className="nvoos-pro-spa-assistants-page__empty"
					role="status"
				>
					<p>
						{ __(
							'No assistants configured yet. Create your first assistant to get started.',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
			) : (
				<ul
					className="nvoos-pro-spa-assistants-list"
					aria-label={ __( 'Assistant list', 'nvoos-pro-spa' ) }
				>
					{ assistants.map( ( assistant ) => (
						<li
							key={ assistant.id }
							className="nvoos-pro-spa-assistants-list__item"
						>
							<div className="nvoos-pro-spa-assistants-list__body">
								<h3 className="nvoos-pro-spa-assistants-list__title">
									{ assistant.title }
									{ assistant.is_preset && (
										<span className="nvoos-pro-spa-assistants-list__badge">
											{ __( 'Preset', 'nvoos-pro-spa' ) }
										</span>
									) }
								</h3>
								<div className="nvoos-pro-spa-assistants-list__meta">
									{ assistant.provider && (
										<span className="nvoos-pro-spa-assistants-list__provider">
											{ assistant.provider }
										</span>
									) }
									{ assistant.model && (
										<span className="nvoos-pro-spa-assistants-list__model">
											{ assistant.model }
										</span>
									) }
									{ typeof assistant.temperature ===
										'number' && (
										<span className="nvoos-pro-spa-assistants-list__temp">
											{ __(
												'Temperature:',
												'nvoos-pro-spa'
											) }{ ' ' }
											{ assistant.temperature }
										</span>
									) }
								</div>
								{ assistant.system_prompt && (
									<p className="nvoos-pro-spa-assistants-list__prompt">
										{ assistant.system_prompt }
									</p>
								) }
							</div>
							<div className="nvoos-pro-spa-assistants-list__actions">
								<button
									type="button"
									className="nvoos-pro-spa-assistants-list__edit-btn"
									onClick={ () =>
										openEdit( assistant )
									}
									aria-label={ __(
										'Edit assistant',
										'nvoos-pro-spa'
									) }
								>
									{ __( 'Edit', 'nvoos-pro-spa' ) }
								</button>
								{ deleteConfirm === assistant.id ? (
									<div className="nvoos-pro-spa-assistants-list__confirm">
										<span>
											{ __(
												'Delete?',
												'nvoos-pro-spa'
											) }
										</span>
										<button
											type="button"
											className="nvoos-pro-spa-assistants-list__confirm-yes"
											onClick={ () =>
												void handleDelete(
													assistant.id
												)
											}
										>
											{ __(
												'Yes',
												'nvoos-pro-spa'
											) }
										</button>
										<button
											type="button"
											className="nvoos-pro-spa-assistants-list__confirm-no"
											onClick={ () =>
												setDeleteConfirm(
													null
												)
											}
										>
											{ __(
												'No',
												'nvoos-pro-spa'
											) }
										</button>
									</div>
								) : (
									<button
										type="button"
										className="nvoos-pro-spa-assistants-list__delete-btn"
										onClick={ () =>
											setDeleteConfirm(
												assistant.id
											)
										}
										aria-label={ __(
											'Delete assistant',
											'nvoos-pro-spa'
										) }
									>
										{ __(
											'Delete',
											'nvoos-pro-spa'
										) }
									</button>
								) }
							</div>
						</li>
					) ) }
				</ul>
			) }

			{ formOpen && (
				<div
					className="nvoos-pro-spa-assistants-form-overlay"
					role="dialog"
					aria-labelledby="nvoos-pro-spa-assistant-form-title"
					aria-modal="true"
				>
					<div className="nvoos-pro-spa-assistants-form">
						<h3
							id="nvoos-pro-spa-assistant-form-title"
							className="nvoos-pro-spa-assistants-form__title"
						>
							{ editingId !== null
								? __(
										'Edit Assistant',
										'nvoos-pro-spa'
								  )
								: __(
										'New Assistant',
										'nvoos-pro-spa'
								  ) }
						</h3>

						<div className="nvoos-pro-spa-assistants-form__field">
							<label
								htmlFor="nvoos-pro-spa-assistant-title"
								className="nvoos-pro-spa-assistants-form__label"
							>
								{ __(
									'Title',
									'nvoos-pro-spa'
								) }
							</label>
							<input
								id="nvoos-pro-spa-assistant-title"
								type="text"
								className="nvoos-pro-spa-assistants-form__input"
								value={ formFields.title }
								onChange={ ( e ) =>
									setFormFields( ( f ) => ( {
										...f,
										title: e.target.value,
									} ) )
								}
								required
							/>
						</div>

						<div className="nvoos-pro-spa-assistants-form__row">
							<div className="nvoos-pro-spa-assistants-form__field">
								<label
									htmlFor="nvoos-pro-spa-assistant-provider"
									className="nvoos-pro-spa-assistants-form__label"
								>
									{ __(
										'Provider',
										'nvoos-pro-spa'
									) }
								</label>
								<select
									id="nvoos-pro-spa-assistant-provider"
									className="nvoos-pro-spa-assistants-form__select"
									value={ formFields.provider }
									onChange={ ( e ) =>
										setFormFields( ( f ) => ( {
											...f,
											provider:
												e.target.value,
											model: '',
										} ) )
									}
								>
									<option value="openai">
										OpenAI
									</option>
									<option value="google">
										Google Gemini
									</option>
									<option value="anthropic">
										Anthropic
									</option>
									<option value="ollama">
										Ollama
									</option>
									{ providers
										.filter(
											( p ) =>
												! [
													'openai',
													'google',
													'anthropic',
													'ollama',
												].includes( p )
										)
										.map( ( p ) => (
											<option
												key={ p }
												value={ p }
											>
												{ p }
											</option>
										) ) }
								</select>
							</div>

							<div className="nvoos-pro-spa-assistants-form__field">
								<label
									htmlFor="nvoos-pro-spa-assistant-model"
									className="nvoos-pro-spa-assistants-form__label"
								>
									{ __(
										'Model',
										'nvoos-pro-spa'
									) }
								</label>
								<input
									id="nvoos-pro-spa-assistant-model"
									type="text"
									className="nvoos-pro-spa-assistants-form__input"
									value={ formFields.model }
									onChange={ ( e ) =>
										setFormFields( ( f ) => ( {
											...f,
											model: e.target.value,
										} ) )
									}
									placeholder={
										formFields.provider ===
										'openai'
											? 'gpt-4o'
											: formFields.provider ===
											  'google'
											? 'gemini-2.5-flash'
											: formFields.provider ===
											  'anthropic'
											? 'claude-sonnet-4-20250514'
											: ''
									}
								/>
							</div>
						</div>

						<div className="nvoos-pro-spa-assistants-form__field">
							<label
								htmlFor="nvoos-pro-spa-assistant-temperature"
								className="nvoos-pro-spa-assistants-form__label"
							>
								{ __(
									'Temperature',
									'nvoos-pro-spa'
								) }{ ' ' }
								({ formFields.temperature })
							</label>
							<input
								id="nvoos-pro-spa-assistant-temperature"
								type="range"
								className="nvoos-pro-spa-assistants-form__range"
								min="0"
								max="2"
								step="0.1"
								value={ formFields.temperature }
								onChange={ ( e ) =>
									setFormFields( ( f ) => ( {
										...f,
										temperature:
											parseFloat(
												e.target.value
											),
									} ) )
								}
							/>
						</div>

						<div className="nvoos-pro-spa-assistants-form__field">
							<label
								htmlFor="nvoos-pro-spa-assistant-prompt"
								className="nvoos-pro-spa-assistants-form__label"
							>
								{ __(
									'System Prompt',
									'nvoos-pro-spa'
								) }
							</label>
							<textarea
								id="nvoos-pro-spa-assistant-prompt"
								className="nvoos-pro-spa-assistants-form__textarea"
								value={ formFields.system_prompt }
								onChange={ ( e ) =>
									setFormFields( ( f ) => ( {
										...f,
										system_prompt:
											e.target.value,
									} ) )
								}
								rows={ 4 }
							/>
						</div>

						<div className="nvoos-pro-spa-assistants-form__field">
							<label
								htmlFor="nvoos-pro-spa-assistant-capabilities"
								className="nvoos-pro-spa-assistants-form__label"
							>
								{ __(
									'Capabilities',
									'nvoos-pro-spa'
								) }
							</label>
							<input
								id="nvoos-pro-spa-assistant-capabilities"
								type="text"
								className="nvoos-pro-spa-assistants-form__input"
								value={ formFields.capabilities }
								onChange={ ( e ) =>
									setFormFields( ( f ) => ( {
										...f,
										capabilities:
											e.target.value,
									} ) )
								}
								placeholder={ __(
									'Comma-separated capabilities, e.g. edit_posts, manage_options',
									'nvoos-pro-spa'
								) }
							/>
						</div>

						<div className="nvoos-pro-spa-assistants-form__actions">
							<button
								type="button"
								className="nvoos-pro-spa-assistants-form__save-btn"
								onClick={ () => void handleSave() }
								disabled={ saving || ! formFields.title.trim() }
							>
								{ saving
									? __(
											'Saving…',
											'nvoos-pro-spa'
									  )
									: editingId !== null
									? __(
											'Update',
											'nvoos-pro-spa'
									  )
									: __(
											'Create',
											'nvoos-pro-spa'
									  ) }
							</button>
							<button
								type="button"
								className="nvoos-pro-spa-assistants-form__cancel-btn"
								onClick={ closeForm }
							>
								{ __( 'Cancel', 'nvoos-pro-spa' ) }
							</button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}
