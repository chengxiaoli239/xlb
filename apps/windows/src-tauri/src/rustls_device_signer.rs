#![cfg(windows)]

use rustls::{
    crypto::signer::{Signer, SigningKey},
    Error, SignatureAlgorithm, SignatureScheme,
};

use crate::cng_device_key::DeviceKey;

#[derive(Debug)]
pub(super) struct CngRustlsSigningKey {
    installation_id: String,
}

impl CngRustlsSigningKey {
    pub(super) fn open_existing(installation_id: &str) -> Result<Self, String> {
        DeviceKey::open_existing(installation_id)
            .map(|key| drop(key))
            .map_err(|_| "device proof key unavailable".to_owned())?;
        Ok(Self {
            installation_id: installation_id.to_owned(),
        })
    }
}

impl SigningKey for CngRustlsSigningKey {
    fn choose_scheme(&self, offered: &[SignatureScheme]) -> Option<Box<dyn Signer>> {
        offered
            .contains(&SignatureScheme::ECDSA_NISTP256_SHA256)
            .then(|| {
                Box::new(CngRustlsSigner {
                    installation_id: self.installation_id.clone(),
                }) as Box<dyn Signer>
            })
    }

    fn algorithm(&self) -> SignatureAlgorithm {
        SignatureAlgorithm::ECDSA
    }
}

#[derive(Debug)]
struct CngRustlsSigner {
    installation_id: String,
}

impl Signer for CngRustlsSigner {
    fn sign(&self, message: &[u8]) -> Result<Vec<u8>, Error> {
        let key = DeviceKey::open_existing(&self.installation_id)
            .map_err(|_| Error::General("CNG device proof key unavailable".into()))?;
        key.sign_message_der(message)
            .map_err(|_| Error::General("CNG device proof signing failed".into()))
    }

    fn scheme(&self) -> SignatureScheme {
        SignatureScheme::ECDSA_NISTP256_SHA256
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn selects_only_the_p256_sha256_signature_scheme() {
        let key = CngRustlsSigningKey {
            installation_id: "fd3ae0f1-1234-4234-9234-000000000001".into(),
        };

        assert_eq!(key.algorithm(), SignatureAlgorithm::ECDSA);
        assert!(key
            .choose_scheme(&[SignatureScheme::ECDSA_NISTP256_SHA256])
            .is_some());
        assert!(key
            .choose_scheme(&[SignatureScheme::ECDSA_NISTP384_SHA384])
            .is_none());
        assert!(key
            .choose_scheme(&[SignatureScheme::RSA_PSS_SHA256])
            .is_none());
    }

    #[test]
    fn signer_uses_the_tls_ecdsa_p256_sha256_scheme() {
        let key = CngRustlsSigningKey {
            installation_id: "fd3ae0f1-1234-4234-9234-000000000001".into(),
        };
        let signer = key
            .choose_scheme(&[SignatureScheme::ECDSA_NISTP256_SHA256])
            .unwrap();

        assert_eq!(signer.scheme(), SignatureScheme::ECDSA_NISTP256_SHA256);
    }

    #[test]
    fn opening_a_rustls_signer_never_creates_a_missing_key() {
        let installation_id = format!(
            "missing-rustls-key-{}-{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        );

        assert!(CngRustlsSigningKey::open_existing(&installation_id).is_err());
        assert!(crate::cng_device_key::DeviceKey::open_existing(&installation_id).is_err());
    }
}
