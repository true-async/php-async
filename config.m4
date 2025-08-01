PHP_ARG_ENABLE([async],
  [whether to enable True Async],
  [AS_HELP_STRING([--enable-async],
    [Enable True Async])])


if test "$PHP_ASYNC" = "yes"; then
  dnl Define a symbol for C code.
  AC_DEFINE([PHP_ASYNC], 1, [Enable True Async API])

  dnl Register extension source files.
  PHP_NEW_EXTENSION([async],
    [async.c coroutine.c scope.c scheduler.c exceptions.c iterator.c async_API.c \
     context.c libuv_reactor.c \
     internal/allocator.c internal/circular_buffer.c \
     zend_common.c],
    $ext_shared)

  dnl Optionally install headers (if desired for public use).
  PHP_INSTALL_HEADERS([ext/async],
    [php_async.h coroutine.h scope.h scheduler.h exceptions.h iterator.h async_API.h context.h])


    AC_PATH_PROG(PKG_CONFIG, pkg-config, no)

    AC_MSG_CHECKING(for libuv)

    if test -x "$PKG_CONFIG" && $PKG_CONFIG --exists libuv; then
      dnl Require libuv >= 1.44.0 for UV_RUN_ONCE busy loop fix
      if $PKG_CONFIG libuv --atleast-version 1.44.0; then
        LIBUV_INCLINE=`$PKG_CONFIG libuv --cflags`
        LIBUV_LIBLINE=`$PKG_CONFIG libuv --libs`
        LIBUV_VERSION=`$PKG_CONFIG libuv --modversion`
        AC_MSG_RESULT(from pkgconfig: found version $LIBUV_VERSION)
      else
        AC_MSG_ERROR(system libuv must be upgraded to version >= 1.44.0 (fixes UV_RUN_ONCE busy loop issue))
      fi
      PHP_EVAL_LIBLINE($LIBUV_LIBLINE, UV_SHARED_LIBADD)
      PHP_EVAL_INCLINE($LIBUV_INCLINE)

    else
      SEARCH_PATH="/usr/local /usr"
      SEARCH_FOR="/include/uv.h"
      if test -r $PHP_ASYNC_LIBUV/$SEARCH_FOR; then # path given as parameter
         UV_DIR=$PHP_ASYNC_LIBUV
         AC_MSG_RESULT(from option: found in $UV_DIR)
      else # search default path list
         for i in $SEARCH_PATH ; do
             if test -r $i/$SEARCH_FOR; then
               UV_DIR=$i
               AC_MSG_RESULT(from default path: found in $i)
             fi
         done
      fi
      PHP_ADD_INCLUDE($UV_DIR/include)
      PHP_CHECK_LIBRARY(uv, uv_version,
      [
        PHP_ADD_LIBRARY_WITH_PATH(uv, $UV_DIR/$PHP_LIBDIR, UV_SHARED_LIBADD)
      ],[
        AC_MSG_ERROR([wrong uv library version or library not found])
      ],[
        -L$UV_DIR/$PHP_LIBDIR -lm
      ])
      case $host in
          *linux*)
              CFLAGS="$CFLAGS -lrt"
      esac
    fi

	PHP_SUBST([CFLAGS])
    PHP_SUBST(UV_SHARED_LIBADD)

    dnl Link against needed libraries.
    PHP_ADD_LIBRARY([uv], 1, ASYNC_SHARED_LIBADD)
    PHP_SUBST(ASYNC_SHARED_LIBADD)

    dnl Install libuv-specific reactor headers.
    PHP_INSTALL_HEADERS([ext/async], [libuv_reactor.h])
fi
