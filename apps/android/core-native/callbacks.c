#include "callbacks.h"

int xlb_call_protect(xlb_protect_callback callback, int fd) {
    return callback != 0 && callback(fd);
}
