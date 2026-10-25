/**
 * SettingsPage — schema-driven plugin settings form (2.4.0, Proposal 084
 * Phase 2).
 *
 * Thin wrapper over the reusable SettingsForm (extracted for Proposal 089
 * Phase 2b — the commerce toolkit pages reuse the same form narrowed to
 * their schema tab). All rendering lives in features/settings/SettingsForm.
 */

import { __ } from '@wordpress/i18n';
import type { JSX } from 'react';
import { SettingsForm } from './SettingsForm';

export function SettingsPage(): JSX.Element {
	return (
		<SettingsForm
			title={ __( 'Settings', 'nvoos-pro-spa' ) }
			subtitle={ __( 'Site-wide NV oOS configuration.', 'nvoos-pro-spa' ) }
		/>
	);
}
