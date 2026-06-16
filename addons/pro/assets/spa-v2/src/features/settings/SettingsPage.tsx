/**
 * SettingsPage — Plugin settings form.
 *
 * Displays and edits plugin settings including API keys for AI providers,
 * model defaults, and logging options. API key fields are masked by default.
 * Uses the useSettings hook and useBootstrap for runtime config.
 */

import { useState, useEffect, useCallback } from 'react';
import type { JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useSettings } from '../../hooks/useSettings';
import { useBootstrap } from '../../hooks/useBootstrap';
import type { PluginSettings } from '../../api/settings';

interface FormState {
	openai_api_key: string;
	google_api_key: string;
	anthropic_api_key: string;
	ollama_endpoint: string;
	default_model: string;
	default_provider: string;
	max_tokens: string;
	temperature: string;
	enable_logging: boolean;
}

const INITIAL_FORM: FormState = {
	openai_api_key: '',
	google_api_key: '',
	anthropic_api_key: '',
	ollama_endpoint: '',
	default_model: '',
	default_provider: 'openai',
	max_tokens: '4096',
	temperature: '0.7',
	enable_logging: false,
};

function mapSettingsToForm(
	settings: PluginSettings | null
): FormState {
	if ( ! settings ) {
		return { ...INITIAL_FORM };
	}
	return {
		openai_api_key: settings.openai_api_key ?? '',
		google_api_key: settings.google_api_key ?? '',
		anthropic_api_key: settings.anthropic_api_key ?? '',
		ollama_endpoint: settings.ollama_endpoint ?? '',
		default_model: settings.default_model ?? '',
		default_provider: settings.default_provider ?? 'openai',
		max_tokens: String( settings.max_tokens ?? 4096 ),
		temperature: String( settings.temperature ?? 0.7 ),
		enable_logging: settings.enable_logging ?? false,
	};
}

function mapFormToSettings(
	form: FormState
): Partial< PluginSettings > {
	return {
		openai_api_key:
			form.openai_api_key.trim() || undefined,
		google_api_key:
			form.google_api_key.trim() || undefined,
		anthropic_api_key:
			form.anthropic_api_key.trim() || undefined,
		ollama_endpoint:
			form.ollama_endpoint.trim() || undefined,
		default_model: form.default_model.trim() || undefined,
		default_provider: form.default_provider,
		max_tokens: parseInt( form.max_tokens, 10 ) || 4096,
		temperature: parseFloat( form.temperature ) || 0.7,
		enable_logging: form.enable_logging,
	};
}

