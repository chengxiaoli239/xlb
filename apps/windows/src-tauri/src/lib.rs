use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine as _};
use reqwest::{header, Method, Response, StatusCode};
use serde::{de::DeserializeOwned, Deserialize, Serialize};
use std::{collections::BTreeMap, sync::Arc, time::Duration};
use url::Url;

mod proof_bridge;
#[cfg(windows)]
mod cng_device_key;
#[cfg(windows)]
mod rustls_device_signer;

const KEYRING_SERVICE: &str = "com.xlb.vpn.windows";
const KEYRING_USER_PREFIX: &str = "api-session-cookies";
const INSTALLATION_ID_PREFIX: &str = "device-installation-id";
const API_PLACEHOLDER: &str = "https://api.xlb.invalid/";

pub trait CredentialStore: Send + Sync {
    fn load(&self) -> Result<Option<String>, String>;
    fn save(&self, value: &str) -> Result<(), String>;
    fn clear(&self) -> Result<(), String>;
    fn load_installation_id(&self) -> Result<Option<String>, String>;
    fn save_installation_id(&self, value: &str) -> Result<(), String>;
}

pub struct KeyringCredentialStore {
    account: String,
}

impl KeyringCredentialStore {
    fn for_api(base_url: &Url) -> Self {
        Self {
            account: format!(
                "{KEYRING_USER_PREFIX}@{}",
                base_url.origin().ascii_serialization()
            ),
        }
    }

    fn installation_identity_account(&self) -> String {
        self.account.replacen(KEYRING_USER_PREFIX, INSTALLATION_ID_PREFIX, 1)
    }

    fn load_or_create_installation_id(&self) -> Result<String, String> {
        match CredentialStore::load_installation_id(self)? {
            Some(value) if valid_installation_id(&value) => Ok(value),
            Some(_) => Err("本机设备身份格式无效".to_owned()),
            None => {
                let value = uuid::Uuid::new_v4().to_string();
                CredentialStore::save_installation_id(self, &value)?;
                Ok(value)
            }
        }
    }
}

impl CredentialStore for KeyringCredentialStore {
    fn load(&self) -> Result<Option<String>, String> {
        match keyring::Entry::new(KEYRING_SERVICE, &self.account)
            .map_err(|error| error.to_string())?
            .get_password()
        {
            Ok(value) => Ok(Some(value)),
            Err(keyring::Error::NoEntry) => Ok(None),
            Err(error) => Err(error.to_string()),
        }
    }

    fn save(&self, value: &str) -> Result<(), String> {
        keyring::Entry::new(KEYRING_SERVICE, &self.account)
            .map_err(|error| error.to_string())?
            .set_password(value)
            .map_err(|error| error.to_string())
    }

    fn clear(&self) -> Result<(), String> {
        match keyring::Entry::new(KEYRING_SERVICE, &self.account)
            .map_err(|error| error.to_string())?
            .delete_credential()
        {
            Ok(()) | Err(keyring::Error::NoEntry) => Ok(()),
            Err(error) => Err(error.to_string()),
        }
    }

    fn load_installation_id(&self) -> Result<Option<String>, String> {
        match keyring::Entry::new(KEYRING_SERVICE, &self.installation_identity_account())
            .map_err(|_| "无法读取本机设备身份".to_owned())?.get_password() {
            Ok(value) => Ok(Some(value)),
            Err(keyring::Error::NoEntry) => Ok(None),
            Err(_) => Err("无法读取本机设备身份".to_owned()),
        }
    }

    fn save_installation_id(&self, value: &str) -> Result<(), String> {
        keyring::Entry::new(KEYRING_SERVICE, &self.installation_identity_account())
            .map_err(|_| "无法保存本机设备身份".to_owned())?
            .set_password(value).map_err(|_| "无法保存本机设备身份".to_owned())
    }
}

fn valid_installation_id(value: &str) -> bool {
    uuid::Uuid::parse_str(value).is_ok()
}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum NodeAuthorizationStatus {
    Unavailable,
    Provisioning,
    Ready,
}

impl NodeAuthorizationStatus {
    fn from_status(value: Option<&str>) -> Self {
        match value {
            Some("provisioning") => Self::Provisioning,
            Some("ready") => Self::Ready,
            _ => Self::Unavailable,
        }
    }
}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub struct PublicUser {
    pub id: String,
    pub email: Option<String>,
    pub name: String,
    pub role: String,
    pub account_type: String,
}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq)]
pub struct Entitlement {
    pub status: String,
    pub ends_at: String,
    pub data_used_gb: f64,
    pub plan_name: String,
    pub period_label: String,
    pub traffic_gb: f64,
    pub max_devices: u32,
}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq)]
pub struct Overview {
    pub user: PublicUser,
    pub entitlement: Option<Entitlement>,
    #[serde(rename = "activeSubscriptions")]
    pub active_subscriptions: u32,
}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
pub struct Device {
    pub id: String,
    pub installation_id: String,
    pub name: String,
    pub platform: String,
    pub status: String,
    pub created_at: String,
}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
pub struct DeviceSummary {
    pub entitlement_active: bool,
    pub max_devices: u32,
    pub registered_count: u32,
    pub available_slots: u32,
    pub devices: Vec<Device>,
}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct AccountSnapshot {
    pub user: PublicUser,
    pub entitlement: Option<Entitlement>,
    pub active_subscriptions: u32,
    pub devices: DeviceSummary,
    pub node_authorization: NodeAuthorizationStatus,
}

