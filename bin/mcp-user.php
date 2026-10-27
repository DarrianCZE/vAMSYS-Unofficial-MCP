<?php

declare(strict_types=1);

/*
 * Manage MCP users (people who may connect Claude through the OAuth sign-in)
 * from the command line; the same as "MCP users" in the web admin.
 *
 *   php bin/mcp-user.php list
 *   php bin/mcp-user.php add <name>        (asks for the password)
 *   php bin/mcp-user.php password <name>
 *   php bin/mcp-user.php disable <name> | enable <name> | delete <name>
 */

use VamsysMcp\Config;
use VamsysMcp\Exception\VamsysMcpException;
use VamsysMcp\Install\Console;
use VamsysMcp\OAuth\McpUsers;
use VamsysMcp\Paths;

require dirname(__DIR__) . '/vendor/autoload.php';

$io = new Console();
[$command, $name] = [$argv[1] ?? 'list', $argv[2] ?? ''];

try {
    $users = new McpUsers(Config::fromFile((new Paths())->configFile())->createPdo());
    if ($command === 'list') {
        foreach ($users->all() as $u) {
            $io->line(sprintf('%-24s %-9s last sign-in: %s', $u['username'], $u['disabled'] ? 'disabled' : 'active', $u['last_login_at'] ?? 'never'));
        }
        exit(0);
    }
    if ($command === 'add') {
        $users->create($name, $io->secret("Password for {$name}"));
        $io->line("✓ User {$name} added.");
        exit(0);
    }
    $id = $users->idByName($name) ?? throw new \VamsysMcp\Exception\ConfigException("No user named '{$name}'.");
    match ($command) {
        'password' => $users->setPassword($id, $io->secret("New password for {$name}")),
        'disable' => $users->setDisabled($id, true),
        'enable' => $users->setDisabled($id, false),
        'delete' => $users->delete($id),
        default => throw new \VamsysMcp\Exception\ConfigException('Commands: list, add, password, disable, enable, delete.'),
    };
    $io->line("✓ {$command}: {$name}");
} catch (VamsysMcpException $e) {
    $io->line("✗ {$e->getMessage()}");
    exit(1);
}
