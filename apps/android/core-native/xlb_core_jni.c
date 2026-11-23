#include <jni.h>
#include <pthread.h>
#include "callbacks.h"
#ifdef XLB_ANDROID_TEST_SIGNER_CALLBACK
extern int XlbCompleteAndroidSignerHandshake(const char *, int);
#endif
extern void XlbInit(char *, int (*)(int));
extern char *XlbLoad(char *, int);
extern char *XlbFirstRouteName(char *, int);
extern char *XlbStartTun(int);
extern void XlbStop(void);
extern void XlbFreeString(void *);
extern char *XlbCreateAndroidDeviceCertificate(char *, char *, char *, int, int, char *, int);
extern void XlbReleaseAndroidDeviceCertificate(void);

static JavaVM *vm;
static jobject protector;
static jobject digest_signer_callback;
static pthread_mutex_t digest_signer_mutex = PTHREAD_MUTEX_INITIALIZER;

static jint attach_current_thread(JavaVM *java_vm, JNIEnv **env) {
#ifdef __ANDROID__
    return (*java_vm)->AttachCurrentThread(java_vm, env, NULL);
#else
    return (*java_vm)->AttachCurrentThread(java_vm, (void **)env, NULL);
#endif
}

static int protect_socket(int fd) {
    JNIEnv *env = NULL;
    int detach = 0;
    if ((*vm)->GetEnv(vm, (void **)&env, JNI_VERSION_1_6) != JNI_OK) {
        if (attach_current_thread(vm, &env) != JNI_OK) return 0;
        detach = 1;
    }
    jclass cls = (*env)->GetObjectClass(env, protector);
    jmethodID method = (*env)->GetMethodID(env, cls, "protect", "(I)Z");
    jboolean result = method == NULL ? JNI_FALSE : (*env)->CallBooleanMethod(env, protector, method, fd);
    if ((*env)->ExceptionCheck(env)) {
        (*env)->ExceptionClear(env);
        result = JNI_FALSE;
    }
    (*env)->DeleteLocalRef(env, cls);
    if (detach) (*vm)->DetachCurrentThread(vm);
    return result == JNI_TRUE;
}

int xlb_install_java_signer(void *raw_env, void *raw_signer) {
    JNIEnv *env = (JNIEnv *)raw_env;
    jobject signer = (jobject)raw_signer;
    if (env == NULL || signer == NULL) return 0;

    pthread_mutex_lock(&digest_signer_mutex);
    if (digest_signer_callback != NULL) {
        pthread_mutex_unlock(&digest_signer_mutex);
        return 0;
    }
    digest_signer_callback = (*env)->NewGlobalRef(env, signer);
    int installed = digest_signer_callback != NULL && !(*env)->ExceptionCheck(env);
    if (!installed) {
        if ((*env)->ExceptionCheck(env)) (*env)->ExceptionClear(env);
        if (digest_signer_callback != NULL) (*env)->DeleteGlobalRef(env, digest_signer_callback);
        digest_signer_callback = NULL;
    }
    pthread_mutex_unlock(&digest_signer_mutex);
    return installed;
}

void xlb_clear_java_signer(void *raw_env) {
    JNIEnv *env = (JNIEnv *)raw_env;
    if (env == NULL) return;

    pthread_mutex_lock(&digest_signer_mutex);
    if (digest_signer_callback != NULL) (*env)->DeleteGlobalRef(env, digest_signer_callback);
    digest_signer_callback = NULL;
    pthread_mutex_unlock(&digest_signer_mutex);
}