trait DeviceProofProvider: Send + Sync {
    fn installation_id(&self) -> &str;
    fn public_key_spki_base64url(&self) -> Result<String, String>;
    fn sign_challenge_base64url(&self, challenge: &str) -> Result<String, String>;
}

#[cfg(windows)]
struct CngDeviceProof {
    installation_id: String,
}

#[cfg(windows)]
impl DeviceProofProvider for CngDeviceProof {
    fn installation_id(&self) -> &str { &self.installation_id }
    fn public_key_spki_base64url(&self) -> Result<String, String> {
        cng_device_key::DeviceKey::open_or_create(&self.installation_id)?
            .public_key_spki_base64url()
    }
    fn sign_challenge_base64url(&self, challenge: &str) -> Result<String, String> {
        let bytes = decode_base64url(challenge).ok_or_else(|| "设备授权响应无效".to_owned())?;
        cng_device_key::DeviceKey::open_or_create(&self.installation_id)?
            .sign_challenge_base64url(&bytes)
    }
}

#[derive(Debug, Deserialize)]
struct DeviceBindingChallenge {
    challenge_id: String,
    challenge: String,
}

#[derive(Deserialize)]
struct DeviceAuthorization {
    status: String,
    peer_id: Option<String>,
    node_id: Option<String>,
    peer_generation: Option<u64>,
    device_binding_generation: Option<u64>,
    peer_credential: Option<String>,
    profile: Option<String>,
}

#[derive(Deserialize)]
struct DeviceBindingResult {
    device: Option<BoundDevice>,
    node_authorization: Option<DeviceAuthorization>,
}

#[derive(Debug, Deserialize)]
struct BoundDevice {
    id: String,
    status: String,
    bound: bool,
}

#[derive(Debug, Deserialize)]
struct Envelope<T> {
    data: T,
}

#[derive(Debug, Deserialize)]
struct ApiErrorEnvelope {
    error: ApiError,
}

#[derive(Debug, Deserialize)]
struct ApiError {
    message: String,
}

#[derive(Debug, Deserialize)]
struct CsrfToken {
    token: String,
}

#[derive(Default)]
struct CookieSession {
    header: String,
}

impl CookieSession {
    fn update(&mut self, response: &Response) {
        let values = response
            .headers()
            .get_all(header::SET_COOKIE)
            .iter()
            .filter_map(|value| value.to_str().ok().map(str::to_owned))
            .collect::<Vec<_>>();
        self.update_values(values);
    }

    fn update_values(&mut self, values: impl IntoIterator<Item = String>) {
        let mut cookies = self
            .header
            .split(';')
            .filter_map(|cookie| cookie.trim().split_once('='))
            .map(|(name, value)| (name.to_owned(), value.to_owned()))
            .collect::<BTreeMap<_, _>>();

        for value in values {
            let Some((pair, attributes)) = value.split_once(';') else {
                if let Some((name, cookie_value)) = value.split_once('=') {
                    cookies.insert(name.trim().to_owned(), cookie_value.trim().to_owned());
                }
                continue;
            };
            let Some((name, cookie_value)) = pair.trim().split_once('=') else {
                continue;
            };
            if attributes.to_ascii_lowercase().contains("max-age=0") {
                cookies.remove(name.trim());
            } else {
                cookies.insert(name.trim().to_owned(), cookie_value.trim().to_owned());
            }
        }
        self.header = cookies
            .into_iter()
            .map(|(name, value)| format!("{name}={value}"))
            .collect::<Vec<_>>()
            .join("; ");
    }
}

pub struct AccountClient {
    base_url: Url,
    client: reqwest::Client,
    credentials: Arc<dyn CredentialStore>,
    session: CookieSession,
    device_proof: Option<Arc<dyn DeviceProofProvider>>,
    verified_member_device_proof: Option<proof_bridge::VerifiedMemberDeviceProof>,
}

