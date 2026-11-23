#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${JAVA_HOME:-}" || ! -x "$JAVA_HOME/bin/java" ]]; then
    printf 'Set JAVA_HOME to an installed JDK 21 before running Android release validation.\n' >&2
    exit 1
fi
JAVA_MAJOR_VERSION="$("$JAVA_HOME/bin/java" -version 2>&1 | sed -n '1s/.*version "\([0-9][0-9]*\).*/\1/p')"
if [[ "$JAVA_MAJOR_VERSION" != "21" ]]; then
    printf 'Android release validation requires JDK 21.\n' >&2
    exit 1
fi
if [[ -z "${ANDROID_HOME:-}" ]]; then
    printf 'Set ANDROID_HOME to the installed Android SDK before running release validation.\n' >&2
    exit 1
fi

ANDROID_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ANDROID_BUILD_TOOLS="$ANDROID_HOME/build-tools/35.0.0"
if [[ ! -x "$ANDROID_BUILD_TOOLS/apksigner" || ! -x "$ANDROID_BUILD_TOOLS/aapt" ]]; then
    printf 'Android SDK build-tools 35.0.0 must be installed.\n' >&2
    exit 1
fi
TEMP_DIR="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/xlb-ci-release-validation.XXXXXX")"
TEST_KEYSTORE="$TEMP_DIR/validation.jks"
APK="$TEMP_DIR/build/outputs/apk/release/app-release.apk"

cleanup() {
    rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

"$JAVA_HOME/bin/keytool" -genkeypair -keystore "$TEST_KEYSTORE" -storepass ci-validation-only \
    -keypass ci-validation-only -alias ci-validation-only -keyalg RSA -keysize 2048 \
    -validity 1 -dname 'CN=XLB CI Ephemeral Signing Validation'

cd "$ANDROID_ROOT"
XLB_ANDROID_RELEASE_KEYSTORE_PATH="$TEST_KEYSTORE" \
XLB_ANDROID_RELEASE_STORE_PASSWORD=ci-validation-only \
XLB_ANDROID_RELEASE_KEY_ALIAS=ci-validation-only \
XLB_ANDROID_RELEASE_KEY_PASSWORD=ci-validation-only \
XLB_ANDROID_RELEASE_API_BASE_URL=https://release-signing-ci.test \
    ./gradlew --no-daemon --stacktrace -Dorg.gradle.jvmargs=-Xmx4g \
    -PxlbRequireReleaseSigning=true \
    -PxlbReleaseSigningValidationOnly=true \
    -PxlbReleaseSigningValidationBuildDir="$TEMP_DIR/build" :app:assembleRelease

SIGNATURE_REPORT="$("$ANDROID_BUILD_TOOLS/apksigner" verify --verbose --print-certs "$APK")"
printf '%s\n' "$SIGNATURE_REPORT"
EXPECTED_CERT_SHA256="$("$JAVA_HOME/bin/keytool" -exportcert -keystore "$TEST_KEYSTORE" \
    -storepass ci-validation-only -alias ci-validation-only | shasum -a 256 | awk '{print $1}')"
ACTUAL_CERT_SHA256="$(printf '%s\n' "$SIGNATURE_REPORT" \
    | awk '/Signer #1 certificate SHA-256 digest:/ {print tolower($NF); exit}')"
if [[ -z "$ACTUAL_CERT_SHA256" || "$ACTUAL_CERT_SHA256" != "$EXPECTED_CERT_SHA256" ]]; then
    printf 'Release APK signer did not match the ephemeral validation key.\n' >&2
    exit 1
fi
"$ANDROID_BUILD_TOOLS/aapt" dump badging "$APK" | grep -F "package: name='com.xlb.vpn'"
printf 'Ephemeral release signing configuration validation passed; temporary keystore and APK will be removed.\n'
