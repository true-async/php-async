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

#include "thread.h"
#include "thread_arginfo.h"
#include "coroutine.h"
#include "exceptions.h"
#include "php_async.h"
#include "php.h"
#include "php_main.h"
#include "SAPI.h"
#include "zend.h"
#include "zend_API.h"
#include "zend_compile.h"
#include "zend_autoload.h"
#include "zend_hash.h"
#include "zend_exceptions.h"
#include "zend_attributes.h"
#include "zend_vm.h"
#include "zend_interfaces.h"
#include "zend_closures.h"
#include "zend_common.h"
#include "zend_map_ptr.h"
#include "main/SAPI.h"

///////////////////////////////////////////////////////////
/// 0. Deep copy — pemalloc-based op_array copy
///////////////////////////////////////////////////////////

/**
 * Deep copy context: holds xlat table for pointer deduplication.
 * All copies go into persistent memory (pemalloc) so child threads
 * can access them safely after parent's emalloc heap is gone.
 */
/* Copy context: pointer to snapshot's persistent_map.
 * All copy functions use this for deduplication (xlat) and cleanup tracking. */
typedef HashTable *thread_copy_ctx_t;

/* Copy memory into persistent heap, register in map for deduplication and cleanup */
static void *thread_persist_copy_xlat(thread_copy_ctx_t ctx, const void *src, size_t size)
{
	void *dst = pemalloc(size, 1);
	memcpy(dst, src, size);
	zend_hash_index_update_ptr(ctx, async_ptr_to_index((void *)src), dst);
	return dst;
}

/* Copy memory into persistent heap, register in map for cleanup only */
static void *thread_persist_copy(thread_copy_ctx_t ctx, const void *src, size_t size)
{
	void *dst = pemalloc(size, 1);
	memcpy(dst, src, size);
	zend_hash_index_add_ptr(ctx, async_ptr_to_index((void *)src), dst);
	return dst;
}

/* Look up a previously copied pointer */
static void *thread_xlat_get(const thread_copy_ctx_t ctx, const void *old_ptr)
{
	return zend_hash_index_find_ptr(ctx, async_ptr_to_index((void *)old_ptr));
}


/* Canonical uninitialized bucket for empty hash tables */
static const uint32_t thread_uninitialized_bucket[-HT_MIN_MASK] =
	{HT_INVALID_IDX, HT_INVALID_IDX};

/* Copy a string into persistent memory with interned+permanent flags */
static zend_string *thread_copy_string(thread_copy_ctx_t ctx, const zend_string *str)
{
	if (!str) {
		return NULL;
	}

	zend_string *new_str = thread_xlat_get(ctx, str);
	if (new_str) {
		return new_str;
	}

	new_str = thread_persist_copy_xlat(ctx, str, _ZSTR_STRUCT_SIZE(ZSTR_LEN(str)));
	zend_string_hash_val(new_str);
	GC_SET_REFCOUNT(new_str, 2);

	const uint32_t flags = GC_STRING
		| (ZSTR_IS_VALID_UTF8(new_str) ? IS_STR_VALID_UTF8 : 0)
		| ((IS_STR_INTERNED | IS_STR_PERMANENT) << GC_FLAGS_SHIFT);
	GC_TYPE_INFO(new_str) = flags;

	return new_str;
}

/* Forward declarations */
static void thread_copy_zval(thread_copy_ctx_t ctx, zval *z);
static void thread_copy_hash_table(thread_copy_ctx_t ctx, HashTable *ht);
static zend_ast *thread_copy_ast(thread_copy_ctx_t ctx, zend_ast *ast);
static void thread_copy_op_array(thread_copy_ctx_t ctx, zval *zv);
static void thread_copy_op_array_ex(thread_copy_ctx_t ctx, zend_op_array *op_array);
static void thread_copy_type(thread_copy_ctx_t ctx, zend_type *type);
static HashTable *thread_copy_attributes(thread_copy_ctx_t ctx, HashTable *attributes);

/* {{{ thread_copy_hash_table — relocate HashTable internals to pemalloc */
static void thread_copy_hash_table(thread_copy_ctx_t ctx, HashTable *ht)
{
	HT_FLAGS(ht) |= HASH_FLAG_STATIC_KEYS;
	ht->pDestructor = NULL;
	ht->nInternalPointer = 0;

	if (HT_FLAGS(ht) & HASH_FLAG_UNINITIALIZED) {
		HT_SET_DATA_ADDR(ht, &thread_uninitialized_bucket);
		return;
	}

	if (ht->nNumUsed == 0) {
		ht->nTableMask = HT_MIN_MASK;
		HT_SET_DATA_ADDR(ht, &thread_uninitialized_bucket);
		HT_FLAGS(ht) |= HASH_FLAG_UNINITIALIZED;
		return;
	}

	if (HT_IS_PACKED(ht)) {
		void *data = thread_persist_copy(ctx, HT_GET_DATA_ADDR(ht), HT_PACKED_USED_SIZE(ht));
		HT_SET_DATA_ADDR(ht, data);
	} else {
		void *data = thread_persist_copy(ctx, HT_GET_DATA_ADDR(ht), HT_USED_SIZE(ht));
		HT_SET_DATA_ADDR(ht, data);
	}
}
/* }}} */

/* {{{ thread_copy_ast */
static zend_ast *thread_copy_ast(thread_copy_ctx_t ctx, zend_ast *ast)
{
	zend_ast *node;

	if (ast->kind == ZEND_AST_ZVAL || ast->kind == ZEND_AST_CONSTANT) {
		zend_ast_zval *copy = thread_persist_copy(ctx, ast, sizeof(zend_ast_zval));
		thread_copy_zval(ctx, &copy->val);
		node = (zend_ast *) copy;
	} else if (zend_ast_is_list(ast)) {
		zend_ast_list *list = zend_ast_get_list(ast);
		zend_ast_list *copy = thread_persist_copy(ctx, ast,
			sizeof(zend_ast_list) - sizeof(zend_ast *) + sizeof(zend_ast *) * list->children);
		for (uint32_t i = 0; i < list->children; i++) {
			if (copy->child[i]) {
				copy->child[i] = thread_copy_ast(ctx, copy->child[i]);
			}
		}
		node = (zend_ast *) copy;
	} else if (ast->kind == ZEND_AST_OP_ARRAY) {
		zend_ast_op_array *copy = thread_persist_copy(ctx, ast, sizeof(zend_ast_op_array));
		zval z;
		ZVAL_PTR(&z, copy->op_array);
		thread_copy_op_array(ctx, &z);
		copy->op_array = Z_PTR(z);
		node = (zend_ast *) copy;
	} else if (ast->kind == ZEND_AST_CALLABLE_CONVERT) {
		zend_ast_fcc *copy = thread_persist_copy(ctx, ast, sizeof(zend_ast_fcc));
		copy->args = thread_copy_ast(ctx, copy->args);
		node = (zend_ast *) copy;
	} else {
		const uint32_t children = zend_ast_get_num_children(ast);
		node = thread_persist_copy(ctx, ast, zend_ast_size(children));
		for (uint32_t i = 0; i < children; i++) {
			if (node->child[i]) {
				node->child[i] = thread_copy_ast(ctx, node->child[i]);
			}
		}
	}

	return node;
}
/* }}} */

/* {{{ thread_copy_zval */
static void thread_copy_zval(thread_copy_ctx_t ctx, zval *z)
{
	switch (Z_TYPE_P(z)) {
		case IS_STRING:
			Z_STR_P(z) = thread_copy_string(ctx, Z_STR_P(z));
			Z_TYPE_FLAGS_P(z) = 0;
			break;
		case IS_ARRAY: {
			void *new_ptr = thread_xlat_get(ctx, Z_ARR_P(z));
			if (new_ptr) {
				Z_ARR_P(z) = new_ptr;
				Z_TYPE_FLAGS_P(z) = 0;
			} else {
				HashTable *ht = thread_persist_copy_xlat(ctx, Z_ARR_P(z), sizeof(zend_array));
				Z_ARR_P(z) = ht;
				thread_copy_hash_table(ctx, ht);
				if (HT_IS_PACKED(ht)) {
					zval *zv;
					ZEND_HASH_PACKED_FOREACH_VAL(ht, zv) {
						thread_copy_zval(ctx, zv);
					} ZEND_HASH_FOREACH_END();
				} else {
					Bucket *p;
					ZEND_HASH_MAP_FOREACH_BUCKET(ht, p) {
						if (p->key) {
							p->key = thread_copy_string(ctx, p->key);
						}
						thread_copy_zval(ctx, &p->val);
					} ZEND_HASH_FOREACH_END();
				}
				Z_TYPE_FLAGS_P(z) = 0;
				GC_SET_REFCOUNT(Z_COUNTED_P(z), 2);
				GC_ADD_FLAGS(Z_COUNTED_P(z), IS_ARRAY_IMMUTABLE);
			}
			break;
		}
		case IS_CONSTANT_AST: {
			void *new_ptr = thread_xlat_get(ctx, Z_AST_P(z));
			if (new_ptr) {
				Z_AST_P(z) = new_ptr;
				Z_TYPE_FLAGS_P(z) = 0;
			} else {
				zend_ast_ref *old_ref = Z_AST_P(z);
				Z_AST_P(z) = thread_persist_copy_xlat(ctx, Z_AST_P(z), sizeof(zend_ast_ref));
				thread_copy_ast(ctx, GC_AST(old_ref));
				Z_TYPE_FLAGS_P(z) = 0;
				GC_SET_REFCOUNT(Z_COUNTED_P(z), 1);
				GC_ADD_FLAGS(Z_COUNTED_P(z), GC_IMMUTABLE);
			}
			break;
		}
		case IS_PTR:
			break;
		default:
			ZEND_ASSERT(Z_TYPE_P(z) < IS_STRING);
			break;
	}
}
/* }}} */

