/**
 * TMATemplateBuilder fixture for React Cosmos.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { TMATemplateBuilder } from '../components/TMATemplateBuilder';
import type { TemplateBuilderConfig } from '../../shared/types';

const mockConfig: TemplateBuilderConfig = {
	ajaxUrl: '/wp-admin/admin-ajax.php',
	nonce: 'abc123',
	templatesUrl: '/wp-json/mcp-ai/v1/tma-templates',
	saveUrl: '/wp-json/mcp-ai/v1/tma-templates/save',
	activeTemplate: 'default',
	previewBaseUrl: 'https://t.me/nv_oos_bot/app',
	customizeUrl: '',
};

export default {
	default: <TMATemplateBuilder config={ mockConfig } />,
	embedded: <TMATemplateBuilder config={ mockConfig } embeddedMode={ true } connectionId="test-conn" />,
};
