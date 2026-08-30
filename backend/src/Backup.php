<?php
declare(strict_types=1);

/**
 * Backups: a database dump plus the audio and photo files, wrapped in one
 * tarball. Restore is CLI-only (bin/restore.php) because it is destructive.
 *
 * The dump holds the trunk and DDNS tokens encrypted with APP_KEY, so a backup
 * only restores onto a box with the same .env / APP_KEY.
 */
final class Backup
{
    /** Archive entry name => absolute source directory inside the container. */
    private const DIRS = [
        'photos' => '/var/lib/twocans/photos',
        'jokes' => '/var/lib/twocans/jokes',
        'recordings' => '/var/spool/asterisk/monitor',
        'voicemail' => '/var/spool/asterisk/voicemail',
        'asks' => '/var/spool/asterisk/asks',
    ];

    public function path(): string
    {
        return rtrim(getenv('BACKUPS_PATH') ?: '/var/lib/twocans/backups', '/');
    }

    /** @return array{ok:bool,name?:string,error?:string} */
    public function create(): array
    {
        $dir = $this->path();
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Cannot create the backups folder (' . $dir . ')'];
        }

        $name = 'twocans-' . date('Ymd-His') . '.tgz';
        $target = $dir . '/' . $name;
        $staging = sys_get_temp_dir() . '/twocans-backup-' . bin2hex(random_bytes(4));
        @mkdir($staging, 0777, true);

        try {
            if (!$this->dumpDatabase($staging . '/db.sql')) {
                return ['ok' => false, 'error' => 'Could not dump the database — is mariadb-dump installed in the php image?'];
            }

            file_put_contents($staging . '/manifest.json', json_encode([
                'created_at' => date('c'),
                'version' => 1,
                'db' => $this->env('DB_NAME', 'twocans'),
                'note' => 'Restore onto a box with the same APP_KEY (encrypted tokens).',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Symlinks are cheap (no copy), and tar -h follows them into the
            // real data. The staging dir is cleaned up with removeTree(), which
            // never follows symlinks, so it can only ever unlink the link.
            $entries = ['db.sql', 'manifest.json'];
            foreach (self::DIRS as $entry => $src) {
                if (is_dir($src)) {
                    @symlink($src, $staging . '/' . $entry);
                    $entries[] = $entry;
                }
            }

            $r = $this->run(['tar', '-czhf', $target, '-C', $staging, ...$entries]);
            if ($r['code'] !== 0 || !is_file($target)) {
                @unlink($target);

                return ['ok' => false, 'error' => trim($r['stderr']) !== '' ? trim($r['stderr']) : 'Could not build the backup archive'];
            }

            return ['ok' => true, 'name' => $name];
        } finally {
            $this->removeTree($staging);
        }
    }

    /** @return array<int,array{name:string,size:int,when:string}> */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->path() . '/twocans-*.tgz') ?: [] as $file) {
            $out[] = [
                'name' => basename($file),
                'size' => (int) @filesize($file),
                'when' => date('j M Y, H:i', (int) @filemtime($file)),
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['name'] <=> $a['name']);

        return $out;
    }

    public function remove(string $name): bool
    {
        if ($name === '' || basename($name) !== $name) {
            return false;
        }

        return is_file($this->path() . '/' . $name) && @unlink($this->path() . '/' . $name);
    }