impl AccountClient {
    pub fn new(base_url: &str, credentials: Arc<dyn CredentialStore>) -> Result<Self, String> {
        let base_url = Url::parse(base_url).map_err(|_| "API 地址无效".to_owned())?;
        let is_loopback = base_url
            .host_str()
            .map(|host| host == "localhost" || host == "127.0.0.1" || host == "[::1]")
            .unwrap_or(false);
        if base_url.scheme() != "https" && !(base_url.scheme() == "http" && is_loopback) {
            return Err("仅允许 HTTPS API 地址；HTTP 只可用于本机开发服务器".to_owned());
        }
        if !base_url.path().ends_with('/') {
            return Err("API 地址路径必须以 / 结尾".to_owned());
        }

        Ok(Self {
            base_url,
            client: reqwest::Client::builder()
                .default_headers(api_default_headers())
                .redirect(reqwest::redirect::Policy::none())
                .timeout(Duration::from_secs(15))
                .build()
                .map_err(|_| "无法初始化 API 客户端".to_owned())?,
            credentials,
            session: CookieSession::default(),
            device_proof: None,
            verified_member_device_proof: None,
        })
    }

    #[cfg(test)]
    fn with_device_proof(mut self, proof: Arc<dyn DeviceProofProvider>) -> Self {
        self.device_proof = Some(proof);
        self
    }

    pub fn load_persisted_session(&mut self) -> Result<(), String> {
        self.session.header = self.credentials.load()?.unwrap_or_default();
        Ok(())
    }

    pub async fn restore(&mut self) -> Result<Option<AccountSnapshot>, String> {
        self.verified_member_device_proof = None;
        if self.session.header.is_empty() {
            self.load_persisted_session()?;
        }
        if self.session.header.is_empty() {
            return Ok(None);
        }

        let response = self.request(Method::GET, "api/v1/auth/session").await?;
        if response.status() == StatusCode::UNAUTHORIZED {
            self.clear_session()?;
            return Ok(None);
        }
        if !response.status().is_success() {
            return Err(api_error(response).await);
        }
        let user = parse_data::<Option<PublicUser>>(response).await?;
        let Some(user) = user else {
            self.clear_session()?;
            return Ok(None);
        };
        if !is_member(&user) {
            self.clear_session()?;
            return Err("此客户端只支持会员账户".to_owned());
        }
        self.load_snapshot(user).await.map(Some)
    }

    pub async fn login(&mut self, email: &str, password: &str) -> Result<AccountSnapshot, String> {
        self.verified_member_device_proof = None;
        let email = email.trim();
        if email.is_empty() || password.is_empty() {
            return Err("请输入邮箱和密码".to_owned());
        }
        let csrf_response = self.request(Method::GET, "api/v1/auth/csrf").await?;
        let csrf = parse_data::<CsrfToken>(csrf_response).await?.token;
        let response = self
            .client
            .post(self.endpoint("api/v1/auth/login")?)
            .header(header::CONTENT_TYPE, "application/json")
            .header("X-CSRF-TOKEN", csrf)
            .header(header::COOKIE, self.session.header.clone())
            .json(&serde_json::json!({ "email": email, "password": password }))
            .send()
            .await
            .map_err(|_| "无法连接会员 API".to_owned())?;
        self.session.update(&response);
        self.persist_cookies()?;
        let user = parse_data::<PublicUser>(response).await?;
        if !is_member(&user) {
            self.clear_session()?;
            return Err("此客户端只支持会员账户".to_owned());
        }
        self.load_snapshot(user).await
    }

    pub async fn logout(&mut self) -> Result<(), String> {
        self.verified_member_device_proof = None;
        let result = async {
            let csrf_response = self.request(Method::GET, "api/v1/auth/csrf").await?;
            let csrf = parse_data::<CsrfToken>(csrf_response).await?.token;
            let response = self
                .client
                .post(self.endpoint("api/v1/auth/logout")?)
                .header("X-CSRF-TOKEN", csrf)
                .header(header::COOKIE, self.session.header.clone())
                .send()
                .await
                .map_err(|_| "无法连接会员 API".to_owned())?;
            self.session.update(&response);
            if response.status().is_success() {
                Ok(())
            } else {
                Err(api_error(response).await)
            }
        }
        .await;
        self.clear_session()?;
        result
    }

    async fn load_snapshot(&mut self, user: PublicUser) -> Result<AccountSnapshot, String> {
        self.verified_member_device_proof = None;
        let overview_response = self.request(Method::GET, "api/v1/me/overview").await?;
        let overview = parse_data::<Overview>(overview_response).await?;
        if !is_member(&overview.user) || overview.user.id != user.id {
            self.clear_session()?;
            return Err("会员账户状态不匹配，请重新登录".to_owned());
        }
        let mut node_authorization = self.authorize_device(&user.id).await;
        let devices_response = match self.request(Method::GET, "api/v1/me/devices").await {
            Ok(response) => response,
            Err(error) => {
                self.verified_member_device_proof = None;
                return Err(error);
            }
        };
        let devices = match parse_data::<DeviceSummary>(devices_response).await {
            Ok(value) => value,
            Err(error) => {
                self.verified_member_device_proof = None;
                return Err(error);
            }
        };
        if !self
            .verified_member_device_proof
            .as_ref()
            .is_some_and(|proof| proof.matches_device_snapshot(&devices))
        {
            self.verified_member_device_proof = None;
            if node_authorization == NodeAuthorizationStatus::Ready {
                node_authorization = NodeAuthorizationStatus::Unavailable;
            }
        }
        Ok(AccountSnapshot {
            user: overview.user,
            entitlement: overview.entitlement,
            active_subscriptions: overview.active_subscriptions,
            devices,
            node_authorization,
        })
    }

