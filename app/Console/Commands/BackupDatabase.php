<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Daily backup of the clinic data.
 *
 *   php artisan backup:database              # database dump (.sql.gz)
 *   php artisan backup:database --with-files # + zip of the archive files (patient files)
 *
 * Backups go to storage/app/backups. Set BACKUP_COPY_DISK (e.g. "s3") in .env
 * to also copy each backup to an off-site disk. Old backups are pruned after
 * BACKUP_KEEP_DAYS days. Scheduled daily in routes/console.php.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database
        {--with-files : Also zip the archive files (reservation attachments)}
        {--keep= : Days to keep old backups (default from config/backup.php)}';

    protected $description = 'Dump the database (and optionally the archive files) to storage/app/backups';

    public function handle(): int
    {
        $dir = storage_path('app/backups');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->error("Cannot create backup folder: {$dir}");
            return self::FAILURE;
        }

        $stamp = now()->format('Y-m-d_His');
        $created = [];

        $dump = $this->dumpDatabase($dir, $stamp);
        if ($dump === null) {
            return self::FAILURE;
        }
        $created[] = $dump;

        if ($this->option('with-files')) {
            $zip = $this->zipArchiveFiles($dir, $stamp);
            if ($zip === null) {
                return self::FAILURE;
            }
            if ($zip !== '') {
                $created[] = $zip;
            }
        }

        $this->copyOffsite($created);
        $this->prune($dir);

        foreach ($created as $file) {
            $this->info('Backup created: ' . basename($file) . ' (' . round(filesize($file) / 1048576, 2) . ' MB)');
        }

        return self::SUCCESS;
    }

    private function dumpDatabase(string $dir, string $stamp): ?string
    {
        $conn = config('database.default');
        $db = config("database.connections.{$conn}");

        if (!in_array($db['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error('Only MySQL/MariaDB databases are supported by this command.');
            return null;
        }

        $target = "{$dir}/db_{$db['database']}_{$stamp}.sql.gz";

        $command = [
            config('backup.mysqldump_path', 'mysqldump'),
            '--host=' . $db['host'],
            '--port=' . $db['port'],
            '--user=' . $db['username'],
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            $db['database'],
        ];

        $gz = gzopen($target, 'wb9');
        if ($gz === false) {
            $this->error("Cannot write {$target}");
            return null;
        }

        $stderr = '';
        // The password goes through the environment, never on the command line
        $process = new Process($command, null, ['MYSQL_PWD' => (string) ($db['password'] ?? '')]);
        $process->setTimeout(1800);
        $process->run(function ($type, $buffer) use ($gz, &$stderr) {
            if ($type === Process::OUT) {
                gzwrite($gz, $buffer);
            } else {
                $stderr .= $buffer;
            }
        });
        gzclose($gz);

        if (!$process->isSuccessful() || !is_file($target) || filesize($target) < 100) {
            @unlink($target);
            $message = 'Database backup failed: ' . trim($stderr ?: $process->getErrorOutput() ?: 'mysqldump produced no output');
            $this->error($message);
            Log::error($message);
            return null;
        }

        return $target;
    }

    /** @return string|null path of the zip, '' when there is nothing to zip, null on failure */
    private function zipArchiveFiles(string $dir, string $stamp): ?string
    {
        $root = Storage::disk('archive')->path('');
        $root = rtrim($root, '/\\');

        if (!is_dir($root)) {
            $this->warn('Archive folder not found, skipping files backup.');
            return '';
        }

        if (!class_exists(ZipArchive::class)) {
            $this->error('The PHP zip extension is not enabled; cannot back up the archive files.');
            return null;
        }

        $target = "{$dir}/archive_files_{$stamp}.zip";
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Cannot create {$target}");
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $zip->addFile($file->getPathname(), $relative);
                $count++;
            }
        }
        $zip->close();

        if ($count === 0) {
            @unlink($target);
            return '';
        }

        return $target;
    }

    private function copyOffsite(array $files): void
    {
        $diskName = config('backup.copy_disk');
        if (!$diskName) {
            return;
        }

        foreach ($files as $file) {
            try {
                $stream = fopen($file, 'rb');
                Storage::disk($diskName)->put('clinic-backups/' . basename($file), $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $this->info('Copied off-site: ' . basename($file));
            } catch (\Throwable $e) {
                $this->warn('Off-site copy failed: ' . $e->getMessage());
                Log::error('Backup off-site copy failed: ' . $e->getMessage());
            }
        }
    }

    private function prune(string $dir): void
    {
        $days = (int) ($this->option('keep') ?: config('backup.keep_days', 14));
        $limit = now()->subDays(max(1, $days))->getTimestamp();

        foreach (glob($dir . '/*.{gz,zip}', GLOB_BRACE) ?: [] as $file) {
            if (filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }
}
