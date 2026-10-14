<?php

namespace Mcp\Server;

if (!class_exists(Builder::class)) {
    class Builder
    {
        /** @var list<array<string, mixed>> */
        private array $tools = [];

        /** @param array<string, mixed>|null $inputSchema */
        public function addTool(
            callable $handler,
            ?string $name = null,
            ?string $description = null,
            ?array $inputSchema = null
        ): self {
            $this->tools[] = compact('handler', 'name', 'description', 'inputSchema');

            return $this;
        }
    }
}
