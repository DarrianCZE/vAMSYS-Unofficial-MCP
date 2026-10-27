<?php

declare(strict_types=1);

namespace VamsysMcp\Tests;

use PHPUnit\Framework\TestCase;
use VamsysMcp\Tests\Support\TempDir;
use VamsysMcp\Tests\Support\TestDatabase;
use VamsysMcp\Tools\CountRoutesTool;
use VamsysMcp\Tools\FindDuplicatesTool;
use VamsysMcp\Tools\FindRoutesTool;
use VamsysMcp\Tools\GetRouteTool;
use VamsysMcp\Tools\ListAirlinesTool;

/**
 * Runs the real STDIO server (SQLite DB, no network) and checks that
 * tools/list advertises exactly the arguments each handler accepts, so the
 * published schemas can never drift from the code.
 */
final class ToolSchemaTest extends TestCase
{
    private const array HANDLERS = [
        ListAirlinesTool::NAME => ListAirlinesTool::class,
        FindRoutesTool::NAME => FindRoutesTool::class,
        CountRoutesTool::NAME => CountRoutesTool::class,
        GetRouteTool::NAME => GetRouteTool::class,
        FindDuplicatesTool::NAME => FindDuplicatesTool::class,
    ];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testToolsListMatchesTheHandlers(): void
    {
        $tools = [];
        foreach ($this->toolsList() as $tool) {
            $tools[$tool['name']] = $tool;
        }
        self::assertEqualsCanonicalizing(array_keys(self::HANDLERS), array_keys($tools));

        foreach (self::HANDLERS as $name => $class) {
            $params = (new \ReflectionMethod($class, '__invoke'))->getParameters();
            $schema = $tools[$name]['inputSchema'];
            self::assertSame(
                array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $params),
                array_keys((array) ($schema['properties'] ?? [])),
                "{$name}: advertised arguments differ from the handler's",
            );
            self::assertSame(
                array_values(array_map(static fn (\ReflectionParameter $p): string => $p->getName(), array_filter($params, static fn (\ReflectionParameter $p): bool => !$p->isOptional()))),
                $schema['required'] ?? [],
                "{$name}: required arguments differ from the handler's",
            );
            self::assertSame($class::DESCRIPTION, $tools[$name]['description']);
            if (isset($schema['properties']['airline'])) {
                self::assertContains('null', (array) $schema['properties']['airline']['type'], "{$name}: airline must be optional");
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolsList(): array
    {
        TestDatabase::create("sqlite:{$this->dir}/db.sqlite");
        $config = [
            'database' => ['dsn' => "sqlite:{$this->dir}/db.sqlite"],
            'airlines' => ['swv' => ['client_id' => '1', 'client_secret' => 'x', 'weight_unit' => 'kg']],
            'vamsys' => ['api_base_url' => 'http://127.0.0.1:9/api', 'token_url' => 'http://127.0.0.1:9/oauth/token'],
        ];
        file_put_contents("{$this->dir}/config.php", '<?php return ' . var_export($config, true) . ';');

        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/server-stdio.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['VAMSYS_MCP_CONFIG' => "{$this->dir}/config.php"] + getenv(),
        );
        self::assertIsResource($process);
        foreach ($messages as $message) {
            fwrite($pipes[0], json_encode($message) . "\n");
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        foreach (explode("\n", $out) as $line) {
            $response = json_decode($line, true);
            if (is_array($response) && ($response['id'] ?? null) === 2) {
                return $response['result']['tools'];
            }
        }
        self::fail("No tools/list answer from the STDIO server. STDERR: {$err}");
    }
}
