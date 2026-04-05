/**
 * Telegram Mini App Context
 *
 * Provides the `Telegram.WebApp` object to the component tree and applies the
 * current Telegram theme parameters to CSS custom properties. Components can
 * read `useTMA()` to access haptic feedback helpers and user data.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import {
	createContext,
	useContext,
	useEffect,
	useState,
} from 'react';

/** @type {React.Context<{twa: object|null, user: object|null, haptic: Function}>} */
const TMAContext = createContext( {
	twa: null,
	user: null,
	haptic: () => {},
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
 * @param {{ children: React.ReactNode }} props
 * @return {JSX.Element}
 */
export function TMAProvider( { children } ) {
	const [ twa ] = useState(
		() => ( window.Telegram?.WebApp ) ?? null
	);

	// Derive Telegram user from initDataUnsafe.
	const user = twa?.initDataUnsafe?.user ?? null;

	useEffect( () => {
		if ( ! twa ) {
			return;
		}
		applyTheme( twa.themeParams );
		updateVH( twa );

		twa.onEvent( 'themeChanged', () => applyTheme( twa.themeParams ) );
		twa.onEvent( 'viewportChanged', () => updateVH( twa ) );
		window.addEventListener( 'resize', () => updateVH( twa ) );

		twa.ready();
		twa.expand();
	}, [ twa ] );

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
		<TMAContext.Provider value={ { twa, user, haptic } }>
			{ children }
		</TMAContext.Provider>
	);
}

/**
 * Hook to consume the TMA context.
 *
 * @return {{ twa: object|null, user: object|null, haptic: Function }}
 */
export function useTMA() {
	return useContext( TMAContext );
}
