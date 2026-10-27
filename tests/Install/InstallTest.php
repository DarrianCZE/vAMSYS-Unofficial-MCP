<?php

declare(strict_types=1);

namespace VamsysMcp\Tests\Install;

use PHPUnit\Framework\TestCase;
use VamsysMcp\Exception\ConfigException;
use VamsysMcp\Install\ConfigFile;
use VamsysMcp\Install\EncryptedSettings;
use VamsysMcp\Install\InstallService;
use VamsysMcp\Install\Migrator;
use VamsysMcp\Install\SecretBox;
use VamsysMcp\Install\SetupKey;
use VamsysMcp\Paths;
use VamsysMcp\Tests\Support\FakeHttpClient;
use VamsysMcp\Tests\Support\TempDir;
use VamsysMcp\Tests\Support\TestDatabase;

final class InstallTest extends TestCase
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

    public function testConfigFileRoundTripAndRefusesHandWrittenConfig(): void
    {
        $file = new ConfigFile("{$this->dir}/config/config.php");
        $file->write(['airlines' => ['swv' => ['client_secret' => "it's \"quoted\""]]]);
        self::assertTrue($file->isManaged());
        self::assertSame("it's \"quoted\"", $file->load()['airlines']['swv']['client_secret']);

        file_put_contents($file->path, "<?php return ['database' => ['password' => getenv('X')]];");
        $this->expectException(ConfigException::class);
        $file->write([]);
    }

    public function testSecretBoxAndEncryptedSettingsNeverStorePlaintext(): void
    {
        $pdo = TestDatabase::create();
        $settings = new EncryptedSettings($pdo, SecretBox::fromBase64(SecretBox::generateKey()));
        $settings->put('ftp', ['password' => 'ftp-secret']);

        self::assertSame(['password' => 'ftp-secret'], $settings->get('ftp'));
        self::assertStringNotContainsString('ftp-secret', (string) $pdo->query('SELECT ciphertext FROM encrypted_setting')->fetchColumn());

        $this->expectException(ConfigException::class);
        (new EncryptedSettings($pdo, SecretBox::fromBase64(SecretBox::generateKey())))->get('ftp');
    }

    public function testMigratorAppliesPendingMigrationsOnceInOrder(): void
    {
        TempDir::write($this->dir, [
            'm/0002_second.sql' => "-- comment\nCREATE TABLE IF NOT EXISTS b (id INTEGER);\n",
            'm/0001_first.sql' => "CREATE TABLE IF NOT EXISTS a (id INTEGER);\nCREATE TABLE IF NOT EXISTS a2 (id INTEGER);",
            'm/notes.txt' => 'ignored',
        ]);
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $migrator = new Migrator("{$this->dir}/m");

        self::assertSame(['0001', '0002'], $migrator->pending($pdo));
        self::assertSame(['0001', '0002'], $migrator->migrate($pdo));
        self::assertSame([], $migrator->pending($pdo));
        self::assertSame([], $migrator->migrate($pdo));
    }

    public function testSchemaSqlContainsEveryTableFromMigrations(): void
    {
        $root = dirname(__DIR__, 2);
        $schema = (string) file_get_contents("{$root}/schema.sql");
        foreach ((new Migrator("{$root}/migrations"))->available() as $file) {
            preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', (string) file_get_contents($file), $m);
            foreach ($m[1] as $table) {
                self::assertStringContainsString("CREATE TABLE IF NOT EXISTS {$table}", $schema, "schema.sql is missing {$table}; regenerate it from migrations/");
            }
        }
    }

    public function testSetupKey(): void
    {
        $key = new SetupKey("{$this->dir}/var/setup-key.txt");
        $key->ensure();
        $value = trim((string) file_get_contents($key->path()));

        self::assertFalse($key->verify('wrong'));
        self::assertTrue($key->verify(" {$value}\n"));
        $key->consume();
        self::assertFalse($key->verify($value));
    }

    public function testBuildConfigOnFreshInstallRequiresEverything(): void
    {
        [, $errors] = $this->service()->buildConfig([], []);

        self::assertContains('Database host, port, name, user and password are required.', $errors);
        self::assertContains('Configure at least one airline.', $errors);
        self::assertContains('Set an admin password for the web admin.', $errors);
    }

    public function testBuildConfigKeepsExistingSecretsWhenLeftEmpty(): void
    {
        $existing = [
            'database' => ['dsn' => 'mysql:host=db;port=3306;dbname=vmcp;charset=utf8mb4', 'username' => 'u', 'password' => 'db-secret'],
            'airlines' => ['swv' => ['name' => 'S', 'client_id' => '1', 'client_secret' => 'oauth-secret', 'weight_unit' => 'kg', 'rate_cap' => 60, 'pilots_api' => false]],
            'admin' => ['password_hash' => password_hash('long enough password', PASSWORD_DEFAULT)],
            'http' => ['bearer_token_sha256' => [hash('sha256', 't')]],
            'custom_key_from_newer_version' => 'kept',
        ];
        [$raw, $errors] = $this->service()->buildConfig([
            'db' => ['host' => 'db', 'port' => 3306, 'name' => 'vmcp', 'user' => 'u', 'password' => ''],
            'airlines' => [['key' => 'swv', 'client_id' => '1', 'client_secret' => '', 'weight_unit' => 'lbs', 'rate_cap' => 40]],
            'allowed_hosts' => 'mcp.example.com, other.example.com',
        ], $existing);

        self::assertSame([], $errors);
        self::assertSame('db-secret', $raw['database']['password']);
        self::assertSame('oauth-secret', $raw['airlines']['swv']['client_secret']);
        self::assertSame('lbs', $raw['airlines']['swv']['weight_unit']);
        self::assertFalse($raw['airlines']['swv']['pilots_api']);
        self::assertSame(['mcp.example.com', 'other.example.com'], $raw['http']['allowed_hosts']);
        self::assertSame($existing['http']['bearer_token_sha256'], $raw['http']['bearer_token_sha256']);
        self::assertSame($existing['admin']['password_hash'], $raw['admin']['password_hash']);
        self::assertSame('kept', $raw['custom_key_from_newer_version']);
        self::assertNotEmpty($raw['app_key']);
    }

    public function testBuildConfigRejectsBadAirlineAndShortPassword(): void
    {
        [, $errors] = $this->service()->buildConfig([
            'db' => ['host' => 'localhost', 'port' => 3306, 'name' => 'x', 'user' => 'u', 'password' => 'p'],
            'airlines' => [['key' => 'swv', 'client_id' => '1', 'client_secret' => 's', 'weight_unit' => 'stone', 'rate_cap' => 500]],
            'allowed_hosts' => 'https://bad/',
            'admin_password' => 'short', 'admin_password_confirm' => 'short',
        ], []);

        self::assertContains("Airline 'swv': weight_unit must be 'kg' or 'lbs'.", $errors);
        self::assertContains("'https://bad/' is not a valid host name (no scheme, port or path).", $errors);
        self::assertContains('The admin password must have at least 12 characters.', $errors);
    }

    private function service(): InstallService
    {
        return new InstallService(new Paths($this->dir), new FakeHttpClient());
    }
}
