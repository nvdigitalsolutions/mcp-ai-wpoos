/**
 * Entry point to encode a JSON object to the rest.li spec with URL encoding.
 *
 * NOTES:
 * - `undefined` values will be removed from the passed in JSON.
 * - `null` values will be turned into the string 'null'.
 * - `true` values will be turned into the string 'true'.
 * - `false` values will be turned into the string 'false'.
 */
export declare function encode(value: any): string;
/**
 * Entry point to encode a JSON object to the rest.li spec with body encoding.
 */
export declare function reducedEncode(value: any): string;
/**
 * Entry point for serializing an arbitrary map of rest.li objects to querystring
 */
export declare function paramEncode(json: unknown): string;
