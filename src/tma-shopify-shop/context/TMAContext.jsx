/**
 * Telegram Mini App Context
 *
 * Provides the `Telegram.WebApp` object to the component tree and applies the
 * current Telegram theme parameters to CSS custom properties.  Components can
 * read `useTMA()` to access haptic feedback helpers, user data, and – crucially
 * – the `authReady` flag that indicates whether session validation has finished.
 *
 * On mount the provider calls the `/validate` endpoint (mirrors the
 * `ecInitSession()` pattern used by the inline ecommerce template) so that
 * subsequent tool-execution requests carry a valid TMA session token.  Without
 * this step, `check_permission()` in the controller returns 403 because
 * Telegram WebView does not share WordPress auth cookies.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import {
	createContext,
	useContext,
	useEffect,
	useState,
} from 'react';
import { validateInitData, setTmaToken, setNonce } from '../api/client';

/** @type {React.Context<{twa: object|null, user: object|null, haptic: Function, authReady: boolean}>} */
const TMAContext = createContext( {
	twa: null,
	user: null,
	haptic: () => {},
	authReady: false,
} );

/**
 * Apply Telegram theme params to CSS custom properties.
 *
 * @param {object} tp Telegram themeParams object.
 */
function applyTheme( tp ) {
	if ( ! tp ) {
		return;
	}
	const map = {
		'--tma-bg': tp.bg_color,
		'--tma-text': tp.text_color,
		'--tma-hint': tp.hint_color,
		'--tma-link': tp.link_color,
		'--tma-btn': tp.button_color,
		'--tma-btn-text': tp.button_text_color,
		'--tma-secondary-bg': tp.secondary_bg_color,
		'--tma-header-bg': tp.header_bg_color,
		'--tma-accent': tp.accent_text_color,
		'--tma-section-bg': tp.section_bg_color,
		'--tma-border': tp.section_separator_color,
	};
	const root = document.documentElement;
	Object.entries( map ).forEach( ( [ prop, value ] ) => {
		if ( value ) {
			root.style.setProperty( prop, value );
		}
	} );
	if ( tp.bg_color ) {
		document.body.style.background = tp.bg_color;
	}
}

/**
 * Update the --tma-vh CSS variable to match the stable viewport height.
 *
 * @param {object|null} twa Telegram WebApp instance.
 */
function updateVH( twa ) {
	const h = twa ? twa.viewportStableHeight : window.innerHeight;
	document.documentElement.style.setProperty( '--tma-vh', h + 'px' );
}

/**
 * TMAProvider – wraps the app and keeps Telegram context up-to-date.
 *
 * On mount it:
 *  1. Applies the Telegram theme and viewport helpers.
 *  2. Calls `/validate` with `initData` to obtain a TMA session token and a
 *     fresh WordPress nonce.  This mirrors `ecInitSession()` in the inline
 *     ecommerce template.
 *  3. Sets `authReady = true` once validation completes (or is skipped when
 *     running outside Telegram, e.g. during development).
 *
 * Child hooks should gate data-loading on `authReady` so that tool-execution
 * requests are not fired before the session token is available.
 *
 * @param {{ children: React.ReactNode }} props
 * @return {JSX.Element}
 */
export function TMAProvider( { children } ) {
	const [ twa ] = useState(
		() => ( window.Telegram?.WebApp ) ?? null
	);
	const [ authReady, setAuthReady ] = useState( false );

	// Derive Telegram user from initDataUnsafe.
	const user = twa?.initDataUnsafe?.user ?? null;

	useEffect( () => {
		if ( ! twa ) {
			// Not inside Telegram – skip validation, mark ready immediately.
			setAuthReady( true );
			return;
		}
		applyTheme( twa.themeParams );
		updateVH( twa );

		twa.onEvent( 'themeChanged', () => applyTheme( twa.themeParams ) );
		twa.onEvent( 'viewportChanged', () => updateVH( twa ) );
		window.addEventListener( 'resize', () => updateVH( twa ) );

		twa.ready();
		twa.expand();

		// Validate Telegram initData and obtain a TMA session token.
		// This must complete before any tool-execution calls are made.
		validateInitData()
			.then( ( res ) => {
				if ( res ) {
					if ( res.tma_token ) {
						setTmaToken( res.tma_token );
					}
					if ( res.nonce || res.wp_nonce ) {
						setNonce( res.nonce || res.wp_nonce );
					}
				}
			} )
			.catch( () => {
				// Validation failed – proceed anyway; nonce auth may still work.
			} )
			.finally( () => {
				setAuthReady( true );
			} );
	}, [ twa ] ); // eslint-disable-line react-hooks/exhaustive-deps

	/** @param {'light'|'medium'|'heavy'|'rigid'|'soft'|'selectionChanged'|'success'|'error'|'warning'} type */
	const haptic = ( type = 'light' ) => {
		const hf = twa?.HapticFeedback;
		if ( ! hf ) {
			return;
		}
		if ( type === 'selectionChanged' ) {
			hf.selectionChanged();
		} else if ( [ 'success', 'error', 'warning' ].includes( type ) ) {
			hf.notificationOccurred( type );
		} else {
			hf.impactOccurred( type );
		}
	};

	return (
		<TMAContext.Provider value={ { twa, user, haptic, authReady } }>
			{ children }
		</TMAContext.Provider>
	);
}

/**
 * Hook to consume the TMA context.
 *
 * @return {{ twa: object|null, user: object|null, haptic: Function, authReady: boolean }}
 */
export function useTMA() {
	return useContext( TMAContext );
}
