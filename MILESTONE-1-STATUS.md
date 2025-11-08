# Milestone 1: REST API Authentication - Implementation Status

**Branch**: `refactor/milestone-1-rest-auth`  
**Status**: 🚧 In Progress (60% complete)  
**Started**: 2025-11-08

## Summary

This milestone extracts authentication logic from the monolithic `WP_MCP_AI_REST` class into a dedicated `WP_MCP_AI_REST_Authenticator` class. This improves:
- **Testability**: Authentication logic can be unit tested in isolation
- **Maintainability**: Easier to modify auth methods without touching REST logic
- **Extensibility**: Adding new auth methods is straightforward
- **Code Organization**: Separates concerns (routing vs authentication)

---

## ✅ Completed Work

### 1. New Authenticator Class Created
**File**: `includes/rest/class-wp-mcp-ai-rest-authenticator.php` (690 lines)

#### Complete Authentication Features:
- ✅ **WordPress Nonce Validation**: Standard WP REST API nonces
- ✅ **Local Assistant Credentials**: Plugin-issued bearer tokens (`cred_xxxxx.SECRET`)
- ✅ **Mesh Network API Keys**: Distributed mesh authentication (`X-WP-MCP-AI-Mesh-Key` header)
- ✅ **Auth0 Bearer Tokens**: Full JWT validation with RS256 signatures
- ✅ **Guest Tokens**: Temporary tokens for public chat surfaces (`X-WP-MCP-AI-Guest` header)

#### Complete Supporting Methods:
- ✅ `reset_auth_context()` - Initialize auth state
- ✅ `mark_token_authenticated()` - Record successful token auth
- ✅ `set_authenticated_user_id()` - Set WordPress user context
- ✅ `maybe_set_current_user()` - Sync global WP user
- ✅ `get_auth_context()` - Retrieve current auth details
- ✅ `validate_local_token()` - Validate assistant credentials
- ✅ `validate_mesh_key()` - Validate mesh API keys
- ✅ `validate_bearer_token()` - Complete Auth0 JWT validation
- ✅ `get_auth0_jwks()` - Fetch and cache Auth0 public keys
- ✅ `insufficient_permissions_error()` - Standard permission error
- ✅ `extract_guest_token()` - Extract guest tokens from request
- ✅ `jwk_to_pem()` - Convert JWK to PEM format for OpenSSL
- ✅ `audience_matches()` - JWT audience claim validation
- ✅ `scope_satisfied()` - JWT scope/permissions validation
- ✅ `base64_url_decode()` - URL-safe base64 decoding
- ✅ `encode_asn1_integer()` - ASN.1 DER encoding
- ✅ `encode_asn1_sequence()` - ASN.1 sequence encoding
- ✅ `encode_asn1_length()` - ASN.1 length encoding
- ✅ `invalid_bearer_error()` - Standard bearer token error

### 2. REST Class Integration Started
**File**: `includes/class-wp-mcp-ai-rest.php`

#### Completed Changes:
- ✅ Added `require_once` for authenticator class
- ✅ Added `$authenticator` property
- ✅ Instantiate authenticator in constructor: `$this->authenticator = new WP_MCP_AI_REST_Authenticator();`

---

## 🚧 Remaining Work

### 3. Update REST Class Methods to Delegate

The following methods in `WP_MCP_AI_REST` need to be updated to delegate to `$this->authenticator`:

#### Auth Context Methods (Lines ~231-311)
```php
// BEFORE (current - direct implementation)
protected function reset_auth_context() {
    $this->auth_context = array( ... );
}

// AFTER (needed - delegate to authenticator)
protected function reset_auth_context() {
    $this->authenticator->reset_auth_context();
    $this->auth_context = $this->authenticator->get_auth_context(); // Sync for BC
}
```

**Methods to Update**:
- [ ] Line 231: `reset_auth_context()` → delegate to `$this->authenticator->reset_auth_context()`
- [ ] Line 247: `mark_token_authenticated()` → delegate to `$this->authenticator->mark_token_authenticated()`
- [ ] Line 279: `set_authenticated_user_id()` → delegate to `$this->authenticator->set_authenticated_user_id()`
- [ ] Line 294: `maybe_set_current_user()` → delegate to `$this->authenticator->maybe_set_current_user()` (or make private in authenticator)
- [ ] Line 305: `get_auth_context()` → delegate to `$this->authenticator->get_auth_context()`

