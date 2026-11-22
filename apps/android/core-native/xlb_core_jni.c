#include <jni.h>
#ifdef XLB_ANDROID_TEST_SIGNER_CALLBACK
#include <pthread.h>
#endif

extern void XlbInit(char *, int (*)(int));
extern char *XlbLoad(char *, int);
extern char *XlbFirstRouteName(char *, int);
extern char *XlbStartTun(int);
extern void XlbStop(void);
extern void XlbFreeString(char *);
#ifdef XLB_ANDROID_TEST_SIGNER_CALLBACK
extern int XlbCompleteAndroidSignerHandshake(const char *, int);
#endif

static JavaVM *vm;
static jobject protector;
#ifdef XLB_ANDROID_TEST_SIGNER_CALLBACK
static jobject digest_signer_callback;
static pthread_mutex_t digest_signer_mutex = PTHREAD_MUTEX_INITIALIZER;
#endif

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

#ifdef XLB_ANDROID_TEST_SIGNER_CALLBACK
int xlb_call_java_sign_digest(const unsigned char *digest, int digest_len,
        unsigned char *signature, int signature_capacity) {
    JNIEnv *env = NULL;
    int detach = 0;
    if (vm == NULL || digest_signer_callback == NULL || digest == NULL || digest_len != 32
            || signature == NULL || signature_capacity < 1) return -1;
    if ((*vm)->GetEnv(vm, (void **)&env, JNI_VERSION_1_6) != JNI_OK) {
        if (attach_current_thread(vm, &env) != JNI_OK) return -1;
        detach = 1;
    }

    jclass cls = (*env)->GetObjectClass(env, digest_signer_callback);
    jmethodID method = cls == NULL ? NULL : (*env)->GetMethodID(env, cls, "signDigest", "([B)[B");
    jbyteArray digest_array = (*env)->NewByteArray(env, digest_len);
    jbyteArray signed_array = NULL;
    if (method == NULL || digest_array == NULL) goto failed;
    (*env)->SetByteArrayRegion(env, digest_array, 0, digest_len, (const jbyte *)digest);
    if ((*env)->ExceptionCheck(env)) goto failed;
    signed_array = (jbyteArray)(*env)->CallObjectMethod(env, digest_signer_callback, method, digest_array);
    if ((*env)->ExceptionCheck(env) || signed_array == NULL) goto failed;
    jsize signed_len = (*env)->GetArrayLength(env, signed_array);
    if (signed_len < 1 || signed_len > signature_capacity) goto failed;
    (*env)->GetByteArrayRegion(env, signed_array, 0, signed_len, (jbyte *)signature);
    if ((*env)->ExceptionCheck(env)) goto failed;
    (*env)->DeleteLocalRef(env, signed_array);
    (*env)->DeleteLocalRef(env, digest_array);
    (*env)->DeleteLocalRef(env, cls);
    if (detach) (*vm)->DetachCurrentThread(vm);
    return (int)signed_len;

failed:
    if ((*env)->ExceptionCheck(env)) (*env)->ExceptionClear(env);
    if (signed_array != NULL) (*env)->DeleteLocalRef(env, signed_array);
    if (cls != NULL) (*env)->DeleteLocalRef(env, cls);
    if (digest_array != NULL) (*env)->DeleteLocalRef(env, digest_array);
    if (detach) (*vm)->DetachCurrentThread(vm);
    return -1;
}

JNIEXPORT jboolean JNICALL Java_com_xlb_vpn_data_DeviceProofJni_nativeCompleteTls13MtlsHandshake(
        JNIEnv *env, jclass cls, jobject signer, jbyteArray public_key) {
    (void)cls;
    if (signer == NULL || public_key == NULL
            || (*env)->GetArrayLength(env, public_key) < 1 || (*env)->GetArrayLength(env, public_key) > 256) {
        return JNI_FALSE;
    }

    (*env)->GetJavaVM(env, &vm);
    pthread_mutex_lock(&digest_signer_mutex);
    digest_signer_callback = (*env)->NewGlobalRef(env, signer);
    if (digest_signer_callback == NULL) {
        pthread_mutex_unlock(&digest_signer_mutex);
        return JNI_FALSE;
    }

    jbyte *public_key_bytes = (*env)->GetByteArrayElements(env, public_key, NULL);
    int valid = 0;
    if (public_key_bytes != NULL) {
        valid = XlbCompleteAndroidSignerHandshake((char *)public_key_bytes, (*env)->GetArrayLength(env, public_key));
    }
    if (public_key_bytes != NULL) {
        (*env)->ReleaseByteArrayElements(env, public_key, public_key_bytes, JNI_ABORT);
    }
    (*env)->DeleteGlobalRef(env, digest_signer_callback);
    digest_signer_callback = NULL;
    pthread_mutex_unlock(&digest_signer_mutex);
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