/* {{{ thread_copy_attributes */
static HashTable *thread_copy_attributes(thread_copy_ctx_t ctx, HashTable *attributes)
{
	HashTable *xlat = thread_xlat_get(ctx, attributes);
	if (xlat) {
		return xlat;
	}

	thread_copy_hash_table(ctx, attributes);

	zval *v;
	ZEND_HASH_PACKED_FOREACH_VAL(attributes, v) {
		const zend_attribute *attr = Z_PTR_P(v);
		zend_attribute *copy = thread_persist_copy_xlat(ctx, attr, ZEND_ATTRIBUTE_SIZE(attr->argc));

		copy->name = thread_copy_string(ctx, copy->name);
		copy->lcname = thread_copy_string(ctx, copy->lcname);
		if (copy->validation_error) {
			copy->validation_error = thread_copy_string(ctx, copy->validation_error);
		}

		for (uint32_t i = 0; i < copy->argc; i++) {
			if (copy->args[i].name) {
				copy->args[i].name = thread_copy_string(ctx, copy->args[i].name);
			}
			thread_copy_zval(ctx, &copy->args[i].value);
		}

		ZVAL_PTR(v, copy);
	} ZEND_HASH_FOREACH_END();

	HashTable *ptr = thread_persist_copy_xlat(ctx, attributes, sizeof(HashTable));
	GC_SET_REFCOUNT(ptr, 2);
	GC_TYPE_INFO(ptr) = GC_ARRAY | ((IS_ARRAY_IMMUTABLE|GC_NOT_COLLECTABLE) << GC_FLAGS_SHIFT);

	return ptr;
}
/* }}} */

/* {{{ thread_copy_type */
static void thread_copy_type(thread_copy_ctx_t ctx, zend_type *type)
{
	if (ZEND_TYPE_HAS_LIST(*type)) {
		zend_type_list *list = ZEND_TYPE_LIST(*type);
		list = thread_persist_copy_xlat(ctx, list, ZEND_TYPE_LIST_SIZE(list->num_types));
		ZEND_TYPE_FULL_MASK(*type) &= ~_ZEND_TYPE_ARENA_BIT;
		ZEND_TYPE_SET_PTR(*type, list);
	}

	zend_type *single_type;
	ZEND_TYPE_FOREACH_MUTABLE(*type, single_type) {
		if (ZEND_TYPE_HAS_LIST(*single_type)) {
			thread_copy_type(ctx, single_type);
			continue;
		}
		if (ZEND_TYPE_HAS_NAME(*single_type)) {
			zend_string *type_name = ZEND_TYPE_NAME(*single_type);
			type_name = thread_copy_string(ctx, type_name);
			ZEND_TYPE_SET_PTR(*single_type, type_name);
			/* Skip zend_accel_get_class_name_map_ptr — not safe without OPcache */
		}
	} ZEND_TYPE_FOREACH_END();
}
/* }}} */

