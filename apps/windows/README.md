# Windows account shell

This Tauri + React stage provides member login, persisted session recovery, account entitlement and registered-device summaries. Windows uses a CNG-backed installation key for device challenge/authorization and validates the returned server-authorized tunnel grant. It does not include a bridge process, VPN engine, TUN/system proxy integration, node data path, or connection controls. `PROOF_BRIDGE_ENABLED` remains false, so this account/proof capability is not a usable VPN connection.

## Local development

Debug builds read `XLB_API_BASE_URL` from the runtime environment. The value must be an HTTPS base URL ending with `/`; plain HTTP is accepted only for loopback development servers. Release builds ignore the runtime variable and use only the API URL embedded at compile time from `XLB_API_BASE_URL`. If no build-time URL is supplied, the app uses the non-routable placeholder `https://api.xlb.invalid/`. The repository does not currently define a production API domain, so do not invent one when producing release artifacts.

For an isolated local/testing account, a debug build may read `XLB_LOCAL_MEMBER_EMAIL` and `XLB_LOCAL_MEMBER_PASSWORD` from the environment and show a button that fills the login form. The option is disabled in release builds and for non-loopback API hosts. Do not put real credentials in source control.

From the repository root:

```sh
pnpm --filter @xlb/windows test
pnpm --filter @xlb/windows build
pnpm --filter @xlb/windows tauri dev
```

The API session cookie and CSRF token are handled by Rust. The session cookie is persisted through the platform credential store (`keyring`); Windows Credential Manager behavior still requires a Windows build and runtime check.
