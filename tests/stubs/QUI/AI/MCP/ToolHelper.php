<?php

namespace QUI\AI\MCP;

use Mcp\Schema\Result\CallToolResult;
use Throwable;

if (!class_exists(ToolHelper::class)) {
    class ToolHelper
    {
        public static function parseExceptionToResult(mixed $Exception): CallToolResult
        {
            return new CallToolResult([[
                'type' => 'text',
                'text' => $Exception instanceof Throwable ? $Exception->getMessage() : 'Internal server error'
            ]]);
        }
    }
}
