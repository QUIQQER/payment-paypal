<?php

namespace Mcp\Schema\Result;

if (!class_exists(CallToolResult::class)) {
    class CallToolResult
    {
        /** @param list<array<string, mixed>> $content */
        public function __construct(public readonly array $content = [], public readonly bool $isError = true)
        {
        }
    }
}