    async fn authorize_device(&mut self, member_id: &str) -> NodeAuthorizationStatus {
        self.verified_member_device_proof = None;
        #[cfg(windows)]
        if self.device_proof.is_none() {
            let installation_id = match self.installation_id() {
                Ok(value) => value,
                Err(_) => return NodeAuthorizationStatus::Unavailable,
            };
            if cng_device_key::DeviceKey::open_or_create(&installation_id).is_err() {
                return NodeAuthorizationStatus::Unavailable;
            }
            self.device_proof = Some(Arc::new(CngDeviceProof { installation_id }));
        }

        let Some(proof) = self.device_proof.as_ref().cloned() else {
            return NodeAuthorizationStatus::Unavailable;
        };
        let registered_public_key = match proof.public_key_spki_base64url() {
            Ok(public_key) => public_key,
            Err(_) => return NodeAuthorizationStatus::Unavailable,
        };
        let request_body = serde_json::json!({
            "installation_id": proof.installation_id(),
            "name": windows_device_name(),
            "platform": "windows",
            "public_key": registered_public_key,
        });
        let challenge_response = match self.post_json("api/v1/me/device-bindings/challenge", request_body).await {
            Ok(response) => response,
            Err(_) => return NodeAuthorizationStatus::Unavailable,
        };
        let challenge = match parse_data::<DeviceBindingChallenge>(challenge_response).await {
            Ok(value) if valid_challenge(&value) => value,
            _ => return NodeAuthorizationStatus::Unavailable,
        };
        let signature = match proof.sign_challenge_base64url(&challenge.challenge) {
            Ok(value) => value,
            Err(_) => return NodeAuthorizationStatus::Unavailable,
        };
        let authorization_response = match self.post_json(
            "api/v1/me/device-bindings/authorize",
            serde_json::json!({ "challenge_id": challenge.challenge_id, "signature": signature }),
        ).await {
            Ok(response) => response,
            Err(_) => return NodeAuthorizationStatus::Unavailable,
        };
        let result = match parse_data::<DeviceBindingResult>(authorization_response).await {
            Ok(value) => value,
            Err(_) => return NodeAuthorizationStatus::Unavailable,
        };
        let Some(device) = result.device.as_ref().filter(|device| {
            device.bound
                && device.status == "registered"
                && uuid::Uuid::parse_str(&device.id).is_ok()
        }) else {
            return NodeAuthorizationStatus::Unavailable;
        };
        let Some(authorization) = result.node_authorization.as_ref() else {
            return NodeAuthorizationStatus::Unavailable;
        };
        let status = NodeAuthorizationStatus::from_status(Some(authorization.status.as_str()));
        if status != NodeAuthorizationStatus::Ready {
            return status;
        }
        let Some(signer) = self.device_proof.as_ref().cloned() else {
            return NodeAuthorizationStatus::Unavailable;
        };
        let installation_id = signer.installation_id().to_owned();
        match proof_bridge::VerifiedMemberDeviceProof::from_authorized_response(
            member_id,
            &installation_id,
            device,
            authorization,
            &registered_public_key,
            signer,
        ) {
            Ok(proof) => {
                self.verified_member_device_proof = Some(proof);
                NodeAuthorizationStatus::Ready
            }
            Err(_) => NodeAuthorizationStatus::Unavailable,
        }
    }

    #[cfg(windows)]
    fn installation_id(&self) -> Result<String, String> {
        KeyringCredentialStore::for_api(&self.base_url).load_or_create_installation_id()
    }

    async fn post_json(&mut self, path: &str, body: serde_json::Value) -> Result<Response, String> {
        let csrf_response = self.request(Method::GET, "api/v1/auth/csrf").await?;
        let csrf = parse_data::<CsrfToken>(csrf_response).await?.token;
        let response = self.client
            .post(self.endpoint(path).map_err(|_| "API 地址无效".to_owned())?)
            .header(header::CONTENT_TYPE, "application/json")
            .header("X-CSRF-TOKEN", csrf)
            .header(header::COOKIE, self.session.header.clone())
            .json(&body)
            .send().await.map_err(|_| "无法连接会员 API".to_owned())?;
        self.session.update(&response);
        self.persist_cookies()?;
        Ok(response)
    }

    async fn request(&mut self, method: Method, path: &str) -> Result<Response, String> {
        let mut request = self.client.request(method, self.endpoint(path)?);
        if !self.session.header.is_empty() {
            request = request.header(header::COOKIE, self.session.header.clone());
        }
        let response = request
            .send()
            .await
            .map_err(|_| "无法连接会员 API".to_owned())?;
        self.session.update(&response);
        self.persist_cookies()?;
        Ok(response)
    }

