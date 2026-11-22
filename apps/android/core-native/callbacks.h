#ifndef XLB_CALLBACKS_H
#define XLB_CALLBACKS_H

typedef int (*xlb_protect_callback)(int);

int xlb_call_protect(xlb_protect_callback callback, int fd);
#ifdef XLB_ANDROID_TEST_SIGNER_CALLBACK
int xlb_call_java_sign_digest(const unsigned char *digest, int digest_len,
                              unsigned char *signature, int signature_capacity);
#endif

#endif