int xlb_call_java_sign_digest(const unsigned char *digest, int digest_len,
        unsigned char *signature, int signature_capacity) {
    JNIEnv *env = NULL;
    int detach = 0;
    if (vm == NULL || digest == NULL || digest_len != 32 || signature == NULL || signature_capacity < 1) return -1;
    if ((*vm)->GetEnv(vm, (void **)&env, JNI_VERSION_1_6) != JNI_OK) {
        if (attach_current_thread(vm, &env) != JNI_OK) return -1;
        detach = 1;
    }

    pthread_mutex_lock(&digest_signer_mutex);
    jobject signer = digest_signer_callback == NULL ? NULL : (*env)->NewLocalRef(env, digest_signer_callback);
    jclass cls = signer == NULL ? NULL : (*env)->GetObjectClass(env, signer);
    jmethodID method = cls == NULL ? NULL : (*env)->GetMethodID(env, cls, "signDigest", "([B)[B");
    jbyteArray digest_array = (*env)->NewByteArray(env, digest_len);
    jbyteArray signed_array = NULL;
    if (signer == NULL || method == NULL || digest_array == NULL) goto failed;
    (*env)->SetByteArrayRegion(env, digest_array, 0, digest_len, (const jbyte *)digest);
    if ((*env)->ExceptionCheck(env)) goto failed;
    signed_array = (jbyteArray)(*env)->CallObjectMethod(env, signer, method, digest_array);
    if ((*env)->ExceptionCheck(env) || signed_array == NULL) goto failed;
    jsize signed_len = (*env)->GetArrayLength(env, signed_array);
    if (signed_len < 1 || signed_len > signature_capacity) goto failed;
    (*env)->GetByteArrayRegion(env, signed_array, 0, signed_len, (jbyte *)signature);
    if ((*env)->ExceptionCheck(env)) goto failed;
    (*env)->DeleteLocalRef(env, signed_array);
    (*env)->DeleteLocalRef(env, digest_array);
    (*env)->DeleteLocalRef(env, cls);
    (*env)->DeleteLocalRef(env, signer);
    pthread_mutex_unlock(&digest_signer_mutex);
    if (detach) (*vm)->DetachCurrentThread(vm);
    return (int)signed_len;

failed:
    if ((*env)->ExceptionCheck(env)) (*env)->ExceptionClear(env);
    if (signed_array != NULL) (*env)->DeleteLocalRef(env, signed_array);
    if (cls != NULL) (*env)->DeleteLocalRef(env, cls);
    if (digest_array != NULL) (*env)->DeleteLocalRef(env, digest_array);
    if (signer != NULL) (*env)->DeleteLocalRef(env, signer);
    pthread_mutex_unlock(&digest_signer_mutex);
    if (detach) (*vm)->DetachCurrentThread(vm);
    return -1;
}

#ifdef XLB_ANDROID_TEST_SIGNER_CALLBACK
JNIEXPORT jboolean JNICALL Java_com_xlb_vpn_data_DeviceProofJni_nativeCompleteTls13MtlsHandshake(
        JNIEnv *env, jclass cls, jobject signer, jbyteArray public_key) {
    (void)cls;
    if (signer == NULL || public_key == NULL
            || (*env)->GetArrayLength(env, public_key) < 1 || (*env)->GetArrayLength(env, public_key) > 256) {
        return JNI_FALSE;
    }

    (*env)->GetJavaVM(env, &vm);
    if (!xlb_install_java_signer(env, signer)) return JNI_FALSE;

    jbyte *public_key_bytes = (*env)->GetByteArrayElements(env, public_key, NULL);
    int valid = 0;
    if (public_key_bytes != NULL) {
        valid = XlbCompleteAndroidSignerHandshake((char *)public_key_bytes, (*env)->GetArrayLength(env, public_key));
    }
    if (public_key_bytes != NULL) {
        (*env)->ReleaseByteArrayElements(env, public_key, public_key_bytes, JNI_ABORT);
    }
    xlb_clear_java_signer(env);
    if ((*env)->ExceptionCheck(env)) {
        (*env)->ExceptionClear(env);
        return JNI_FALSE;
    }
    return valid == 1 ? JNI_TRUE : JNI_FALSE;
}
#endif

JNIEXPORT void JNICALL Java_com_xlb_vpn_tunnel_MihomoCore_nativeInit(JNIEnv *env, jclass cls, jstring home, jobject callback) {
    (void)cls;
    (*env)->GetJavaVM(env, &vm);
    if (protector != NULL) (*env)->DeleteGlobalRef(env, protector);
    protector = (*env)->NewGlobalRef(env, callback);
    const char *value = (*env)->GetStringUTFChars(env, home, NULL);
    XlbInit((char *)value, protect_socket);
    (*env)->ReleaseStringUTFChars(env, home, value);
}