/* {{{ thread_copy_op_array_ex — deep copy op_array internals */
static void thread_copy_op_array_ex(thread_copy_ctx_t ctx, zend_op_array *op_array)
{
	const zval *orig_literals = NULL;

	/* refcount: detach from parent's shared counter without modifying it */
	op_array->refcount = NULL;

	if (op_array->function_name) {
		op_array->function_name = thread_copy_string(ctx, op_array->function_name);
	}

	if (op_array->scope) {
		zend_class_entry *scope = thread_xlat_get(ctx, op_array->scope);
		if (scope) {
			op_array->scope = scope;
		}

		if (op_array->prototype) {
			zend_function *ptr = thread_xlat_get(ctx, op_array->prototype);
			if (ptr) {
				op_array->prototype = ptr;
			}
		}

		/* Check if opcodes were already copied (shared method) */
		zend_op *persist_ptr = thread_xlat_get(ctx, op_array->opcodes);
		if (persist_ptr) {
			op_array->opcodes = persist_ptr;
			if (op_array->static_variables) {
				op_array->static_variables = thread_xlat_get(ctx, op_array->static_variables);
				ZEND_ASSERT(op_array->static_variables != NULL);
			}
			if (op_array->literals) {
				op_array->literals = thread_xlat_get(ctx, op_array->literals);
				ZEND_ASSERT(op_array->literals != NULL);
			}
			if (op_array->filename) {
				op_array->filename = thread_xlat_get(ctx, op_array->filename);
				ZEND_ASSERT(op_array->filename != NULL);
			}
			if (op_array->arg_info) {
				zend_arg_info *arg_info = op_array->arg_info;
				if (op_array->fn_flags & ZEND_ACC_HAS_RETURN_TYPE) {
					arg_info--;
				}
				arg_info = thread_xlat_get(ctx, arg_info);
				ZEND_ASSERT(arg_info != NULL);
				if (op_array->fn_flags & ZEND_ACC_HAS_RETURN_TYPE) {
					arg_info++;
				}
				op_array->arg_info = arg_info;
			}
			if (op_array->live_range) {
				op_array->live_range = thread_xlat_get(ctx, op_array->live_range);
				ZEND_ASSERT(op_array->live_range != NULL);
			}
			if (op_array->doc_comment) {
				op_array->doc_comment = thread_xlat_get(ctx, op_array->doc_comment);
			}
			if (op_array->attributes) {
				op_array->attributes = thread_xlat_get(ctx, op_array->attributes);
				ZEND_ASSERT(op_array->attributes != NULL);
			}
			if (op_array->try_catch_array) {
				op_array->try_catch_array = thread_xlat_get(ctx, op_array->try_catch_array);
				ZEND_ASSERT(op_array->try_catch_array != NULL);
			}
			if (op_array->vars) {
				op_array->vars = thread_xlat_get(ctx, op_array->vars);
				ZEND_ASSERT(op_array->vars != NULL);
			}
			if (op_array->dynamic_func_defs) {
				op_array->dynamic_func_defs = thread_xlat_get(ctx, op_array->dynamic_func_defs);
				ZEND_ASSERT(op_array->dynamic_func_defs != NULL);
			}
			return;
		}
	} else {
		op_array->prototype = NULL;
	}

	/* static_variables */
	if (op_array->static_variables) {
		Bucket *p;
		thread_copy_hash_table(ctx, op_array->static_variables);
		ZEND_HASH_MAP_FOREACH_BUCKET(op_array->static_variables, p) {
			ZEND_ASSERT(p->key != NULL);
			p->key = thread_copy_string(ctx, p->key);
			thread_copy_zval(ctx, &p->val);
		} ZEND_HASH_FOREACH_END();
		op_array->static_variables = thread_persist_copy_xlat(ctx, op_array->static_variables, sizeof(HashTable));
		GC_SET_REFCOUNT(op_array->static_variables, 2);
		GC_TYPE_INFO(op_array->static_variables) = GC_ARRAY | ((IS_ARRAY_IMMUTABLE|GC_NOT_COLLECTABLE) << GC_FLAGS_SHIFT);
	}

	/* literals */
	if (op_array->literals) {
		orig_literals = op_array->literals;
		zval *p = thread_persist_copy_xlat(ctx, op_array->literals,
			sizeof(zval) * op_array->last_literal);
		const zval *end = p + op_array->last_literal;
		op_array->literals = p;
		while (p < end) {
			thread_copy_zval(ctx, p);
			p++;
		}
	}

	/* opcodes */
	{
		zend_op *new_opcodes = thread_persist_copy_xlat(ctx, op_array->opcodes, sizeof(zend_op) * op_array->last);
		zend_op *opline = new_opcodes;
		const zend_op *end = new_opcodes + op_array->last;

		for (; opline < end; opline++) {
#if ZEND_USE_ABS_CONST_ADDR
			if (opline->op1_type == IS_CONST) {
				opline->op1.zv = (zval*)((char*)opline->op1.zv + ((char*)op_array->literals - (char*)orig_literals));
				if (opline->opcode == ZEND_SEND_VAL
				 || opline->opcode == ZEND_SEND_VAL_EX
				 || opline->opcode == ZEND_QM_ASSIGN) {
					zend_vm_set_opcode_handler_ex(opline, 1 << Z_TYPE_P(opline->op1.zv), 0, 0);
				}
			}
			if (opline->op2_type == IS_CONST) {
				opline->op2.zv = (zval*)((char*)opline->op2.zv + ((char*)op_array->literals - (char*)orig_literals));
			}
#else
			if (opline->op1_type == IS_CONST) {
				opline->op1.constant =
					(char*)(op_array->literals +
						((zval*)((char*)(op_array->opcodes + (opline - new_opcodes)) +
						(int32_t)opline->op1.constant) - orig_literals)) -
					(char*)opline;
				if (opline->opcode == ZEND_SEND_VAL
				 || opline->opcode == ZEND_SEND_VAL_EX
				 || opline->opcode == ZEND_QM_ASSIGN) {
					zend_vm_set_opcode_handler_ex(opline, 0, 0, 0);
				}
			}
			if (opline->op2_type == IS_CONST) {
				opline->op2.constant =
					(char*)(op_array->literals +
						((zval*)((char*)(op_array->opcodes + (opline - new_opcodes)) +
						(int32_t)opline->op2.constant) - orig_literals)) -
					(char*)opline;
			}
#endif
#if ZEND_USE_ABS_JMP_ADDR
			if (op_array->fn_flags & ZEND_ACC_DONE_PASS_TWO) {
				switch (opline->opcode) {
					case ZEND_JMP:
					case ZEND_FAST_CALL:
						opline->op1.jmp_addr = &new_opcodes[opline->op1.jmp_addr - op_array->opcodes];
						break;
					case ZEND_JMPZ:
					case ZEND_JMPNZ:
					case ZEND_JMPZ_EX:
					case ZEND_JMPNZ_EX:
					case ZEND_JMP_SET:
					case ZEND_COALESCE:
					case ZEND_FE_RESET_R:
					case ZEND_FE_RESET_RW:
					case ZEND_ASSERT_CHECK:
					case ZEND_JMP_NULL:
					case ZEND_BIND_INIT_STATIC_OR_JMP:
					case ZEND_JMP_FRAMELESS:
						opline->op2.jmp_addr = &new_opcodes[opline->op2.jmp_addr - op_array->opcodes];
						break;
					case ZEND_CATCH:
						if (!(opline->extended_value & ZEND_LAST_CATCH)) {
							opline->op2.jmp_addr = &new_opcodes[opline->op2.jmp_addr - op_array->opcodes];
						}
						break;
					case ZEND_FE_FETCH_R:
					case ZEND_FE_FETCH_RW:
					case ZEND_SWITCH_LONG:
					case ZEND_SWITCH_STRING:
					case ZEND_MATCH:
						break;
				}
			}
#endif
			if (opline->opcode == ZEND_OP_DATA
					&& (opline-1)->opcode == ZEND_DECLARE_ATTRIBUTED_CONST) {
				zval *literal = RT_CONSTANT(opline, opline->op1);
				HashTable *attributes = Z_PTR_P(literal);
				attributes = thread_copy_attributes(ctx, attributes);
				ZVAL_PTR(literal, attributes);
			}
		}

		op_array->opcodes = new_opcodes;
	}

	/* filename */
	if (op_array->filename) {
		op_array->filename = thread_copy_string(ctx, op_array->filename);
	}

	/* arg_info */
	if (op_array->arg_info) {
		zend_arg_info *arg_info = op_array->arg_info;
		uint32_t num_args = op_array->num_args;

		if (op_array->fn_flags & ZEND_ACC_HAS_RETURN_TYPE) {
			arg_info--;
			num_args++;
		}
		if (op_array->fn_flags & ZEND_ACC_VARIADIC) {
			num_args++;
		}
		arg_info = thread_persist_copy_xlat(ctx, arg_info, sizeof(zend_arg_info) * num_args);
		for (uint32_t i = 0; i < num_args; i++) {
			if (arg_info[i].name) {
				arg_info[i].name = thread_copy_string(ctx, arg_info[i].name);
			}
			thread_copy_type(ctx, &arg_info[i].type);
		}
		if (op_array->fn_flags & ZEND_ACC_HAS_RETURN_TYPE) {
			arg_info++;
		}
		op_array->arg_info = arg_info;
	}

	/* live_range */
	if (op_array->live_range) {
		op_array->live_range = thread_persist_copy_xlat(ctx, op_array->live_range,
			sizeof(zend_live_range) * op_array->last_live_range);
	}

	/* doc_comment — always copy */
	if (op_array->doc_comment) {
		op_array->doc_comment = thread_copy_string(ctx, op_array->doc_comment);
	}

	/* attributes */
	if (op_array->attributes) {
		op_array->attributes = thread_copy_attributes(ctx, op_array->attributes);
	}

	/* try_catch_array */
	if (op_array->try_catch_array) {
		op_array->try_catch_array = thread_persist_copy_xlat(ctx, op_array->try_catch_array,
			sizeof(zend_try_catch_element) * op_array->last_try_catch);
	}

	/* vars */
	if (op_array->vars) {
		op_array->vars = thread_persist_copy_xlat(ctx, op_array->vars,
			sizeof(zend_string*) * op_array->last_var);
		for (int i = 0; i < op_array->last_var; i++) {
			op_array->vars[i] = thread_copy_string(ctx, op_array->vars[i]);
		}
	}

	/* dynamic_func_defs (nested closures/lambdas) */
	if (op_array->num_dynamic_func_defs) {
		op_array->dynamic_func_defs = thread_persist_copy_xlat(ctx, op_array->dynamic_func_defs,
			sizeof(zend_function *) * op_array->num_dynamic_func_defs);
		for (uint32_t i = 0; i < op_array->num_dynamic_func_defs; i++) {
			zval tmp;
			ZVAL_PTR(&tmp, op_array->dynamic_func_defs[i]);
			thread_copy_op_array(ctx, &tmp);
			op_array->dynamic_func_defs[i] = Z_PTR(tmp);
		}
	}
}
/* }}} */

/* {{{ thread_copy_op_array — copy a standalone user function */
static void thread_copy_op_array(thread_copy_ctx_t ctx, zval *zv)
{
	zend_op_array *op_array = Z_PTR_P(zv);
	ZEND_ASSERT(op_array->type == ZEND_USER_FUNCTION);

	zend_op_array *old = thread_xlat_get(ctx, op_array);
	if (old) {
		Z_PTR_P(zv) = old;
		return;
	}

	op_array = Z_PTR_P(zv) = thread_persist_copy_xlat(ctx, Z_PTR_P(zv), sizeof(zend_op_array));
	thread_copy_op_array_ex(ctx, op_array);

	op_array->fn_flags |= ZEND_ACC_IMMUTABLE;
	ZEND_MAP_PTR_INIT(op_array->run_time_cache, NULL);
	if (op_array->static_variables) {
		ZEND_MAP_PTR_INIT(op_array->static_variables_ptr, NULL);
	}
}
/* }}} */

///////////////////////////////////////////////////////////
/// 0.5. Zval transfer — copy runtime values between threads
///////////////////////////////////////////////////////////

/**
 * Transfer context: persistent-memory deep copy of runtime zvals.
 * Uses xlat table to preserve object/array identity (same pointer
 * in source → same pointer in destination) and handle cycles.
 */
#define THREAD_TRANSFER_MAX_DEPTH 512

#define THREAD_DEPTH_CHECK(ctx, ret) do { \
	if (UNEXPECTED((ctx)->depth >= THREAD_TRANSFER_MAX_DEPTH)) { \
		zend_throw_error(NULL, \
			"Maximum nesting depth (%u) exceeded during thread data transfer", \
			THREAD_TRANSFER_MAX_DEPTH); \
		return ret; \
	} \
	(ctx)->depth++; \
} while (0)

#define THREAD_DEPTH_RELEASE(ctx) (ctx)->depth--

typedef struct {
	HashTable xlat;   /* old_ptr → new_ptr mapping */
	uint32_t depth;
} thread_transfer_ctx_t;

static void thread_transfer_ctx_init(thread_transfer_ctx_t *ctx)
{
	zend_hash_init(&ctx->xlat, 32, NULL, NULL, 0);
	ctx->depth = 0;
}

static void thread_transfer_ctx_destroy(thread_transfer_ctx_t *ctx)
{
	zend_hash_destroy(&ctx->xlat);
}

static void *thread_transfer_xlat_get(const thread_transfer_ctx_t *ctx, const void *ptr)
{
	return zend_hash_index_find_ptr(&ctx->xlat, async_ptr_to_index((void *)ptr));
}

static void thread_transfer_xlat_put(thread_transfer_ctx_t *ctx, const void *old_ptr, void *new_ptr)
{
	zend_hash_index_update_ptr(&ctx->xlat, async_ptr_to_index((void *)old_ptr), new_ptr);
}

