type StringOrStringObject = string | string[] | Record<string, string>;
export declare function paramDecode(querystring: string): Record<string, StringOrStringObject>;
/**
 * Entry point to decode a URL encoded rest.li object.
 *
 * NOTES:
 * - The Rest.li format is lossy. All values come out of this as strings.
 */
export declare function decode(serializedrestli: string): StringOrStringObject;
/**
 * Entry point to decode a body encoded rest.li object.
 */
export declare function reducedDecode(serializedrestli: string): StringOrStringObject;
export {};
