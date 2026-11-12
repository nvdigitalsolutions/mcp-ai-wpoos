import { useWizardStore } from '../state/wizardStore';
import { Field } from '../components/Field';

export function CommerceStep() {
	const commerce = useWizardStore( ( state ) => state.commerce );
	const patch = useWizardStore( ( state ) => state.patchCommerce );

	return (
		<section aria-labelledby="step-commerce">
			<h2 id="step-commerce">Link commerce</h2>
			<p className="lede">
				You keep your own Stripe product. The Exchange stores the price pointer and
				gates catalogue visibility on your platform subscription — it never touches
				customer payments.
			</p>
			<div className="grid grid--2">
				<Field
					label="Stripe price id"
					htmlFor="commerce-price"
					hint="Optional at deploy; required before the estate can be listed."
				>
					<input
						id="commerce-price"
						value={ commerce.priceId }
						onChange={ ( event ) => patch( { priceId: event.target.value } ) }
						placeholder="price_1234567890"
					/>
				</Field>
				<Field label="Trial days" htmlFor="commerce-trial">
					<input
						id="commerce-trial"
						type="number"
						min="0"
						max="90"
						value={ commerce.trialDays }
						onChange={ ( event ) => patch( { trialDays: Number( event.target.value ) } ) }
					/>
				</Field>
			</div>
		</section>
	);
}