/* Forward declarations */
static void thread_transfer_zval_inner(thread_transfer_ctx_t *ctx, zval *dst, const zval *src);
static HashTable *thread_transfer_hash_table(thread_transfer_ctx_t *ctx, const HashTable *src);
static zend_object *thread_transfer_object(thread_transfer_ctx_t *ctx, const zend_object *src);

/* Copy a zend_string into persistent memory */
static zend_string *thread_transfer_string(thread_transfer_ctx_t *ctx, const zend_string *str)
{
	zend_string *existing = thread_transfer_xlat_get(ctx, str);
	if (existing) {
		return existing;
	}

	zend_string *copy = zend_string_init(ZSTR_VAL(str), ZSTR_LEN(str), 1);
	thread_transfer_xlat_put(ctx, str, copy);

	return copy;
}

/* {{{ thread_transfer_hash_table — deep copy a HashTable into persistent memory */
static HashTable *thread_transfer_hash_table(thread_transfer_ctx_t *ctx, const HashTable *src)
{
	THREAD_DEPTH_CHECK(ctx, NULL);

	HashTable *existing = thread_transfer_xlat_get(ctx, src);
	if (existing) {
		THREAD_DEPTH_RELEASE(ctx);
		return existing;
	}

	HashTable *dst = pemalloc(sizeof(HashTable), 1);
	thread_transfer_xlat_put(ctx, src, dst);

	const uint32_t count = zend_hash_num_elements(src);

	zend_hash_init(dst, count, NULL, NULL, 1);

	if (count == 0) {
		THREAD_DEPTH_RELEASE(ctx);
		return dst;
	}

	if (HT_IS_PACKED(src)) {
		zval *val;
		zend_ulong idx;
		ZEND_HASH_PACKED_FOREACH_KEY_VAL((HashTable *)src, idx, val) {
			zval copy;
			thread_transfer_zval_inner(ctx, &copy, val);
			zend_hash_index_add(dst, idx, &copy);
		} ZEND_HASH_FOREACH_END();
	} else {
		zend_string *key;
		zend_ulong idx;
		zval *val;
		ZEND_HASH_MAP_FOREACH_KEY_VAL((HashTable *)src, idx, key, val) {
			zval copy;
			thread_transfer_zval_inner(ctx, &copy, val);
			if (key) {
				zend_string *pkey = thread_transfer_string(ctx, key);
				zend_hash_add(dst, pkey, &copy);
				zend_string_release(pkey);
			} else {
				zend_hash_index_add(dst, idx, &copy);
			}
		} ZEND_HASH_FOREACH_END();
	}

	THREAD_DEPTH_RELEASE(ctx);
	return dst;
}
/* }}} */

/* {{{ thread_transfer_object — deep copy an object into persistent memory.
 *
 * pemalloc + memcpy the whole zend_object, then deep-copy each property
 * in the copy's properties_table. Repurpose ce/handlers fields for transit:
 *   ce       → persistent class name (zend_string *)
 *   handlers → properties count (cast to pointer)
 */
static zend_object *thread_transfer_object(thread_transfer_ctx_t *ctx, const zend_object *src)
{
	THREAD_DEPTH_CHECK(ctx, NULL);

	zend_object *existing = thread_transfer_xlat_get(ctx, src);
	if (existing) {
		THREAD_DEPTH_RELEASE(ctx);
		return existing;
	}

	/* Dynamic properties (stdClass, __set) are not supported */
	if (src->properties && zend_hash_num_elements(src->properties) > 0) {
		zend_async_throw(ZEND_ASYNC_EXCEPTION_THREAD_TRANSFER,
			"Cannot transfer object with dynamic properties between threads "
			"(class %s). Use arrays instead", ZSTR_VAL(src->ce->name));
		THREAD_DEPTH_RELEASE(ctx);
		return NULL;
	}

	zend_class_entry *ce = src->ce;
	const uint32_t prop_count = ce->default_properties_count;
	const size_t obj_size = sizeof(zend_object) + zend_object_properties_size(ce);

	/* pemalloc + memcpy the entire object */
	zend_object *dst = pemalloc(obj_size, 1);
	memcpy(dst, src, obj_size);

	/* Register early so cycles are handled */
	thread_transfer_xlat_put(ctx, src, dst);

	/* Repurpose fields for transit */
	dst->ce = (zend_class_entry *) thread_transfer_string(ctx, ce->name);
	dst->handlers = (const zend_object_handlers *)(uintptr_t) prop_count;
	dst->properties = NULL;

	/* Deep-copy each property zval in the copy */
	for (uint32_t i = 0; i < prop_count; i++) {
		zval *prop = &dst->properties_table[i];
		if (Z_TYPE_P(prop) != IS_UNDEF) {
			zval transferred;
			thread_transfer_zval_inner(ctx, &transferred, prop);
			ZVAL_COPY_VALUE(prop, &transferred);
		}
	}

	THREAD_DEPTH_RELEASE(ctx);
	return dst;
}
/* }}} */

/* {{{ thread_transfer_zval_inner — recursive deep copy of a single zval */
static zend_always_inline void thread_transfer_zval_inner(thread_transfer_ctx_t *ctx, zval *dst, const zval *src)
{
	switch (Z_TYPE_P(src)) {
		case IS_UNDEF:
			ZVAL_UNDEF(dst);
			break;

		case IS_NULL:
			ZVAL_NULL(dst);
			break;

		case IS_FALSE:
			ZVAL_FALSE(dst);
			break;

		case IS_TRUE:
			ZVAL_TRUE(dst);
			break;

		case IS_LONG:
			ZVAL_LONG(dst, Z_LVAL_P(src));
			break;

		case IS_DOUBLE:
			ZVAL_DOUBLE(dst, Z_DVAL_P(src));
			break;

		case IS_STRING: {
			zend_string *copy = thread_transfer_string(ctx, Z_STR_P(src));
			ZVAL_STR(dst, copy);
			break;
		}

		case IS_ARRAY: {
			HashTable *copy = thread_transfer_hash_table(ctx, Z_ARRVAL_P(src));
			if (UNEXPECTED(copy == NULL)) {
				ZVAL_NULL(dst);
				return;
			}
			ZVAL_ARR(dst, copy);
			break;
		}

		case IS_OBJECT: {
			zend_object *copy = thread_transfer_object(ctx, Z_OBJ_P(src));
			if (UNEXPECTED(copy == NULL)) {
				ZVAL_NULL(dst);
				return;
			}
			ZVAL_OBJ(dst, copy);
			break;
		}

		case IS_RESOURCE:
			/* TODO: support resource transfer via opt-in cloning */
			zend_throw_error(NULL,
				"Cannot transfer a resource between threads");
			ZVAL_NULL(dst);
			break;

		case IS_REFERENCE:
			zend_throw_error(NULL,
				"Cannot transfer a reference between threads");
			ZVAL_NULL(dst);
			break;

		default:
			zend_throw_error(NULL,
				"Cannot transfer zval of type %d between threads", Z_TYPE_P(src));
			ZVAL_NULL(dst);
			break;
	}
}
/* }}} */

/**
 * Copy a zval into persistent memory for cross-thread transfer.
 *
 * The result is a deep copy in pemalloc'd memory. Objects and arrays
 * that appear in multiple places share identity (same copy).
 * Cyclic references are handled via xlat table.
 *
 * @param dst  Destination zval (will be overwritten)
 * @param src  Source zval (unchanged)
 */
void async_thread_transfer_zval(zval *dst, const zval *src)
{
	thread_transfer_ctx_t ctx;
	thread_transfer_ctx_init(&ctx);
	thread_transfer_zval_inner(&ctx, dst, src);
	thread_transfer_ctx_destroy(&ctx);
}

/**
 * Load a persistent zval into the current thread's emalloc heap.
 * Creates a proper refcounted copy that the current thread owns.
 *
 * @param dst  Destination zval (current thread, emalloc'd)
 * @param src  Source zval (persistent memory from thread_transfer_zval)
 */
static void thread_load_zval_inner(thread_transfer_ctx_t *ctx, zval *dst, const zval *src);
static HashTable *thread_load_hash_table(thread_transfer_ctx_t *ctx, const HashTable *src);
static zend_object *thread_load_object(thread_transfer_ctx_t *ctx, const zend_object *src);

static zend_string *thread_load_string(thread_transfer_ctx_t *ctx, const zend_string *str)
{
	zend_string *existing = thread_transfer_xlat_get(ctx, str);
	if (existing) {
		return zend_string_copy(existing);
	}

	zend_string *copy = zend_string_init(ZSTR_VAL(str), ZSTR_LEN(str), 0);
	thread_transfer_xlat_put(ctx, str, copy);

	return copy;
}