    fn endpoint(&self, path: &str) -> Result<Url, String> {
        self.base_url
            .join(path)
            .map_err(|_| "API 地址无效".to_owned())
    }

    fn persist_cookies(&self) -> Result<(), String> {
        if self.session.header.is_empty() {
            self.credentials.clear()
        } else {
            self.credentials.save(&self.session.header)
        }
    }

    fn clear_session(&mut self) -> Result<(), String> {
        self.verified_member_device_proof = None;
        self.session.header.clear();
        self.credentials.clear()
    }
}

fn api_default_headers() -> header::HeaderMap {
    let mut headers = header::HeaderMap::new();
    headers.insert(
        header::ACCEPT,
        header::HeaderValue::from_static("application/json"),
    );
    headers
}

fn configured_api_base_url(
    is_debug: bool,
    runtime_value: Option<&str>,
    build_time_value: Option<&str>,
) -> String {
    let value = if is_debug {
        runtime_value
    } else {
        build_time_value
    };
    value.unwrap_or(API_PLACEHOLDER).to_owned()
}

async fn parse_data<T: DeserializeOwned>(response: Response) -> Result<T, String> {
    if !response.status().is_success() {
        return Err(api_error(response).await);
    }
    let envelope = response
        .json::<Envelope<T>>()
        .await
        .map_err(|_| "会员 API 返回了无法识别的数据".to_owned())?;
    Ok(envelope.data)
}

async fn api_error(response: Response) -> String {
    let status = response.status();
    match response.json::<ApiErrorEnvelope>().await {
        Ok(error) => error.error.message,
        Err(_) if status == StatusCode::UNAUTHORIZED => "登录状态已失效，请重新登录".to_owned(),
        Err(_) => format!("会员 API 请求失败（HTTP {}）", status.as_u16()),
    }
}

fn is_member(user: &PublicUser) -> bool {
    user.role == "member"
}

fn valid_challenge(challenge: &DeviceBindingChallenge) -> bool {
    uuid::Uuid::parse_str(&challenge.challenge_id).is_ok()
        && decode_base64url(&challenge.challenge).is_some_and(|bytes| bytes.len() == 32)
}

fn decode_base64url(input: &str) -> Option<Vec<u8>> {
    if input.is_empty() || input.len() % 4 == 1
        || !input.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'_' || byte == b'-') {
        return None;
    }
    URL_SAFE_NO_PAD.decode(input).ok()
}

