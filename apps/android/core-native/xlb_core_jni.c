#include <jni.h>

extern void XlbInit(char *, int (*)(int));
extern char *XlbLoad(char *, int);
extern char *XlbStartTun(int);
extern void XlbStop(void);
extern void XlbFreeString(char *);

static JavaVM *vm;
static jobject protector;

static int protect_socket(int fd) {
    JNIEnv *env = NULL;
    int detach = 0;
    if ((*vm)->GetEnv(vm, (void **)&env, JNI_VERSION_1_6) != JNI_OK) {
        if ((*vm)->AttachCurrentThread(vm, &env, NULL) != JNI_OK) return 0;
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

JNIEXPORT jstring JNICALL Java_com_xlb_vpn_tunnel_MihomoCore_nativeStartTun(JNIEnv *env, jclass cls, jint fd) {
    (void)cls;
    return result_string(env, XlbStartTun(fd));
}

JNIEXPORT void JNICALL Java_com_xlb_vpn_tunnel_MihomoCore_nativeStop(JNIEnv *env, jclass cls) {
    (void)env;
    (void)cls;
    XlbStop();
}
