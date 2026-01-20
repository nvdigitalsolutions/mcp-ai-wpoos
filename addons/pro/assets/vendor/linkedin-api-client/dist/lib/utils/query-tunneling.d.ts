/**
 * Utils related to query tunneling
 */
export declare function isQueryTunnelingRequired(encodedQueryParamString: string): boolean;
export declare function maybeApplyQueryTunnelingToRequestsWithoutBody({ encodedQueryParamString, urlPath, originalRestliMethod, accessToken, versionString, additionalConfig }: {
    encodedQueryParamString: any;
    urlPath: any;
    originalRestliMethod: any;
    accessToken: any;
    versionString: any;
    additionalConfig?: {};
}): any;
export declare function maybeApplyQueryTunnelingToRequestsWithBody({ encodedQueryParamString, urlPath, originalRestliMethod, originalJSONRequestBody, accessToken, versionString, additionalConfig }: {
    encodedQueryParamString: any;
    urlPath: any;
    originalRestliMethod: any;
    originalJSONRequestBody: any;
    accessToken: any;
    versionString: any;
    additionalConfig?: {};
}): any;
