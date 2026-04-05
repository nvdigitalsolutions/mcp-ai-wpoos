/**
 * LoadingSpinner component
 *
 * Renders a centred CSS spinner that matches the current Telegram theme.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

/**
 * @param {{ size?: number }} props
 * @return {JSX.Element}
 */
export default function LoadingSpinner( { size = 32 } ) {
	return (
		<div className="tma-shopify-spinner-wrap">
			<span
				className="tma-shopify-spinner"
				style={ { width: size, height: size } }
				aria-label="Loading"
			/>
		</div>
	);
}
