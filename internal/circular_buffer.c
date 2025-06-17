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
#include "circular_buffer.h"

#include <zend.h>
#include <zend_errors.h>

#define MINIMUM_COUNT 4

/**
 * Round up to the nearest power of 2
 */
static zend_always_inline size_t round_up_to_power_of_2(size_t n)
{
    if (n == 0) return 1;
    if ((n & (n - 1)) == 0) return n;  // Already power of 2
    
    // Find the highest bit position
    size_t power = 1;
    while (power < n) {
        power <<= 1;
    }
    return power;
}

#ifdef ASYNC_UNIT_TESTS
#include <stdio.h>
#endif

#ifdef ASYNC_UNIT_TESTS
#define ASYNC_ERROR(type, format) fprintf(stderr, format "\n")
#else
#define ASYNC_ERROR(type, format) zend_error(type, format)
#endif

/**
 * Initialize a new circular buffer.
 */
circular_buffer_t *circular_buffer_new(size_t count, const size_t item_size, const allocator_t *allocator)
{
    if(item_size <= 0) {
        ASYNC_ERROR(E_ERROR, "Item size must be greater than zero");
        return NULL;
    }

    if(allocator == NULL) {
        allocator = &zend_std_allocator;
    }

    if(count <= 0) {
        count = MINIMUM_COUNT;
    }

    // Ensure count is always a power of 2 for optimal performance
    count = round_up_to_power_of_2(count);

    circular_buffer_t* buffer = (allocator->m_calloc)(1, sizeof(circular_buffer_t));

    if (UNEXPECTED(buffer == NULL)) {
        ASYNC_ERROR(E_ERROR, "Failed to allocate memory for circular buffer");
        return NULL;
    }

    if (UNEXPECTED(circular_buffer_ctor(buffer, count, item_size, allocator) == FAILURE)) {
        (allocator->m_free)(buffer);
        return NULL;
    }

    return buffer;
}

/**
 * Free the memory associated with a circular buffer.
 */
void circular_buffer_destroy(circular_buffer_t *buffer)
{
    (buffer->allocator->m_free)(buffer->data);
    (buffer->allocator->m_free)(buffer);
}

zend_result circular_buffer_ctor(circular_buffer_t *buffer, size_t count, const size_t item_size, const allocator_t *allocator)
{
    if(allocator == NULL) {
        allocator = &zend_std_allocator;
    }

    if(count <= 0) {
        count = MINIMUM_COUNT;
    }

    // Ensure count is always a power of 2 for optimal performance
    count = round_up_to_power_of_2(count);

    void *data = (allocator->m_calloc)(count, item_size);

    if (UNEXPECTED(data == NULL)) {
        ASYNC_ERROR(E_ERROR, "Failed to allocate memory for circular buffer");
        return FAILURE;
    }

    buffer->allocator     = allocator;
    buffer->item_size     = item_size;
    buffer->min_size      = count;
    buffer->capacity      = count;
    buffer->auto_optimize = true;  // Default to enabled for backward compatibility
    buffer->decrease_t    = count > MINIMUM_COUNT ? (count / 2 - count / 4) : 0;
    buffer->data          = data;
    buffer->head          = 0;
    buffer->tail          = 0;

    return SUCCESS;
}

void circular_buffer_dtor(circular_buffer_t *buffer)
{
    if (buffer->data != NULL) {
        (buffer->allocator->m_free)(buffer->data);
        buffer->data = NULL;
    }
}

/**
 * Get next index in circular fashion
 * Optimized for power-of-2 capacities using bitwise AND instead of modulo
 */
static zend_always_inline size_t next_index(size_t index, size_t capacity)
{
    ZEND_ASSERT((capacity & (capacity - 1)) == 0 && "Capacity must be power of 2");
    // Since capacity is always power of 2, we can use fast bitwise AND
    return (index + 1) & (capacity - 1);
}

/**
 * Check if buffer should be decreased
 */
static zend_always_inline bool should_decrease(const circular_buffer_t *buffer)
{
    return buffer->auto_optimize && 
           !circular_buffer_is_empty(buffer) && 
           circular_buffer_count(buffer) < buffer->decrease_t;
}

/**
 * Recalculate the decrease threshold.
 */
