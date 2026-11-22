#![cfg(windows)]
#![allow(dead_code)] // Device signer is not connected to the default-disabled bridge yet.

use std::ptr;
use windows_sys::Win32::Security::Cryptography::{
    BCryptCloseAlgorithmProvider, BCryptCreateHash, BCryptDestroyHash, BCryptFinishHash,
    BCryptGetProperty, BCryptHashData, BCryptOpenAlgorithmProvider, BCRYPT_ALG_HANDLE,
    BCRYPT_ECCKEY_BLOB, BCRYPT_ECDSA_PUBLIC_P256_MAGIC, BCRYPT_HASH_HANDLE,
    NCryptCreatePersistedKey, NCryptDeleteKey, NCryptExportKey, NCryptFinalizeKey,
    NCryptFreeObject, NCryptOpenKey, NCryptOpenStorageProvider,
    NCryptGetProperty, NCryptSetProperty, NCryptSignHash,
    NCRYPT_ALLOW_SIGNING_FLAG, NCRYPT_EXPORT_POLICY_PROPERTY, NCRYPT_KEY_HANDLE,
    NCRYPT_KEY_USAGE_PROPERTY, NCRYPT_PERSIST_FLAG, NCRYPT_PROV_HANDLE,
    NCRYPT_SILENT_FLAG,
};

const PROVIDER: &str = "Microsoft Software Key Storage Provider";
const KEY_PREFIX: &str = "com.xlb.vpn.device.";
const SPKI_P256_PREFIX: &[u8] = &[
    0x30, 0x59, 0x30, 0x13, 0x06, 0x07, 0x2a, 0x86, 0x48, 0xce, 0x3d, 0x02, 0x01, 0x06,
    0x08, 0x2a, 0x86, 0x48, 0xce, 0x3d, 0x03, 0x01, 0x07, 0x03, 0x42, 0x00, 0x04,
];

pub struct DeviceKey {
    provider: NCRYPT_PROV_HANDLE,
    key: NCRYPT_KEY_HANDLE,
}

impl DeviceKey {
    pub fn open_or_create(installation_id: &str) -> Result<Self, String> {
        if installation_id.trim().is_empty() {
            return Err("installation identity is empty".into());
        }
        let name = key_name_for_identity(installation_id)?;
        Self::open_named(&name, true)
    }

    pub fn open_existing(installation_id: &str) -> Result<Self, String> {
        if installation_id.trim().is_empty() {
            return Err("installation identity is empty".into());
        }
        let name = key_name_for_identity(installation_id)?;
        Self::open_named(&name, false)
    }

    pub fn public_key_spki_base64url(&self) -> Result<String, String> {
        let blob = self.export_public_blob()?;
        let header_len = std::mem::size_of::<BCRYPT_ECCKEY_BLOB>();
        validate_p256_public_blob(&blob)?;
        let mut spki = SPKI_P256_PREFIX.to_vec();
        spki.extend_from_slice(&blob[header_len..]);
        Ok(base64url(&spki))
    }

    pub fn sign_challenge_base64url(&self, challenge: &[u8]) -> Result<String, String> {
        if challenge.len() != 32 {
            return Err("device challenge must be 32 bytes".into());
        }
        Ok(base64url(&self.sign_message_der(challenge)?))
    }

    pub fn sign_message_der(&self, message: &[u8]) -> Result<Vec<u8>, String> {
        let digest = sha256(message)?;
        self.sign_digest_der(&digest)
    }

    fn sign_digest_der(&self, digest: &[u8; 32]) -> Result<Vec<u8>, String> {
        let mut size = 0u32;
        status(unsafe { NCryptSignHash(
            self.key,
            ptr::null(),
            digest.as_ptr(),
            digest.len() as u32,
            ptr::null_mut(),
            0,
            &mut size,
            NCRYPT_SILENT_FLAG,
        ) }, "query ECDSA signature size")?;
        let mut raw = vec![0; size as usize];
        status(unsafe { NCryptSignHash(
            self.key,
            ptr::null(),
            digest.as_ptr(),
            digest.len() as u32,
            raw.as_mut_ptr(),
            size,
            &mut size,
            NCRYPT_SILENT_FLAG,
        ) }, "sign device challenge")?;
        raw.truncate(size as usize);
        raw_signature_to_der(&raw)
    }

