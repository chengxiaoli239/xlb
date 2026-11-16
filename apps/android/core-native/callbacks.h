#ifndef XLB_CALLBACKS_H
#define XLB_CALLBACKS_H

typedef int (*xlb_protect_callback)(int);

int xlb_call_protect(xlb_protect_callback callback, int fd);

#endif
