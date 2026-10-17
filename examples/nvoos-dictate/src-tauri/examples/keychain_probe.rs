//! Keychain probe: verify the store the app actually uses (Windows
//! Credential Manager via `storage::win_cred`, keyring elsewhere) persists
//! correctly: set → read-back → delete.
//!
//! Usage: cargo run --example keychain_probe

#[cfg(not(windows))]
use nvoos_dictate_lib::state::OsKeyringStore;
use nvoos_dictate_lib::state::SecretStore;
#[cfg(windows)]
use nvoos_dictate_lib::storage::win_cred::WindowsCredentialStore;

fn main() {
    #[cfg(windows)]
    let store = WindowsCredentialStore;
    #[cfg(not(windows))]
    let store = OsKeyringStore;
    let key = "probe/test-value";
    match store.set(key, "probe-secret-123") {
        Ok(()) => println!("set: ok"),
        Err(e) => {
            println!("set: FAILED: {e}");
            return;
        }
    }
    match store.get(key) {
        Ok(Some(v)) if v == "probe-secret-123" => println!("read-back: ok (matched)"),
        Ok(Some(v)) => println!("read-back: MISMATCH ({v:?})"),
        Ok(None) => println!("read-back: NOT FOUND — store is not persistent"),
        Err(e) => println!("read-back: ERROR: {e}"),
    }
    match store.delete(key) {
        Ok(()) => println!("delete: ok"),
        Err(e) => println!("delete: FAILED: {e}"),
    }
    println!("verify with: cmdkey /list | findstr nvoos");
}