static jstring result_string(JNIEnv *env, char *result) {
    if (result == NULL) return NULL;
    jstring value = (*env)->NewStringUTF(env, result);
    XlbFreeString(result);
    return value;
}

JNIEXPORT jstring JNICALL Java_com_xlb_vpn_data_DeviceProofCertificate_nativeCreateDeviceCertificate(
        JNIEnv *env, jclass cls, jstring node_id, jstring peer_id, jstring device_id,
        jint peer_generation, jint device_generation, jbyteArray public_key, jobject signer) {
    (void)cls;
    if (node_id == NULL || peer_id == NULL || device_id == NULL || public_key == NULL || signer == NULL ||
            peer_generation < 1 || device_generation < 1) return NULL;
    jsize public_key_length = (*env)->GetArrayLength(env, public_key);
    if (public_key_length < 1 || public_key_length > 256) return NULL;

    (*env)->GetJavaVM(env, &vm);
    if (!xlb_install_java_signer(env, signer)) return NULL;
    const char *node_value = (*env)->GetStringUTFChars(env, node_id, NULL);
    const char *peer_value = (*env)->GetStringUTFChars(env, peer_id, NULL);
    const char *device_value = (*env)->GetStringUTFChars(env, device_id, NULL);
    jbyte *public_value = (*env)->GetByteArrayElements(env, public_key, NULL);
    jstring result = NULL;

    if (node_value != NULL && peer_value != NULL && device_value != NULL && public_value != NULL) {
        char *encoded = XlbCreateAndroidDeviceCertificate((char *)node_value, (char *)peer_value,
                (char *)device_value, peer_generation, device_generation,
                (char *)public_value, public_key_length);
        result = result_string(env, encoded);
    }
    if (node_value != NULL) (*env)->ReleaseStringUTFChars(env, node_id, node_value);
    if (peer_value != NULL) (*env)->ReleaseStringUTFChars(env, peer_id, peer_value);
    if (device_value != NULL) (*env)->ReleaseStringUTFChars(env, device_id, device_value);
    if (public_value != NULL) (*env)->ReleaseByteArrayElements(env, public_key, public_value, JNI_ABORT);

    if (result == NULL || (*env)->ExceptionCheck(env)) {
        if ((*env)->ExceptionCheck(env)) (*env)->ExceptionClear(env);
        XlbReleaseAndroidDeviceCertificate();
        xlb_clear_java_signer(env);
        return NULL;
    }
    return result;
}

JNIEXPORT void JNICALL Java_com_xlb_vpn_data_DeviceProofCertificate_nativeReleaseDeviceCertificate(
        JNIEnv *env, jclass cls) {
    (void)cls;
    XlbReleaseAndroidDeviceCertificate();
    xlb_clear_java_signer(env);
}

JNIEXPORT jstring JNICALL Java_com_xlb_vpn_tunnel_MihomoCore_nativeLoad(JNIEnv *env, jclass cls, jbyteArray profile) {
    (void)cls;
    jbyte *value = (*env)->GetByteArrayElements(env, profile, NULL);
    char *result = XlbLoad((char *)value, (*env)->GetArrayLength(env, profile));
    (*env)->ReleaseByteArrayElements(env, profile, value, JNI_ABORT);
    return result_string(env, result);
}

JNIEXPORT jstring JNICALL Java_com_xlb_vpn_tunnel_MihomoCore_nativeFirstRouteName(JNIEnv *env, jclass cls, jbyteArray profile) {
	(void)cls;
	jbyte *value = (*env)->GetByteArrayElements(env, profile, NULL);
	char *result = XlbFirstRouteName((char *)value, (*env)->GetArrayLength(env, profile));
	(*env)->ReleaseByteArrayElements(env, profile, value, JNI_ABORT);
	return result_string(env, result);
}

JNIEXPORT jstring JNICALL Java_com_xlb_vpn_tunnel_MihomoCore_nativeStartTun(JNIEnv *env, jclass cls, jint fd) {
    (void)cls;
    return result_string(env, XlbStartTun(fd));
}

JNIEXPORT void JNICALL Java_com_xlb_vpn_tunnel_MihomoCore_nativeStop(JNIEnv *env, jclass cls) {
    (void)env;
    (void)cls;
    XlbStop();
}
