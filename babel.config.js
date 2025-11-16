/**
 * Babel configuration for Jest test environment
 *
 * @package WP_MCP_AI
 */

module.exports = {
	presets: [
		[
			'@babel/preset-env',
			{
				targets: {
					node: 'current',
				},
			},
		],
	],
};
