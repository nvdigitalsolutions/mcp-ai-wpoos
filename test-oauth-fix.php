#!/usr/bin/env php
<?php
/**
 * Test script to verify League OAuth2 Client doesn't send approval_prompt parameter.
 */

require_once __DIR__ . '/vendor/autoload.php';

echo "Testing League OAuth2 Client authorization URL generation...\n\n";

if ( ! class_exists( '\League\OAuth2\Client\Provider\GenericProvider' ) ) {
	echo "ERROR: League OAuth2 Client is not available\n";
	exit( 1 );
}

$provider = new \League\OAuth2\Client\Provider\GenericProvider(
	array(
		'clientId'                => 'test-client-id',
		'clientSecret'            => 'test-client-secret',
		'redirectUri'             => 'https://example.com/callback',
		'urlAuthorize'            => 'https://accounts.google.com/o/oauth2/v2/auth',
		'urlAccessToken'          => 'https://oauth2.googleapis.com/token',
		'urlResourceOwnerDetails' => 'https://www.googleapis.com/oauth2/v1/userinfo',
		'scopes'                  => 'https://www.googleapis.com/auth/gmail.readonly',
	)
);

// Get authorization URL with prompt=consent.
$authorize_url = $provider->getAuthorizationUrl(
	array(
		'state'                  => 'test-state-12345',
		'access_type'            => 'offline',
		'include_granted_scopes' => 'true',
		'prompt'                 => 'consent',
	)
);

echo "Generated Authorization URL:\n";
echo $authorize_url . "\n\n";

// Parse the URL to check parameters.
$parsed = parse_url( $authorize_url );

if ( ! isset( $parsed['query'] ) ) {
	echo "ERROR: No query parameters in URL\n";
	exit( 1 );
}

parse_str( $parsed['query'], $query_params );

echo "Query Parameters:\n";
foreach ( $query_params as $key => $value ) {
	echo "  $key = $value\n";
}

echo "\n";

// Critical checks.
$has_errors = false;

if ( isset( $query_params['approval_prompt'] ) ) {
	echo "❌ FAIL: approval_prompt parameter is present (should be removed)\n";
	$has_errors = true;
} else {
	echo "✅ PASS: approval_prompt parameter is NOT present\n";
}

if ( isset( $query_params['prompt'] ) && $query_params['prompt'] === 'consent' ) {
	echo "✅ PASS: prompt parameter is present with value 'consent'\n";
} else {
	echo "❌ FAIL: prompt parameter is missing or has wrong value\n";
	$has_errors = true;
}

if ( isset( $query_params['response_type'] ) && $query_params['response_type'] === 'code' ) {
	echo "✅ PASS: response_type parameter is present with value 'code'\n";
} else {
	echo "❌ FAIL: response_type parameter is missing or has wrong value\n";
	$has_errors = true;
}

if ( isset( $query_params['access_type'] ) && $query_params['access_type'] === 'offline' ) {
	echo "✅ PASS: access_type parameter is present with value 'offline'\n";
} else {
	echo "❌ FAIL: access_type parameter is missing or has wrong value\n";
	$has_errors = true;
}

echo "\n";

if ( $has_errors ) {
	echo "TEST FAILED: There were errors\n";
	exit( 1 );
} else {
	echo "TEST PASSED: All checks succeeded\n";
	exit( 0 );
}
