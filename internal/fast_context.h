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
#ifndef FAST_CONTEXT_H
#define FAST_CONTEXT_H

/**
 * Fast Context Switching - Based on Alibaba Photon
 * Ultra-fast context switching for all platforms
 * Supports GCC, Clang, MSVC
 */

/* ===== COMPILER DETECTION ===== */
#if defined(_MSC_VER)
    #define FASTCTX_MSVC 1
    #define FASTCTX_NAKED __declspec(naked)
    #define FASTCTX_INLINE __forceinline
    #define FASTCTX_ASM_FUNCTION FASTCTX_NAKED
#elif defined(__clang__)
    #define FASTCTX_CLANG 1
    #define FASTCTX_NAKED __attribute__((naked))
    #define FASTCTX_INLINE static inline
    /* For inline assembly functions, naked is more important than inline */
    #define FASTCTX_ASM_FUNCTION __attribute__((naked, noinline))
#elif defined(__GNUC__)
    #define FASTCTX_GCC 1
    #define FASTCTX_NAKED __attribute__((naked))
    #define FASTCTX_INLINE static inline
    /* For inline assembly functions, naked is more important than inline */
    #define FASTCTX_ASM_FUNCTION __attribute__((naked, noinline))
#else
    #define FASTCTX_UNKNOWN 1
    #define FASTCTX_NAKED
    #define FASTCTX_INLINE static inline
    #define FASTCTX_ASM_FUNCTION static
#endif