    fn open_named(name: &str, create: bool) -> Result<Self, String> {
        let provider_name = wide(PROVIDER);
        let key_name = wide(name);
        let mut provider = 0;
        status(unsafe {
            NCryptOpenStorageProvider(&mut provider, provider_name.as_ptr(), 0)
        }, "open current-user CNG provider")?;
        let mut key = 0;
        let opened = unsafe {
            NCryptOpenKey(provider, &mut key, key_name.as_ptr(), 0, NCRYPT_SILENT_FLAG)
        };
        if opened == 0 {
            let key = Self { provider, key };
            key.validate_constraints()?;
            return Ok(key);
        }
        if !create {
            unsafe { NCryptFreeObject(provider) };
            return Err(format!("open CNG key failed (0x{:08x})", opened as u32));
        }
        let algorithm = wide("ECDSA_P256");
        let created = unsafe {
            NCryptCreatePersistedKey(
                provider,
                &mut key,
                algorithm.as_ptr(),
                key_name.as_ptr(),
                0,
                0,
            )
        };
        if created != 0 {
            let reopened = unsafe {
                NCryptOpenKey(provider, &mut key, key_name.as_ptr(), 0, NCRYPT_SILENT_FLAG)
            };
            if reopened == 0 {
                let key = Self { provider, key };
                key.validate_constraints()?;
                return Ok(key);
            }
            unsafe { NCryptFreeObject(provider) };
            return Err(format!("create CNG key failed (0x{:08x})", created as u32));
        }
        let usage = NCRYPT_ALLOW_SIGNING_FLAG;
        let usage_name = wide("Key Usage");
        let configured = unsafe {
            NCryptSetProperty(
                key,
                usage_name.as_ptr(),
                (&usage as *const u32).cast(),
                std::mem::size_of_val(&usage) as u32,
                NCRYPT_PERSIST_FLAG,
            )
        };
        if configured != 0 {
            unsafe {
                NCryptDeleteKey(key, 0);
                NCryptFreeObject(provider);
            }
            return Err(format!("restrict CNG key usage failed (0x{:08x})", configured as u32));
        }
        let export_policy_name = wide("Export Policy");
        let export_policy = 0u32;
        let restricted = unsafe {
            NCryptSetProperty(
                key,
                export_policy_name.as_ptr(),
                (&export_policy as *const u32).cast(),
                std::mem::size_of_val(&export_policy) as u32,
                NCRYPT_PERSIST_FLAG,
            )
        };
        if restricted != 0 {
            unsafe {
                NCryptDeleteKey(key, 0);
                NCryptFreeObject(provider);
            }
            return Err(format!("disable CNG key export failed (0x{:08x})", restricted as u32));
        }
        let finalized = unsafe { NCryptFinalizeKey(key, 0) };
        if finalized != 0 {
            unsafe {
                NCryptDeleteKey(key, 0);
                NCryptFreeObject(provider);
            }
            return Err(format!("finalize CNG key failed (0x{:08x})", finalized as u32));
        }
        let key = Self { provider, key };
        if let Err(error) = key.validate_constraints() {
            key.delete_persisted();
            return Err(error);
        }
        Ok(key)
    }

    fn validate_constraints(&self) -> Result<(), String> {
        if self.key_usage()? != NCRYPT_ALLOW_SIGNING_FLAG || self.export_policy()? != 0 {
            return Err("existing CNG device key does not satisfy proof-key policy".into());
        }
        validate_p256_public_blob(&self.export_public_blob()?)
    }

    fn export_public_blob(&self) -> Result<Vec<u8>, String> {
        let blob_type = wide("ECCPUBLICBLOB");
        let mut size = 0u32;
        status(unsafe {
            NCryptExportKey(self.key, 0, blob_type.as_ptr(), ptr::null(), ptr::null_mut(), 0, &mut size, 0)
        }, "query public key size")?;
        let mut blob = vec![0; size as usize];
        status(unsafe {
            NCryptExportKey(self.key, 0, blob_type.as_ptr(), ptr::null(), blob.as_mut_ptr(), size, &mut size, 0)
        }, "export public key")?;
        blob.truncate(size as usize);
        Ok(blob)
    }

