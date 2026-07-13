/**
 * VectorStoreIndicator — Subtle status badge showing whether the current
 * assistant's vector store / knowledge base is ready, syncing, or in error.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.1.0
 */

import { type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useVectorStoreStatus } from '../../hooks/useVectorStoreStatus';

export interface VectorStoreIndicatorProps {
	apiRoot: string;
	nonce: string;
	assistantId: number;
}

export function VectorStoreIndicator( props: VectorStoreIndicatorProps ): JSX.Element | null {
	const { apiRoot, nonce, assistantId } = props;
	const vss = useVectorStoreStatus( apiRoot, nonce, assistantId );

	if ( assistantId <= 0 ) {
		return null;
	}

	let cls = 'nvoos-pro-spa-sidebar__vs-status';
	let dot = '';
	let label = '';

	if ( vss.loading ) {
		cls += ' nvoos-pro-spa-sidebar__vs-status--loading';
		dot = '\u25CF'; // ●
		label = __( 'Syncing…', 'nvoos-pro-spa' );
	} else if ( vss.ready ) {
		cls += ' nvoos-pro-spa-sidebar__vs-status--ready';
		dot = '\u25CF'; // ●
		label = __( 'Ready', 'nvoos-pro-spa' );
	} else if ( vss.error ) {
		cls += ' nvoos-pro-spa-sidebar__vs-status--error';
		dot = '\u25CF'; // ●
		label = __( 'Error', 'nvoos-pro-spa' );
	}

	if ( ! label ) {
		return null;
	}

	const title = vss.loading
		? __( 'Vector store is syncing…', 'nvoos-pro-spa' )
		: vss.ready && vss.name
		? sprintf(
			__( 'Vector store ready: %1$s (%2$d files)', 'nvoos-pro-spa' ),
			vss.name,
			vss.fileCount ?? 0,
		  )
		: vss.error
		? sprintf( __( 'Vector store error: %s', 'nvoos-pro-spa' ), vss.error )
		: '';

	return (
		<div className={ cls } title={ title }>
			<span className="nvoos-pro-spa-sidebar__vs-dot" aria-hidden="true">
				{ dot }
			</span>
			<span className="nvoos-pro-spa-sidebar__vs-label">
				{ label }
			</span>
		</div>
	);
}