    /**
     * Restore a named backup from the backups folder. Used by bin/restore.php.
     * Always writes a safety dump of the current database first.
     *
     * @return array{ok:bool,error?:string,files?:array<int,string>,dryRun?:bool}
     */
    public function restore(string $name, bool $dryRun = false): array
    {
        if ($name === '' || basename($name) !== $name) {
            return ['ok' => false, 'error' => 'Not a backup name'];
        }
        $path = $this->path() . '/' . $name;
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'No such backup: ' . $name];
        }

        return $this->restoreFromPath($path, $dryRun);
    }

    /**
     * Restore from an uploaded file. The web path into the same restore core;
     * guarded server-side by the Owner-only `backups` permission and a typed
     * confirmation before this is ever called.
     *
     * @return array{ok:bool,error?:string,files?:array<int,string>}
     */
    public function restoreFile(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'No file to restore from'];
        }

        return $this->restoreFromPath($path, false);
    }

    /**
     * @return array{ok:bool,error?:string,files?:array<int,string>,dryRun?:bool}
     */
    private function restoreFromPath(string $path, bool $dryRun): array
    {
        $staging = sys_get_temp_dir() . '/twocans-restore-' . bin2hex(random_bytes(4));
        @mkdir($staging, 0777, true);

        try {
            // --no-same-owner: an uploaded tarball's ownership bits are not ours
            // to trust; extract as the pool user. Modern GNU tar also refuses
            // `..` members, so nothing can escape the staging dir.
            $r = $this->run(['tar', '--no-same-owner', '-xzf', $path, '-C', $staging]);
            if ($r['code'] !== 0 || !is_file($staging . '/db.sql')) {
                return ['ok' => false, 'error' => 'Not a valid twocans backup (missing db.sql)'];
            }

            $files = [];
            foreach (self::DIRS as $entry => $src) {
                if (is_dir($staging . '/' . $entry)) {
                    $files[] = $entry;
                }
            }

            if ($dryRun) {
                return ['ok' => true, 'dryRun' => true, 'files' => $files];
            }

            // A safety dump so a bad restore can be undone by hand.
            $pre = $this->path() . '/pre-restore-' . date('Ymd-His') . '.sql';
            if (!$this->dumpDatabase($pre)) {
                return ['ok' => false, 'error' => 'Could not write the safety dump — aborting restore'];
            }

            $import = $this->importDatabase($staging . '/db.sql');
            if (!$import['ok']) {
                return ['ok' => false, 'error' => 'Database restore failed: ' . $import['error']];
            }

            foreach ($files as $entry) {
                $this->copyDir($staging . '/' . $entry, self::DIRS[$entry]);
            }

            return ['ok' => true, 'files' => $files];
        } finally {
            $this->removeTree($staging);
        }
    }

    // ------------------------------------------------------------ database

    private function dumpDatabase(string $outFile): bool
    {
        $fh = @fopen($outFile, 'wb');
        if ($fh === false) {
            return false;
        }

        $cmd = ['mariadb-dump', '--single-transaction', '--no-tablespaces',
            '-h', $this->env('DB_HOST', 'mariadb'),
            '-P', $this->env('DB_PORT', '3306'),
            '-u', $this->env('DB_USER', 'twocans'),
            $this->env('DB_NAME', 'twocans')];

        $env = array_merge(getenv(), ['MYSQL_PWD' => $this->env('DB_PASSWORD', '')]);

        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $fh, 2 => ['pipe', 'w']], $pipes, null, $env);
        fclose($fh);
        if (!is_resource($proc)) {
            return false;
        }
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($proc) === 0;
    }

    /** @return array{ok:bool,error?:string} */
    private function importDatabase(string $sqlFile): array
    {
        $db = $this->env('DB_NAME', 'twocans');
        $env = array_merge(getenv(), ['MYSQL_PWD' => $this->env('DB_PASSWORD', '')]);
        $base = ['mariadb', '-h', $this->env('DB_HOST', 'mariadb'), '-P', $this->env('DB_PORT', '3306'),
            '-u', $this->env('DB_USER', 'twocans'), $db];

        if (!$this->dropAllTables()) {
            return ['ok' => false, 'error' => 'could not clear the existing tables'];
        }

        $fh = @fopen($sqlFile, 'rb');
        if ($fh === false) {
            return ['ok' => false, 'error' => 'could not read ' . $sqlFile];
        }
        $proc = @proc_open($base, [0 => $fh, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        fclose($fh);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'could not start mariadb'];
        }
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return $code === 0
            ? ['ok' => true]
            : ['ok' => false, 'error' => trim($err) !== '' ? trim($err) : 'mariadb exited ' . $code];
    }

    private function dropAllTables(): bool
    {
        $db = $this->env('DB_NAME', 'twocans');
        $env = array_merge(getenv(), ['MYSQL_PWD' => $this->env('DB_PASSWORD', '')]);
        $base = ['mariadb', '-h', $this->env('DB_HOST', 'mariadb'), '-P', $this->env('DB_PORT', '3306'),
            '-u', $this->env('DB_USER', 'twocans'), $db];

        $r = $this->run(array_merge($base, ['-N', '-e', 'SHOW TABLES']), $env);
        if ($r['code'] !== 0) {
            return false;
        }
        $names = array_values(array_filter(array_map('trim', explode("\n", $r['stdout']))));
        if ($names === []) {
            return true;
        }
        $quoted = implode(', ', array_map(static fn(string $t): string => '`' . str_replace('`', '``', $t) . '`', $names));
        $drop = 'SET FOREIGN_KEY_CHECKS=0; DROP TABLE ' . $quoted . '; SET FOREIGN_KEY_CHECKS=1;';

        return $this->run(array_merge($base, ['-e', $drop]), $env)['code'] === 0;
    }

    // ---------------------------------------------------------------- files

    private function copyDir(string $from, string $to): bool
    {
        @mkdir($to, 0777, true);

        return $this->run(['cp', '-a', rtrim($from, '/') . '/.', rtrim($to, '/') . '/'])['code'] === 0;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        // Never follows symlinks (no FOLLOW_SYMLINKS), so cleanup can only
        // ever unlink the link, never touch the real data dirs it points at.
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    // ----------------------------------------------------------------- misc

    private function env(string $name, string $default = ''): string
    {
        $v = getenv($name);

        return $v === false || $v === '' ? $default : $v;
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    private function run(array $cmd, array $extraEnv = []): array
    {
        $env = array_merge(getenv(), $extraEnv);
        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            return ['code' => -1, 'stdout' => '', 'stderr' => 'could not start process'];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($proc), 'stdout' => $out, 'stderr' => $err];
    }
}
