/**
 * Manifest type definitions shared by SPA components.
 *
 * @since 0.1.0
 */

export interface Field {
	name: string;
	type: string;
	label: string;
	required: boolean;
	readonly: boolean;
	options?: string[];
	reference?: string;
}

export interface Resource {
	name: string;
	label: string;
	endpoint: string;
	primary_key: string;
	fields: Field[];
}

export interface View {
	name: string;
	type: 'table' | 'kanban' | 'detail' | 'form' | 'calendar' | 'chart';
	resource: string;
	default: boolean;
	group_by?: string;
	label?: string;
}

export interface Manifest {
	version: string;
	toolkit: string;
	label: string;
	icon: string;
	rest_namespace: string;
	capability: string;
	resources: Resource[];
	views: View[];
}