#### Validation Methods (Lines ~2011-2447)
- [ ] Line 2011: `validate_local_token()` → delegate with assistant hint parameter
- [ ] Line 2048: `validate_mesh_key()` → delegate to `$this->authenticator->validate_mesh_key()`
- [ ] Line 2098: `validate_bearer_token()` → delegate to `$this->authenticator->validate_bearer_token()`
- [ ] Line 2392: `get_auth0_jwks()` → delegate (or make protected in authenticator)
- [ ] Line 2455: `jwk_to_pem()` → Remove (only used by bearer validation)
- [ ] Line 2490: `audience_matches()` → Remove (only used by bearer validation)
- [ ] Line 2513: `scope_satisfied()` → Remove (only used by bearer validation)
- [ ] Line 2538: `base64_url_decode()` → Remove (only used by bearer validation)
- [ ] Line 2559: `encode_asn1_integer()` → Remove (only used by JWK conversion)
- [ ] Line 2577: `encode_asn1_sequence()` → Remove (only used by JWK conversion)
- [ ] Line 2587: `encode_asn1_length()` → Remove (only used by JWK conversion)
- [ ] Line 2602: `invalid_bearer_error()` → Remove (only used by bearer validation)

#### Permission/Error Methods
- [ ] Line 1979: `insufficient_permissions_error()` → delegate to `$this->authenticator->insufficient_permissions_error()`
- [ ] Line 6678: `extract_guest_token()` → delegate to `$this->authenticator->extract_guest_token()`

#### Update permissions_check() Method (Line ~1836)
This is the main method that orchestrates authentication. Update to use authenticator:

```php
// Current structure:
public function permissions_check( WP_REST_Request $request ) {
    $this->reset_auth_context();  // ← delegate
    
    $guest_token = $this->extract_guest_token( $request );  // ← delegate
    
    $mesh_validated = $this->validate_mesh_key( $mesh_key );  // ← delegate
    
    $local = $this->validate_local_token( $token, $request );  // ← delegate
    
    $validated = $this->validate_bearer_token( $token, $request );  // ← delegate
    
    $this->set_authenticated_user_id( get_current_user_id() );  // ← delegate
    
    return $this->insufficient_permissions_error( $capability );  // ← delegate
}
```

### 4. Add Unit Tests
**New File**: `tests/rest/test-wp-mcp-ai-rest-authenticator.php`

Tests needed:
- [ ] Test `reset_auth_context()` initializes correctly
- [ ] Test `mark_token_authenticated()` updates context
- [ ] Test `set_authenticated_user_id()` sets user
- [ ] Test `get_auth_context()` returns current state
- [ ] Test `validate_local_token()` with valid/invalid tokens
- [ ] Test `validate_mesh_key()` with valid/invalid keys
- [ ] Test `validate_bearer_token()` with valid/invalid/expired JWT
- [ ] Test `extract_guest_token()` from header and param
- [ ] Test `insufficient_permissions_error()` format
- [ ] Test JWK to PEM conversion
- [ ] Test base64url decoding
- [ ] Test audience and scope matching

### 5. Integration Testing
- [ ] Test authentication via WordPress nonce
- [ ] Test authentication via local credentials
- [ ] Test authentication via mesh API key
- [ ] Test authentication via Auth0 bearer token
- [ ] Test guest token authentication
- [ ] Test permission denied scenarios
- [ ] Test multiple auth methods in sequence

### 6. Documentation
- [ ] Add PHPDoc examples to authenticator class
- [ ] Document new authentication flow in README or docs
- [ ] Update architecture diagrams if they exist

### 7. Code Review
- [ ] Run PHPCS linter: `composer run lint`
- [ ] Run verification script: `bash bin/verify-refactoring.sh`
- [ ] Check no public methods removed
- [ ] Verify backward compatibility maintained

---

## Implementation Guide

### Step 1: Replace Auth Context Methods

```php
// In WP_MCP_AI_REST class, update these methods:

protected function reset_auth_context() {
    $this->authenticator->reset_auth_context();
    $this->auth_context = $this->authenticator->get_auth_context();
}

protected function mark_token_authenticated( $type, $context = array() ) {
    $this->authenticator->mark_token_authenticated( $type, $context );
    $this->auth_context = $this->authenticator->get_auth_context();
}

protected function set_authenticated_user_id( $user_id ) {
    $this->authenticator->set_authenticated_user_id( $user_id );
    $this->auth_context = $this->authenticator->get_auth_context();
}

protected function get_auth_context() {
    return $this->authenticator->get_auth_context();
}
```

### Step 2: Replace Validation Methods

