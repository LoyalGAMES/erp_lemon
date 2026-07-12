<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

/**
 * Create a database backup using Laravel's resolved connection configuration.
 * Secrets are passed to native clients through temporary mode-0600 files, never
 * through process arguments or logs.
 */
if ($argc !== 4) {
    fwrite(STDERR, "Użycie: php backup-database.php RELEASE_PATH BACKUP_DIRECTORY DEPLOY_PATH\n");
    exit(64);
}

[$script, $releaseArgument, $backupArgument, $deployPath] = $argv;
unset($script);

$releasePath = realpath($releaseArgument);
if ($releasePath === false || ! is_dir($releasePath)) {
    throw new RuntimeException('Katalog wydania nie istnieje.');
}

if (! str_starts_with($backupArgument, '/')) {
    throw new RuntimeException('Katalog backupu musi być ścieżką bezwzględną.');
}
if (! is_dir($backupArgument) && ! mkdir($backupArgument, 0700, true) && ! is_dir($backupArgument)) {
    throw new RuntimeException('Nie można utworzyć katalogu backupu.');
}
chmod($backupArgument, 0700);
$backupDirectory = realpath($backupArgument);
if ($backupDirectory === false) {
    throw new RuntimeException('Nie można rozwiązać katalogu backupu.');
}

require $releasePath.'/vendor/autoload.php';
$app = require $releasePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connectionName = (string) config('database.default');
$configuration = $app->make('db')->connection($connectionName)->getConfig();
if (! is_array($configuration)) {
    throw new RuntimeException("Brak konfiguracji połączenia bazy {$connectionName}.");
}

$driver = mb_strtolower((string) ($configuration['driver'] ?? $connectionName));
$safeRelease = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($releasePath)) ?: 'release';
$timestamp = gmdate('Ymd-His');
$temporaryFiles = [];

try {
    $backupPath = match ($driver) {
        'mysql', 'mariadb' => backupMysql($configuration, $backupDirectory, $timestamp, $safeRelease, $temporaryFiles),
        'pgsql', 'postgres', 'postgresql' => backupPostgres($configuration, $backupDirectory, $timestamp, $safeRelease, $temporaryFiles),
        'sqlite' => backupSqlite($configuration, $backupDirectory, $timestamp, $safeRelease, $releasePath, $deployPath),
        default => throw new RuntimeException("Nieobsługiwany sterownik backupu bazy: {$driver}."),
    };

    clearstatcache(true, $backupPath);
    if (! is_file($backupPath) || filesize($backupPath) === false || filesize($backupPath) < 1) {
        throw new RuntimeException('Backup bazy jest pusty albo nie został utworzony.');
    }

    chmod($backupPath, 0600);
    $hash = hash_file('sha256', $backupPath);
    if ($hash === false) {
        throw new RuntimeException('Nie można policzyć SHA-256 backupu bazy.');
    }

    $checksumPath = $backupPath.'.sha256';
    file_put_contents($checksumPath, $hash.'  '.basename($backupPath).PHP_EOL, LOCK_EX);
    chmod($checksumPath, 0600);

    fwrite(STDOUT, sprintf(
        "Backup bazy gotowy: %s (sha256: %s)\n",
        basename($backupPath),
        $hash,
    ));
} finally {
    foreach ($temporaryFiles as $temporaryFile) {
        if (is_string($temporaryFile)) {
            @unlink($temporaryFile);
        }
    }
}

/** @param array<string, mixed> $configuration */
function backupMysql(
    array $configuration,
    string $backupDirectory,
    string $timestamp,
    string $release,
    array &$temporaryFiles,
): string {
    $database = requiredString($configuration, 'database');
    $username = requiredString($configuration, 'username');
    $password = (string) ($configuration['password'] ?? '');
    $socket = trim((string) ($configuration['unix_socket'] ?? ''));
    $host = trim((string) ($configuration['host'] ?? '127.0.0.1'));
    $port = (string) ($configuration['port'] ?? '3306');

    $defaultsFile = tempnam($backupDirectory, '.mysql-client-');
    if ($defaultsFile === false) {
        throw new RuntimeException('Nie można utworzyć tymczasowej konfiguracji mysqldump.');
    }
    $temporaryFiles[] = $defaultsFile;

    $clientConfiguration = "[client]\n"
        .'user="'.mysqlOptionValue($username)."\"\n"
        .'password="'.mysqlOptionValue($password)."\"\n";
    if ($socket !== '') {
        $clientConfiguration .= 'socket="'.mysqlOptionValue($socket)."\"\n";
    } else {
        $clientConfiguration .= 'host="'.mysqlOptionValue($host)."\"\n"
            .'port="'.mysqlOptionValue($port)."\"\n";
    }
    file_put_contents($defaultsFile, $clientConfiguration, LOCK_EX);
    chmod($defaultsFile, 0600);

    $backupPath = "{$backupDirectory}/{$timestamp}-{$release}-mysql.sql";
    $command = [
        findExecutable('mysqldump'),
        "--defaults-extra-file={$defaultsFile}",
        '--single-transaction',
        '--quick',
        '--triggers',
        '--hex-blob',
        "--result-file={$backupPath}",
        '--databases',
        $database,
    ];
    runCommand($command);

    return $backupPath;
}

