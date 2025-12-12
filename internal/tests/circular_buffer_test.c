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
#include "circular_buffer_test.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "../internal/circular_buffer.h"

#define ASSERT(condition, message) \
	do { \
		if (!(condition)) { \
			fprintf(stderr, "Assertion failed: %s\n", #condition); \
			fprintf(stderr, "Message: %s\n", message); \
			fprintf(stderr, "File: %s, Line: %d\n", __FILE__, __LINE__); \
			exit(1); \
		} \
	} while (0)

typedef struct
{
	int a;
	int b;
	char c;
	char d;
} test_struct_t;

static void test_create_and_destroy()
{
	circular_buffer_t *buffer = circular_buffer_new(8, sizeof(test_struct_t), NULL);

	ASSERT(buffer != NULL, "Buffer creation failed");
	ASSERT(buffer->item_size == sizeof(test_struct_t), "Buffer item size mismatch");

	circular_buffer_destroy(buffer);
	printf("[*] test_create_and_destroy passed\n");
}

static void test_push_and_pop()
{
	circular_buffer_t *buffer = circular_buffer_new(16, sizeof(test_struct_t), NULL);
	test_struct_t value_in = { 1, 2, 'a', 'b' };
	test_struct_t value_out = { 0, 0, 0, 0 };

	ASSERT(circular_buffer_push(buffer, &value_in, true) == SUCCESS, "Push operation failed");
	ASSERT(circular_buffer_pop(buffer, &value_out) == SUCCESS, "Pop operation failed");

	ASSERT(value_in.a == value_out.a && value_in.b == value_out.b && value_in.c == value_out.c &&
				   value_in.d == value_out.d,
		   "Value mismatch after pop");

	circular_buffer_destroy(buffer);
	printf("[*] test_push_and_pop passed\n");
}

static void test_is_empty_and_is_full()
{
	// Request size 2, but will be rounded up to 2 (already power of 2)
	// Capacity=2 means 1 usable slot (1 slot reserved to distinguish full/empty)
	circular_buffer_t *buffer = circular_buffer_new(2, sizeof(test_struct_t), NULL);
	test_struct_t value = { 100, 200, 'x', 'q' };

	ASSERT(circular_buffer_is_empty(buffer), "Buffer should be empty initially");
	ASSERT(!circular_buffer_is_full(buffer), "Buffer should not be full initially");

	circular_buffer_push(buffer, &value, true);
	ASSERT(!circular_buffer_is_empty(buffer), "Buffer should not be empty after push");

	ASSERT(circular_buffer_is_full(buffer), "Buffer should be full after one push (capacity=2, usable=1)");

	circular_buffer_destroy(buffer);
	printf("[*] test_is_empty_and_is_full passed\n");
}

static void test_reallocation()
{
	circular_buffer_t *buffer = circular_buffer_new(2, sizeof(test_struct_t), NULL);
	test_struct_t value = { 100, 200, 'x', 'q' };

	// Insert 5 elements into the buffer
	for (int i = 1; i <= 5; i++) {
		value.a++;
		value.b--;
		circular_buffer_push(buffer, &value, true);
	}

	// Assert insertion
	test_struct_t expected = { 100, 200, 'x', 'q' };
	test_struct_t result = { 0, 0, '0', '0' };

	for (int i = 1; i <= 5; i++) {
		expected.a++;
		expected.b--;
		circular_buffer_pop(buffer, &result);

		ASSERT(expected.a == result.a && expected.b == result.b && expected.c == result.c && expected.d == result.d,
			   "Expected mismatch after pop");
	}

	circular_buffer_destroy(buffer);
	printf("[*] test_reallocation passed\n");
}

static void test_wrap_around_order(void)
{
	circular_buffer_t *buffer = circular_buffer_new(4, sizeof(int), NULL); /* 4 slots → 3 usable */
	int in, out;

	/* push 1 2 3  */
	in = 1;
	circular_buffer_push(buffer, &in, false);
	in = 2;
	circular_buffer_push(buffer, &in, false);
	in = 3;
	circular_buffer_push(buffer, &in, false);

	/* pop two → expect 1, 2 */
	circular_buffer_pop(buffer, &out);
	ASSERT(out == 1, "Expected 1 after first pop");
	circular_buffer_pop(buffer, &out);
	ASSERT(out == 2, "Expected 2 after second pop");

	/* push 4 5 … here head will wrap */
	in = 4;
	circular_buffer_push(buffer, &in, false);
	in = 5;
	circular_buffer_push(buffer, &in, false);

	/* pop remaining elements: expect 3 4 5 */
	int expect_seq[] = { 3, 4, 5 };
	for (size_t i = 0; i < 3; i++) {
		circular_buffer_pop(buffer, &out);
		ASSERT(out == expect_seq[i], "Sequence mismatch after wrap‑around");
	}

	ASSERT(circular_buffer_is_empty(buffer), "Buffer should be empty at end");
	circular_buffer_destroy(buffer);
	printf("[*] test_wrap_around_order passed\n");
}

static void test_wrap_realloc(void)
{
	/* Request capacity 3, will be rounded up to 4 (power of 2) */
	/* Actual usable capacity: 4-1 = 3 slots */
	circular_buffer_t *buffer = circular_buffer_new(3, sizeof(int), NULL);
	int in, out;

	/* fill 1,2,3 (fills the buffer) */
	in = 1;
	circular_buffer_push(buffer, &in, true);
	in = 2;
	circular_buffer_push(buffer, &in, true);
	in = 3;
	circular_buffer_push(buffer, &in, true);

	/* pop two (removes 1, 2) */
	circular_buffer_pop(buffer, &out);
	ASSERT(out == 1, "Expected 1 after first pop (pre‑resize)");
	circular_buffer_pop(buffer, &out);
	ASSERT(out == 2, "Expected 2 after second pop (pre‑resize)");

	const size_t current_count = circular_buffer_count(buffer);
	ASSERT(current_count == 1, "Buffer should have 1 item before realloc");

	/* push 4, 5 (should fit in current capacity=4) */
	in = 4;
	circular_buffer_push(buffer, &in, true);
	in = 5;
	circular_buffer_push(buffer, &in, true);

	/* push 6 (triggers realloc: 4 -> 8) */
	in = 6;
	circular_buffer_push(buffer, &in, true); /* realloc here */

	/* Pop remaining 4 elements: expect 3,4,5,6 */
	int expect_seq[] = { 3, 4, 5, 6 };
	for (size_t i = 0; i < 4; i++) {
		circular_buffer_pop(buffer, &out);
		ASSERT(out == expect_seq[i], "Order mismatch after realloc wrap‑around");
	}

	ASSERT(circular_buffer_is_empty(buffer), "Buffer should be empty after final pops");
	circular_buffer_destroy(buffer);
	printf("[*] test_wrap_realloc passed\n");
}

static void test_wrap_with_decrease_realloc(void)
{
	circular_buffer_t *buffer = circular_buffer_new(2, sizeof(int), NULL);
	int in, out;

	in = 1;
	circular_buffer_push(buffer, &in, true);
	in = 2;
	circular_buffer_push(buffer, &in, true);
	in = 3;
	circular_buffer_push(buffer, &in, true);
	in = 4;
	circular_buffer_push(buffer, &in, true);
	in = 5;
	circular_buffer_push(buffer, &in, true);

	for (size_t i = 1; i <= 5; i++) {
		circular_buffer_pop(buffer, &out);
		ASSERT(out == i, "Order mismatch");
	}

	in = 1;
	circular_buffer_push(buffer, &in, true);
	in = 2;
	circular_buffer_push(buffer, &in, true);
	in = 3;
	circular_buffer_push(buffer, &in, true);
	in = 4;
	circular_buffer_push(buffer, &in, true);
	in = 5;
	circular_buffer_push(buffer, &in, true);

	for (size_t i = 1; i <= 5; i++) {
		circular_buffer_pop(buffer, &out);
		ASSERT(out == i, "Order mismatch");
	}

	ASSERT(circular_buffer_is_empty(buffer), "Buffer should be empty after final pops");
	circular_buffer_destroy(buffer);
	printf("[*] test_wrap_realloc_last_item passed\n");
}

static void test_wrap_with_decrease2_realloc(void)
{
	circular_buffer_t *buffer = circular_buffer_new(2, sizeof(int), NULL);
	int in, out;

	for (size_t i = 1; i <= 16; i++) {
		in = (int) i;
		circular_buffer_push(buffer, &in, true);
	}

	for (size_t i = 1; i <= 14; i++) {
		circular_buffer_pop(buffer, &out);
		ASSERT(out == i, "Order mismatch");
	}

	in = 17;
	circular_buffer_push(buffer, &in, true);

	for (size_t i = 15; i <= 15; i++) {
		circular_buffer_pop(buffer, &out);
		ASSERT(out == i, "Order mismatch");
	}

	// buffer->decrease_t = 20; // Set a high decrease threshold to force reallocation
	in = 18;
	circular_buffer_push(buffer, &in, true);

	for (size_t i = 16; i <= 18; i++) {
		circular_buffer_pop(buffer, &out);
		ASSERT(out == i, "Order mismatch");
	}

	ASSERT(circular_buffer_is_empty(buffer), "Buffer should be empty after final pops");
	circular_buffer_destroy(buffer);
	printf("[*] test_wrap_with_decrease2_realloc passed\n");
}

static void test_pop_empty()
{
	circular_buffer_t *buffer = circular_buffer_new(2, sizeof(test_struct_t), NULL);
	test_struct_t result = { 0, 0, '0', '0' };

	ASSERT(circular_buffer_pop(buffer, &result) == FAILURE, "Pop from empty buffer should fail");

	circular_buffer_destroy(buffer);
	printf("[*] test_pop_empty passed\n");
}

static void test_push_full()
{
	// Capacity=2, usable=1 slot
	circular_buffer_t *buffer = circular_buffer_new(2, sizeof(test_struct_t), NULL);
	test_struct_t value = { 100, 200, 'x', 'q' };

	// Fill the only usable slot
	circular_buffer_push(buffer, &value, false);

	// Second push should fail (buffer is full)
	ASSERT(circular_buffer_push(buffer, &value, false) == FAILURE, "Push to full buffer should fail");

	circular_buffer_destroy(buffer);
	printf("[*] test_push_full passed\n");
}

static void test_head_and_tail_exchange()
{
	circular_buffer_t *buffer = circular_buffer_new(4, sizeof(test_struct_t), NULL);
	test_struct_t value = { 100, 200, 'x', 'q' };

	// Insert 3 elements into the buffer
	for (int i = 1; i <= 3; i++) {
		circular_buffer_push(buffer, &value, false);
	}

	ASSERT(circular_buffer_is_full(buffer), "Buffer should be full");

	circular_buffer_pop(buffer, &value);
	circular_buffer_pop(buffer, &value);
	circular_buffer_push(buffer, &value, false);

	test_struct_t new_value = { 500, 500, 'a', 'z' };
	/**
	 * If the head is to the left of the tail and the difference between them is one element,
	 * the circular buffer is considered full, even though the tail points to a read element
	 * and theoretically one more element could be written.
	 *
	 * However, such an operation would put the buffer in an undefined state, which would be equivalent to being empty.
	 * In other words, the rule is as follows:
	 * the tail can "catch up" to the head, but the head is not allowed to catch up to the tail.
	 *
	 * The minimum difference between them must always be one element if the head is to the left.
	 */
	circular_buffer_push(buffer, &new_value, false);

	ASSERT(circular_buffer_push(buffer, &new_value, false) == FAILURE, "Push to full buffer should fail");

	// Buffer full?
	ASSERT(circular_buffer_is_full(buffer), "Buffer should be full");

	circular_buffer_pop(buffer, &value);
	circular_buffer_pop(buffer, &value);

	// This value should be equal to the {100, 200, 'x', 'q'}
	ASSERT(value.a == 100 && value.b == 200 && value.c == 'x' && value.d == 'q', "Value mismatch after pop");

	// This value should be equal to the {500, 500, 'a', 'z'}
	circular_buffer_pop(buffer, &value);
	ASSERT(value.a == 500 && value.b == 500 && value.c == 'a' && value.d == 'z', "Value mismatch after pop");

	ASSERT(circular_buffer_is_empty(buffer), "Buffer should be empty");

	circular_buffer_destroy(buffer);
	printf("[*] test_head_and_tail_exchange passed\n");
}

static void test_power_of_2_rounding()
{
	// Test that requested sizes are rounded up to power of 2
	circular_buffer_t *buffer;

	// Request 3, should get 4
	buffer = circular_buffer_new(3, sizeof(int), NULL);
	ASSERT(buffer->capacity == 4, "Size 3 should round up to 4");
	circular_buffer_destroy(buffer);

	// Request 5, should get 8
	buffer = circular_buffer_new(5, sizeof(int), NULL);
	ASSERT(buffer->capacity == 8, "Size 5 should round up to 8");
	circular_buffer_destroy(buffer);

	// Request 10, should get 16
	buffer = circular_buffer_new(10, sizeof(int), NULL);
	ASSERT(buffer->capacity == 16, "Size 10 should round up to 16");
	circular_buffer_destroy(buffer);

	// Request 16, should stay 16 (already power of 2)
	buffer = circular_buffer_new(16, sizeof(int), NULL);
	ASSERT(buffer->capacity == 16, "Size 16 should stay 16");
	circular_buffer_destroy(buffer);

	printf("[*] test_power_of_2_rounding passed\n");
}

static void test_zval_buffer()
{
	zval value_in;
	zval value_out;
	ZVAL_STRING(&value_in, "Hello, World!");

	// Request 5, will be rounded to 8
	circular_buffer_t *buffer = zval_circular_buffer_new(5, NULL);
	ASSERT(buffer != NULL, "zval buffer creation failed");
	ASSERT(buffer->capacity == 8, "Size 5 should round up to 8");

	ASSERT(zval_circular_buffer_push(buffer, &value_in, true) == SUCCESS, "zval push failed");
	ASSERT(zval_circular_buffer_pop(buffer, &value_out) == SUCCESS, "zval pop failed");

	ASSERT(Z_TYPE(value_out) == IS_STRING && strcmp(Z_STRVAL(value_out), "Hello, World!") == 0, "zval value mismatch");

	zval_ptr_dtor(&value_in);
	zval_ptr_dtor(&value_out);
	circular_buffer_destroy(buffer);

	printf("[*] test_zval_buffer passed\n");
}

int main()
{
	test_create_and_destroy();
	test_push_and_pop();
	test_is_empty_and_is_full();
	test_reallocation();
	test_wrap_around_order();
	test_wrap_realloc();
	test_wrap_with_decrease_realloc();
	test_wrap_with_decrease2_realloc();
	test_pop_empty();
	test_push_full();
	test_head_and_tail_exchange();
	test_power_of_2_rounding();
	test_zval_buffer();

	printf("\n[✅] All tests passed! Buffer now uses power-of-2 optimization.\n");
	return 0;
}