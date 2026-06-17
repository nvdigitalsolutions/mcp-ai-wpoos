/**
 * Browser-native AI tool registry powered by Transformers.js.
 * @package @nvdigitalsolutions/nvoos-client-tools
 */

export interface ClientToolParameters {
	type: 'object';
	properties: Record<string, unknown>;
	required?: string[];
}

export interface ClientTool {
	name: string;
	description: string;
	parameters: ClientToolParameters;
	execute(args: Record<string, any>): Promise<any>;
}

export interface ClientToolsConfig {
	/** Transformers.js pipeline factory function. */
	pipeline?: (...args: any[]) => Promise<any>;
}

/** Configure the registry (inject a pipeline factory). */
export declare function configure(options: ClientToolsConfig): void;

/** Get the full tool registry as an object keyed by tool name. */
export declare function getTools(): Record<string, ClientTool>;

/** Get a single tool definition by name. */
export declare function getTool(name: string): ClientTool | null;

/** Execute a tool by name with the provided arguments. */
export declare function executeTool(name: string, args?: Record<string, any>): Promise<any>;

/** The raw tool registry. */
export declare const CLIENT_TOOLS: Record<string, ClientTool>;

declare const _default: {
	configure: typeof configure;
	getTools: typeof getTools;
	getTool: typeof getTool;
	executeTool: typeof executeTool;
	CLIENT_TOOLS: typeof CLIENT_TOOLS;
};

export default _default;