/** @param array<string, mixed> $configuration */
function backupPostgres(
    array $configuration,
    string $backupDirectory,
    string $timestamp,
    string $release,
    array &$temporaryFiles,
): string {
    $database = requiredString($configuration, 'database');
    $username = requiredString($configuration, 'username');
    $password = (string) ($configuration['password'] ?? '');
    $host = trim((string) ($configuration['host'] ?? '127.0.0.1'));
    $port = (string) ($configuration['port'] ?? '5432');

    $passwordFile = tempnam($backupDirectory, '.pgpass-');
    if ($passwordFile === false) {
        throw new RuntimeException('Nie można utworzyć tymczasowego pliku pgpass.');
    }
    $temporaryFiles[] = $passwordFile;
    $pgpass = implode(':', array_map('postgresPasswordValue', [$host, $port, $database, $username, $password]));
    file_put_contents($passwordFile, $pgpass.PHP_EOL, LOCK_EX);
    chmod($passwordFile, 0600);

    $backupPath = "{$backupDirectory}/{$timestamp}-{$release}-postgres.dump";
    $command = [
        findExecutable('pg_dump'),
        '--format=custom',
        '--no-password',
        "--file={$backupPath}",
        "--host={$host}",
        "--port={$port}",
        "--username={$username}",
        $database,
    ];
    $environment = getenv();
    if (! is_array($environment)) {
        $environment = [];
    }
    $environment['PGPASSFILE'] = $passwordFile;
    $environment['PGCONNECT_TIMEOUT'] = '10';
    if (isset($configuration['sslmode']) && is_string($configuration['sslmode'])) {
        $environment['PGSSLMODE'] = $configuration['sslmode'];
    }
    runCommand($command, $environment);

    return $backupPath;
}

/** @param array<string, mixed> $configuration */
function backupSqlite(
    array $configuration,
    string $backupDirectory,
    string $timestamp,
    string $release,
    string $releasePath,
    string $deployPath,
): string {
    $database = trim((string) ($configuration['database'] ?? ''));
    if ($database === '' || $database === ':memory:') {
        throw new RuntimeException('Produkcyjna baza SQLite musi wskazywać trwały plik.');
    }
    if (! str_starts_with($database, '/')) {
        $database = $releasePath.'/'.ltrim($database, '/');
    }

    $normalizedDeployPath = rtrim($deployPath, '/').'/';
    if (str_starts_with($database, $normalizedDeployPath)) {
        throw new RuntimeException(
            'DB_DATABASE dla SQLite nie może wskazywać wnętrza przełączanego DEPLOY_PATH; użyj pliku w katalogu shared.',
        );
    }
    if (! is_file($database) || ! is_readable($database)) {
        throw new RuntimeException("Plik bazy SQLite nie istnieje albo nie jest czytelny: {$database}");
    }

    $backupPath = "{$backupDirectory}/{$timestamp}-{$release}-sqlite.sqlite";
    $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $quotedBackupPath = str_replace("'", "''", $backupPath);
    $pdo->exec("VACUUM INTO '{$quotedBackupPath}'");

    return $backupPath;
}

/** @param array<string, mixed> $configuration */
function requiredString(array $configuration, string $key): string
{
    $value = trim((string) ($configuration[$key] ?? ''));
    if ($value === '') {
        throw new RuntimeException("Brak wymaganej wartości konfiguracji bazy: {$key}.");
    }

    return $value;
}

function mysqlOptionValue(string $value): string
{
    return addcslashes($value, "\\\"\n\r\t");
}

function postgresPasswordValue(string $value): string
{
    return str_replace(['\\', ':'], ['\\\\', '\\:'], $value);
}

function findExecutable(string $name): string
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        if ($directory === '') {
            continue;
        }
        $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException("Nie znaleziono wymaganego programu {$name} w PATH.");
}

/** @param list<string> $command */
function runCommand(array $command, ?array $environment = null): void
{
    $stdoutHandle = tmpfile();
    $stderrHandle = tmpfile();
    if ($stdoutHandle === false || $stderrHandle === false) {
        throw new RuntimeException('Nie można utworzyć tymczasowych plików diagnostycznych backupu.');
    }
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => $stdoutHandle,
        2 => $stderrHandle,
    ];
    $process = proc_open($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        fclose($stdoutHandle);
        fclose($stderrHandle);
        throw new RuntimeException('Nie można uruchomić programu wykonującego backup bazy.');
    }

    $exitCode = proc_close($process);
    rewind($stdoutHandle);
    rewind($stderrHandle);
    $stdout = stream_get_contents($stdoutHandle);
    $stderr = stream_get_contents($stderrHandle);
    fclose($stdoutHandle);
    fclose($stderrHandle);
    if ($exitCode !== 0) {
        $diagnostic = trim((string) ($stderr !== '' ? $stderr : $stdout));
        $diagnostic = mb_substr($diagnostic, 0, 2000);
        throw new RuntimeException("Backup bazy zakończył się kodem {$exitCode}: {$diagnostic}");
    }
}