    fn export_private_blob(&self) -> Result<Vec<u8>, String> {
        let blob_type = wide("ECCPRIVATEBLOB");
        let mut size = 0u32;
        status(unsafe {
            NCryptExportKey(self.key, 0, blob_type.as_ptr(), ptr::null(), ptr::null_mut(), 0, &mut size, 0)
        }, "query private key export")?;
        let mut blob = vec![0; size as usize];
        status(unsafe {
            NCryptExportKey(self.key, 0, blob_type.as_ptr(), ptr::null(), blob.as_mut_ptr(), size, &mut size, 0)
        }, "export private key")?;
        blob.truncate(size as usize);
        Ok(blob)
    }

    fn export_policy(&self) -> Result<u32, String> {
        self.read_u32_property(NCRYPT_EXPORT_POLICY_PROPERTY, "read CNG export policy")
    }

    fn key_usage(&self) -> Result<u32, String> {
        self.read_u32_property(NCRYPT_KEY_USAGE_PROPERTY, "read CNG key usage")
    }

    fn read_u32_property(&self, property: windows_sys::core::PCWSTR, action: &str) -> Result<u32, String> {
        let mut policy = u32::MAX;
        let mut size = 0u32;
        status(unsafe {
            NCryptGetProperty(
                self.key,
                property,
                (&mut policy as *mut u32).cast(),
                std::mem::size_of_val(&policy) as u32,
                &mut size,
                0,
            )
        }, action)?;
        if size != std::mem::size_of_val(&policy) as u32 {
            return Err("CNG returned an invalid proof key property".into());
        }
        Ok(policy)
    }

    fn delete_persisted(mut self) {
        if self.key != 0 {
            if unsafe { NCryptDeleteKey(self.key, 0) } == 0 {
                self.key = 0;
            }
        }
    }
}

impl Drop for DeviceKey {
    fn drop(&mut self) {
        unsafe {
            if self.key != 0 {
                NCryptFreeObject(self.key);
            }
            if self.provider != 0 {
                NCryptFreeObject(self.provider);
            }
        }
    }
}

impl super::proof_bridge::DeviceProofSigner for DeviceKey {
    fn public_key_spki_base64url(&self) -> Result<String, super::proof_bridge::BridgeFailure> {
        DeviceKey::public_key_spki_base64url(self)
            .map_err(|_| super::proof_bridge::BridgeFailure::DeviceKeyUnavailable)
    }

    fn sign_challenge_base64url(
        &self,
        challenge: &[u8; 32],
    ) -> Result<String, super::proof_bridge::BridgeFailure> {
        DeviceKey::sign_challenge_base64url(self, challenge)
            .map_err(|_| super::proof_bridge::BridgeFailure::DeviceProofSigningFailed)
    }
}

fn validate_p256_public_blob(blob: &[u8]) -> Result<(), String> {
    let header_len = std::mem::size_of::<BCRYPT_ECCKEY_BLOB>();
    if blob.len() != header_len + 64 {
        return Err("CNG returned an invalid P-256 public key".into());
    }
    let magic = u32::from_le_bytes(
        blob[0..4]
            .try_into()
            .map_err(|_| "CNG returned an invalid public key blob")?,
    );
    let key_bytes = u32::from_le_bytes(
        blob[4..8]
            .try_into()
            .map_err(|_| "CNG returned an invalid public key blob")?,
    );
    if magic != BCRYPT_ECDSA_PUBLIC_P256_MAGIC || key_bytes != 32 {
        return Err("CNG returned a non-P-256 public key".into());
    }
    Ok(())
}

