import { useWizardStore } from '../state/wizardStore';
import { Field } from '../components/Field';

export function AssistantStep() {
	const assistant = useWizardStore( ( state ) => state.assistant );
	const patch = useWizardStore( ( state ) => state.patchAssistant );

	return (
		<section aria-labelledby="step-assistant">
			<h2 id="step-assistant">Tune the assistant</h2>
			<p className="lede">
				This prompt ships inside the bundle. Credentials never travel in blueprints —
				tokens are minted on the destination site.
			</p>
			<Field label="System prompt" htmlFor="assistant-prompt">
				<textarea
					id="assistant-prompt"
					rows={ 8 }
					value={ assistant.systemPrompt }
					onChange={ ( event ) => patch( { systemPrompt: event.target.value } ) }
				/>
			</Field>
			<div className="grid grid--2">
				<Field label="Model" htmlFor="assistant-model" hint="Provider model id, e.g. gpt-4o-mini.">
					<input
						id="assistant-model"
						value={ assistant.model }
						onChange={ ( event ) => patch( { model: event.target.value } ) }
					/>
				</Field>
				<Field
					label={ `Temperature: ${ assistant.temperature.toFixed( 1 ) }` }
					htmlFor="assistant-temperature"
					hint="0 = deterministic, 2 = exploratory."
				>
					<input
						id="assistant-temperature"
						type="range"
						min="0"
						max="2"
						step="0.1"
						value={ assistant.temperature }
						onChange={ ( event ) => patch( { temperature: Number( event.target.value ) } ) }
					/>
				</Field>
			</div>
		</section>
	);
}