static void recalc_decrease_threshold(circular_buffer_t *buffer, size_t new_count)
{
    buffer->decrease_t = (new_count <= buffer->min_size) ? 0 : (new_count / 2 - new_count / 4);
}

/**
 * Reallocate the memory associated with a circular buffer while preserving element order.
 */
zend_result circular_buffer_realloc(circular_buffer_t *buffer, size_t new_count)
{
    ZEND_ASSERT(buffer != NULL && "Buffer cannot be NULL");
    ZEND_ASSERT(buffer->data != NULL && "Buffer data cannot be NULL");
    ZEND_ASSERT(buffer->capacity > 0 && "Buffer capacity must be positive");
    ZEND_ASSERT(buffer->head < buffer->capacity && "Head index out of bounds");
    ZEND_ASSERT(buffer->tail < buffer->capacity && "Tail index out of bounds");
    ZEND_ASSERT(buffer->item_size > 0 && "Item size must be positive");

    if(new_count <= 0) {
        if(circular_buffer_is_full(buffer)) {
            // Buffer is full, need to increase size (double it, stays power of 2)
            new_count = buffer->capacity * 2;
        } else if(should_decrease(buffer)) {
            // Buffer is underused, can decrease size (halve it, stays power of 2)
            new_count = buffer->capacity / 2;
            if(new_count < buffer->min_size) {
                new_count = buffer->min_size;
            }
        } else {
            return SUCCESS; // No need to reallocate
        }
    } else {
        // Ensure manually specified count is also power of 2
        new_count = round_up_to_power_of_2(new_count);
    }

    ZEND_ASSERT(new_count > 0 && "New count must be positive");
    ZEND_ASSERT(new_count >= buffer->min_size && "New count cannot be less than minimum size");
    ZEND_ASSERT((new_count & (new_count - 1)) == 0 && "New count must be power of 2");

    /*
     * Case 1: Empty buffer - simple replacement
     * 
     * Before: [  |  |  |  ]  head=tail=0
     * After:  [  |  |  |  |  |  ]  head=tail=0
     */
    if(circular_buffer_is_empty(buffer)) {
        void *new_data = (buffer->allocator->m_alloc)(new_count * buffer->item_size);
        if (UNEXPECTED(new_data == NULL)) {
            ASYNC_ERROR(E_WARNING, "Failed to reallocate circular buffer");
            return FAILURE;
        }

        (buffer->allocator->m_free)(buffer->data);
        buffer->data = new_data;
        buffer->capacity = new_count;
        buffer->head = 0;
        buffer->tail = 0;
        recalc_decrease_threshold(buffer, new_count);
        return SUCCESS;
    }

    size_t count = circular_buffer_count(buffer);

    /*
     * Case 2: Linear order (head >= tail) - data is contiguous
     * 
     * Example:
     * Before: [  | A| B| C|  |  ]  tail=1, head=4, count=3
     *              ^     ^
     *            tail  head
     */
    if (buffer->head >= buffer->tail) {
        
        if (EXPECTED(new_count > buffer->capacity)) {
            /*
             * Increasing size - can use realloc safely
             * Data layout won't change, just more space at the end
             * 
             * After:  [  | A| B| C|  |  |  |  |  ]  tail=1, head=4
             *              ^     ^
             *            tail  head
             */
            void *new_data = (buffer->allocator->m_realloc)(buffer->data, 
                new_count * buffer->item_size, buffer->capacity * buffer->item_size);
            
            if (UNEXPECTED(new_data == NULL)) {
                ASYNC_ERROR(E_WARNING, "Failed to reallocate circular buffer");
                return FAILURE;
            }
            
            buffer->data = new_data;
            buffer->capacity = new_count;
            // head and tail offsets remain the same
            
        } else {
            /*
             * Decreasing size - use memcpy to copy only needed data
             * 
             * Copy [tail...head) to beginning of new buffer
             * After:  [ A| B| C|  ]  tail=0, head=3
             *           ^     ^
             *         tail  head
             */
            void *new_data = (buffer->allocator->m_alloc)(new_count * buffer->item_size);
            if (UNEXPECTED(new_data == NULL)) {
                ASYNC_ERROR(E_WARNING, "Failed to reallocate circular buffer");
                return FAILURE;
            }
            
            // Single memcpy for contiguous data
            memcpy(new_data, (char *)buffer->data + buffer->tail * buffer->item_size, 
                   count * buffer->item_size);
            
            (buffer->allocator->m_free)(buffer->data);
            buffer->data = new_data;
            buffer->capacity = new_count;
            buffer->tail = 0;
            buffer->head = count;
        }
        
    } else {
        /*
         * Case 3: Wrapped order (head < tail) - data wraps around
         * 
         * Example:
         * Before: [ C| D|  |  | A| B]  tail=4, head=2, count=4
         *              ^     ^
         *            head  tail
         * 
         * Need to copy in two parts:
         * Part 1: [A, B] from positions [tail...end]
         * Part 2: [C, D] from positions [start...head)
         * 
         * After:  [ A| B| C| D|  |  ]  tail=0, head=4
         *           ^           ^
         *         tail        head
         */
        void *new_data = (buffer->allocator->m_alloc)(new_count * buffer->item_size);
        if (UNEXPECTED(new_data == NULL)) {
            ASYNC_ERROR(E_WARNING, "Failed to reallocate circular buffer");
            return FAILURE;
        }
        
        // First part: copy [tail...end] to beginning of new buffer
        size_t first_part_count = buffer->capacity - buffer->tail;
        memcpy(new_data, 
               (char *)buffer->data + buffer->tail * buffer->item_size, 
               first_part_count * buffer->item_size);
        
        // Second part: copy [start...head) after first part
        memcpy((char *)new_data + first_part_count * buffer->item_size, 
               buffer->data, 
               buffer->head * buffer->item_size);
        
        (buffer->allocator->m_free)(buffer->data);
        buffer->data = new_data;
        buffer->capacity = new_count;
        buffer->tail = 0;
        buffer->head = count;
    }
    
    // Final consistency checks
    ZEND_ASSERT(buffer->data != NULL && "Buffer data should not be NULL after realloc");
    ZEND_ASSERT(buffer->capacity == new_count && "Capacity should match new_count");
    ZEND_ASSERT(buffer->head < buffer->capacity && "Head should be within new capacity");
    ZEND_ASSERT(buffer->tail < buffer->capacity && "Tail should be within new capacity");
    ZEND_ASSERT(circular_buffer_count(buffer) == count && "Element count should be preserved");
    
    recalc_decrease_threshold(buffer, new_count);
    return SUCCESS;
}

