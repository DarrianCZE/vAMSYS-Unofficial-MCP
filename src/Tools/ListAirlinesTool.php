<?php

declare(strict_types=1);

namespace VamsysMcp\Tools;

use Mcp\Schema\Result\CallToolResult;
use VamsysMcp\Config;

final class ListAirlinesTool
{
    public const string NAME = 'list_airlines';
    public const string DESCRIPTION = 'List the vAMSYS airlines this server is configured for. Use the returned "key" as the airline argument of every other tool.';

    public function __construct(private readonly Config $config)
    {
    }

    public function __invoke(): CallToolResult
    {
        $airlines = [];
        foreach ($this->config->airlines as $airline) {
            $airlines[] = ['key' => $airline->key, 'name' => $airline->name, 'weight_unit' => $airline->weightUnit];
        }

        return ToolResult::of(['airlines' => $airlines]);
    }
}
