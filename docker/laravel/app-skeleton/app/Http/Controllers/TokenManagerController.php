<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Token manager (`/api/v1/token-manager`).
 *
 * Manages the credentials issued by `AuthProvider::issueCredential()`, which
 * are Sanctum personal access tokens named `oos-assistant-{id}`.
 *
 * Security rules this endpoint follows:
 *
 * - **The token hash is never read or returned.** Only metadata columns are
 *   selected; the `token` column never leaves the database.
 * - **Revocation deletes the row.** Sanctum resolves the bearer token from
 *   `personal_access_tokens` on every request, so a deleted row stops working
 *   immediately. A soft-revoked row would keep authenticating — a credential
 *   that *looks* revoked but still works is the failure mode worth avoiding,
 *   so this runtime has no separate revocation state and `revoked_at` is
 *   always empty.
 * - **Expired tokens are reported as revoked.** Sanctum rejects them, so the
 *   status is truthful; `revoked_at` carries the expiry timestamp.
 * - **Revocation is scoped to the assistant in the URL.** A credential whose
 *   name does not name that assistant answers 404, so the route cannot be
 *   used to revoke another assistant's token by guessing an id.
 *
 * Only tokens named `oos-assistant-*` are listed. Other Sanctum tokens in the
 * table belong to unrelated features and are none of this page's business.
 *
 * Restrictions (`TokenRestriction`) have no store on this runtime: the
 * rate limiter is window-based and keeps no persisted block, so the list is
 * empty and `lift` answers 404. That is honest rather than invented.
 */
class TokenManagerController
{
    private const NAME_PATTERN = '/^oos-assistant-(\d+)$/';

    public function overview(): JsonResponse
    {
        $rows = DB::table( 'personal_access_tokens' )
            ->where( 'name', 'like', 'oos-assistant-%' )
            ->orderByDesc( 'created_at' )
            ->get( array(
                'id',
                'tokenable_id',
                'name',
                'last_used_at',
                'expires_at',
                'created_at',
            ) );

        $assistantIds = array();
        $userIds = array();

        foreach ( $rows as $row ) {
            $assistantId = $this->assistantIdFrom( (string) $row->name );

            if ( $assistantId > 0 ) {
                $assistantIds[ $assistantId ] = true;
            }

            $userIds[ (int) $row->tokenable_id ] = true;
        }

        $titles = array() === $assistantIds
            ? array()
            : Assistant::query()->whereIn( 'id', array_keys( $assistantIds ) )->pluck( 'title', 'id' )->all();

        $names = array() === $userIds
            ? array()
            : User::query()->whereIn( 'id', array_keys( $userIds ) )->pluck( 'name', 'id' )->all();

        $now = now();
        $credentials = array();
        $active = 0;
        $revoked = 0;
        $assistants = array();

        foreach ( $rows as $row ) {
            $assistantId = $this->assistantIdFrom( (string) $row->name );
            $expiresAt = $row->expires_at ? (string) $row->expires_at : '';
            $expired = '' !== $expiresAt && strtotime( $expiresAt ) <= $now->getTimestamp();

            if ( $expired ) {
                ++$revoked;
            } else {
                ++$active;
            }

            if ( $assistantId > 0 ) {
                $assistants[ $assistantId ] = true;
            }

            $credentials[] = array(
                'id' => (string) $row->id,
                'status' => $expired ? 'revoked' : 'active',
                'assistant_id' => $assistantId,
                'assistant_title' => (string) ( $titles[ $assistantId ] ?? '' ),
                // ISO-8601, as the console's other timestamps are.
                'created_at' => $this->iso( (string) $row->created_at ),
                'created_by' => (int) $row->tokenable_id,
                'created_by_name' => (string) ( $names[ (int) $row->tokenable_id ] ?? '' ),
                // No revocation state exists: revocation is deletion.
                'revoked_at' => $expired ? $this->iso( $expiresAt ) : '',
                'revoked_by' => 0,
                'expires_at' => $this->iso( $expiresAt ),
            );
        }

        return response()->json( array(
            'success' => true,
            'data' => array(
                'stats' => array(
                    'total' => count( $credentials ),
                    'active' => $active,
                    'revoked' => $revoked,
                    'assistants' => count( $assistants ),
                ),
                'credentials' => $credentials,
                'restrictions' => array(),
            ),
        ) );
    }

    public function revoke( int $assistant, string $credential ): JsonResponse
    {
        return $this->destroy( $assistant, $credential, 'revoked' );
    }

    public function delete( int $assistant, string $credential ): JsonResponse
    {
        return $this->destroy( $assistant, $credential, 'deleted' );
    }

    public function liftRestriction( Request $request, int $user ): JsonResponse
    {
        return response()->json( array(
            'success' => false,
            'code' => 'restriction_not_found',
            'message' => 'This runtime keeps no persisted token restrictions.',
        ), 404 );
    }

    /**
     * Delete a credential, refusing ids that belong to another assistant.
     */
    private function destroy( int $assistant, string $credential, string $verb ): JsonResponse
    {
        if ( ! ctype_digit( $credential ) ) {
            return $this->missing();
        }

        $row = DB::table( 'personal_access_tokens' )
            ->where( 'id', (int) $credential )
            ->where( 'name', 'like', 'oos-assistant-%' )
            ->first( array( 'id', 'name' ) );

        if ( null === $row || $this->assistantIdFrom( (string) $row->name ) !== $assistant ) {
            return $this->missing();
        }

        DB::table( 'personal_access_tokens' )->where( 'id', (int) $row->id )->delete();

        return response()->json( array(
            'success' => true,
            'data' => array( $verb => true ),
        ) );
    }

    private function missing(): JsonResponse
    {
        return response()->json( array(
            'success' => false,
            'code' => 'credential_not_found',
            'message' => 'No credential with that id belongs to this assistant.',
        ), 404 );
    }

    private function assistantIdFrom( string $name ): int
    {
        return 1 === preg_match( self::NAME_PATTERN, $name, $matches )
            ? (int) $matches[1]
            : 0;
    }

    private function iso( string $value ): string
    {
        if ( '' === $value ) {
            return '';
        }

        $timestamp = strtotime( $value );

        return false === $timestamp ? '' : gmdate( 'c', $timestamp );
    }
}