/**
 * Push a value into the circular buffer.
 */
zend_result circular_buffer_push(circular_buffer_t *buffer, const void *value, const bool should_resize)
{
    ZEND_ASSERT(buffer != NULL && "Buffer cannot be NULL");
    ZEND_ASSERT(buffer->data != NULL && "Buffer data cannot be NULL");
    ZEND_ASSERT(value != NULL && "Value cannot be NULL");
    ZEND_ASSERT(buffer->head < buffer->capacity && "Head index out of bounds");
    ZEND_ASSERT(buffer->tail < buffer->capacity && "Tail index out of bounds");
    ZEND_ASSERT(buffer->item_size > 0 && "Item size must be positive");

    // First check if resize is needed
    if (should_resize) {
        // Check resize conditions once
        bool need_increase = circular_buffer_is_full(buffer);
        bool need_decrease = !need_increase && should_decrease(buffer);
        
        if (need_increase || need_decrease) {
            if (circular_buffer_realloc(buffer, 0) == FAILURE) {
                return FAILURE;
            }
        }
    } else if (circular_buffer_is_full(buffer)) {
        // If resize is disabled but buffer is full - error
        ASYNC_ERROR(E_WARNING, "Cannot push into full circular buffer");
        return FAILURE;
    }

    /*
     * Classic circular buffer push operation:
     * 1. Store data at head position
     * 2. Advance head to next position
     * 
     * Visual example:
     * Before: [ A| B|  |  ]  head=2, tail=0
     *              ^
     *            head
     * 
     * After:  [ A| B| C|  ]  head=3, tail=0
     *                 ^
     *               head
     */
    ZEND_ASSERT(!circular_buffer_is_full(buffer) && "Buffer should not be full at this point");
    
    memcpy((char *)buffer->data + buffer->head * buffer->item_size, value, buffer->item_size);
    buffer->head = next_index(buffer->head, buffer->capacity);

    ZEND_ASSERT(buffer->head < buffer->capacity && "Head index should be valid after increment");
    
    return SUCCESS;
}

