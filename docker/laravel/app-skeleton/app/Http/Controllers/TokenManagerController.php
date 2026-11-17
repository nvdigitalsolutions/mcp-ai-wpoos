<?php

namespace App\Http\Controllers;

use App\Support\EstateCredentials;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token manager (`/api/v1/token-manager`).
 *
 * Manages the credentials issued by `AuthProvider::issueCredential()`, which
 * are Sanctum personal access tokens named `oos-assistant-{id}`.
 *
 * The mapping and the estate scoping live in App\Support\EstateCredentials
 * because the read-only `/admin/credentials` page (149 §24) reads and revokes
 * the same rows. This controller is the HTTP envelope plus the console's error
 * vocabulary; two mappers would drift, and a second revoke path would drift on
 * the one rule that matters (a credential must never be revocable across
 * estates).
 *
 * That rule, and the rest of the security posture, is documented on
 * `EstateCredentials`: the token hash is never selected, revocation deletes the
 * row, expired tokens report as revoked, and both the listing and the revoke
 * path resolve the estate's assistant ids first.
 *
 * This surface is assistant credentials, not personal access tokens. The
 * ownership matrix used to label the `tokenManager` key "personal access
 * tokens"; §19 corrects the contract to say what the console actually serves,
 * and `tokens.personal` remains a separate, unbuilt row.
 *
 * Restrictions (`TokenRestriction`) have no store on this runtime: the rate
 * limiter is window-based and keeps no persisted block, so the list is empty
 * and `lift` answers 404. That is honest rather than invented.
 */
class TokenManagerController
{
    public function overview(): JsonResponse
    {
        return response()->json(array(
            'success' => true,
            'data' => EstateCredentials::overview(TenantContext::current()),
        ));
    }

    public function revoke(int $assistant, string $credential): JsonResponse
    {
        return $this->destroy($assistant, $credential, 'revoked');
    }

    public function delete(int $assistant, string $credential): JsonResponse
    {
        return $this->destroy($assistant, $credential, 'deleted');
    }

    public function liftRestriction(Request $request, int $user): JsonResponse
    {
        return response()->json(array(
            'success' => false,
            'code' => 'restriction_not_found',
            'message' => 'This runtime keeps no persisted token restrictions.',
        ), 404);
    }

    /**
     * Delete a credential, refusing ids that belong to another assistant or
     * another estate.
     *
     * A non-numeric id is answered with the same 404 as a well-formed id that
     * belongs elsewhere: distinguishing them would confirm which ids exist.
     */
    private function destroy(int $assistant, string $credential, string $verb): JsonResponse
    {
        if (! ctype_digit($credential)) {
            return $this->missing();
        }

        if (! EstateCredentials::revoke(TenantContext::current(), $assistant, (int) $credential)) {
            return $this->missing();
        }

        return response()->json(array(
            'success' => true,
            'data' => array($verb => true),
        ));
    }

    private function missing(): JsonResponse
    {
        return response()->json(array(
            'success' => false,
            'code' => 'credential_not_found',
            'message' => 'No credential with that id belongs to this assistant.',
        ), 404);
    }
}
