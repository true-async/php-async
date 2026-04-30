/*
+----------------------------------------------------------------------+
  | Copyright (c) The PHP Group                                          |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | https://www.php.net/license/3_01.txt                                 |
  +----------------------------------------------------------------------+
*/
#ifndef PHP_ASYNC_CPU_INFO_H
#define PHP_ASYNC_CPU_INFO_H

#include "php_async.h"

PHP_ASYNC_API extern zend_class_entry *async_ce_cpu_snapshot;

void async_register_cpu_snapshot_ce(void);

/* Reset module-level state held for cpu_usage(). Call from RSHUTDOWN. */
void async_cpu_usage_reset_state(void);

/* Free module-level resources held for cpu_usage(). Call from MSHUTDOWN. */
void async_cpu_info_module_shutdown(void);

#endif /* PHP_ASYNC_CPU_INFO_H */
