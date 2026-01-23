/**
 * Generates the member authorization URL to redirect users to in order to
 * authorize the requested scopes for an application.
 */
export declare function generateMemberAuthorizationUrl(params: {
    clientId: string;
    redirectUrl: string;
    scopes: string[];
    state?: string;
}): string;
