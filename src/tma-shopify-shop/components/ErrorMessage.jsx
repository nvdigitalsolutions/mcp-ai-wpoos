/**
 * ErrorMessage component
 *
 * Displays an inline error banner with an optional retry action.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

/**
 * @param {{ message:string, onRetry?: Function }} props
 * @return {JSX.Element}
 */
export default function ErrorMessage( { message, onRetry } ) {
	return (
		<div className="tma-shopify-error">
			<span className="tma-shopify-error__icon">⚠️</span>
			<span className="tma-shopify-error__text">{ message }</span>
			{ onRetry && (
				<button className="tma-shopify-error__retry" onClick={ onRetry }>
					Retry
				</button>
			) }
		</div>
	);
}