static HashTable *thread_load_hash_table(thread_transfer_ctx_t *ctx, const HashTable *src)
{
	THREAD_DEPTH_CHECK(ctx, NULL);

	HashTable *existing = thread_transfer_xlat_get(ctx, src);
	if (existing) {
		GC_ADDREF(existing);
		THREAD_DEPTH_RELEASE(ctx);
		return existing;
	}

	HashTable *dst = emalloc(sizeof(HashTable));
	thread_transfer_xlat_put(ctx, src, dst);

	const uint32_t count = zend_hash_num_elements(src);
	zend_hash_init(dst, count, NULL, ZVAL_PTR_DTOR, 0);

	if (count == 0) {
		return dst;
	}

	zend_string *key;
	zend_ulong idx;
	zval *val;
	ZEND_HASH_FOREACH_KEY_VAL((HashTable *)src, idx, key, val) {
		zval copy;
		thread_load_zval_inner(ctx, &copy, val);
		if (key) {
			zend_string *ekey = thread_load_string(ctx, key);
			zend_hash_add(dst, ekey, &copy);
			zend_string_release(ekey);
		} else {
			zend_hash_index_add(dst, idx, &copy);
		}
	} ZEND_HASH_FOREACH_END();

	THREAD_DEPTH_RELEASE(ctx);
	return dst;
}

static zend_object *thread_load_object(thread_transfer_ctx_t *ctx, const zend_object *src)
{
	THREAD_DEPTH_CHECK(ctx, NULL);

	zend_object *existing = thread_transfer_xlat_get(ctx, src);
	if (existing) {
		GC_ADDREF(existing);
		THREAD_DEPTH_RELEASE(ctx);
		return existing;
	}

	/* Read transit fields: ce = class_name, handlers = prop_count */
	const zend_string *class_name = (const zend_string *) src->ce;
	const uint32_t src_prop_count = (uint32_t)(uintptr_t) src->handlers;

	/* Resolve class by name via autoload */
	zend_string *lookup_name = zend_string_init(
		ZSTR_VAL(class_name), ZSTR_LEN(class_name), 0);
	zend_class_entry *ce = zend_lookup_class(lookup_name);
	zend_string_release(lookup_name);

	if (UNEXPECTED(ce == NULL)) {
		if (!EG(exception)) {
			zend_throw_error(NULL,
				"Cannot load transferred object: class \"%s\" not found",
				ZSTR_VAL(class_name));
		}
		zend_object *fallback = zend_objects_new(zend_standard_class_def);
		object_properties_init(fallback, zend_standard_class_def);
		THREAD_DEPTH_RELEASE(ctx);
		return fallback;
	}

	/* Create a normal emalloc'd object */
	zend_object *dst = zend_objects_new(ce);
	thread_transfer_xlat_put(ctx, src, dst);
	object_properties_init(dst, ce);

	/* Copy declared properties from transit object */
	const uint32_t prop_count = MIN(src_prop_count,
		(uint32_t) ce->default_properties_count);

	for (uint32_t i = 0; i < prop_count; i++) {
		const zval *prop = &src->properties_table[i];
		if (Z_TYPE_P(prop) != IS_UNDEF) {
			zval copy;
			thread_load_zval_inner(ctx, &copy, prop);
			zval_ptr_dtor(&dst->properties_table[i]);
			ZVAL_COPY_VALUE(&dst->properties_table[i], &copy);
		}
	}

	THREAD_DEPTH_RELEASE(ctx);
	return dst;
}

static zend_always_inline void thread_load_zval_inner(thread_transfer_ctx_t *ctx, zval *dst, const zval *src)
{
	switch (Z_TYPE_P(src)) {
		case IS_UNDEF:
			ZVAL_UNDEF(dst);
			break;

		case IS_NULL:
			ZVAL_NULL(dst);
			break;

		case IS_FALSE:
			ZVAL_FALSE(dst);
			break;

		case IS_TRUE:
			ZVAL_TRUE(dst);
			break;

		case IS_LONG:
			ZVAL_LONG(dst, Z_LVAL_P(src));
			break;

		case IS_DOUBLE:
			ZVAL_DOUBLE(dst, Z_DVAL_P(src));
			break;

		case IS_STRING: {
			zend_string *copy = thread_load_string(ctx, Z_STR_P(src));
			ZVAL_STR(dst, copy);
			break;
		}

		case IS_ARRAY: {
			HashTable *copy = thread_load_hash_table(ctx, Z_ARR_P(src));
			ZVAL_ARR(dst, copy);
			break;
		}

		case IS_OBJECT: {
			zend_object *copy = thread_load_object(ctx, Z_OBJ_P(src));
			ZVAL_OBJ(dst, copy);
			break;
		}

		default:
			ZVAL_NULL(dst);
			break;
	}
}

/**
 * Load a persistent zval into the current thread's emalloc heap.
 *
 * @param dst  Destination zval (emalloc, current thread)
 * @param src  Source zval (pemalloc, from async_thread_transfer_zval)
 */
void async_thread_load_zval(zval *dst, const zval *src)
{
	thread_transfer_ctx_t ctx;
	thread_transfer_ctx_init(&ctx);
	thread_load_zval_inner(&ctx, dst, src);
	thread_transfer_ctx_destroy(&ctx);
}

/* {{{ Release — free persistent zvals created by async_thread_transfer_zval */

static void thread_release_transferred_hash_table(HashTable *ht);
static void thread_release_transferred_object(zend_object *obj);

static void thread_release_transferred_zval(zval *z)
{
	switch (Z_TYPE_P(z)) {
		case IS_STRING:
			zend_string_release(Z_STR_P(z));
			break;

		case IS_ARRAY:
			thread_release_transferred_hash_table(Z_ARR_P(z));
			break;

		case IS_OBJECT:
			thread_release_transferred_object(Z_OBJ_P(z));
			break;

		default:
			break;
	}
}

static void thread_release_transferred_hash_table(HashTable *ht)
{
	if (ht->nNumUsed == 0 && ht->nNumOfElements == 0 && ht->nTableSize == 0) {
		return;
	}

	zval *val;
	ZEND_HASH_FOREACH_VAL(ht, val) {
		thread_release_transferred_zval(val);
	} ZEND_HASH_FOREACH_END();

	zend_hash_destroy(ht);
	pefree(ht, 1);
}

static void thread_release_transferred_object(zend_object *obj)
{
	/* Read transit fields */
	const uint32_t prop_count = (uint32_t)(uintptr_t) obj->handlers;
	zend_string *class_name = (zend_string *) obj->ce;

	for (uint32_t i = 0; i < prop_count; i++) {
		thread_release_transferred_zval(&obj->properties_table[i]);
	}

	zend_string_release(class_name);
	pefree(obj, 1);
}

void async_thread_release_transferred_zval(zval *z)
{
	thread_release_transferred_zval(z);
	ZVAL_UNDEF(z);
}

/* }}} */

///////////////////////////////////////////////////////////
/// 1. Snapshot — transfer callable between threads
///////////////////////////////////////////////////////////

/**
 * Deep-copy a callable into persistent memory.
 * Copies op_array via thread_copy_op_array and transfers
 * captured variables via async_thread_transfer_zval.
 */
static void thread_copy_callable(
	thread_copy_ctx_t ctx, const zend_fcall_t *fcall, async_thread_closure_copy_t *dst)
{
	const zend_op_array *src_op = &fcall->fci_cache.function_handler->op_array;

	/* Deep copy the op_array */
	zval tmp;
	ZVAL_PTR(&tmp, (void *) src_op);
	thread_copy_op_array(ctx, &tmp);
	dst->func = Z_PTR(tmp);

	/* Transfer captured variables (use ($a, $b)) into persistent memory */
	HashTable *static_vars = ZEND_MAP_PTR_GET(src_op->static_variables_ptr);
	if (!static_vars) {
		static_vars = src_op->static_variables;
	}

	if (static_vars && zend_hash_num_elements(static_vars) > 0) {
		dst->bound_vars = pemalloc(sizeof(HashTable), 1);
		zend_hash_init(dst->bound_vars, zend_hash_num_elements(static_vars), NULL, NULL, 1);
		zend_hash_index_add_ptr(ctx, async_ptr_to_index(dst->bound_vars), dst->bound_vars);

		zend_string *key;
		zval *val;
		ZEND_HASH_FOREACH_STR_KEY_VAL(static_vars, key, val) {
			zval transferred;
			async_thread_transfer_zval(&transferred, val);
			zend_string *pkey = zend_string_dup(key, 1);
			zend_hash_add(dst->bound_vars, pkey, &transferred);
			zend_string_release(pkey);
		} ZEND_HASH_FOREACH_END();
	} else {
		dst->bound_vars = NULL;
	}
}

/**
 * Free resources of a copied closure (bound vars only;
 * op_array is freed via persistent_map).
 */
static void thread_release_closure_copy(async_thread_closure_copy_t *copy)
{
	if (copy->bound_vars) {
		zend_string *key;
		zval *val;
		ZEND_HASH_FOREACH_STR_KEY_VAL(copy->bound_vars, key, val) {
			async_thread_release_transferred_zval(val);
			if (key) {
				zend_string_release(key);
			}
		} ZEND_HASH_FOREACH_END();
		zend_hash_destroy(copy->bound_vars);
		/* bound_vars itself is tracked in persistent_map */
	}
}