fn sha256(input: &[u8]) -> Result<[u8; 32], String> {
    let algorithm = wide("SHA256");
    let mut provider: BCRYPT_ALG_HANDLE = ptr::null_mut();
    status(unsafe { BCryptOpenAlgorithmProvider(&mut provider, algorithm.as_ptr(), ptr::null(), 0) }, "open SHA-256")?;
    let property = wide("ObjectLength");
    let mut object_len = 0u32;
    let mut result_len = 0u32;
    let property_status = unsafe {
        BCryptGetProperty(
            provider,
            property.as_ptr(),
            (&mut object_len as *mut u32).cast(),
            std::mem::size_of_val(&object_len) as u32,
            &mut result_len,
            0,
        )
    };
    if property_status != 0 {
        unsafe { BCryptCloseAlgorithmProvider(provider, 0) };
        return Err(format!("query SHA-256 storage failed (0x{:08x})", property_status as u32));
    }
    let mut object = vec![0; object_len as usize];
    let mut hash: BCRYPT_HASH_HANDLE = ptr::null_mut();
    let created = unsafe {
        BCryptCreateHash(
            provider,
            &mut hash,
            object.as_mut_ptr(),
            object_len,
            ptr::null(),
            0,
            0,
        )
    };
    if created != 0 {
        unsafe { BCryptCloseAlgorithmProvider(provider, 0) };
        return Err(format!("create SHA-256 operation failed (0x{:08x})", created as u32));
    }
    let hashed = unsafe { BCryptHashData(hash, input.as_ptr() as *mut u8, input.len() as u32, 0) };
    let mut digest = [0; 32];
    let finished = if hashed == 0 {
        unsafe { BCryptFinishHash(hash, digest.as_mut_ptr(), digest.len() as u32, 0) }
    } else {
        hashed
    };
    unsafe {
        BCryptDestroyHash(hash);
        BCryptCloseAlgorithmProvider(provider, 0);
    }
    status(finished, "hash SHA-256")?;
    Ok(digest)
}

fn key_name_for_identity(installation_id: &str) -> Result<String, String> {
    let digest = sha256(installation_id.as_bytes())?;
    Ok(format!("{KEY_PREFIX}{}", base64url(&digest)))
}

fn raw_signature_to_der(raw: &[u8]) -> Result<Vec<u8>, String> {
    if raw.len() != 64 {
        return Err("CNG returned an invalid P-256 signature".into());
    }
    let mut body = Vec::with_capacity(70);
    for integer in [&raw[..32], &raw[32..]] {
        let first = integer.iter().position(|byte| *byte != 0).unwrap_or(31);
        let value = &integer[first..];
        body.push(0x02);
        body.push((value.len() + usize::from(value[0] & 0x80 != 0)) as u8);
        if value[0] & 0x80 != 0 { body.push(0); }
        body.extend_from_slice(value);
    }
    let mut der = vec![0x30, body.len() as u8];
    der.extend(body);
    Ok(der)
}

fn base64url(bytes: &[u8]) -> String {
    const TABLE: &[u8; 64] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_";
    let mut out = String::with_capacity((bytes.len() * 4 + 2) / 3);
    for chunk in bytes.chunks(3) {
        let n = ((chunk[0] as u32) << 16)
            | ((chunk.get(1).copied().unwrap_or(0) as u32) << 8)
            | chunk.get(2).copied().unwrap_or(0) as u32;
        out.push(TABLE[((n >> 18) & 63) as usize] as char);
        out.push(TABLE[((n >> 12) & 63) as usize] as char);
        if chunk.len() > 1 { out.push(TABLE[((n >> 6) & 63) as usize] as char); }
        if chunk.len() > 2 { out.push(TABLE[(n & 63) as usize] as char); }
    }
    out
}

fn wide(value: &str) -> Vec<u16> {
    value.encode_utf16().chain(Some(0)).collect()
}