fn windows_device_name() -> String {
    let name = std::env::var("COMPUTERNAME").unwrap_or_default();
    let name: String = name.trim().chars().take(80).collect();
    if name.is_empty() { "Windows device".to_owned() } else { name }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;
    #[cfg(target_os = "windows")]
    use std::time::{SystemTime, UNIX_EPOCH};

    #[derive(Default)]
    struct MemoryStore {
        session: Mutex<Option<String>>,
        installation_id: Mutex<Option<String>>,
    }

    impl CredentialStore for MemoryStore {
        fn load(&self) -> Result<Option<String>, String> {
            Ok(self.session.lock().unwrap().clone())
        }

        fn save(&self, value: &str) -> Result<(), String> {
            *self.session.lock().unwrap() = Some(value.to_owned());
            Ok(())
        }

        fn clear(&self) -> Result<(), String> {
            *self.session.lock().unwrap() = None;
            Ok(())
        }

        fn load_installation_id(&self) -> Result<Option<String>, String> {
            Ok(self.installation_id.lock().unwrap().clone())
        }

        fn save_installation_id(&self, value: &str) -> Result<(), String> {
            *self.installation_id.lock().unwrap() = Some(value.to_owned());
            Ok(())
        }
    }

    fn member_user(role: &str) -> PublicUser {
        PublicUser {
            id: "user-1".to_owned(),
            email: Some("member@example.test".to_owned()),
            name: "Test Member".to_owned(),
            role: role.to_owned(),
            account_type: "full".to_owned(),
        }
    }

    #[test]
    fn parses_laravel_member_overview_envelope() {
        let envelope = serde_json::json!({
            "data": {
                "user": { "id": "u1", "email": "m@example.test", "name": "M", "role": "member", "account_type": "full" },
                "entitlement": { "status": "active", "ends_at": "2027-01-01", "data_used_gb": 12.5, "plan_name": "Annual", "period_label": "Annual", "traffic_gb": 1500, "max_devices": 5 },
                "activeSubscriptions": 1
            }
        });
        let parsed: Envelope<Overview> = serde_json::from_value(envelope).unwrap();
        assert_eq!(parsed.data.user.role, "member");
        assert_eq!(parsed.data.entitlement.unwrap().max_devices, 5);
        assert_eq!(parsed.data.active_subscriptions, 1);
    }

    #[test]
    fn rejects_non_member_roles() {
        assert!(is_member(&member_user("member")));
        assert!(!is_member(&member_user("admin")));
        assert!(!is_member(&member_user("support")));
    }

    #[test]
    fn limits_plain_http_api_to_loopback_and_requires_a_trailing_slash() {
        let credentials = Arc::new(MemoryStore::default());
        assert!(AccountClient::new("http://127.0.0.1:4175/", credentials.clone()).is_ok());
        assert!(AccountClient::new("http://api.example.test/", credentials.clone()).is_err());
        assert!(AccountClient::new("https://api.example.test/", credentials.clone()).is_ok());
        assert!(AccountClient::new("https://api.example.test/api", credentials).is_err());
    }

    #[test]
    fn debug_uses_runtime_api_override_but_release_uses_only_build_time_value() {
        assert_eq!(
            configured_api_base_url(true, Some("http://127.0.0.1:4175/"), None),
            "http://127.0.0.1:4175/"
        );
        assert_eq!(
            configured_api_base_url(
                false,
                Some("https://attacker.example/"),
                Some("https://configured.example/"),
            ),
            "https://configured.example/"
        );
        assert_eq!(
            configured_api_base_url(false, Some("https://attacker.example/"), None),
            API_PLACEHOLDER
        );
    }

    #[test]
    fn installation_identity_is_a_stable_uuid_and_rejects_corrupt_values() {
        assert!(valid_installation_id("fd3ae0f1-1234-4234-9234-000000000001"));
        assert!(!valid_installation_id("not-an-installation-id"));
        let store = MemoryStore::default();
        assert_eq!(store.load_installation_id().unwrap(), None);
        store.save_installation_id("fd3ae0f1-1234-4234-9234-000000000001").unwrap();
        assert_eq!(store.load_installation_id().unwrap().as_deref(),
            Some("fd3ae0f1-1234-4234-9234-000000000001"));
    }

    #[test]
    fn node_authorization_status_is_closed_to_known_values() {
        assert_eq!(NodeAuthorizationStatus::from_status(Some("ready")), NodeAuthorizationStatus::Ready);
        assert_eq!(NodeAuthorizationStatus::from_status(Some("provisioning")), NodeAuthorizationStatus::Provisioning);
        assert_eq!(NodeAuthorizationStatus::from_status(Some("connected")), NodeAuthorizationStatus::Unavailable);
        assert_eq!(NodeAuthorizationStatus::from_status(None), NodeAuthorizationStatus::Unavailable);
    }

    #[test]
    fn binding_result_requires_a_bound_device_identity() {
        let valid: Envelope<DeviceBindingResult> = serde_json::from_value(serde_json::json!({
            "data": {
                "device": { "id": "fd3ae0f1-1234-4234-9234-000000000001", "status": "registered", "bound": true },
                "node_authorization": { "status": "ready" }
            }
        })).unwrap();
        assert!(valid.data.device.as_ref().is_some_and(|device| device.bound));
        let invalid: Envelope<DeviceBindingResult> = serde_json::from_value(serde_json::json!({
            "data": { "node_authorization": { "status": "ready" } }
        })).unwrap();
        assert!(invalid.data.device.is_none());
    }

    #[test]
    fn malformed_challenge_fails_closed() {
        let valid = DeviceBindingChallenge {
            challenge_id: "fd3ae0f1-1234-4234-9234-000000000001".into(),
            challenge: URL_SAFE_NO_PAD.encode([7u8; 32]),
        };
        assert!(valid_challenge(&valid));
        assert!(!valid_challenge(&DeviceBindingChallenge { challenge_id: "bad".into(), challenge: valid.challenge.clone() }));
        assert!(!valid_challenge(&DeviceBindingChallenge { challenge_id: valid.challenge_id, challenge: URL_SAFE_NO_PAD.encode([7u8; 31]) }));
    }

    struct FakeDeviceProof {
        installation_id: String,
    }

    impl DeviceProofProvider for FakeDeviceProof {
        fn installation_id(&self) -> &str { &self.installation_id }
        fn public_key_spki_base64url(&self) -> Result<String, String> { Ok("test-public-key".into()) }
        fn sign_challenge_base64url(&self, challenge: &str) -> Result<String, String> {
            if decode_base64url(challenge).is_some_and(|value| value.len() == 32) {
                Ok("test-signature".into())
            } else {
                Err("invalid challenge".into())
            }
        }
    }

    #[tokio::test]
    async fn snapshot_runs_device_challenge_before_loading_devices_and_keeps_ready_as_auth_only() {
        use std::{io::{Read, Write}, net::TcpListener, thread};

        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let address = listener.local_addr().unwrap();
        let challenge_id = "fd3ae0f1-1234-4234-9234-000000000001";
        let challenge = URL_SAFE_NO_PAD.encode([9u8; 32]);
        let peer_credential = URL_SAFE_NO_PAD.encode([0x41u8; 32]);
        let profile = "mode: global\nproxy-groups:\n  - name: GLOBAL\n";
        let responses = vec![
            serde_json::json!({"data": member_user("member")}),
            serde_json::json!({"data": {"user": member_user("member"), "entitlement": null, "activeSubscriptions": 0}}),
            serde_json::json!({"data": {"token": "csrf"}}),
            serde_json::json!({"data": {"challenge_id": challenge_id, "challenge": challenge}}),
            serde_json::json!({"data": {"token": "csrf"}}),
            serde_json::json!({"data": {
                "device": {"id": "fd3ae0f1-1234-4234-9234-000000000002", "status": "registered", "bound": true},
                "node_authorization": {
                    "status": "ready",
                    "peer_id": "fd3ae0f1-1234-4234-9234-000000000003",
                    "node_id": "fd3ae0f1-1234-4234-9234-000000000004",
                    "peer_generation": 1,
                    "device_binding_generation": 1,
                    "peer_credential": peer_credential.clone(),
                    "profile": profile
                }
            }}),
            serde_json::json!({"data": {"entitlement_active": true, "max_devices": 5, "registered_count": 1, "available_slots": 4, "devices": [{"id": "fd3ae0f1-1234-4234-9234-000000000002", "installation_id": "fd3ae0f1-1234-4234-9234-000000000002", "name": "Windows", "platform": "windows", "status": "registered", "created_at": "2026-11-22T00:00:00Z"}]}}),
        ];
        let server = thread::spawn(move || {
            let mut paths = Vec::new();
            for body in responses {
                let (mut stream, _) = listener.accept().unwrap();
                let mut request = Vec::new();
                let mut buffer = [0u8; 4096];
                let (header_end, content_length) = loop {
                    let count = stream.read(&mut buffer).unwrap();
                    if count == 0 { panic!("request ended before headers were complete"); }
                    request.extend_from_slice(&buffer[..count]);
                    if let Some(index) = request.windows(4).position(|window| window == b"\r\n\r\n") {
                        let headers = String::from_utf8_lossy(&request[..index]);
                        let length = headers.lines().find_map(|line| {
                            let (name, value) = line.split_once(':')?;
                            name.eq_ignore_ascii_case("content-length").then(|| value.trim().parse::<usize>().ok()).flatten()
                        }).unwrap_or(0);
                        if request.len() >= index + 4 + length {
                            break (index, length);
                        }
                    }
                };
                let text = String::from_utf8_lossy(&request);
                let path = text.lines().next().unwrap_or_default().to_owned();
                paths.push(path.clone());
                let body_text = String::from_utf8_lossy(&request[header_end + 4..header_end + 4 + content_length]);
                if path.contains("device-bindings/challenge") {
                    assert!(body_text.contains("\"platform\":\"windows\""));
                    assert!(body_text.contains("\"public_key\":\"test-public-key\""));
                    assert!(body_text.contains("\"installation_id\":\"fd3ae0f1-1234-4234-9234-000000000002\""));
                }
                if path.contains("device-bindings/authorize") {
                    assert!(text.to_ascii_lowercase().contains("x-csrf-token: csrf"));
                    assert!(body_text.contains(&format!("\"challenge_id\":\"{challenge_id}\"")));
                    assert!(body_text.contains("\"signature\":\"test-signature\""));
                }
                let content_length = body.to_string().len();
                let response = format!("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {content_length}\r\nConnection: close\r\n\r\n{body}");
                stream.write_all(response.as_bytes()).unwrap();
            }
            paths
        });

        let store = Arc::new(MemoryStore::default());
        store.save("laravel_session=test").unwrap();
        let mut client = AccountClient::new(&format!("http://{address}/"), store.clone()).unwrap()
            .with_device_proof(Arc::new(FakeDeviceProof {
                installation_id: "fd3ae0f1-1234-4234-9234-000000000002".into(),
            }));
        client.load_persisted_session().unwrap();
        let snapshot = client.restore().await.unwrap().unwrap();
        let paths = server.join().unwrap();
        assert_eq!(snapshot.node_authorization, NodeAuthorizationStatus::Ready);
        assert!(client.verified_member_device_proof.is_some());
        assert_eq!(snapshot.devices.registered_count, 1);
        let snapshot_json = serde_json::to_string(&snapshot).unwrap();
        assert!(!snapshot_json.contains(&peer_credential));
        assert!(!snapshot_json.contains(profile));
        assert!(paths[3].contains("device-bindings/challenge"));
        assert!(paths[5].contains("device-bindings/authorize"));
        assert!(paths[6].contains("me/devices"));
        assert!(!paths[6].contains("device-bindings"));
    }

    #[test]
    fn scopes_credential_entries_to_the_api_origin() {
        let first = Url::parse("https://api.example.test/").unwrap();
        let second = Url::parse("https://other.example.test/").unwrap();
        assert_ne!(
            KeyringCredentialStore::for_api(&first).account,
            KeyringCredentialStore::for_api(&second).account
        );
    }

    #[cfg(target_os = "windows")]
    #[test]
    fn windows_credential_store_round_trips_and_clears_a_temporary_entry() {
        struct Cleanup<'a>(&'a KeyringCredentialStore);

        impl Drop for Cleanup<'_> {
            fn drop(&mut self) {
                let _ = self.0.clear();
            }
        }

        let nonce = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let store = KeyringCredentialStore {
            account: format!(
                "{KEYRING_USER_PREFIX}@ci-test-{}-{nonce}",
                std::process::id()
            ),
        };
        let _cleanup = Cleanup(&store);

        store.clear().unwrap();
        store.save("temporary-test-only-cookie").unwrap();
        assert_eq!(
            store.load().unwrap().as_deref(),
            Some("temporary-test-only-cookie")
        );
        store.clear().unwrap();
        assert_eq!(store.load().unwrap(), None);
    }

    #[test]
    fn api_client_defaults_to_json_accept_for_laravel_errors() {
        let headers = api_default_headers();
        assert_eq!(headers.get(header::ACCEPT).unwrap(), "application/json");
    }

    #[test]
    fn updates_rotated_cookies_and_removes_expired_session_cookie() {
        let mut cookies = CookieSession {
            header: "laravel_session=old; XSRF-TOKEN=csrf".to_owned(),
        };
        cookies.update_values([
            "laravel_session=new; Path=/; HttpOnly".to_owned(),
            "XSRF-TOKEN=; Max-Age=0; Path=/".to_owned(),
        ]);
        assert_eq!(cookies.header, "laravel_session=new");
    }

    #[test]
    fn parses_device_quota_without_inferring_a_limit_was_enforced() {
        let envelope = serde_json::json!({
            "data": {
                "entitlement_active": true,
                "max_devices": 2,
                "registered_count": 2,
                "available_slots": 0,
                "devices": []
            }
        });
        let parsed: Envelope<DeviceSummary> = serde_json::from_value(envelope).unwrap();
        assert_eq!(parsed.data.registered_count, parsed.data.max_devices);
        assert_eq!(parsed.data.available_slots, 0);
        assert!(parsed.data.devices.is_empty());
    }
}