/* Destructor for persistent_map entries: free pemalloc'd pointers */
static void thread_persistent_ptr_dtor(zval *zv)
{
	pefree(Z_PTR_P(zv), 1);
}

/**
 * Create a snapshot: deep-copy closures into persistent memory.
 */
async_thread_snapshot_t *async_thread_snapshot_create(const zend_fcall_t *entry, const zend_fcall_t *bootloader)
{
	async_thread_snapshot_t *snapshot = pecalloc(1, sizeof(async_thread_snapshot_t), 1);

	/* Deep copy callables into persistent memory */
	zend_hash_init(&snapshot->persistent_map, 128, NULL, thread_persistent_ptr_dtor, 1);

	thread_copy_callable(&snapshot->persistent_map, entry, &snapshot->entry);

	if (bootloader != NULL) {
		thread_copy_callable(&snapshot->persistent_map, bootloader, &snapshot->bootloader);
	}

	return snapshot;
}

/**
 * Free snapshot resources.
 */
void async_thread_snapshot_destroy(async_thread_snapshot_t *snapshot)
{
	thread_release_closure_copy(&snapshot->entry);

	if (snapshot->bootloader.func != NULL) {
		thread_release_closure_copy(&snapshot->bootloader);
	}

	/* Destructor auto-frees all pemalloc'd pointers */
	zend_hash_destroy(&snapshot->persistent_map);

	pefree(snapshot, 1);
}

/**
 * Create a RemoteException wrapping a loaded remote exception object.
 */
/**
 * Create a ThreadTransferException with message and optional previous exception.
 * Consumes the previous exception reference (OBJ_RELEASE after setting).
 */
static zend_object *thread_create_transfer_exception(
	const char *message, zend_object *previous)
{
	zval exception_zv;
	object_init_ex(&exception_zv, async_ce_thread_transfer_exception);

	zval msg_zv;
	ZVAL_STRING(&msg_zv, message);
	zend_update_property_ex(async_ce_thread_transfer_exception,
		Z_OBJ(exception_zv), ZSTR_KNOWN(ZEND_STR_MESSAGE), &msg_zv);
	zval_ptr_dtor(&msg_zv);

	if (previous) {
		zend_exception_set_previous(Z_OBJ(exception_zv), previous);
	}

	return Z_OBJ(exception_zv);
}

static zend_object *thread_wrap_remote_exception(
	zend_object *remote_obj, const char *remote_class_name)
{
	zval wrapper_zval;
	object_init_ex(&wrapper_zval, async_ce_remote_exception);
	zend_object *wrapper = Z_OBJ(wrapper_zval);

	/* Set message from remote exception */
	zval rv;
	zval *remote_message = zend_read_property_ex(
		remote_obj->ce, remote_obj, ZSTR_KNOWN(ZEND_STR_MESSAGE), 1, &rv);
	if (remote_message && Z_TYPE_P(remote_message) == IS_STRING) {
		zend_update_property_ex(async_ce_remote_exception, wrapper,
			ZSTR_KNOWN(ZEND_STR_MESSAGE), remote_message);
	}

	/* Set code from remote exception */
	zval *remote_code = zend_read_property_ex(
		remote_obj->ce, remote_obj, ZSTR_KNOWN(ZEND_STR_CODE), 1, &rv);
	if (remote_code && Z_TYPE_P(remote_code) == IS_LONG) {
		zval code_zv;
		ZVAL_LONG(&code_zv, Z_LVAL_P(remote_code));
		zend_update_property_ex(async_ce_remote_exception, wrapper,
			ZSTR_KNOWN(ZEND_STR_CODE), &code_zv);
	}

	/* Store the original remote exception */
	zval remote_exception_zval;
	ZVAL_OBJ_COPY(&remote_exception_zval, remote_obj);
	zend_update_property(async_ce_remote_exception, wrapper,
		"remoteException", sizeof("remoteException") - 1, &remote_exception_zval);
	zval_ptr_dtor(&remote_exception_zval);

	/* Store the remote class name */
	zend_update_property_string(async_ce_remote_exception, wrapper,
		"remoteClass", sizeof("remoteClass") - 1, remote_class_name);

	return wrapper;
}

/**
 * Load thread result/exception from persistent memory into parent's emalloc.
 * Called from reactor's notify callback in the parent thread.
 */
void async_thread_load_result(zend_async_thread_event_t *event)
{
	ZEND_ASYNC_EVENT_CLR_EXCEPTION_HANDLED(&event->base);

	/* Handle bailout from child thread (fatal error, OOM, exit()) */
	if (UNEXPECTED(event->bailout_error_message != NULL)) {
		event->exception = thread_create_transfer_exception(
			event->bailout_error_message, NULL);
		pefree(event->bailout_error_message, 1);
		event->bailout_error_message = NULL;
		ZEND_THREAD_SET_RESULT_LOADED(event);
		return;
	}

	/* Load exception and wrap in RemoteException */
	if (event->exception != NULL) {
		/* remote_class_name lives in persistent memory (transit format: ce = class_name) */
		const char *remote_class_name = ZSTR_VAL((zend_string *) event->exception->ce);

		zval persistent_exception, loaded_exception;
		ZVAL_OBJ(&persistent_exception, event->exception);
		async_thread_load_zval(&loaded_exception, &persistent_exception);

		if (UNEXPECTED(EG(exception))) {
			zend_object *loading_exception = EG(exception);
			GC_ADDREF(loading_exception);
			zend_clear_exception();

			zend_string *message = zend_strpprintf(0,
				"Failed to load remote exception of class \"%s\"", remote_class_name);
			event->exception = thread_create_transfer_exception(
				ZSTR_VAL(message), loading_exception);
			zend_string_release(message);

			zval_ptr_dtor(&loaded_exception);
			async_thread_release_transferred_zval(&persistent_exception);
		} else {
			event->exception = thread_wrap_remote_exception(
				Z_OBJ(loaded_exception), remote_class_name);
			zval_ptr_dtor(&loaded_exception);
			async_thread_release_transferred_zval(&persistent_exception);
		}
	}

	/* Load result */
	if (!Z_ISUNDEF(event->result)) {
		zval loaded_result;
		async_thread_load_zval(&loaded_result, &event->result);

		if (UNEXPECTED(EG(exception))) {
			zend_object *loading_exception = EG(exception);
			GC_ADDREF(loading_exception);
			zend_clear_exception();

			async_thread_release_transferred_zval(&event->result);
			zval_ptr_dtor(&loaded_result);
			event->exception = thread_create_transfer_exception(
				"Failed to load thread result into parent thread", loading_exception);
			ZEND_THREAD_SET_RESULT_LOADED(event);
			return;
		}

		async_thread_release_transferred_zval(&event->result);
		ZVAL_COPY_VALUE(&event->result, &loaded_result);
		ZEND_ASYNC_EVENT_SET_ZVAL_RESULT(&event->base);
	}

	ZEND_THREAD_SET_RESULT_LOADED(event);
}

///////////////////////////////////////////////////////////
/// 2. Thread lifecycle — PHP request in child thread
///////////////////////////////////////////////////////////

/**
 * Initialize TSRM for the child thread.
 * Must be called BEFORE any zend_try block (EG(bailout) is not available yet).
 * After this call, EG()/SG()/PG() macros work correctly.
 */
void async_thread_tsrm_init(void)
{
#ifdef ZTS
	ts_resource(0);
	TSRMLS_CACHE_UPDATE();

	/* Update TSRMLS cache in the SAPI module (e.g. php.exe on Windows).
	 * Without this, SG()/EG() calls from SAPI callbacks would use
	 * an uninitialized per-module thread-local cache. */
	if (sapi_module.thread_init) {
		sapi_module.thread_init();
	}
#endif
}

int async_thread_request_startup(const async_thread_snapshot_t *snapshot)
{
#ifdef ZTS
	PG(expose_php) = 0;
	PG(auto_globals_jit) = 1;

	if (UNEXPECTED(php_request_startup() == FAILURE)) {
		ts_free_thread();
		return FAILURE;
	}

	/* Suppress HTTP-specific behavior */
	PG(during_request_startup) = 0;
	SG(sapi_started) = 0;
	SG(headers_sent) = 1;
	SG(request_info).no_headers = 1;

	/* Set script filename from entry closure for error reporting */
	if (snapshot->entry.func && snapshot->entry.func->filename) {
		SG(request_info).path_translated = estrndup(
			ZSTR_VAL(snapshot->entry.func->filename),
			ZSTR_LEN(snapshot->entry.func->filename));
	}

	return SUCCESS;
#else
	return FAILURE;
#endif
}

void async_thread_request_shutdown(void)
{
#ifdef ZTS
	/* Free path_translated before shutdown destroys emalloc heap */
	if (SG(request_info).path_translated) {
		efree(SG(request_info).path_translated);
		SG(request_info).path_translated = NULL;
	}
	php_request_shutdown(NULL);
#endif
}

