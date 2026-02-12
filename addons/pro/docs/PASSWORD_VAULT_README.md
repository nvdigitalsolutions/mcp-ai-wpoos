# Password Vault Manager - Pro Feature

WordPress-native password vault with OWASP-compliant encryption and TOTP authenticator support.

## Features

### 🔐 Secure Password Storage
- **AES-256-GCM** authenticated encryption (OWASP recommended)
- **Per-user encryption keys** with PBKDF2 key derivation (100,000 iterations)
- **Zero-knowledge architecture** - encrypted data at rest
- **Authentication tags** for tamper detection

### 🔑 Password Generator
- Cryptographically secure random password generation
- Customizable length (12-128 characters)
- Character set options (uppercase, lowercase, numbers, symbols)
- Avoid ambiguous characters option
- Password strength calculator

### 📱 TOTP Authenticator (RFC 6238)
- **Google Authenticator compatible**
- Generate TOTP secrets (Base32 encoded)
- QR code generation for easy setup (using qrcode NPM package)
- Time-based One-Time Password verification
- 6-digit codes with ±30 second time drift tolerance
- Compatible with: Google Authenticator, Authy, Microsoft Authenticator, etc.

## Architecture

```
WordPress Admin UI (Settings → NV oOS Pro → Password Vault)
       ↓
├── Vault Items Tab
│   └── CPT: mcp_vault_item (passwords, notes, cards, identities)
│
├── Password Generator & Authenticator Tab
│   ├── Secure Password Generator
│   └── TOTP Authenticator with QR Codes
│
└── Security Settings Tab
    ├── Default generator settings
    ├── TOTP issuer configuration
    └── Encryption information

Encryption Service (AES-256-GCM, PBKDF2)
       ↓
Data Storage (WordPress CPT + User Meta)
```

## Security Standards

### Encryption (OWASP Compliant)
- **Algorithm**: AES-256-GCM (Galois/Counter Mode)
- **Key Derivation**: PBKDF2-HMAC-SHA256, 100,000 iterations
- **IV**: Unique random 16-byte IV per encryption
- **Auth Tag**: 128-bit authentication tag for integrity
- **Per-User Keys**: Isolated encryption keys derived from:
  - WordPress AUTH_KEY constant
  - User-specific random salt (32 bytes)
  - User ID

### TOTP (RFC 6238 & RFC 4226)
- **Algorithm**: HMAC-SHA1 (RFC 4226 HOTP)
- **Time Step**: 30 seconds
- **Code Length**: 6 digits
- **Time Drift**: ±1 step (±30 seconds)
- **Secret Length**: 160 bits (20 bytes, Base32 encoded)
- **Timing-Safe Comparison**: Prevents timing attacks

## NPM Dependencies

- **qrcode** (^1.5.4): QR code generation for TOTP setup

## Usage

### Admin Interface

1. **Navigate to**: Settings → NV oOS Pro → Password Vault

2. **Three Main Tabs**:
   - **Vault Items**: Manage encrypted vault entries
   - **Password Generator & Authenticator**: Generate passwords and TOTP secrets
   - **Security Settings**: Configure defaults and view encryption info

### Password Generation

```php
$encryption_service = new WP_MCP_AI_Vault_Encryption_Service();

// Generate secure password
$password = $encryption_service->generate_password(
    20,    // Length
    true,  // Uppercase
    true,  // Lowercase
    true,  // Numbers
    true,  // Symbols
    true   // Avoid ambiguous
);

// Calculate strength (0-4)
$strength = $encryption_service->calculate_password_strength( $password );
```

### TOTP Authenticator

```php
$encryption_service = new WP_MCP_AI_Vault_Encryption_Service();

// Generate TOTP secret
$secret = $encryption_service->generate_totp_secret();

// Generate QR code URI for authenticator apps
$qr_uri = $encryption_service->get_totp_qr_code_uri(
    $secret,
    'user@example.com', // Account label
    'My WordPress Site' // Issuer
);

// Generate QR code image (uses qrcode NPM package)
$qr_code = wp_mcp_ai_generate_qr_code( $qr_uri, 'data-url' );

// Generate current TOTP code
$code = $encryption_service->generate_totp_code( $secret );

// Verify user-provided code
$is_valid = $encryption_service->verify_totp_code( $secret, '123456' );
```

### Data Encryption

```php
$encryption_service = new WP_MCP_AI_Vault_Encryption_Service();
$user_id = get_current_user_id();

// Encrypt sensitive data
$encrypted = $encryption_service->encrypt( 'my_password_123', $user_id );
// Returns: ['iv' => '...', 'ciphertext' => '...', 'auth_tag' => '...']

// Decrypt data
$plaintext = $encryption_service->decrypt( $encrypted, $user_id );
```

## Files Structure

```
addons/pro/
├── includes/
│   ├── password-vault-init.php                          # Main initialization
│   ├── npm-integration-filters.php                      # QR code integration
│   ├── vault/
│   │   └── class-wp-mcp-ai-vault-encryption-service.php # Encryption & TOTP
│   ├── admin/
│   │   └── class-wp-mcp-ai-password-vault-admin.php     # Admin interface
│   └── npm-services/
│       └── qrcode-service.js                            # QR code generation
├── assets/
│   ├── css/
│   │   └── password-vault-admin.css                     # Admin styles
│   └── js/
│       └── password-vault-admin.js                      # Admin JavaScript
└── package.json                                          # Added: qrcode ^1.5.4
```

## System Requirements

- WordPress 6.0+
- PHP 7.4+ with OpenSSL extension
- Node.js 18+ (for QR code generation)
- WordPress AUTH_KEY constant defined

## Security Considerations

### Protected Against
✅ Database dumps (encrypted at rest)
✅ Unauthorized users (capability checks)
✅ SQL injection (prepared statements)
✅ XSS attacks (output escaping)
✅ CSRF attacks (nonce verification)
✅ Data tampering (GCM auth tags)
✅ Timing attacks (constant-time comparison)

### Not Protected Against
❌ Compromised WordPress admin account
❌ Server-level root access
❌ PHP process memory dumps
❌ Malware/backdoors on server

### Mitigation
- Use strong admin passwords + 2FA
- Keep WordPress and plugins updated
- Server hardening (file permissions, disable dangerous functions)
- Regular security scans
- Encrypted database backups (off-site)

## References

- [OWASP Cryptographic Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html)
- [OWASP Password Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html)
- [RFC 6238 - TOTP: Time-Based One-Time Password Algorithm](https://datatracker.ietf.org/doc/html/rfc6238)
- [RFC 4226 - HOTP: An HMAC-Based One-Time Password Algorithm](https://datatracker.ietf.org/doc/html/rfc4226)

## License

Proprietary - NV Digital Solutions

---

**Created**: January 23, 2026  
**Version**: 1.3.0  
**Status**: ✅ Implementation Complete (Phase 1)