/**
 * Pop a value from the circular buffer.
 */
zend_result circular_buffer_pop(circular_buffer_t *buffer, void *value)
{
    ZEND_ASSERT(buffer != NULL && "Buffer cannot be NULL");
    ZEND_ASSERT(buffer->data != NULL && "Buffer data cannot be NULL");
    ZEND_ASSERT(value != NULL && "Value cannot be NULL");
    ZEND_ASSERT(buffer->head < buffer->capacity && "Head index out of bounds");
    ZEND_ASSERT(buffer->tail < buffer->capacity && "Tail index out of bounds");
    ZEND_ASSERT(buffer->item_size > 0 && "Item size must be positive");

    if(circular_buffer_is_empty(buffer)) {
        ASYNC_ERROR(E_WARNING, "Cannot pop from empty circular buffer");
        return FAILURE;
    }

    /*
     * Classic circular buffer pop operation:
     * 1. Read data from tail position
     * 2. Advance tail to next position
     * 
     * Visual example:
     * Before: [ A| B| C|  ]  head=3, tail=0
     *           ^
     *         tail
     * 
     * After:  [ A| B| C|  ]  head=3, tail=1
     *              ^
     *            tail
     * (A is returned, but memory isn't cleared for performance)
     */
    ZEND_ASSERT(!circular_buffer_is_empty(buffer) && "Buffer should not be empty at this point");
    
    memcpy(value, (char *)buffer->data + buffer->tail * buffer->item_size, buffer->item_size);
    buffer->tail = next_index(buffer->tail, buffer->capacity);

    ZEND_ASSERT(buffer->tail < buffer->capacity && "Tail index should be valid after increment");
    
    return SUCCESS;
}

/**
 * Check if the circular buffer is empty.
 */
bool circular_buffer_is_empty(const circular_buffer_t *buffer)
{
    // Empty when head == tail
    return buffer->head == buffer->tail;
}

bool circular_buffer_is_not_empty(const circular_buffer_t *buffer)
{
    return buffer->head != buffer->tail;
}

/**
 * Check if the circular buffer is full.
 */
bool circular_buffer_is_full(const circular_buffer_t *buffer)
{
    // Full when advancing head would make it equal to tail
    // (we keep one slot empty to distinguish full from empty)
    return next_index(buffer->head, buffer->capacity) == buffer->tail;
}

/**
 * Get the number of elements currently in the buffer.
 */
size_t circular_buffer_count(const circular_buffer_t *buffer)
{
    ZEND_ASSERT(buffer != NULL && "Buffer cannot be NULL");
    ZEND_ASSERT(buffer->head < buffer->capacity && "Head index out of bounds");
    ZEND_ASSERT(buffer->tail < buffer->capacity && "Tail index out of bounds");

    /*
     * Two cases to handle:
     * Case 1: head >= tail (normal order)
     *   count = head - tail
     * 
     * Case 2: head < tail (wrapped around)
     *   count = capacity - tail + head
     */
    size_t count;
    if (buffer->head >= buffer->tail) {
        count = buffer->head - buffer->tail;
    } else {
        count = buffer->capacity - buffer->tail + buffer->head;
    }
    
    ZEND_ASSERT(count <= buffer->capacity && "Count cannot exceed capacity");
    return count;
}

size_t circular_buffer_capacity(const circular_buffer_t *buffer)
{
    return buffer->capacity - 1; // One slot is reserved to distinguish full from empty
}

//
// Functions for ZVAL circular buffer
//
circular_buffer_t *zval_circular_buffer_new(const size_t count, const allocator_t *allocator)
{
    return circular_buffer_new(count, sizeof(zval), allocator);
}

/**
 * Push a new zval into the circular buffer.
 * The zval will be copied and its reference count will be increased.
 */
zend_result zval_circular_buffer_push(circular_buffer_t *buffer, zval *value, const bool should_resize)
{
    Z_TRY_ADDREF_P(value);
    return circular_buffer_push(buffer, value, should_resize);
}

/**
 * Pop a zval from the circular buffer.
 * The zval will be copied and its reference count will not be changed because your code will get the ownership.
 */
zend_result zval_circular_buffer_pop(circular_buffer_t *buffer, zval *value)
{
    ZVAL_UNDEF(value);
    return circular_buffer_pop(buffer, value);
}