import type { SequenceSchema } from './sequence-field-schema';
export type ResolveValue = (key: string) => unknown;
export declare const flattenActiveSchema: (schema: SequenceSchema, resolve: ResolveValue) => SequenceSchema;
export declare const getFlatSchemaWithAllKeys: (schema: SequenceSchema) => SequenceSchema;
