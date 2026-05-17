<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class BackupService
{
    public function backupPath(): string
    {
        $path = storage_path('app/backups');
        if (!is_dir($path)) mkdir($path, 0775, true);
        return $path;
    }

    /**
     * Copy the current SQLite database to a timestamped file. Returns the new file path.
     */
    public function createBackup(): string
    {
        $dbPath = database_path('database.sqlite');
        $configured = config('database.connections.sqlite.database');
        if ($configured && is_string($configured) && file_exists($configured)) {
            $dbPath = $configured;
        }

        if (!file_exists($dbPath)) {
            throw new RuntimeException('SQLite database file not found: ' . $dbPath);
        }

        // Force a WAL checkpoint so the .sqlite file is fully up to date before copying.
        try { DB::statement('PRAGMA wal_checkpoint(FULL);'); } catch (\Throwable $e) {}

        $target = $this->backupPath() . DIRECTORY_SEPARATOR . 'pos_backup_' . date('Y-m-d_H-i-s') . '.sqlite';
        if (!@copy($dbPath, $target)) {
            throw new RuntimeException('Failed to copy database to ' . $target);
        }

        return $target;
    }

    /**
     * @return array<int, array{name:string,size:int,bytes:string,date:int}>
     */
    public function listBackups(): array
    {
        $dir = $this->backupPath();
        $items = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.sqlite') ?: [] as $f) {
            $items[] = [
                'name' => basename($f),
                'size' => filesize($f) ?: 0,
                'bytes' => $this->humanSize(filesize($f) ?: 0),
                'date' => filemtime($f) ?: 0,
            ];
        }
        usort($items, fn ($a, $b) => $b['date'] <=> $a['date']);
        return $items;
    }

    public function restore(string $filename): void
    {
        $src = $this->backupPath() . DIRECTORY_SEPARATOR . basename($filename);
        if (!file_exists($src)) {
            throw new RuntimeException('Backup file not found.');
        }

        $dbPath = config('database.connections.sqlite.database') ?: database_path('database.sqlite');

        // Pre-restore safety: snapshot the current DB
        @copy($dbPath, $dbPath . '.pre-restore.' . date('His'));

        DB::disconnect();
        if (!@copy($src, $dbPath)) {
            throw new RuntimeException('Restore failed: could not overwrite live DB.');
        }
    }

    public function delete(string $filename): void
    {
        $f = $this->backupPath() . DIRECTORY_SEPARATOR . basename($filename);
        if (file_exists($f)) {
            @unlink($f);
        }
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B','KB','MB','GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
