<?php

declare(strict_types=1);

namespace VamsysMcp\Tests\Update;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use VamsysMcp\App;
use VamsysMcp\Paths;
use VamsysMcp\Tests\Support\FakeHttpClient;
use VamsysMcp\Tests\Support\TempDir;
use VamsysMcp\Update\Changelog;
use VamsysMcp\Update\GitHubReleases;
use VamsysMcp\Update\ManagedFiles;
use VamsysMcp\Update\ReleasePackage;
use VamsysMcp\Update\StepContext;
use VamsysMcp\Update\UpdateException;
use VamsysMcp\Update\UpdateJob;
use VamsysMcp\Update\Updater;

final class UpdateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testSignatureVerification(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $pk = base64_encode(sodium_crypto_sign_publickey($pair));
        $sig = base64_encode(sodium_crypto_sign_detached('zip-bytes', sodium_crypto_sign_secretkey($pair)));

        ReleasePackage::verifySignature('zip-bytes', $sig, $pk);
        $this->addToAssertionCount(1);

        $this->expectException(UpdateException::class);
        ReleasePackage::verifySignature('tampered', $sig, $pk);
    }

    public function testUpdatesAreDisabledWithoutPublicKey(): void
    {
        $this->expectExceptionMessageMatches('/no release-signing public key/');
        ReleasePackage::verifySignature('x', 'y', '');
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function incompatible(): iterable
    {
        yield 'downgrade' => [['version' => '0.1.0'], '0.2.0', 'not newer'];
        yield 'same version' => [['version' => '0.2.0'], '0.2.0', 'not newer'];
        yield 'new major' => [['version' => '2.0.0'], '1.4.0', 'major version'];
        yield 'too old to update' => [['version' => '0.5.0', 'update_from_min' => '0.3.0'], '0.2.0', 'needs at least 0.3.0'];
        yield 'newer PHP' => [['version' => '0.3.0', 'requires_php' => '99.0.0'], '0.2.0', 'needs PHP 99.0.0'];
    }

    /**
     * @param array<string, mixed> $info
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('incompatible')]
    public function testIncompatibleReleasesAreRefused(array $info, string $current, string $message): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage($message);
        ReleasePackage::assertCompatible($info, $current, (string) $info['version']);
    }

    public function testCompatibleMinorUpdateIsAccepted(): void
    {
        ReleasePackage::assertCompatible(['version' => '1.3.0', 'update_from_min' => '1.0.0', 'requires_php' => '8.4.0'], '1.2.5', '1.3.0');
        $this->addToAssertionCount(1);
    }

    public function testChangelogListsBreakingChangesOfTheCurrentVersion(): void
    {
        $changelog = (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
        $section = preg_quote('## [' . App::VERSION . ']', '/');

        self::assertMatchesRegularExpression("/^{$section} - \\d{4}-\\d{2}-\\d{2}\\R+### Breaking changes\\R/m", $changelog, 'CHANGELOG.md needs a section for App::VERSION that starts with "### Breaking changes"');
    }

    /**
     * Installed versions extract a package with their own ManagedFiles and
     * reject any file outside it, so a new top-level path in a release
     * breaks every update from an older version (0.4.3 did this with
     * CHANGELOG.md). New files must go into one of the existing directories.
     */
    public function testReleasesNeverAddTopLevelPaths(): void
    {
        self::assertSame(['src/', 'vendor/', 'bin/', 'public/', 'migrations/', 'docs/'], ManagedFiles::DIRECTORIES);
        self::assertSame([
            'config/config.example.php', 'composer.json', 'composer.lock', 'schema.sql',
            'README.md', 'AGENTS.md', 'CLAUDE.md', 'LICENSE', 'release.json', 'manifest.json',
        ], ManagedFiles::FILES);
    }

    public function testChangelogBetweenReturnsTheVersionsAnUpdateInstalls(): void
    {
        $changes = Changelog::between((string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'), '0.1.1', '0.4.2');

        self::assertSame(['0.4.2', '0.4.1', '0.4.0', '0.3.0', '0.2.0'], array_column($changes, 'version'));
        self::assertSame(['0.3.0', '0.2.0'], array_column(array_filter($changes, static fn (array $c): bool => $c['breaking']), 'version'));
        self::assertStringStartsWith('### Breaking changes', $changes[0]['text']);
        self::assertStringNotContainsString('Unreleased', implode("\n", array_column($changes, 'text')));
    }

    public function testCheckReadsTheChangelogOfTheLatestReleaseAndFallsBackToReleaseNotes(): void
    {
        $changelog = "# Changelog\n\n## [Unreleased]\n\n### Breaking changes\n\nNone.\n\n"
            . "## [1.2.0] - 2026-11-02\n\n### Breaking changes\n\n- Renamed `a` to `b`.\n\n"
            . "## [1.1.0] - 2026-11-01\n\n### Breaking changes\n\nNone.\n\n"
            . "## [1.0.0] - 2026-10-30\n\n### Breaking changes\n\nNone.\n\n[1.2.0]: https://example.com\n";
        $http = (new FakeHttpClient())->route(static fn (RequestInterface $r): ResponseInterface => match ((string) $r->getUri()) {
            'https://api.github.com/repos/o/r/releases/latest' => FakeHttpClient::json(['tag_name' => 'v1.2.0', 'body' => 'notes', 'assets' => [
                ['name' => 'vamsys-mcp-1.2.0.zip', 'browser_download_url' => 'https://github.com/z'],
                ['name' => 'vamsys-mcp-1.2.0.zip.sig', 'browser_download_url' => 'https://github.com/s'],
            ]]),
            'https://raw.githubusercontent.com/o/r/v1.2.0/CHANGELOG.md' => new Response(200, [], $changelog),
        });
        $psr17 = new Psr17Factory();
        $check = (new Updater(new Paths($this->dir), '1.0.0', new GitHubReleases($http, $psr17, 'o/r')))->check();

        self::assertTrue($check['update_available']);
        self::assertSame([
            ['version' => '1.2.0', 'date' => '2026-11-02', 'text' => "### Breaking changes\n\n- Renamed `a` to `b`.", 'breaking' => true],
            ['version' => '1.1.0', 'date' => '2026-11-01', 'text' => "### Breaking changes\n\nNone.", 'breaking' => false],
        ], $check['changes']);

        $http->route(static fn (RequestInterface $r): ResponseInterface => str_contains((string) $r->getUri(), 'raw.githubusercontent.com')
            ? new Response(404)
            : FakeHttpClient::json(['tag_name' => 'v1.2.0', 'body' => 'notes', 'assets' => [
                ['name' => 'vamsys-mcp-1.2.0.zip', 'browser_download_url' => 'https://github.com/z'],
                ['name' => 'vamsys-mcp-1.2.0.zip.sig', 'browser_download_url' => 'https://github.com/s'],
            ]]));
        $check = (new Updater(new Paths($this->dir), '1.0.0', new GitHubReleases($http, $psr17, 'o/r')))->check();
        self::assertNull($check['changes']);
        self::assertSame('notes', $check['release']['notes']);
    }

    public function testExtractRejectsPathsOutsideManagedFiles(): void
    {
        foreach (['../evil.php', 'config/config.php', 'var/x', '/abs.php', 'public/../../x.php'] as $bad) {
            self::assertFalse(ManagedFiles::isManaged($bad), $bad);
        }
        $zip = $this->zip(['src/A.php' => '<?php', 'config/config.php' => '<?php return [];']);

        $this->expectExceptionMessage("unexpected file 'config/config.php'");
        ReleasePackage::extract($zip, "{$this->dir}/staging");
    }

    public function testExtractRejectsFilesThatDoNotMatchManifest(): void
    {
        $zip = $this->zip(['src/A.php' => '<?php // changed'], manifestOverride: ['src/A.php' => hash('sha256', '<?php')]);

        $this->expectExceptionMessage('does not match its manifest');
        ReleasePackage::extract($zip, "{$this->dir}/staging");
    }

    public function testFullUpdateReplacesChangedFilesDeletesRemovedOnesAndKeepsOperatorFiles(): void
    {
        $root = "{$this->dir}/install";
        [$paths, $ctx] = $this->installation($root, [
            'src/Keep.php' => 'same', 'src/Change.php' => 'old', 'src/Removed.php' => 'gone soon',
        ]);
        TempDir::write($root, ['config/config.php' => 'operator config', 'var/sessions/s' => 'session', 'public/custom.txt' => 'mine']);
        $this->stage($paths, ['src/Keep.php' => 'same', 'src/Change.php' => 'new', 'src/Added.php' => 'added']);

        $status = (new UpdateJob($paths))->step($ctx);

        self::assertSame('done', $status['phase'], (string) $status['error']);
        self::assertSame('new', file_get_contents("{$root}/src/Change.php"));
        self::assertSame('added', file_get_contents("{$root}/src/Added.php"));
        self::assertFileDoesNotExist("{$root}/src/Removed.php");
        self::assertSame('operator config', file_get_contents("{$root}/config/config.php"));
        self::assertFileExists("{$root}/var/sessions/s");
        self::assertFileExists("{$root}/public/custom.txt");
        self::assertFileDoesNotExist($paths->maintenanceFlag());
        self::assertSame('old', file_get_contents($paths->updateDir('backup-0.1.0/src/Change.php')));
        self::assertArrayHasKey('src/Added.php', json_decode((string) file_get_contents("{$root}/manifest.json"), true)['files']);
    }

    public function testFailedApplyCanBeRolledBack(): void
    {
        $root = "{$this->dir}/install";
        [$paths, $ctx] = $this->installation($root, ['src/A.php' => 'old A', 'src/B.php' => 'old B']);
        $this->stage($paths, ['src/A.php' => 'new A', 'src/B.php' => 'new B', 'src/C.php' => 'new C']);
        // Make replacing src/C.php impossible: a directory is in the way.
        mkdir("{$root}/src/C.php/blocker", 0777, true);

        $job = new UpdateJob($paths);
        $status = $job->step($ctx);
        self::assertSame('failed', $status['phase']);
        self::assertTrue($status['can_roll_back']);
        self::assertFileExists($paths->maintenanceFlag());

        $job->startRollback();
        $status = $job->step($ctx);

        self::assertSame('rolled_back', $status['phase'], (string) $status['error']);
        self::assertSame('old A', file_get_contents("{$root}/src/A.php"));
        self::assertSame('old B', file_get_contents("{$root}/src/B.php"));
        self::assertFileDoesNotExist($paths->maintenanceFlag());
    }

    public function testPendingMigrationsAskForDbAdminLoginBeforeAnyFileChanges(): void
    {
        $root = "{$this->dir}/install";
        [$paths, $ctx, $dsn] = $this->installation($root, ['src/A.php' => 'old']);
        $this->stage($paths, ['src/A.php' => 'new', 'migrations/0001_new.sql' => 'CREATE TABLE IF NOT EXISTS t (id INTEGER);']);

        $status = (new UpdateJob($paths))->step($ctx);
        self::assertSame('db_credentials', $status['needs']);
        self::assertSame('old', file_get_contents("{$root}/src/A.php"));

        $withDdl = new StepContext($ctx->appPdo, $dsn, ddl: ['user' => 'admin', 'password' => 'x']);
        $status = (new UpdateJob($paths))->step($withDdl);
        self::assertSame('done', $status['phase'], (string) $status['error']);
        self::assertSame('0001', (new \PDO($dsn))->query('SELECT version FROM schema_migration')->fetchColumn());
    }

    /**
     * Create an installation with a manifest and return its paths and a step context.
     *
     * @param array<string, string> $files
     *
     * @return array{0: Paths, 1: StepContext, 2: string}
     */
    private function installation(string $root, array $files): array
    {
        TempDir::write($root, $files);
        file_put_contents("{$root}/manifest.json", json_encode(['files' => ManagedFiles::scan($root)]));
        $dsn = "sqlite:{$this->dir}/db.sqlite";

        return [new Paths($root), new StepContext(static fn (): \PDO => new \PDO($dsn), $dsn), $dsn];
    }

    /**
     * Put a new version into var/update/staging and hand over to UpdateJob.
     *
     * @param array<string, string> $files
     */
    private function stage(Paths $paths, array $files): void
    {
        $staging = $paths->updateDir('staging');
        TempDir::write($staging, $files + ['release.json' => '{"version":"0.2.0"}']);
        file_put_contents("{$staging}/manifest.json", json_encode(['files' => ManagedFiles::scan($staging)]));
        UpdateJob::writeState($paths, [
            'contract' => UpdateJob::CONTRACT, 'phase' => 'extracted',
            'from_version' => '0.1.0', 'to_version' => '0.2.0', 'staging' => $staging,
        ]);
    }

    /**
     * @param array<string, string>      $files
     * @param array<string, string>|null $manifestOverride
     */
    private function zip(array $files, ?array $manifestOverride = null): string
    {
        $src = "{$this->dir}/src-package";
        TempDir::write($src, $files + ['release.json' => '{"version":"0.2.0"}']);
        $manifest = $manifestOverride !== null ? $manifestOverride + ['release.json' => hash('sha256', '{"version":"0.2.0"}')] : ManagedFiles::scan($src);
        $path = "{$this->dir}/package.zip";
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files + ['release.json' => ''] as $name => $_) {
            $zip->addFile("{$src}/{$name}", $name);
        }
        $zip->addFromString('manifest.json', (string) json_encode(['files' => $manifest]));
        $zip->close();

        return $path;
    }
}
