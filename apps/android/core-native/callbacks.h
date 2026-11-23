#ifndef XLB_CALLBACKS_H
#define XLB_CALLBACKS_H

typedef int (*xlb_protect_callback)(int);

int xlb_call_protect(xlb_protect_callback callback, int fd);
int xlb_call_java_sign_digest(const unsigned char *digest, int digest_len,
                              unsigned char *signature, int signature_capacity);
int xlb_install_java_signer(void *env, void *signer);
void xlb_clear_java_signer(void *env);

#endif
