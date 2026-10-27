<?php

declare(strict_types=1);

namespace VamsysMcp\Tools;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Tool output as compact JSON text (no pretty-printing; LLM context is
 * expensive) plus the same data as structuredContent.
 */
final class ToolResult
{
    /**
     * @param array<string, mixed> $data
     */
    public static function of(array $data): CallToolResult
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new CallToolResult([new TextContent($json)], structuredContent: $data);
    }
}