/* ===== C++ COMPATIBILITY ===== */
#ifdef __cplusplus
extern "C" {
#endif

/* ===== INTEL CET (Control-flow Enforcement Technology) DETECTION ===== */
#if defined(__CET__)
    #include <cet.h>
    /* Intel CET has two features:
     * - IBT (Indirect Branch Tracking): __CET__ & 0x1
     * - SHSTK (Shadow Stack): __CET__ & 0x2
     * We only need Shadow Stack for context switching */
    #define FASTCTX_IBT_ENABLED (__CET__ & 0x1)
    #define FASTCTX_SHSTK_ENABLED (__CET__ & 0x2)

    /* Check if both compile-time and runtime support are available */
    #if FASTCTX_SHSTK_ENABLED && defined(SHADOW_STACK_SYSCALL)
        #define FASTCTX_CET 1
    #else
        #define FASTCTX_CET 0
    #endif
#else
    /* No CET support - define empty macros */
    #define _CET_ENDBR
    #define FASTCTX_CET 0
    #define FASTCTX_IBT_ENABLED 0
    #define FASTCTX_SHSTK_ENABLED 0
#endif

/* ===== CONTEXT STRUCTURE ===== */
#if !defined(FASTCTX_FALLBACK_SETJMP)
typedef struct {
    void *stack_ptr;
} coroutine_context;
#endif

/* Forward declaration for all platforms */
/* Note: actual function is defined inline for each platform below */

// x86_64
#if defined(__x86_64__) || defined(_M_X64)

#ifdef FASTCTX_MSVC
/* MSVC x64 calling convention: RCX, RDX, R8, R9
 * Microsoft x64 callee-saved: RBP, RBX, RDI, RSI, R12-R15 */
FASTCTX_NAKED void fast_context_switch(coroutine_context *from, coroutine_context *to) {
    __asm {
        ; Save Microsoft x64 callee-saved registers (like Photon)
        push rbp
        push rbx
        push rdi
        push rsi
        push r12
        push r13
        push r14
        push r15

        mov  [rcx], rsp     ; from->stack_ptr = rsp
        mov  rsp, [rdx]     ; rsp = to->stack_ptr

        ; Restore Microsoft x64 callee-saved registers
        pop  r15
        pop  r14
        pop  r13
        pop  r12
        pop  rsi
        pop  rdi
        pop  rbx
        pop  rbp
        ret
    }
}
#else
/* GCC/Clang x64 calling convention: RDI, RSI */
FASTCTX_ASM_FUNCTION void fast_context_switch(coroutine_context *from, coroutine_context *to)
{
#if FASTCTX_CET
    __asm__ volatile(
        "_CET_ENDBR\n"                   /* IBT: Mark as valid indirect branch target */

        /* Save Shadow Stack Pointer (for SHSTK) */
        "rdsspq %%rcx\n"                 /* Read current Shadow Stack Pointer */
        "pushq %%rcx\n"                  /* Save SSP to current stack */

        /* Standard context switch - save System V callee-saved registers */
        "pushq %%rbp\n"                  /* Save frame pointer */
        "pushq %%rbx\n"                  /* Save callee-saved */
        "pushq %%r12\n"                  /* Save callee-saved */
        "pushq %%r13\n"                  /* Save callee-saved */
        "pushq %%r14\n"                  /* Save callee-saved */
        "pushq %%r15\n"                  /* Save callee-saved */
        "movq %%rsp, (%0)\n"             /* from->stack_ptr = current rsp */
        "movq (%1), %%rsp\n"             /* rsp = to->stack_ptr (switch stacks) */
        "popq %%r15\n"                   /* Restore callee-saved */
        "popq %%r14\n"                   /* Restore callee-saved */
        "popq %%r13\n"                   /* Restore callee-saved */
        "popq %%r12\n"                   /* Restore callee-saved */
        "popq %%rbx\n"                   /* Restore callee-saved */
        "popq %%rbp\n"                   /* Restore frame pointer */

        /* Restore Shadow Stack (for SHSTK) */
        "popq %%rcx\n"                   /* Load SSP from new stack */
        "test %%rcx, %%rcx\n"            /* Check if SSP is non-zero */
        "jz 1f\n"                        /* Skip if zero (no shadow stack) */
        "rstorssp -8(%%rcx)\n"           /* Restore shadow stack from token */
        "saveprevssp\n"                  /* Save token for previous shadow stack */

        /* CRITICAL: Since we use 'ret' instead of 'jmp', we need to adjust SSP
         * because 'ret' will decrement SSP but we haven't actually returned
         * from a function call - we're jumping to a different context */
        "incsspq $1\n"                   /* Increment SSP to compensate */
        "1:\n"
        "ret\n"                          /* Return to new context */
        : : "r"(from), "r"(to) : "rcx", "memory"
    );
#else
    __asm__ volatile(
        /* Save System V AMD64 callee-saved registers (like Photon) */
        "pushq %%rbp\n"
        "pushq %%rbx\n"
        "pushq %%r12\n"
        "pushq %%r13\n"
        "pushq %%r14\n"
        "pushq %%r15\n"
        "movq %%rsp, (%0)\n"
        "movq (%1), %%rsp\n"
        /* Restore System V AMD64 callee-saved registers */
        "popq %%r15\n"
        "popq %%r14\n"
        "popq %%r13\n"
        "popq %%r12\n"
        "popq %%rbx\n"
        "popq %%rbp\n"
        "ret\n"
        : : "r"(from), "r"(to) : "memory"
    );
#endif
}
#endif

// ARM64
#elif defined(__aarch64__) || defined(_M_ARM64)

#ifdef FASTCTX_MSVC
/*
 * MSVC ARM64 LIMITATION:
 *
 * Microsoft Visual C++ does NOT support inline assembly on ARM64,
 * unlike x86/x64 where __asm blocks are supported. This is because:
 *
 * 1. ARM64 ABI COMPLEXITY: The ARM64 calling convention is more complex
 *    than x86/x64, with different register classes and stricter alignment
 *
 * 2. MICROSOFT'S DESIGN DECISION: MSVC encourages use of compiler intrinsics
 *    rather than inline assembly for better optimization and security
 *
 * 3. TOOLCHAIN SEPARATION: Microsoft separates assembly code into dedicated
 *    .asm files processed by armasm64.exe assembler
 *
 * SOLUTION:
 * We provide the context switching function in a separate .asm file:
 * 'fast_context_arm64_msvc.asm' which must be:
 * 1. Assembled with armasm64.exe
 * 2. Linked with the main program
 * 3. Declared as external function here
 *
 * BUILD INSTRUCTIONS:
 * armasm64.exe fast_context_arm64_msvc.asm
 * link.exe your_program.obj fast_context_arm64_msvc.obj
 */

/* External function declaration - implemented in fast_context_arm64_msvc.asm */
extern void fast_context_switch(coroutine_context *from, coroutine_context *to);

#else
/* GCC/Clang ARM64 - supports inline assembly */
FASTCTX_ASM_FUNCTION void fast_context_switch(coroutine_context *from, coroutine_context *to) {
    __asm__ volatile(
        "stp x29, x30, [sp, #-16]!\n"  /* Save FP and LR to current stack */
        "mov x29, sp\n"                /* Update frame pointer */
        "str x29, [%0]\n"              /* from->stack_ptr = current stack */
        "ldr x29, [%1]\n"              /* Load new stack pointer */
        "mov sp, x29\n"                /* Switch to new stack */
        "ldp x29, x30, [sp], #16\n"    /* Restore FP and LR from new stack */
        "ret\n"                        /* Return to new context */
        : : "r"(from), "r"(to) : "x29", "x30", "memory"
    );
}
#endif

// RISC-V 64
#elif defined(__riscv) && (__riscv_xlen == 64)

FASTCTX_ASM_FUNCTION void fast_context_switch(coroutine_context *from, coroutine_context *to) {
    __asm__ volatile(
        "addi sp, sp, -16\n"
        "sd ra, 8(sp)\n"
        "sd s0, 0(sp)\n"
        "sd sp, 0(%0)\n"
        "ld sp, 0(%1)\n"
        "ld s0, 0(sp)\n"
        "ld ra, 8(sp)\n"
        "addi sp, sp, 16\n"
        "jr ra\n"
        : : "r"(from), "r"(to) : "memory"
    );
}

// ARM32
#elif defined(__arm__) || defined(_M_ARM)

FASTCTX_ASM_FUNCTION void fast_context_switch(coroutine_context *from, coroutine_context *to) {
    __asm__ volatile(
        "push {r11, lr}\n"
        "mov r11, sp\n"
        "str r11, [%0]\n"
        "ldr r11, [%1]\n"
        "mov sp, r11\n"
        "pop {r11, pc}\n"
        : : "r"(from), "r"(to) : "r11", "lr", "memory"
    );
}

// x86_32
#elif defined(__i386__) || defined(_M_IX86)

#ifdef FASTCTX_MSVC
/* MSVC x86 calling convention: parameters on stack */
FASTCTX_NAKED void fast_context_switch(coroutine_context *from, coroutine_context *to) {
    __asm {
        push ebp            ; Save frame pointer
        mov  eax, [esp+8]   ; eax = from parameter (after push ebp)
        mov  edx, [esp+12]  ; edx = to parameter (after push ebp)
        mov  [eax], esp     ; from->stack_ptr = current esp
        mov  esp, [edx]     ; switch to new stack
        pop  ebp            ; restore frame pointer from new stack
        ret                 ; return to new context
    }
}
#else
FASTCTX_ASM_FUNCTION void fast_context_switch(coroutine_context *from, coroutine_context *to) {
    __asm__ volatile(
        "pushl %%ebp\n"
        "movl %%esp, (%0)\n"
        "movl (%1), %%esp\n"
        "popl %%ebp\n"
        "ret\n"
        : : "r"(from), "r"(to) : "memory"
    );
}
#endif

#else

/* ===== FALLBACK FOR UNSUPPORTED PLATFORMS ===== */
#define FASTCTX_FALLBACK_SETJMP 1
#include <setjmp.h>

#ifdef FASTCTX_FALLBACK_SETJMP
#undef coroutine_context  /* Remove previous declaration */
typedef struct {
    jmp_buf ctx;
    int initialized;
} coroutine_context;
#endif

FASTCTX_INLINE void fast_context_switch(coroutine_context *from, coroutine_context *to) {
    if (setjmp(from->ctx) == 0) {
        longjmp(to->ctx, 1);
    }
}

#if defined(__GNUC__) || defined(__clang__)
#warning "Using setjmp/longjmp fallback - performance will be reduced"
#elif defined(_MSC_VER)
#pragma message("Using setjmp/longjmp fallback - performance will be reduced")
#endif

#endif

#ifdef __cplusplus
}
#endif

#endif /* FAST_CONTEXT_H */