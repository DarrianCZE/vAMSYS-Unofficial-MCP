<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

use VamsysMcp\Install\InstallException;
use VamsysMcp\Install\InstallService;
use VamsysMcp\Install\Migrator;
use VamsysMcp\Paths;

/**
 * A resumable update, persisted in var/update/state.json and advanced in
 * time-boxed steps (each web request does ~15 s of work, so PHP's
 * max_execution_time is never hit, even over slow FTP).
 *
 * Phases: extracted → planned → migrated → backed_up → applying → finalizing → done.
 * On failure while files are being replaced: failed → rolling_back → rolled_back.
 *
 * The steps run with the code of the NEW version (public/setup.php and
 * bin/update.php load var/update/staging/vendor/autoload.php while an update
 * is active), so a half-replaced installation never executes mixed code.
 * Contract between versions (keep stable): state.json keys contract, phase,
 * from_version, to_version, staging.
 */
final class UpdateJob
{
    public const int CONTRACT = 1;
    /** Phases during which the staged (new) code drives the update. */
    public const array ACTIVE_PHASES = ['extracted', 'planned', 'migrated', 'backed_up', 'applying', 'finalizing', 'failed', 'rolling_back'];

    /** @var array<string, mixed> */
    private array $state;

    public function __construct(private readonly Paths $paths)
    {
        $state = self::readState($paths);
        if ($state === null) {
            throw new UpdateException('No update in progress.');
        }
        $this->state = $state;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readState(Paths $paths): ?array
    {
        $file = $paths->updateDir('state.json');
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($state) && ($state['contract'] ?? null) === self::CONTRACT ? $state : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function writeState(Paths $paths, array $state): void
    {
        Fs::ensureDir($paths->updateDir());
        $file = $paths->updateDir('state.json');
        file_put_contents($file . '.tmp', (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($file . '.tmp', $file);
    }

    /**
     * Advance the update as far as the time budget allows.
     *
     * @return array<string, mixed> status for the UI/CLI
     */
    public function step(StepContext $ctx): array
    {
        $deadline = microtime(true) + $ctx->budgetSeconds;
        $writer = null;
        try {
            while (true) {
                if (in_array($this->state['phase'], ['applying', 'rolling_back'], true) && $this->state['writer'] === 'ftp' && $ctx->ftp === null) {
                    return $this->status('ftp_credentials');
                }
                $needs = match ($this->state['phase']) {
                    'extracted' => $this->plan($ctx),
                    'planned' => $this->migrate($ctx),
                    'migrated' => $this->backup($deadline),
                    'backed_up' => $this->startApplying($ctx),
                    'applying' => $this->apply($writer ??= $this->writer($ctx), $deadline),
                    'finalizing' => $this->finalize(),
                    'rolling_back' => $this->rollBack($writer ??= $this->writer($ctx), $deadline),
                    default => null,
                };
                $this->save();
                if ($needs !== null || in_array($this->state['phase'], ['done', 'failed', 'rolled_back'], true) || microtime(true) >= $deadline) {
                    return $this->status($needs);
                }
            }
        } catch (\Throwable $e) {
            $this->state['failed_phase'] = $this->state['phase'];
            $this->state['phase'] = 'failed';
            $this->state['error'] = $e instanceof UpdateException || $e instanceof InstallException
                ? $e->getMessage()
                : 'Unexpected error (' . $e::class . '): ' . $e->getMessage();
            $this->save();

            return $this->status(null);
        } finally {
            $writer?->close();
        }
    }

    /** Undo the file changes of a failed update (database migrations are additive and stay). */
    public function startRollback(): void
    {
        if ($this->state['phase'] !== 'failed' || !in_array($this->state['failed_phase'] ?? '', ['applying', 'finalizing', 'rolling_back'], true)) {
            throw new UpdateException('There is nothing to roll back; cancel the update instead.');
        }
        $this->state['phase'] = 'rolling_back';
        $this->state['rollback_cursor'] ??= (int) $this->state['cursor'];
        unset($this->state['error']);
        $this->save();
    }

    /** Retry the step that failed (e.g. after a wrong DB admin password or an FTP hiccup). */
    public function retry(): void
    {
        if ($this->state['phase'] !== 'failed') {
            throw new UpdateException('The update has not failed.');
        }
        $this->state['phase'] = (string) $this->state['failed_phase'];
        unset($this->state['error'], $this->state['failed_phase']);
        $this->save();
    }

    /** Abandon an update that has not changed any installation file yet. */
    public function cancel(): void
    {
        $touched = in_array($this->state['phase'], ['applying', 'finalizing', 'rolling_back'], true)
            || in_array($this->state['failed_phase'] ?? '', ['applying', 'finalizing', 'rolling_back'], true);
        if ($touched) {
            throw new UpdateException('Files were already replaced; roll back instead of cancelling.');
        }
        @unlink($this->paths->maintenanceFlag());
        $this->state['phase'] = 'rolled_back';
        $this->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function status(?string $needs = null): array
    {
        $total = count($this->state['ops'] ?? []);
        $done = match ($this->state['phase']) {
            'migrated', 'backed_up' => (int) ($this->state['backup_cursor'] ?? 0),
            'rolling_back' => $total - (int) ($this->state['rollback_cursor'] ?? 0),
            default => (int) ($this->state['cursor'] ?? 0),
        };

        return [
            'phase' => $this->state['phase'],
            'from_version' => $this->state['from_version'],
            'to_version' => $this->state['to_version'],
            'writer' => $this->state['writer'] ?? null,
            'progress' => ['done' => min($done, $total), 'total' => $total],
            'needs' => $needs,
            'error' => $this->state['error'] ?? null,
            'can_roll_back' => $this->state['phase'] === 'failed' && in_array($this->state['failed_phase'] ?? '', ['applying', 'finalizing', 'rolling_back'], true),
            'finished' => in_array($this->state['phase'], ['done', 'rolled_back'], true),
        ];
    }

    private function plan(StepContext $ctx): ?string
    {
        $staging = (string) $this->state['staging'];
        $new = ManagedFiles::installed($staging);
        $old = ManagedFiles::installed($this->paths->root);

        $ops = [];
        foreach ($new as $path => $sha) {
            if (($old[$path] ?? null) !== $sha || !is_file("{$this->paths->root}/{$path}")) {
                $ops[] = ['put', $path];
            }
        }
        $ops[] = ['put', 'manifest.json'];
        foreach (array_keys($old) as $path) {
            if (!isset($new[$path]) && ManagedFiles::isManaged($path) && is_file("{$this->paths->root}/{$path}")) {
                $ops[] = ['delete', $path];
            }
        }

        $pending = (new Migrator("{$staging}/migrations"))->pending(($ctx->appPdo)());

        $this->state['ops'] = $ops;
        $this->state['cursor'] = 0;
        $this->state['backup_cursor'] = 0;
        $this->state['pending_migrations'] = $pending;
        $this->state['writer'] = LocalFileWriter::canWrite($this->paths->root, array_column($ops, 1)) ? 'local' : 'ftp';
        $this->state['backup_dir'] = $this->paths->updateDir('backup-' . $this->state['from_version']);
        Fs::removeDir($this->state['backup_dir']);
        $this->state['phase'] = 'planned';

        return null;
    }

    private function migrate(StepContext $ctx): ?string
    {
        if (($this->state['pending_migrations'] ?? []) !== []) {
            if ($ctx->ddl === null || $ctx->ddl['user'] === '') {
                return 'db_credentials';
            }
            $pdo = InstallService::connect($ctx->dbDsn, $ctx->ddl['user'], $ctx->ddl['password'], 'the DB admin user');
            $this->state['migrated'] = (new Migrator($this->state['staging'] . '/migrations'))->migrate($pdo);
        }
        $this->state['phase'] = 'migrated';

        return null;
    }

    /** Keep a local copy of every file that will be overwritten or deleted. */
    private function backup(float $deadline): ?string
    {
        $ops = $this->state['ops'];
        $backupDir = (string) $this->state['backup_dir'];
        for ($i = (int) $this->state['backup_cursor']; $i < count($ops); ++$i) {
            $source = "{$this->paths->root}/{$ops[$i][1]}";
            if (is_file($source)) {
                Fs::copyAtomic($source, "{$backupDir}/{$ops[$i][1]}");
            }
            $this->state['backup_cursor'] = $i + 1;
            if (microtime(true) >= $deadline) {
                return null;
            }
        }
        $this->state['phase'] = 'backed_up';

        return null;
    }

    private function startApplying(StepContext $ctx): ?string
    {
        if ($this->state['writer'] === 'ftp') {
            if ($ctx->ftp === null) {
                return 'ftp_credentials';
            }
            (new FtpFileWriter($ctx->ftp))->probe($this->paths->root);
        }
        Fs::ensureDir($this->paths->var());
        file_put_contents($this->paths->maintenanceFlag(), (string) $this->state['to_version']);
        $this->state['phase'] = 'applying';

        return null;
    }

    private function apply(FileWriter $writer, float $deadline): ?string
    {
        $ops = $this->state['ops'];
        for ($i = (int) $this->state['cursor']; $i < count($ops); ++$i) {
            [$op, $path] = $ops[$i];
            $op === 'put' ? $writer->put($path, $this->state['staging'] . '/' . $path) : $writer->delete($path);
            $this->state['cursor'] = $i + 1;
            if (microtime(true) >= $deadline) {
                $this->save();

                return null;
            }
        }
        $this->state['phase'] = 'finalizing';

        return null;
    }

    private function finalize(): ?string
    {
        @unlink($this->paths->maintenanceFlag());
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $this->state['phase'] = 'done';
        $this->state['finished_at'] = gmdate('c');

        return null;
    }

    private function rollBack(FileWriter $writer, float $deadline): ?string
    {
        $ops = $this->state['ops'];
        $backupDir = (string) $this->state['backup_dir'];
        // Undo applied operations in reverse order.
        for ($i = (int) $this->state['rollback_cursor'] - 1; $i >= 0; --$i) {
            $path = $ops[$i][1];
            is_file("{$backupDir}/{$path}") ? $writer->put($path, "{$backupDir}/{$path}") : $writer->delete($path);
            $this->state['rollback_cursor'] = $i;
            if (microtime(true) >= $deadline) {
                $this->save();

                return null;
            }
        }
        @unlink($this->paths->maintenanceFlag());
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $this->state['phase'] = 'rolled_back';

        return null;
    }

    private function writer(StepContext $ctx): FileWriter
    {
        if ($this->state['writer'] === 'local') {
            return new LocalFileWriter($this->paths->root);
        }
        if ($ctx->ftp === null) {
            throw new UpdateException('FTP credentials are needed to continue.');
        }

        return new FtpFileWriter($ctx->ftp);
    }

    private function save(): void
    {
        self::writeState($this->paths, $this->state);
    }
}
