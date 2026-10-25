<?php

namespace App\Services;

use App\Exceptions\DatabaseBackupException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupService
{
    /** @var array{exit_code: int|null, stderr: string, command: array<int, string>, method?: string} */
    private array $diagnostics = ['exit_code' => null, 'stderr' => '', 'command' => []];

    public function filename(): string
    {
        return 'khaloo-backup-'.now()->format('Y-m-d-Hi').'.sql';
    }

    public function create(): string
    {
        if (config('database.default') !== 'mysql') {
            throw new DatabaseBackupException('النسخ الاحتياطي المباشر متاح لقواعد بيانات MySQL فقط.');
        }

        $database = config('database.connections.mysql');
        $directory = storage_path('app/private');
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new DatabaseBackupException('مجلد النسخ الاحتياطي الخاص غير متاح للكتابة.');
        }

        $outputFile = tempnam($directory, 'khaloo-backup-');
        if (! $outputFile) {
            throw new DatabaseBackupException('تعذر إنشاء ملف مؤقت آمن للنسخة الاحتياطية.');
        }

        try {
            $dump = $this->detectedExecutable();
            if ($dump) {
                $failure = $this->createWithMysqldump($dump, $database, $outputFile);
                if ($failure === null) {
                    $this->verifyBackup($outputFile);

                    return $outputFile;
                }

                if (! $this->isWindowsSocketFailure($failure)) {
                    throw new DatabaseBackupException($this->messageForFailure($failure));
                }

                Log::warning('mysqldump Windows socket failed; using PDO SQL backup fallback.', $this->diagnostics);
            } else {
                Log::warning('mysqldump was not found; using PDO SQL backup fallback.');
            }

            $this->createWithPdo($database, $outputFile);
            $this->verifyBackup($outputFile);
            $this->diagnostics['method'] = 'pdo';

            return $outputFile;
        } catch (DatabaseBackupException $exception) {
            @unlink($outputFile);
            throw $exception;
        } catch (Throwable $exception) {
            @unlink($outputFile);
            Log::error('Unexpected database backup failure.', [
                'exception' => $exception::class,
                'code' => $exception->getCode(),
                'diagnostics' => $this->diagnostics,
            ]);
            throw new DatabaseBackupException('تعذر إنشاء النسخة الاحتياطية. فشل اتصال أداة MySQL وكذلك مسار النسخ الآمن داخل التطبيق. راجع سجل التطبيق للتفاصيل الفنية الآمنة.');
        }
    }

    /**
     * Returns null on success, otherwise the final safe stderr.
     *
     * @param  array<string, mixed>  $database
     */
    private function createWithMysqldump(string $dump, array $database, string $outputFile): ?string
    {
        $optionsFile = tempnam(sys_get_temp_dir(), 'khaloo-db-');
        if (! $optionsFile) {
            throw new DatabaseBackupException('تعذر إنشاء ملف اتصال مؤقت آمن لأداة mysqldump.');
        }

        try {
            $settings = "[client]\n";
            foreach (['username', 'password'] as $key) {
                $value = (string) ($database[$key] ?? '');
                if (str_contains($value, "\n") || str_contains($value, "\r")) {
                    throw new DatabaseBackupException('إعدادات الاتصال بقاعدة البيانات غير صالحة.');
                }
                $settings .= ($key === 'username' ? 'user' : $key).'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value)."\"\n";
            }
            if (file_put_contents($optionsFile, $settings, LOCK_EX) === false) {
                throw new DatabaseBackupException('تعذر تجهيز اتصال آمن بأداة mysqldump.');
            }
            @chmod($optionsFile, 0600);

            $host = (string) ($database['host'] ?? '127.0.0.1');
            $attempts = [['host' => $host, 'protocol' => null]];
            if ($this->isLocalHost($host)) {
                $alternate = strtolower($host) === 'localhost' ? '127.0.0.1' : 'localhost';
                $attempts[] = ['host' => $host, 'protocol' => 'TCP'];
                $attempts[] = ['host' => $alternate, 'protocol' => null];
                $attempts[] = ['host' => $alternate, 'protocol' => 'TCP'];
            }

            $lastError = '';
            foreach ($attempts as $index => $attempt) {
                if ($index > 0 && ! $this->isWindowsSocketFailure($lastError)) {
                    break;
                }

                file_put_contents($outputFile, '');
                $arguments = [$dump, '--defaults-extra-file='.$optionsFile];
                if ($attempt['protocol']) {
                    $arguments[] = '--protocol='.$attempt['protocol'];
                }
                array_push(
                    $arguments,
                    '--host='.$attempt['host'],
                    '--port='.(string) ($database['port'] ?? 3306),
                    '--single-transaction', '--quick', '--routines', '--triggers', '--events',
                    '--hex-blob', '--default-character-set=utf8mb4',
                );
                if ($this->supportsOption($dump, '--no-tablespaces')) {
                    $arguments[] = '--no-tablespaces';
                }
                array_push($arguments, '--result-file='.$outputFile, '--databases', (string) $database['database']);

                $process = new Process($arguments, base_path());
                $process->setTimeout(600);
                $process->run();
                $lastError = $this->safeStderr($process->getErrorOutput(), (string) ($database['password'] ?? ''));
                $this->diagnostics = [
                    'exit_code' => $process->getExitCode(),
                    'stderr' => $lastError,
                    'command' => $this->safeCommand($arguments, $optionsFile),
                    'method' => 'mysqldump',
                ];
                if ($process->isSuccessful()) {
                    return null;
                }
                Log::error('mysqldump failed.', $this->diagnostics);
            }

            return $lastError;
        } finally {
            @unlink($optionsFile);
        }
    }

    /** @param array<string, mixed> $database */
    private function createWithPdo(array $database, string $outputFile): void
    {
        $pdo = DB::connection('mysql')->getPdo();
        $handle = fopen($outputFile, 'wb');
        if (! $handle) {
            throw new DatabaseBackupException('تعذر فتح ملف النسخة الاحتياطية للكتابة.');
        }

        $databaseName = (string) $database['database'];
        try {
            fwrite($handle, "-- Khaloo PDO SQL backup\n-- Database: ".$this->quoteIdentifier($databaseName)."\n\n");
            fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $quotedTable = $this->quoteIdentifier((string) $table);
                $create = $pdo->query('SHOW CREATE TABLE '.$quotedTable)->fetch(PDO::FETCH_NUM);
                fwrite($handle, 'DROP TABLE IF EXISTS '.$quotedTable.";\n".$create[1].";\n\n");

                $columns = $pdo->query('SHOW FULL COLUMNS FROM '.$quotedTable)->fetchAll(PDO::FETCH_ASSOC);
                $columns = array_values(array_filter($columns, fn (array $column): bool => ! str_contains(strtolower((string) $column['Extra']), 'generated')));
                if ($columns === []) {
                    continue;
                }
                $names = array_column($columns, 'Field');
                $binary = [];
                foreach ($columns as $column) {
                    $binary[$column['Field']] = preg_match('/(?:binary|blob|geometry|point|linestring|polygon)/i', (string) $column['Type']) === 1;
                }

                $statement = $pdo->query('SELECT '.implode(',', array_map($this->quoteIdentifier(...), $names)).' FROM '.$quotedTable);
                $batch = [];
                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    $values = [];
                    foreach ($names as $name) {
                        $value = $row[$name];
                        $values[] = $value === null ? 'NULL' : ($binary[$name] ? '0x'.bin2hex((string) $value) : $pdo->quote((string) $value));
                    }
                    $batch[] = '('.implode(',', $values).')';
                    if (count($batch) === 200) {
                        $this->writeInsert($handle, $quotedTable, $names, $batch);
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    $this->writeInsert($handle, $quotedTable, $names, $batch);
                }
                fwrite($handle, "\n");
            }

            $views = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'VIEW\'')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($views as $view) {
                $quotedView = $this->quoteIdentifier((string) $view);
                $create = $pdo->query('SHOW CREATE VIEW '.$quotedView)->fetch(PDO::FETCH_ASSOC);
                $sql = $create['Create View'] ?? null;
                if (is_string($sql)) {
                    fwrite($handle, 'DROP VIEW IF EXISTS '.$quotedView.";\n".$sql.";\n\n");
                }
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle @param array<int, string> $names @param array<int, string> $batch */
    private function writeInsert($handle, string $table, array $names, array $batch): void
    {
        $columns = implode(',', array_map($this->quoteIdentifier(...), $names));
        fwrite($handle, 'INSERT INTO '.$table.' ('.$columns.") VALUES\n".implode(",\n", $batch).";\n");
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function verifyBackup(string $path): void
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw new DatabaseBackupException('لم يتم إنشاء ملف SQL صالح للنسخة الاحتياطية.');
        }
        $contents = file_get_contents($path);
        if (! is_string($contents) || ! str_contains($contents, 'CREATE TABLE')) {
            throw new DatabaseBackupException('ملف النسخة الاحتياطية لا يحتوي على بنية جداول صالحة.');
        }
    }

    public function detectedExecutable(): ?string
    {
        $configured = trim((string) config('backup.mysqldump_path'));
        $xampp = trim((string) config('backup.xampp_path'));
        $candidates = array_filter([
            $configured,
            PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : null,
            PHP_OS_FAMILY === 'Windows' ? 'D:\\xampp\\mysql\\bin\\mysqldump.exe' : null,
            $xampp ? rtrim($xampp, '\\/').DIRECTORY_SEPARATOR.'mysql'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'mysqldump.exe' : null,
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'mysqldump.exe' : 'mysqldump');
    }

    /** @return array{exit_code: int|null, stderr: string, command: array<int, string>, method?: string} */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    private function supportsOption(string $dump, string $option): bool
    {
        $process = new Process([$dump, '--help'], base_path());
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful() && str_contains($process->getOutput().$process->getErrorOutput(), $option);
    }

    private function safeStderr(string $stderr, string $password): string
    {
        $safe = trim(str_replace(["\0", "\r"], ['', ''], $stderr));
        if ($password !== '') {
            $safe = str_replace($password, '[REDACTED]', $safe);
        }
        $safe = preg_replace('/(password\s*[=:]\s*)\S+/i', '$1[REDACTED]', $safe) ?? '';

        return mb_substr($safe, 0, 4000);
    }

    /** @param array<int, string> $arguments */
    private function safeCommand(array $arguments, string $optionsFile): array
    {
        return array_map(
            fn (string $argument) => str_starts_with($argument, '--defaults-extra-file=')
                ? '--defaults-extra-file=[PRIVATE_TEMP_FILE]'
                : str_replace($optionsFile, '[PRIVATE_TEMP_FILE]', $argument),
            $arguments,
        );
    }

    private function isWindowsSocketFailure(string $stderr): bool
    {
        $normalized = strtolower($stderr);

        return str_contains($normalized, "can't create tcp/ip socket")
            || str_contains($normalized, 'error: 2004')
            || str_contains($normalized, '(10106)');
    }

    private function isLocalHost(string $host): bool
    {
        return in_array(strtolower(trim($host)), ['127.0.0.1', 'localhost', '::1'], true);
    }

    private function messageForFailure(string $stderr): string
    {
        $normalized = strtolower($stderr);
        if (str_contains($normalized, 'access denied') || str_contains($normalized, 'authentication')) {
            return 'فشل تسجيل الدخول إلى قاعدة البيانات أثناء النسخ الاحتياطي. تحقق من اسم المستخدم وكلمة المرور.';
        }
        if (str_contains($normalized, 'unknown option') || str_contains($normalized, 'unknown variable')) {
            return 'إصدار أداة mysqldump لا يدعم أحد خيارات النسخ الاحتياطي. راجع سجل التطبيق لمعرفة الخيار.';
        }
        if ($this->isWindowsSocketFailure($stderr)) {
            return 'تعذر على أداة XAMPP إنشاء اتصال TCP في Windows (الخطأ 10106). حاول التطبيق استخدام الاتصال البديل والنسخ الداخلي الآمن؛ راجع السجل إذا استمر الفشل.';
        }
        if (str_contains($normalized, "can't connect") || str_contains($normalized, 'cannot connect') || str_contains($normalized, 'connection refused')) {
            return 'تعذر الاتصال بخادم MySQL. تحقق من تشغيل XAMPP ومن عنوان الخادم والمنفذ.';
        }
        if (str_contains($normalized, 'permission denied') || str_contains($normalized, 'access is denied') || str_contains($normalized, 'errno 13')) {
            return 'تعذر كتابة ملف النسخة الاحتياطية بسبب صلاحيات المجلد أو المسار.';
        }

        return 'فشلت أداة mysqldump في إنشاء النسخة الاحتياطية. راجع سجل التطبيق للتفاصيل الفنية الآمنة.';
    }
}
