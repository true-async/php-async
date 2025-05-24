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

#include <stdio.h>

#ifdef __STDC_VERSION__
#define STRINGIFY(x) #x
#define TOSTRING(x) STRINGIFY(x)
#pragma message("__STDC_VERSION__ is defined: " TOSTRING(__STDC_VERSION__))
#else
#pragma message("__STDC_VERSION__ is not defined.")
#endif

#include "circular_buffer_test.h"

int main() {
	circular_buffer_run();
	printf("All tests passed!\n");
	return 0;
}