void async_thread_create_closure(
	const async_thread_closure_copy_t *copy, zval *closure_zv)
{
	ZEND_ASSERT(copy->func != NULL);

	zend_function func;
	memcpy(&func, copy->func, sizeof(zend_op_array));
	func.op_array.fn_flags &= ~ZEND_ACC_IMMUTABLE;

	/* Set refcount to NULL: zend_create_closure skips increment,
	 * and destroy_op_array returns early without freeing pemalloc'd data. */
	func.op_array.refcount = NULL;

	/* Load bound variables from persistent memory into child's emalloc */
	HashTable *loaded_vars = NULL;
	if (copy->bound_vars) {
		loaded_vars = zend_new_array(
			zend_hash_num_elements(copy->bound_vars));
		zend_string *key;
		zval *val;
		ZEND_HASH_FOREACH_STR_KEY_VAL(copy->bound_vars, key, val) {
			zval loaded;
			async_thread_load_zval(&loaded, val);
			zend_hash_add(loaded_vars, key, &loaded);
		} ZEND_HASH_FOREACH_END();
		ZEND_MAP_PTR_INIT(func.op_array.static_variables_ptr, loaded_vars);
	} else {
		ZEND_MAP_PTR_INIT(func.op_array.static_variables_ptr, NULL);
	}

	/* Let zend_create_closure allocate runtime cache itself */
	ZEND_MAP_PTR_INIT(func.op_array.run_time_cache, NULL);
	func.op_array.fn_flags &= ~ZEND_ACC_HEAP_RT_CACHE;

	zend_create_closure(closure_zv, &func, NULL, NULL, NULL);

	/* zend_create_closure duplicates static_variables via zend_array_dup,
	 * so we must free our intermediate copy */
	if (loaded_vars) {
		zend_array_destroy(loaded_vars);
	}
}

/**
 * Call a deep-copied closure in the child thread.
 *
 * Creates a Closure from the copy, executes it, and captures any exception
 * immediately (before dtors that could trigger bailout).
 *
 * @param copy    Deep-copied closure from snapshot
 * @param event   Thread event (receives exception on failure)
 * @param retval  Output: return value (UNDEF on exception or void return)
 * @return true on success, false if exception was captured
 */
static bool thread_call_closure(
	const async_thread_closure_copy_t *copy,
	zend_async_thread_event_t *event,
	zval *retval)
{
	zval closure_zv;
	async_thread_create_closure(copy, &closure_zv);

	const zend_function *func = zend_get_closure_method_def(Z_OBJ(closure_zv));

	ZVAL_UNDEF(retval);

	/* Execute closure directly via VM, bypassing zend_call_function.
	 * zend_call_function would trigger zend_throw_exception_internal
	 * when current_execute_data is NULL (no PHP caller above us),
	 * converting any uncaught exception into a fatal bailout.
	 * With zend_execute_ex we get the exception cleanly in EG(exception). */
	zend_execute_data *frame = zend_vm_stack_push_call_frame(
		ZEND_CALL_TOP_FUNCTION, (zend_function *) func, 0, NULL);
	zend_init_func_execute_data(frame, (zend_op_array *) &func->op_array, retval);
	zend_execute_ex(frame);

	/* After zend_execute_ex returns, the frame is already freed by the VM.
	 * If the closure threw, EG(exception) is set — no bailout occurred. */
	const bool has_exception = EG(exception) != NULL;
	if (UNEXPECTED(has_exception)) {
		zval exception_zval, transferred_exception;
		ZVAL_OBJ_COPY(&exception_zval, EG(exception));
		zend_clear_exception();
		async_thread_transfer_zval(&transferred_exception, &exception_zval);
		event->exception = Z_OBJ(transferred_exception);
		zval_ptr_dtor(&exception_zval);
		zval_ptr_dtor(retval);
		ZVAL_UNDEF(retval);
	}

	zval_ptr_dtor(&closure_zv);
	return !has_exception;
}

/**
 * Capture bailout error message into the event.
 * Safe to call after zend_catch — uses only pemalloc and PG().
 *
 * @param fallback  Pre-allocated fallback message (ownership transferred
 *                  if PG(last_error_message) is NULL). Caller must set
 *                  its pointer to NULL after this call to avoid double-free.
 */
static zend_always_inline void thread_capture_bailout(zend_async_thread_event_t *event, char **fallback)
{
	const zend_string *last_error = PG(last_error_message);

	if (last_error != NULL) {
		event->bailout_error_message = pestrdup(ZSTR_VAL(last_error), 1);
	} else {
		event->bailout_error_message = *fallback;
		*fallback = NULL;
	}
}

void async_thread_run(void *arg)
{
	zend_async_thread_event_t *event = (zend_async_thread_event_t *) arg;
	const async_thread_snapshot_t *snapshot = event->snapshot;
	bool request_started = false;
	char *fallback_message = pestrdup("Unknown fatal error in child thread", 1);

	/* Record OS thread ID */
#ifdef _WIN32
	zend_atomic_int64_store(&event->thread_id, (int64_t) GetCurrentThreadId());
#else
	zend_atomic_int64_store(&event->thread_id, (int64_t) pthread_self());
#endif

	if (UNEXPECTED(snapshot == NULL)) {
		goto notify;
	}

	/* 1a. Initialize TSRM — must happen before any zend_try
	 * because zend_try uses EG(bailout) which requires TSRM. */
	async_thread_tsrm_init();

	/* 1b. Start PHP request (can bailout).
	 * zend_first_try initializes EG(bailout) for this thread. */
	zend_first_try {
		if (UNEXPECTED(async_thread_request_startup(snapshot) == FAILURE)) {
			goto notify;
		}
		request_started = true;
	} zend_catch {
		thread_capture_bailout(event, &fallback_message);
		goto notify;
	} zend_end_try();

	/* 2. Execute user closures */
	zend_first_try {
		zval retval;
		ZVAL_UNDEF(&retval);

		/* Bootloader (optional) */
		if (snapshot->bootloader.func != NULL) {
			if (!thread_call_closure(&snapshot->bootloader, event, &retval)) {
				goto cleanup;
			}
		}

		/* Entry closure */
		if (thread_call_closure(&snapshot->entry, event, &retval)) {
			if (!Z_ISUNDEF(retval)) {
				async_thread_transfer_zval(&event->result, &retval);

				/* Transfer itself may fail (unsupported types, depth limit) */
				if (UNEXPECTED(EG(exception))) {
					zval exception_zval, transferred_exception;
					ZVAL_OBJ_COPY(&exception_zval, EG(exception));
					zend_clear_exception();
					async_thread_transfer_zval(&transferred_exception, &exception_zval);
					event->exception = Z_OBJ(transferred_exception);
					zval_ptr_dtor(&exception_zval);
					ZVAL_UNDEF(&event->result);
				}
			}
		}

cleanup:
		zval_ptr_dtor(&retval);
	} zend_catch {
		thread_capture_bailout(event, &fallback_message);
	} zend_end_try();

	/* 3. Free snapshot — closures have been executed, all pemalloc'd
	 * op_array data (strings, literals) is no longer referenced. */
	if (event->snapshot != NULL) {
		async_thread_snapshot_destroy(event->snapshot);
		event->snapshot = NULL;
	}

	/* 4. Shut down PHP request */
	if (EXPECTED(request_started)) {
		zend_first_try {
			async_thread_request_shutdown();
		} zend_catch {
			/* Bailout during shutdown — not much we can do,
			 * but don't overwrite a prior bailout message. */
			if (event->bailout_error_message == NULL) {
				thread_capture_bailout(event, &fallback_message);
			}
		} zend_end_try();
	}

	/* Free TSRM storage after all zend_end_try blocks.
	 * Must be separate because zend_end_try accesses EG(bailout). */
#ifdef ZTS
	ts_free_thread();
#endif

notify:
	if (fallback_message != NULL) {
		pefree(fallback_message, 1);
	}
	event->notify_parent(event);
}

///////////////////////////////////////////////////////////
/// 3. Thread PHP object — Async\Thread class
///////////////////////////////////////////////////////////

#define METHOD(name) PHP_METHOD(Async_Thread, name)
#define THIS_THREAD() Z_ASYNC_THREAD_P(ZEND_THIS)

zend_class_entry *async_ce_thread = NULL;
zend_class_entry *async_ce_remote_exception = NULL;
zend_class_entry *async_ce_thread_transfer_exception = NULL;

static zend_object_handlers thread_object_handlers;

/* ---- RemoteException methods ---- */

PHP_METHOD(Async_RemoteException, getRemoteException)
{
	ZEND_PARSE_PARAMETERS_NONE();
	zval *prop = zend_read_property(async_ce_remote_exception, Z_OBJ_P(ZEND_THIS),
		"remoteException", sizeof("remoteException") - 1, 0, NULL);
	RETURN_ZVAL(prop, 1, 0);
}

