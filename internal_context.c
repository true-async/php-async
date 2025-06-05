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
  | Author: TrueAsync API Implementation                                 |
  +----------------------------------------------------------------------+
*/

#include "php_async.h"
#include "zend_async_API.h"

// Global variables for key management
static zend_atomic_int next_context_key = ZEND_ATOMIC_INT_INITIALIZER(1);
static HashTable *context_key_names = NULL;

// Structure for storing key information
typedef struct {
    uint32_t key;
    char *name;
} context_key_info_t;

static void context_key_info_dtor(zval *zv)
{
    context_key_info_t *info = Z_PTR_P(zv);
    if (info->name) {
        pefree(info->name, 1);
    }
    pefree(info, 1);
}

//////////////////////////////////////////////////////////////////////////
// Key management functions
//////////////////////////////////////////////////////////////////////////

uint32_t async_internal_context_key_alloc(const char *key_name) 
{
    // Initialize key names table if needed
    if (context_key_names == NULL) {
        context_key_names = pemalloc(sizeof(HashTable), 1);
        zend_hash_init(context_key_names, 8, NULL, context_key_info_dtor, 1);
    }

    // Thread-safe atomic key allocation
    uint32_t key;
    int current, new_val;
    do {
        current = zend_atomic_int_load_ex(&next_context_key);
        new_val = current + 1;
        key = current;
    } while (!zend_atomic_int_compare_exchange_ex(&next_context_key, &current, new_val));
    
    // Store key name for debugging
    context_key_info_t *info = pemalloc(sizeof(context_key_info_t), 1);
    info->key = key;
    info->name = pestrdup(key_name ? key_name : "unnamed", 1);
    
    zval info_zval;
    ZVAL_PTR(&info_zval, info);
    zend_hash_index_add(context_key_names, key, &info_zval);
    
    return key;
}

const char* async_internal_context_key_name(uint32_t key) 
{
    if (context_key_names == NULL) {
        return NULL;
    }
    
    zval *info_zval = zend_hash_index_find(context_key_names, key);
    if (info_zval == NULL) {
        return NULL;
    }
    
    context_key_info_t *info = Z_PTR_P(info_zval);
    return info->name;
}

//////////////////////////////////////////////////////////////////////////
// Internal context functions
//////////////////////////////////////////////////////////////////////////

bool async_internal_context_get(zend_coroutine_t *coroutine, uint32_t key, zval *result) 
{
    if (coroutine == NULL || coroutine->internal_context == NULL) {
        return false;
    }
    
    zval *value = zend_hash_index_find(coroutine->internal_context, key);
    if (value == NULL) {
        return false;
    }
    
    ZVAL_COPY(result, value);
    return true;
}

void async_internal_context_set(zend_coroutine_t *coroutine, uint32_t key, zval *value) 
{
    if (coroutine == NULL) {
        return;
    }
    
    // Initialize internal_context if needed
    if (coroutine->internal_context == NULL) {
        coroutine->internal_context = emalloc(sizeof(HashTable));
        zend_hash_init(coroutine->internal_context, 8, NULL, ZVAL_PTR_DTOR, 0);
    }
    
    // Set the value
    zval copy;
    ZVAL_COPY(&copy, value);
    zend_hash_index_update(coroutine->internal_context, key, &copy);
}

bool async_internal_context_unset(zend_coroutine_t *coroutine, uint32_t key) 
{
    if (coroutine == NULL || coroutine->internal_context == NULL) {
        return false;
    }
    
    return zend_hash_index_del(coroutine->internal_context, key) == SUCCESS;
}

//////////////////////////////////////////////////////////////////////////
// Cleanup functions
//////////////////////////////////////////////////////////////////////////

void async_coroutine_dispose_internal_context(zend_coroutine_t *coroutine) 
{
    if (coroutine->internal_context != NULL) {
        zend_hash_destroy(coroutine->internal_context);
        efree(coroutine->internal_context);
        coroutine->internal_context = NULL;
    }
}

void async_shutdown_internal_context_api(void) 
{
    if (context_key_names != NULL) {
        zend_hash_destroy(context_key_names);
        pefree(context_key_names, 1);
        context_key_names = NULL;
    }
}

//////////////////////////////////////////////////////////////////////////
// Initialize Internal Context for new coroutines
//////////////////////////////////////////////////////////////////////////

void async_coroutine_init_internal_context(zend_coroutine_t *coroutine)
{
    coroutine->internal_context = NULL;
}