export function SettingsPage(): JSX.Element {
	const { runtime } = useBootstrap();
	const { settings, loading, error, fetchSettings, updateSettings } =
		useSettings(
			runtime
				? {
						endpoint: runtime.endpoints.settings,
						nonce: runtime.nonce,
				  }
				: { endpoint: '', nonce: '' }
		);

	const [ form, setForm ] = useState< FormState >( INITIAL_FORM );
	const [ dirty, setDirty ] = useState< boolean >( false );
	const [ saving, setSaving ] = useState< boolean >( false );
	const [ unmaskKeys, setUnmaskKeys ] = useState< boolean >( false );

	useEffect( () => {
		setForm( mapSettingsToForm( settings ) );
		setDirty( false );
	}, [ settings ] );

	const handleChange = useCallback(
		<K extends keyof FormState>(
			key: K,
			value: FormState[ K ]
		) => {
			setForm( ( prev ) => ( { ...prev, [ key ]: value } ) );
			setDirty( true );
		},
		[]
	);

	const handleSave = useCallback( async () => {
		setSaving( true );
		try {
			await updateSettings( mapFormToSettings( form ) );
			setDirty( false );
		} finally {
			setSaving( false );
		}
	}, [ form, updateSettings ] );

	const handleReset = useCallback( () => {
		setForm( mapSettingsToForm( settings ) );
		setDirty( false );
	}, [ settings ] );

	if ( ! runtime ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--error"
				role="alert"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Settings', 'nvoos-pro-spa' ) }
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
					{ __( 'Settings', 'nvoos-pro-spa' ) }
				</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading settings…', 'nvoos-pro-spa' ) }
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
					{ __( 'Settings', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ error }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => void fetchSettings() }
				>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-settings-page">
			<header className="nvoos-pro-spa-settings-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Settings', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __(
						'Configure API keys and default model behavior for the AI Assistant.',
						'nvoos-pro-spa'
					) }
				</p>
			</header>

			<form
				className="nvoos-pro-spa-settings-form"
				onSubmit={ ( e ) => {
					e.preventDefault();
					void handleSave();
				} }
				aria-label={ __(
					'Plugin settings',
					'nvoos-pro-spa'
				) }
			>
				<fieldset className="nvoos-pro-spa-settings-form__section">
					<legend className="nvoos-pro-spa-settings-form__section-title">
						{ __( 'API Keys', 'nvoos-pro-spa' ) }
					</legend>

					<div className="nvoos-pro-spa-settings-form__field">
						<label
							htmlFor="nvoos-pro-spa-settings-openai-key"
							className="nvoos-pro-spa-settings-form__label"
						>
							{ __(
								'OpenAI API Key',
								'nvoos-pro-spa'
							) }
						</label>
						<input
							id="nvoos-pro-spa-settings-openai-key"
							type={ unmaskKeys ? 'text' : 'password' }
							className="nvoos-pro-spa-settings-form__input"
							value={ form.openai_api_key }
							onChange={ ( e ) =>
								handleChange(
									'openai_api_key',
									e.target.value
								)
							}
							autoComplete="off"
							spellCheck={ false }
						/>
					</div>

					<div className="nvoos-pro-spa-settings-form__field">
						<label
							htmlFor="nvoos-pro-spa-settings-google-key"
							className="nvoos-pro-spa-settings-form__label"
						>
							{ __(
								'Google API Key (Gemini)',
								'nvoos-pro-spa'
							) }
						</label>
						<input
							id="nvoos-pro-spa-settings-google-key"
							type={ unmaskKeys ? 'text' : 'password' }
							className="nvoos-pro-spa-settings-form__input"
							value={ form.google_api_key }
							onChange={ ( e ) =>
								handleChange(
									'google_api_key',
									e.target.value
								)
							}
							autoComplete="off"
							spellCheck={ false }
						/>
					</div>

					<div className="nvoos-pro-spa-settings-form__field">
						<label
							htmlFor="nvoos-pro-spa-settings-anthropic-key"
							className="nvoos-pro-spa-settings-form__label"
						>
							{ __(
								'Anthropic API Key',
								'nvoos-pro-spa'
							) }
						</label>
						<input
							id="nvoos-pro-spa-settings-anthropic-key"
							type={ unmaskKeys ? 'text' : 'password' }
							className="nvoos-pro-spa-settings-form__input"
							value={ form.anthropic_api_key }
							onChange={ ( e ) =>
								handleChange(
									'anthropic_api_key',
									e.target.value
								)
							}
							autoComplete="off"
							spellCheck={ false }
						/>
					</div>

					<div className="nvoos-pro-spa-settings-form__field">
						<label
							htmlFor="nvoos-pro-spa-settings-ollama-url"
							className="nvoos-pro-spa-settings-form__label"
						>
							{ __(
								'Ollama Endpoint URL',
								'nvoos-pro-spa'
							) }
						</label>
						<input
							id="nvoos-pro-spa-settings-ollama-url"
							type="text"
							className="nvoos-pro-spa-settings-form__input"
							value={ form.ollama_endpoint }
							onChange={ ( e ) =>
								handleChange(
									'ollama_endpoint',
									e.target.value
								)
							}
							placeholder="http://localhost:11434"
						/>
					</div>

					<div className="nvoos-pro-spa-settings-form__field">
						<button
							type="button"
							className="nvoos-pro-spa-settings-form__toggle-visibility"
							onClick={ () =>
								setUnmaskKeys( ( prev ) => ! prev )
							}
							aria-label={
								unmaskKeys
									? __(
											'Hide API keys',
											'nvoos-pro-spa'
									  )
									: __(
											'Show API keys',
											'nvoos-pro-spa'
									  )
							}
						>
							{ unmaskKeys
								? __(
										'Hide API keys',
										'nvoos-pro-spa'
								  )
								: __(
										'Show API keys',
										'nvoos-pro-spa'
								  ) }
						</button>
					</div>
				</fieldset>

				<fieldset className="nvoos-pro-spa-settings-form__section">
					<legend className="nvoos-pro-spa-settings-form__section-title">
						{ __( 'Model Defaults', 'nvoos-pro-spa' ) }
					</legend>

					<div className="nvoos-pro-spa-settings-form__row">
						<div className="nvoos-pro-spa-settings-form__field">
							<label
								htmlFor="nvoos-pro-spa-settings-provider"
								className="nvoos-pro-spa-settings-form__label"
							>
								{ __(
									'Default Provider',
									'nvoos-pro-spa'
								) }
							</label>
							<select
								id="nvoos-pro-spa-settings-provider"
								className="nvoos-pro-spa-settings-form__select"
								value={ form.default_provider }
								onChange={ ( e ) =>
									handleChange(
										'default_provider',
										e.target.value
									)
								}
							>
								<option value="openai">OpenAI</option>
								<option value="google">
									Google Gemini
								</option>
								<option value="anthropic">
									Anthropic
								</option>
								<option value="ollama">Ollama</option>
							</select>
						</div>

						<div className="nvoos-pro-spa-settings-form__field">
							<label
								htmlFor="nvoos-pro-spa-settings-model"
								className="nvoos-pro-spa-settings-form__label"
							>
								{ __(
									'Default Model',
									'nvoos-pro-spa'
								) }
							</label>
							<input
								id="nvoos-pro-spa-settings-model"
								type="text"
								className="nvoos-pro-spa-settings-form__input"
								value={ form.default_model }
								onChange={ ( e ) =>
									handleChange(
										'default_model',
										e.target.value
									)
								}
								placeholder={
									form.default_provider ===
									'openai'
										? 'gpt-4o'
										: form.default_provider ===
										  'google'
										? 'gemini-2.5-flash'
										: form.default_provider ===
										  'anthropic'
										? 'claude-sonnet-4-20250514'
										: ''
								}
							/>
						</div>
					</div>

					<div className="nvoos-pro-spa-settings-form__row">
						<div className="nvoos-pro-spa-settings-form__field">
							<label
								htmlFor="nvoos-pro-spa-settings-max-tokens"
								className="nvoos-pro-spa-settings-form__label"
							>
								{ __(
									'Max Tokens',
									'nvoos-pro-spa'
								) }
							</label>
							<input
								id="nvoos-pro-spa-settings-max-tokens"
								type="number"
								className="nvoos-pro-spa-settings-form__input"
								value={ form.max_tokens }
								onChange={ ( e ) =>
									handleChange(
										'max_tokens',
										e.target.value
									)
								}
								min="1"
								max="131072"
							/>
						</div>

						<div className="nvoos-pro-spa-settings-form__field">
							<label
								htmlFor="nvoos-pro-spa-settings-temperature"
								className="nvoos-pro-spa-settings-form__label"
							>
								{ __(
									'Temperature',
									'nvoos-pro-spa'
								) }
							</label>
							<input
								id="nvoos-pro-spa-settings-temperature"
								type="number"
								className="nvoos-pro-spa-settings-form__input"
								value={ form.temperature }
								onChange={ ( e ) =>
									handleChange(
										'temperature',
										e.target.value
									)
								}
								min="0"
								max="2"
								step="0.1"
							/>
						</div>
					</div>
				</fieldset>

				<fieldset className="nvoos-pro-spa-settings-form__section">
					<legend className="nvoos-pro-spa-settings-form__section-title">
						{ __( 'Debugging', 'nvoos-pro-spa' ) }
					</legend>

					<div className="nvoos-pro-spa-settings-form__field nvoos-pro-spa-settings-form__field--checkbox">
						<label className="nvoos-pro-spa-settings-form__checkbox-label">
							<input
								type="checkbox"
								className="nvoos-pro-spa-settings-form__checkbox"
								checked={ form.enable_logging }
								onChange={ ( e ) =>
									handleChange(
										'enable_logging',
										e.target.checked
									)
								}
							/>
							<span className="nvoos-pro-spa-settings-form__checkbox-text">
								{ __(
									'Enable logging',
									'nvoos-pro-spa'
								) }
							</span>
						</label>
						<p className="nvoos-pro-spa-settings-form__help">
							{ __(
								'Log API requests, tool executions, and errors for debugging purposes.',
								'nvoos-pro-spa'
							) }
						</p>
					</div>
				</fieldset>

				<div className="nvoos-pro-spa-settings-form__actions">
					<button
						type="submit"
						className="nvoos-pro-spa-settings-form__save-btn"
						disabled={ saving || ! dirty }
					>
						{ saving
							? __( 'Saving…', 'nvoos-pro-spa' )
							: __( 'Save Settings', 'nvoos-pro-spa' ) }
					</button>
					<button
						type="button"
						className="nvoos-pro-spa-settings-form__reset-btn"
						onClick={ handleReset }
						disabled={ saving || ! dirty }
					>
						{ __( 'Reset', 'nvoos-pro-spa' ) }
					</button>
				</div>
			</form>
		</div>
	);
}
