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
#ifndef CONTEXT_H
#define CONTEXT_H

#include <Zend/zend_async_API.h>

typedef struct _async_context_s async_context_t;

struct _async_context_s {
	zend_async_context_t base;
	HashTable values;
	HashTable keys;
	async_context_t *parent;
	zend_object std;
};

bool async_context_find(async_context_t * context, zval *key, zval *result);
bool async_context_find_local(async_context_t * context, zval *key, zval *result);
bool async_context_set(async_context_t * context, zval *key, zval *value);
bool async_context_has(async_context_t * context, zval *key);
bool async_context_has_local(async_context_t * context, zval *key);
bool async_context_delete(async_context_t * context, zval *key);

async_context_t *async_context_create(async_context_t *parent_context);
void async_context_dispose(async_context_t *context);

// Class entry
extern zend_class_entry *async_ce_context;
void async_register_context_ce(void);


#endif //CONTEXT_H