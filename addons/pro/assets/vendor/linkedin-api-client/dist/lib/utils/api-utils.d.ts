/**
 * Utilities related to working with LinkedIn's APIs
 */
/**
 * Method to build the URL (not including query parameters) for a REST-based API call to LinkedIn
 */
export declare function buildRestliUrl(resourcePath: string, pathKeys?: Record<string, any>, versionString?: string): string;
export declare function getRestliRequestHeaders({ restliMethodType, accessToken, versionString, httpMethodOverride, contentType }: {
    restliMethodType: string;
    accessToken: string;
    versionString?: string | null;
    httpMethodOverride?: string;
    contentType?: string;
}): Record<string, any>;
