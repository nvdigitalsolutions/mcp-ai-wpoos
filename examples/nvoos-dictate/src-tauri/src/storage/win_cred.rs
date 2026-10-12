//! Native Windows Credential Manager store (Generic credentials).
//!
//! Why not the `keyring` crate on Windows: keyring 3.6.3 writes every
//! credential with `CRED_PERSIST_ENTERPRISE` persistence, which targets
//! elevated/roaming contexts. From an unelevated app the write "succeeds"
//! but the credential is not readable back — the classic "I stored it but
//! it did not stick" failure (see also tono PR #632, which replaced keyring
//! the same way).
//!
//! This store calls `CredWriteW`/`CredReadW`/`CredDeleteW` directly with:
//! - `CRED_PERSIST_LOCAL_MACHINE` — machine-local, survives reboots,
//!   visible in the user's Credential Manager, no elevation (the
//!   persistence git credential manager and most desktop apps use);
//! - the same target-name convention keyring used (`{user}.{service}`)
//!   so any credential written by an older build stays readable;
//! - `CRED_TYPE_GENERIC` with the secret as a UTF-16LE blob (keyring's
//!   on-disk format, interop-friendly).
//!
//! `SecretStore` errors surface via `AppError::Keyring`; a missing entry
//! maps to `Ok(None)` exactly like the keyring backend.

use windows::core::PWSTR;
use windows::Win32::Foundation::FILETIME;
use windows::Win32::Security::Credentials::{
    CredDeleteW, CredFree, CredReadW, CredWriteW, CREDENTIALW, CRED_FLAGS,
    CRED_PERSIST_LOCAL_MACHINE, CRED_TYPE_GENERIC,
};

use crate::error::{AppError, AppResult};
use crate::state::SecretStore;

/// `SecretStore` backed by the Windows Credential Manager.
#[derive(Default)]
pub struct WindowsCredentialStore;

impl SecretStore for WindowsCredentialStore {
    fn get(&self, key: &str) -> AppResult<Option<String>> {
        let service = crate::state::KEYRING_SERVICE;
        let target = format!("{key}.{service}");
        let target_wide: Vec<u16> = target.encode_utf16().chain(Some(0)).collect();

        let mut p_cred: *mut CREDENTIALW = std::ptr::null_mut();
        let result = unsafe {
            CredReadW(
                PWSTR(target_wide.as_ptr() as *mut u16),
                CRED_TYPE_GENERIC,
                0,
                &mut p_cred,
            )
        };
        if let Err(e) = result {
            if is_not_found(&e) {
                // ERROR_NOT_FOUND — no entry.
                return Ok(None);
            }
            return Err(AppError::Keyring(format!("CredReadW failed: {e}")));
        }
        let credential = unsafe { &*p_cred };
        let password = decode_blob(unsafe {
            std::slice::from_raw_parts(
                credential.CredentialBlob,
                credential.CredentialBlobSize as usize,
            )
        });
        unsafe { CredFree(p_cred as *mut _) };
        Ok(Some(password))
    }

    fn set(&self, key: &str, value: &str) -> AppResult<()> {
        let service = crate::state::KEYRING_SERVICE;
        let target: Vec<u16> = format!("{key}.{service}")
            .encode_utf16()
            .chain(Some(0))
            .collect();
        let user_wide: Vec<u16> = key.encode_utf16().chain(Some(0)).collect();
        let blob: Vec<u8> = value.encode_utf16().flat_map(|u| u.to_le_bytes()).collect();

        let credential = CREDENTIALW {
            Flags: CRED_FLAGS(0),
            Type: CRED_TYPE_GENERIC,
            TargetName: PWSTR(target.as_ptr() as *mut u16),
            Comment: PWSTR::null(),
            LastWritten: FILETIME {
                dwLowDateTime: 0,
                dwHighDateTime: 0,
            },
            CredentialBlobSize: blob.len() as u32,
            CredentialBlob: blob.as_ptr() as *mut u8,
            Persist: CRED_PERSIST_LOCAL_MACHINE,
            AttributeCount: 0,
            Attributes: std::ptr::null_mut(),
            TargetAlias: PWSTR::null(),
            UserName: PWSTR(user_wide.as_ptr() as *mut u16),
        };
        unsafe { CredWriteW(&credential, 0) }
            .map_err(|e| AppError::Keyring(format!("CredWriteW failed: {e}")))
    }

    fn delete(&self, key: &str) -> AppResult<()> {
        let service = crate::state::KEYRING_SERVICE;
        let target: Vec<u16> = format!("{key}.{service}")
            .encode_utf16()
            .chain(Some(0))
            .collect();
        let result =
            unsafe { CredDeleteW(PWSTR(target.as_ptr() as *mut u16), CRED_TYPE_GENERIC, 0) };
        match result {
            Ok(()) => Ok(()),
            Err(e) if is_not_found(&e) => Ok(()), // ERROR_NOT_FOUND
            Err(e) => Err(AppError::Keyring(format!("CredDeleteW failed: {e}"))),
        }
    }
}

/// Win32 API errors come back as HRESULT_FROM_WIN32 codes.
fn is_not_found(e: &windows::core::Error) -> bool {
    e.code() == windows::core::HRESULT::from_win32(1168)
}

/// Decode a UTF-16LE credential blob (keyring's on-disk format).
fn decode_blob(blob: &[u8]) -> String {
    let units: Vec<u16> = blob
        .as_chunks::<2>()
        .0
        .iter()
        .map(|c| u16::from_le_bytes(*c))
        .collect();
    String::from_utf16_lossy(&units)
}