pub struct AppState {
    account: tokio::sync::Mutex<Result<AccountClient, String>>,
    api_base_url: String,
}

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
pub struct LocalTestCredentials {
    email: String,
    password: String,
}

#[tauri::command]
async fn restore_session(
    state: tauri::State<'_, AppState>,
) -> Result<Option<AccountSnapshot>, String> {
    match state.account.lock().await.as_mut() {
        Ok(account) => account.restore().await,
        Err(error) => Err(error.clone()),
    }
}

#[tauri::command]
async fn login(
    email: String,
    password: String,
    state: tauri::State<'_, AppState>,
) -> Result<AccountSnapshot, String> {
    match state.account.lock().await.as_mut() {
        Ok(account) => account.login(&email, &password).await,
        Err(error) => Err(error.clone()),
    }
}

#[tauri::command]
async fn logout(state: tauri::State<'_, AppState>) -> Result<(), String> {
    match state.account.lock().await.as_mut() {
        Ok(account) => account.logout().await,
        Err(error) => Err(error.clone()),
    }
}

#[tauri::command]
fn local_test_credentials(state: tauri::State<'_, AppState>) -> Option<LocalTestCredentials> {
    #[cfg(debug_assertions)]
    {
        let email = std::env::var("XLB_LOCAL_MEMBER_EMAIL").ok()?;
        let password = std::env::var("XLB_LOCAL_MEMBER_PASSWORD").ok()?;
        let api_url = Url::parse(&state.api_base_url).ok()?;
        let host = api_url.host_str()?;
        if host != "localhost" && host != "127.0.0.1" && host != "[::1]" {
            return None;
        }
        return Some(LocalTestCredentials { email, password });
    }

    #[cfg(not(debug_assertions))]
    {
        let _ = state;
        None
    }
}

pub fn run() {
    let runtime_api_base_url = std::env::var("XLB_API_BASE_URL").ok();
    let api_base_url = configured_api_base_url(
        cfg!(debug_assertions),
        runtime_api_base_url.as_deref(),
        option_env!("XLB_API_BASE_URL"),
    );
    let account = Url::parse(&api_base_url)
        .map_err(|_| "API 地址无效".to_owned())
        .and_then(|parsed| {
            AccountClient::new(
                &api_base_url,
                Arc::new(KeyringCredentialStore::for_api(&parsed)),
            )
        });
    let state = AppState {
        account: tokio::sync::Mutex::new(account),
        api_base_url,
    };

    tauri::Builder::default()
        .manage(state)
        .invoke_handler(tauri::generate_handler![
            restore_session,
            login,
            logout,
            local_test_credentials
        ])
        .run(tauri::generate_context!())
        .expect("failed to run XLB Windows account shell");
}
