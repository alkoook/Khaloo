<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupService
{
    public function filename(): string
    {
        return 'khaloo-backup-'.now()->format('Y-m-d-Hi').'.sql';
    }

    public function create(): string
    {
        $connection = config('database.default');
        if ($connection !== 'mysql') {
            throw new RuntimeException('تعذر إنشاء النسخة الاحتياطية.');
        }

        $database = config('database.connections.mysql');
        $dump = $this->dumpExecutable();
        if (! $dump || ! is_dir(storage_path('app/private')) || ! is_writable(storage_path('app/private'))) {
            throw new RuntimeException('تعذر إنشاء النسخة الاحتياطية.');
        }

        $optionsFile = tempnam(sys_get_temp_dir(), 'khaloo-db-');
        $outputFile = tempnam(storage_path('app/private'), 'khaloo-backup-');
        if (! $optionsFile || ! $outputFile) {
            if ($optionsFile) @unlink($optionsFile);
            if ($outputFile) @unlink($outputFile);
            throw new RuntimeException('تعذر إنشاء النسخة الاحتياطية.');
        }

        try {
            $settings = "[client]\n";
            foreach (['host', 'port', 'username', 'password'] as $key) {
                $value = (string) ($database[$key] ?? '');
                if (str_contains($value, "\n") || str_contains($value, "\r")) {
                    throw new RuntimeException('تعذر إنشاء النسخة الاحتياطية.');
                }
                $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
                $option = $key === 'username' ? 'user' : $key;
                $settings .= $option.'="'.$escaped."\"\n";
            }
            if (file_put_contents($optionsFile, $settings, LOCK_EX) === false) {
                throw new RuntimeException('تعذر إنشاء النسخة الاحتياطية.');
            }
            @chmod($optionsFile, 0600);

            $process = new Process([
                $dump,
                '--defaults-extra-file='.$optionsFile,
                '--single-transaction', '--quick', '--routines', '--triggers', '--events',
                '--hex-blob', '--no-tablespaces', '--default-character-set=utf8mb4',
                '--result-file='.$outputFile, '--databases', (string) $database['database'],
            ], base_path());
            $process->setTimeout(600);
            $process->run();
            if (! $process->isSuccessful() || filesize($outputFile) === 0) {
                throw new RuntimeException('تعذر إنشاء النسخة الاحتياطية.');
            }

            return $outputFile;
        } catch (Throwable) {
            @unlink($outputFile);
            throw new RuntimeException('تعذر إنشاء النسخة الاحتياطية.');
        } finally {
            @unlink($optionsFile);
        }
    }

    private function dumpExecutable(): ?string
    {
        $found = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'mysqldump.exe' : 'mysqldump');
        if ($found) return $found;

        if (PHP_OS_FAMILY === 'Windows') {
            $xampp = dirname(PHP_BINARY, 2).DIRECTORY_SEPARATOR.'mysql'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'mysqldump.exe';
            if (is_file($xampp)) return $xampp;
        }

        return null;
    }
}
