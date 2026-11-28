// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview The toolkit `console` screen — a dispatcher.
 *
 * The engine owns one generic frame and one tab id (`console`); the panel is
 * toolkit-specific because a console is, by definition, the surface that knows
 * the toolkit's own resources. `database` is the first family to declare one.
 * A second family adds a branch here rather than a new screen id, which keeps
 * the manifest vocabulary small and the dispatch explicit.
 *
 * @package NV_oOS_Pro_Spa
 * @since   3.2.0
 */

import type { JSX } from 'react';
import { __ } from '@wordpress/i18n';

import type { ToolkitScreenProps } from '../ToolkitPage';
import { DatabaseConsole } from '../database/DatabaseConsole';
import { Notice } from '../database/shared';

export function ConsoleScreen( { manifest }: ToolkitScreenProps ): JSX.Element {
	if ( manifest.slug === 'database' ) {
		return <DatabaseConsole />;
	}

	return (
		<Notice tone="info" title={ __( 'No console is available for this toolkit', 'nvoos-pro-spa' ) }>
			<p>
				{ __(
					'The manifest declares a console screen, but the console plugin does not implement one for this family yet.',
					'nvoos-pro-spa'
				) }
			</p>
		</Notice>
	);
}