```php
protected function validate_local_token( $token, WP_REST_Request $request ) {
    $assistant_hint = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
    return $this->authenticator->validate_local_token( $token, $request, $assistant_hint );
}

protected function validate_mesh_key( $key ) {
    return $this->authenticator->validate_mesh_key( $key );
}

protected function validate_bearer_token( $token, WP_REST_Request $request ) {
    return $this->authenticator->validate_bearer_token( $token, $request );
}

protected function extract_guest_token( WP_REST_Request $request ) {
    return $this->authenticator->extract_guest_token( $request );
}

protected function insufficient_permissions_error( $capability = 'edit_posts' ) {
    return $this->authenticator->insufficient_permissions_error( $capability );
}
```

### Step 3: Remove Duplicated Helper Methods

Delete these methods from `WP_MCP_AI_REST` as they're now in the authenticator:
- `get_auth0_jwks()`
- `jwk_to_pem()`
- `audience_matches()`
- `scope_satisfied()`
- `base64_url_decode()`
- `encode_asn1_integer()`
- `encode_asn1_sequence()`
- `encode_asn1_length()`
- `invalid_bearer_error()`

These are approximately 355 lines that can be removed.

### Step 4: Sync Auth Context

Since some code may directly access `$this->auth_context`, we maintain backward compatibility by syncing it:

```php
// After any auth operation:
$this->auth_context = $this->authenticator->get_auth_context();
```

Later, we can deprecate direct `$this->auth_context` access.

---

## Expected Outcomes

### Lines Reduced from WP_MCP_AI_REST
- Auth context methods (delegated): ~80 lines → ~40 lines = **40 lines saved**
- Validation methods (delegated): ~400 lines → ~40 lines = **360 lines saved**
- Helper methods (removed): ~355 lines → 0 lines = **355 lines saved**
- **Total Estimated Reduction**: ~755 lines (exceeds 300-line goal!)

### New Code Added
- New authenticator class: 690 lines
- REST class additions: 10 lines (property + instantiation)
- **Net Change**: Moved 755 lines out, added 700 lines to new class = **Better organization**

### Code Quality Improvements
- ✅ Single Responsibility Principle - Auth logic isolated
- ✅ Testability - Can unit test authenticator independently
- ✅ Maintainability - Easier to modify auth without touching REST routing
- ✅ Extensibility - New auth methods added to one class
- ✅ Readability - REST class focused on routing, not auth details

---

## Testing Checklist

### Manual Testing
- [ ] Chat with WordPress nonce (logged-in user)
- [ ] Chat with assistant credential token
- [ ] Chat with guest token (public access)
- [ ] Mesh network request with API key
- [ ] Auth0-authenticated request
- [ ] Permission denied (insufficient capability)
- [ ] Invalid/expired tokens rejected

### Automated Testing
- [ ] PHPUnit tests pass: `composer run test`
- [ ] PHPCS lint passes: `composer run lint`
- [ ] Verification script passes: `bash bin/verify-refactoring.sh`

---

## Next Steps for Completion

1. **Update delegation methods** (~2 hours)
   - Replace method bodies to call `$this->authenticator->`
   - Maintain `$this->auth_context` sync for backward compatibility

2. **Remove duplicate code** (~1 hour)
   - Delete helper methods now in authenticator
   - Verify no other code calls these methods

3. **Write unit tests** (~3 hours)
   - Test authenticator class methods
   - Mock dependencies (WP_REST_Request, settings)
   - Test edge cases and errors

4. **Integration testing** (~2 hours)
   - Manual testing of all auth flows
   - Verify no regressions

5. **Documentation & review** (~1 hour)
   - Update docs
   - Run linters
   - Final verification

**Estimated Time to Complete**: 9 hours

---

## Questions & Decisions

### Q: Should we deprecate direct `$auth_context` access?
**A**: Not yet. Maintain backward compatibility by syncing. In Milestone 8 (Service Layer), we can fully migrate.

### Q: Should helper methods be public or protected in authenticator?
**A**: Keep them protected. Only the main validation/context methods need to be public.

### Q: Do we need feature flags?
**A**: Not for this milestone since it's internal refactoring with backward compatibility.

---

## Success Criteria

- ✅ All authentication flows work identically
- ✅ No public API changes
- ✅ Test coverage for authenticator class
- ✅ PHPCS compliance
- ✅ Verification script passes
- ✅ ~300+ lines reduced from REST class
- ✅ Authentication logic isolated in dedicated class

---

**Status**: Ready for completion. Authenticator class is complete and well-structured. Just need to update REST class methods to delegate and add tests.
