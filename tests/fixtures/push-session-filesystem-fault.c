#define _GNU_SOURCE

#include <dlfcn.h>
#include <errno.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

/*
 * Fail one selected filesystem call in the child upload process. Matching an
 * operation and full path suffix keeps metadata writes and unrelated cleanup
 * on the production path while stopping at the publication boundary under
 * test.
 */
static int path_has_suffix(const char *path, const char *suffix) {
    size_t path_length = strlen(path);
    size_t suffix_length = strlen(suffix);
    return path_length >= suffix_length && strcmp(path + path_length - suffix_length, suffix) == 0;
}

static int should_fail(const char *operation, const char *path) {
    const char *requested_operation = getenv("REPRINT_FAULT_OPERATION");
    const char *requested_path_suffix = getenv("REPRINT_FAULT_PATH_SUFFIX");
    return requested_operation != NULL
        && requested_path_suffix != NULL
        && strcmp(operation, requested_operation) == 0
        && path_has_suffix(path, requested_path_suffix);
}

#ifdef __APPLE__

#define DYLD_INTERPOSE(replacement, replacee) \
    __attribute__((used)) static struct { \
        const void *replacement; \
        const void *replacee; \
    } interpose_##replacee __attribute__((section("__DATA,__interpose"))) = { \
        (const void *)(uintptr_t)&replacement, \
        (const void *)(uintptr_t)&replacee \
    }

static int fault_unlink(const char *path) {
    if (should_fail("unlink", path)) {
        errno = EIO;
        return -1;
    }
    return unlink(path);
}
DYLD_INTERPOSE(fault_unlink, unlink);

static int fault_mkdir(const char *path, mode_t mode) {
    if (should_fail("mkdir", path)) {
        errno = EIO;
        return -1;
    }
    return mkdir(path, mode);
}
DYLD_INTERPOSE(fault_mkdir, mkdir);

static int fault_symlink(const char *target, const char *path) {
    if (should_fail("symlink", path)) {
        errno = EIO;
        return -1;
    }
    return symlink(target, path);
}
DYLD_INTERPOSE(fault_symlink, symlink);

#else

int unlink(const char *path) {
    static int (*real_unlink)(const char *) = NULL;
    if (should_fail("unlink", path)) {
        errno = EIO;
        return -1;
    }
    if (real_unlink == NULL) {
        real_unlink = dlsym(RTLD_NEXT, "unlink");
    }
    return real_unlink(path);
}

int mkdir(const char *path, mode_t mode) {
    static int (*real_mkdir)(const char *, mode_t) = NULL;
    if (should_fail("mkdir", path)) {
        errno = EIO;
        return -1;
    }
    if (real_mkdir == NULL) {
        real_mkdir = dlsym(RTLD_NEXT, "mkdir");
    }
    return real_mkdir(path, mode);
}

int symlink(const char *target, const char *path) {
    static int (*real_symlink)(const char *, const char *) = NULL;
    if (should_fail("symlink", path)) {
        errno = EIO;
        return -1;
    }
    if (real_symlink == NULL) {
        real_symlink = dlsym(RTLD_NEXT, "symlink");
    }
    return real_symlink(target, path);
}

#endif