fn status(code: i32, action: &str) -> Result<(), String> {
    if code == 0 { Ok(()) } else { Err(format!("{action} failed (0x{:08x})", code as u32)) }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::{AtomicU64, Ordering};

    static NEXT_ID: AtomicU64 = AtomicU64::new(0);

    struct TestKey {
        key_name: String,
        installation_id: String,
    }
    impl TestKey {
        fn for_identity(identity: &str) -> Self {
            let installation_id = format!("{identity}-{}-{}", std::process::id(), NEXT_ID.fetch_add(1, Ordering::Relaxed));
            let key_name = key_name_for_identity(&installation_id).unwrap();
            let key = DeviceKey::open_or_create(&installation_id).unwrap();
            drop(key);
            Self { key_name, installation_id }
        }

        fn for_exportable_identity(identity: &str) -> Self {
            let key_name = key_name_for_identity(identity).unwrap();
            let provider_name = wide(PROVIDER);
            let name = wide(&key_name);
            let algorithm = wide("ECDSA_P256");
            let mut provider = 0;
            status(unsafe { NCryptOpenStorageProvider(&mut provider, provider_name.as_ptr(), 0) }, "open test provider").unwrap();
            let mut key = 0;
            status(unsafe {
                NCryptCreatePersistedKey(provider, &mut key, algorithm.as_ptr(), name.as_ptr(), 0, 0)
            }, "create test exportable key").unwrap();
            let usage = NCRYPT_ALLOW_SIGNING_FLAG;
            let export_policy = 1u32;
            for (property, value) in [("Key Usage", usage), ("Export Policy", export_policy)] {
                let property = wide(property);
                status(unsafe {
                    NCryptSetProperty(
                        key,
                        property.as_ptr(),
                        (&value as *const u32).cast(),
                        std::mem::size_of_val(&value) as u32,
                        NCRYPT_PERSIST_FLAG,
                    )
                }, "configure test exportable key").unwrap();
            }
            status(unsafe { NCryptFinalizeKey(key, 0) }, "finalize test exportable key").unwrap();
            unsafe {
                NCryptFreeObject(key);
                NCryptFreeObject(provider);
            }
            Self { key_name, installation_id: identity.to_owned() }
        }
        fn open(&self) -> DeviceKey { DeviceKey::open_named(&self.key_name, false).unwrap() }
    }
    impl Drop for TestKey {
        fn drop(&mut self) {
            if let Ok(key) = DeviceKey::open_named(&self.key_name, false) {
                let key = std::mem::ManuallyDrop::new(key);
                unsafe {
                    NCryptDeleteKey(key.key, 0);
                    NCryptFreeObject(key.provider);
                }
            }
        }
    }

    fn assert_signature_verifies(signer: &DeviceKey, message: &[u8], signature_der: &[u8]) {
        let digest = sha256(message).unwrap();
        let public_blob = signer.export_public_blob().unwrap();
        let mut imported = 0;
        let provider_name = wide(PROVIDER);
        let mut provider = 0;
        status(
            unsafe { NCryptOpenStorageProvider(&mut provider, provider_name.as_ptr(), 0) },
            "open provider for verification",
        )
        .unwrap();
        let public_type = wide("ECCPUBLICBLOB");
        status(
            unsafe {
                windows_sys::Win32::Security::Cryptography::NCryptImportKey(
                    provider,
                    0,
                    public_type.as_ptr(),
                    ptr::null(),
                    &mut imported,
                    public_blob.as_ptr(),
                    public_blob.len() as u32,
                    0,
                )
            },
            "import public key",
        )
        .unwrap();
        let raw = der_signature_to_raw(signature_der).unwrap();
        assert_eq!(
            unsafe {
                windows_sys::Win32::Security::Cryptography::NCryptVerifySignature(
                    imported,
                    ptr::null(),
                    digest.as_ptr(),
                    digest.len() as u32,
                    raw.as_ptr(),
                    raw.len() as u32,
                    0,
                )
            },
            0
        );
        unsafe {
            NCryptFreeObject(imported);
            NCryptFreeObject(provider);
        }
    }

    #[test]
    fn same_identity_reuses_key() {
        let a = TestKey::for_identity("test-installation-alpha");
        let a_key = a.open();
        let again = a.open();
        assert_eq!(a_key.public_key_spki_base64url().unwrap(), again.public_key_spki_base64url().unwrap());
    }

    #[test]
    fn different_identities_use_isolated_keys() {
        let a = TestKey::for_identity("test-installation-alpha");
        let b = TestKey::for_identity("test-installation-beta");
        let a_key = a.open();
        let b = b.open();
        assert_ne!(a_key.public_key_spki_base64url().unwrap(), b.public_key_spki_base64url().unwrap());
    }

    #[test]
    fn challenge_signature_verifies_with_exported_public_key() {
        let key = TestKey::for_identity("challenge");
        let signer = key.open();
        let challenge = [0x5a; 32];
        let proof_signer: &dyn super::super::proof_bridge::DeviceProofSigner = &signer;
        let signature = decode_base64url(&proof_signer.sign_challenge_base64url(&challenge).unwrap()).unwrap();
        assert_eq!(signature[0], 0x30);
        let public_key = proof_signer.public_key_spki_base64url().unwrap();
        assert!(public_key.starts_with("MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE"));
        assert_eq!(public_key.len(), 122);
        assert_signature_verifies(&signer, &challenge, &signature);
    }

    #[test]
    fn rustls_signer_hashes_messages_and_returns_verifiable_der() {
        use rustls::crypto::signer::{Signer, SigningKey};

        let key = TestKey::for_identity("rustls-signature");
        let signing_key = crate::rustls_device_signer::CngRustlsSigningKey::open_existing(
            &key.installation_id,
        )
        .unwrap();
        let signer = signing_key
            .choose_scheme(&[rustls::SignatureScheme::ECDSA_NISTP256_SHA256])
            .unwrap();
        let message = b"TLS 1.3, client CertificateVerify\0test transcript";
        let signature = signer.sign(message).unwrap();

        assert_eq!(signer.scheme(), rustls::SignatureScheme::ECDSA_NISTP256_SHA256);
        assert_eq!(signature.first(), Some(&0x30));
        assert_signature_verifies(&key.open(), message, &signature);
    }

    #[test]
    fn rejects_wrong_challenge_length() {
        let key = TestKey::for_identity("length");
        assert!(key.open().sign_challenge_base64url(&[0; 31]).is_err());
    }

    #[test]
    fn key_storage_is_current_user_and_non_exportable() {
        let key = TestKey::for_identity("export");
        let signer = key.open();
        assert_eq!(signer.export_policy().unwrap(), 0);
        assert!(signer.export_private_blob().is_err());
    }

    #[test]
    fn existing_exportable_key_is_rejected() {
        let identity = format!("exportable-{}-{}", std::process::id(), NEXT_ID.fetch_add(1, Ordering::Relaxed));
        let _key = TestKey::for_exportable_identity(&identity);
        assert!(DeviceKey::open_or_create(&identity).is_err());
    }

    #[test]
    fn test_key_guard_deletes_persisted_key_on_drop() {
        let name = {
            let key = TestKey::for_identity("cleanup");
            assert!(DeviceKey::open_named(&key.key_name, false).is_ok());
            key.key_name.clone()
        };
        assert!(DeviceKey::open_named(&name, false).is_err());
    }

    #[test]
    fn signature_der_encoding_handles_integer_sign_bits_and_padding() {
        let mut raw = [0; 64];
        raw[0] = 0x80;
        raw[31] = 1;
        raw[63] = 0x7f;
        let der = raw_signature_to_der(&raw).unwrap();
        assert_eq!(&der[..2], &[0x30, 0x26]);
        assert_eq!(&der[2..5], &[0x02, 0x21, 0x00]);
        assert_eq!(&der[37..40], &[0x02, 0x01, 0x7f]);
        assert_eq!(der_signature_to_raw(&der).unwrap(), raw);
    }

    fn der_signature_to_raw(der: &[u8]) -> Result<Vec<u8>, String> {
        if der.len() < 8 || der[0] != 0x30 { return Err("invalid DER signature".into()); }
        let mut offset = 2;
        let mut raw = Vec::with_capacity(64);
        for _ in 0..2 {
            if der.get(offset) != Some(&0x02) { return Err("invalid DER integer".into()); }
            let len = *der.get(offset + 1).ok_or("truncated DER signature")? as usize;
            let start = offset + 2;
            let end = start + len;
            let value = der.get(start..end).ok_or("truncated DER integer")?;
            let value = value.strip_prefix(&[0]).unwrap_or(value);
            if value.len() > 32 { return Err("oversized DER integer".into()); }
            raw.extend(std::iter::repeat(0).take(32 - value.len()));
            raw.extend_from_slice(value);
            offset = end;
        }
        if raw.len() == 64 { Ok(raw) } else { Err("invalid DER signature length".into()) }
    }

    fn decode_base64url(input: &str) -> Result<Vec<u8>, String> {
        let mut output = Vec::with_capacity(input.len() * 3 / 4);
        let mut accumulator = 0u32;
        let mut bits = 0u32;
        for byte in input.bytes() {
            let value = match byte {
                b'A'..=b'Z' => byte - b'A',
                b'a'..=b'z' => byte - b'a' + 26,
                b'0'..=b'9' => byte - b'0' + 52,
                b'-' => 62,
                b'_' => 63,
                _ => return Err("invalid base64url".into()),
            } as u32;
            accumulator = (accumulator << 6) | value;
            bits += 6;
            if bits >= 8 {
                bits -= 8;
                output.push((accumulator >> bits) as u8);
                accumulator &= (1 << bits) - 1;
            }
        }
        if bits != 0 && accumulator != 0 {
            return Err("invalid base64url padding bits".into());
        }
        Ok(output)
    }
}
