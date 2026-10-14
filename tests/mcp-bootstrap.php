<?php

// Guarded shims keep the optional MCP integration testable without installing ai-mcp.
foreach (
    [
    'Mcp/Server/Builder.php',
    'Mcp/Schema/Result/CallToolResult.php',
    'QUI/AI/MCP/ProviderInterface.php',
    'QUI/AI/MCP/Server.php',
    'QUI/AI/MCP/ToolHelper.php'
    ] as $mcpStub
) {
    require_once __DIR__ . '/stubs/' . $mcpStub;
}
