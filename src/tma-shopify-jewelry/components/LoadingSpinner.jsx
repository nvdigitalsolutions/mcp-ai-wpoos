/**
 * LoadingSpinner component
 *
 * Renders a centred CSS spinner that adapts to the current Telegram theme.
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
		<div className="tma-jw-spinner-wrap">
			<span
				className="tma-jw-spinner"
				style={ { width: size, height: size } }
				aria-label="Loading"
			/>
		</div>
	);
}
