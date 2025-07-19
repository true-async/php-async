/*
+----------------------------------------------------------------------+
  | Copyright (c) The PHP Group                                          |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | https://www.php.net/license/3_01.txt                                 |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Edmond                                                       |
  +----------------------------------------------------------------------+
*/
#ifndef PHP_ASYNC_API_H
#define PHP_ASYNC_API_H

#ifdef PHP_WIN32
# ifdef ASYNC_EXPORTS
#  define PHP_ASYNC_API __declspec(dllexport)
# else
#  define PHP_ASYNC_API __declspec(dllimport)
# endif
#else
# if defined(__GNUC__) && __GNUC__ >= 4
#  define PHP_ASYNC_API __attribute__ ((visibility("default")))
# else
#  define PHP_ASYNC_API
# endif
#endif

#endif // PHP_ASYNC_API_H