PHP_METHOD(Async_RemoteException, getRemoteClass)
{
	ZEND_PARSE_PARAMETERS_NONE();
	zval *prop = zend_read_property(async_ce_remote_exception, Z_OBJ_P(ZEND_THIS),
		"remoteClass", sizeof("remoteClass") - 1, 0, NULL);
	RETURN_ZVAL(prop, 1, 0);
}

/* ---- Object Lifecycle ---- */

static zend_object *thread_object_create(zend_class_entry *class_entry)
{
	async_thread_object_t *thread = zend_object_alloc(sizeof(async_thread_object_t), class_entry);

	ZEND_ASYNC_EVENT_REF_SET(thread, XtOffsetOf(async_thread_object_t, std), NULL);

	thread->thread_event = NULL;
	thread->finally_handlers = NULL;

	zend_object_std_init(&thread->std, class_entry);
	object_properties_init(&thread->std, class_entry);

	return &thread->std;
}

static void thread_object_dtor(zend_object *object)
{
	async_thread_object_t *thread = async_thread_object_from_obj(object);

	if (thread->thread_event != NULL) {
		zend_async_event_t *event = &thread->thread_event->base;

		/* Call finally handlers if the thread completed */
		if (thread->finally_handlers != NULL
			&& zend_hash_num_elements(thread->finally_handlers) > 0
			&& ZEND_ASYNC_EVENT_IS_CLOSED(event)) {

			finally_handlers_context_t *finally_context = ecalloc(1, sizeof(finally_handlers_context_t));
			finally_context->target = thread;
			finally_context->scope = NULL;
			finally_context->dtor = NULL;
			finally_context->params_count = 1;
			ZVAL_OBJ(&finally_context->params[0], &thread->std);

			HashTable *handlers = thread->finally_handlers;
			thread->finally_handlers = NULL;

			if (async_call_finally_handlers(handlers, finally_context, 0)) {
				GC_ADDREF(&thread->std);
			} else {
				efree(finally_context);
				zend_array_destroy(handlers);
			}
		}

		/* Dispose the underlying event (Thread object is the sole owner) */
		if (event->dispose != NULL) {
			event->dispose(event);
		}
		thread->thread_event = NULL;
	}

	if (thread->finally_handlers) {
		zend_array_destroy(thread->finally_handlers);
		thread->finally_handlers = NULL;
	}
}

static void thread_object_free(zend_object *object)
{
	zend_object_std_dtor(object);
}

static HashTable *thread_object_gc(zend_object *object, zval **table, int *num)
{
	async_thread_object_t *thread = async_thread_object_from_obj(object);

	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();

	if (thread->thread_event != NULL) {
		if (!Z_ISUNDEF(thread->thread_event->result)) {
			zend_get_gc_buffer_add_zval(buf, &thread->thread_event->result);
		}
		if (thread->thread_event->exception != NULL) {
			zend_get_gc_buffer_add_obj(buf, thread->thread_event->exception);
		}
	}

	if (thread->finally_handlers) {
		zval *val;
		ZEND_HASH_FOREACH_VAL(thread->finally_handlers, val)
		{
			zend_get_gc_buffer_add_zval(buf, val);
		}
		ZEND_HASH_FOREACH_END();
	}

	zend_get_gc_buffer_use(buf, table, num);

	return NULL;
}

/* ---- PHP Methods ---- */

METHOD(__construct)
{
	zend_throw_error(NULL, "Cannot directly construct Async\\Thread");
}

METHOD(isRunning)
{
	ZEND_PARSE_PARAMETERS_NONE();

	const async_thread_object_t *thread = THIS_THREAD();

	if (UNEXPECTED(thread->thread_event == NULL)) {
		RETURN_FALSE;
	}

	const zend_async_event_t *event = &thread->thread_event->base;

	RETURN_BOOL(event->loop_ref_count > 0
		&& !ZEND_ASYNC_EVENT_IS_CLOSED(event));
}

METHOD(isCompleted)
{
	ZEND_PARSE_PARAMETERS_NONE();

	const async_thread_object_t *thread = THIS_THREAD();

	if (UNEXPECTED(thread->thread_event == NULL)) {
		RETURN_TRUE;
	}

	RETURN_BOOL(ZEND_ASYNC_EVENT_IS_CLOSED(&thread->thread_event->base));
}

METHOD(isCancelled)
{
	ZEND_PARSE_PARAMETERS_NONE();

	const async_thread_object_t *thread = THIS_THREAD();

	if (UNEXPECTED(thread->thread_event == NULL)) {
		RETURN_FALSE;
	}

	/* TODO: thread cancellation not yet implemented */
	RETURN_FALSE;
}

METHOD(getResult)
{
	ZEND_PARSE_PARAMETERS_NONE();

	const async_thread_object_t *thread = THIS_THREAD();

	if (UNEXPECTED(thread->thread_event == NULL)
		|| !ZEND_ASYNC_EVENT_IS_CLOSED(&thread->thread_event->base)) {
		RETURN_NULL();
	}

	if (!Z_ISUNDEF(thread->thread_event->result)) {
		RETURN_COPY(&thread->thread_event->result);
	}

	RETURN_NULL();
}

METHOD(getException)
{
	ZEND_PARSE_PARAMETERS_NONE();

	const async_thread_object_t *thread = THIS_THREAD();

	if (UNEXPECTED(thread->thread_event == NULL)
		|| !ZEND_ASYNC_EVENT_IS_CLOSED(&thread->thread_event->base)) {
		RETURN_NULL();
	}

	if (thread->thread_event->exception != NULL) {
		RETURN_OBJ_COPY(thread->thread_event->exception);
	}

	RETURN_NULL();
}

METHOD(cancel)
{
	zval *cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancellation, async_ce_completable)
	ZEND_PARSE_PARAMETERS_END();

	async_thread_object_t *thread = THIS_THREAD();

	if (UNEXPECTED(thread->thread_event == NULL)) {
		return;
	}

	zend_async_event_t *event = &thread->thread_event->base;

	if (ZEND_ASYNC_EVENT_IS_CLOSED(event)) {
		return;
	}

	/* TODO: implement thread cancellation mechanism */
	async_throw_error("Thread cancellation is not yet implemented");
}

METHOD(finally)
{
	zval *callable;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(callable)
	ZEND_PARSE_PARAMETERS_END();

	if (UNEXPECTED(!zend_is_callable(callable, 0, NULL))) {
		async_throw_error("Argument #1 ($callback) must be callable");
		RETURN_THROWS();
	}

	async_thread_object_t *thread = THIS_THREAD();

	/* If the thread is already completed, call the callback immediately */
	if (thread->thread_event != NULL
		&& ZEND_ASYNC_EVENT_IS_CLOSED(&thread->thread_event->base)) {
		zval rv;
		ZVAL_UNDEF(&rv);

		zval param;
		ZVAL_OBJ(&param, &thread->std);

		zend_fcall_info fci;
		zend_fcall_info_cache fcc;
		if (zend_fcall_info_init(callable, 0, &fci, &fcc, NULL, NULL) == SUCCESS) {
			fci.retval = &rv;
			fci.param_count = 1;
			fci.params = &param;
			zend_call_function(&fci, &fcc);
		}

		zval_ptr_dtor(&rv);
		return;
	}

	if (thread->finally_handlers == NULL) {
		thread->finally_handlers = zend_new_array(0);
	}

	if (UNEXPECTED(zend_hash_next_index_insert(thread->finally_handlers, callable) == NULL)) {
		async_throw_error("Failed to add finally handler to thread");
		RETURN_THROWS();
	}

	Z_TRY_ADDREF_P(callable);
}

/* API wrappers for opaque void* signatures */
/* API-compatible wrappers with opaque void* signatures */
void *async_thread_snapshot_create_api(
	const zend_fcall_t *entry, const zend_fcall_t *bootloader)
{
	return async_thread_snapshot_create(entry, bootloader);
}

void async_thread_snapshot_destroy_api(void *snapshot)
{
	async_thread_snapshot_destroy((async_thread_snapshot_t *) snapshot);
}

void async_register_thread_ce(void)
{
	async_ce_remote_exception = register_class_Async_RemoteException(async_ce_async_exception);
	async_ce_thread_transfer_exception = register_class_Async_ThreadTransferException(async_ce_async_exception);

	async_ce_thread = register_class_Async_Thread(async_ce_completable);
	async_ce_thread->create_object = thread_object_create;
	async_ce_thread->default_object_handlers = &thread_object_handlers;

	thread_object_handlers = std_object_handlers;
	thread_object_handlers.offset = XtOffsetOf(async_thread_object_t, std);
	thread_object_handlers.clone_obj = NULL;
	thread_object_handlers.dtor_obj = thread_object_dtor;
	thread_object_handlers.free_obj = thread_object_free;
	thread_object_handlers.get_gc = thread_object_gc;
}
