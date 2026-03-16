/**
 * ErrorMessage component
 *
 * Displays an inline error banner with an optional retry action.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

/**
 * @param {{ message:string, onRetry?: Function }} props
 * @return {JSX.Element}
 */
export default function ErrorMessage( { message, onRetry } ) {
	return (
		<div className="tma-woo-error">
			<span className="tma-woo-error__icon">⚠️</span>
			<span className="tma-woo-error__text">{ message }</span>
			{ onRetry && (
				<button className="tma-woo-error__retry" onClick={ onRetry }>
					Retry
				</button>
			) }
		</div>
	);
}
