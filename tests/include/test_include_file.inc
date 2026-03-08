<?php
function test_included_function(): string {
    return "included_ok";
}

class TestIncludedClass {
    public function getValue(): string {
        return "class_ok";
    }
}

const TEST_INCLUDED_CONST = 42;
