<?php

namespace Tests\Feature\Demo;

use App\Support\Demo;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The file-swap that makes a demo reset itself, and the guard that stops that
 * swap ever landing on a real database.
 *
 * Deliberately not RefreshDatabase: the thing under test is what happens to
 * actual SQLite files on disk, so these run against temp files instead of the
 * in-memory test database.
 */
class DemoResetTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        // No "demo" in the directory name: the path guard looks at the whole
        // path, and these tests need to control whether it matches.
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lebasouk-test-'.uniqid();
        mkdir($this->dir, 0777, true);
        config(['pos.demo_mode' => true]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('demo_test');

        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** Point the default connection at a file under our temp dir. */
    private function useDatabase(string $filename): string
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.$filename;

        config(['database.connections.demo_test' => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['database.default' => 'demo_test']);
        DB::purge('demo_test');

        return $path;
    }

    /** A minimal but genuine SQLite file carrying one recognisable row. */
    private function makeSqlite(string $path, string $marker): void
    {
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE IF NOT EXISTS marker (value TEXT)');
        $pdo->exec("INSERT INTO marker (value) VALUES ('{$marker}')");
        $pdo = null;
    }

    private function markerIn(string $path): string
    {
        $pdo = new \PDO('sqlite:'.$path);
        $value = $pdo->query('SELECT value FROM marker LIMIT 1')->fetchColumn();
        $pdo = null;

        return (string) $value;
    }

    public function test_reset_restores_the_live_database_from_the_template(): void
    {
        $live = $this->useDatabase('demo.sqlite');
        $template = $this->dir.DIRECTORY_SEPARATOR.'demo-template.sqlite';

        $this->makeSqlite($template, 'seeded-baseline');
        $this->makeSqlite($live, 'messy-after-a-pitch');

        $this->resetWithTemplate($template);

        $this->assertSame('seeded-baseline', $this->markerIn($live));
    }

    public function test_reset_clears_the_wal_sidecars_of_the_database_it_replaced(): void
    {
        $live = $this->useDatabase('demo.sqlite');
        $template = $this->dir.DIRECTORY_SEPARATOR.'demo-template.sqlite';

        $this->makeSqlite($template, 'seeded-baseline');
        $this->makeSqlite($live, 'stale');
        file_put_contents($live.'-wal', 'stale wal');
        file_put_contents($live.'-shm', 'stale shm');

        $this->resetWithTemplate($template);

        $this->assertFileDoesNotExist($live.'-wal');
        $this->assertFileDoesNotExist($live.'-shm');
    }

    public function test_reset_refuses_when_the_template_is_missing(): void
    {
        $this->useDatabase('demo.sqlite');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Demo template missing');

        $this->resetWithTemplate($this->dir.DIRECTORY_SEPARATOR.'nope.sqlite');
    }

    public function test_a_demo_build_refuses_a_database_path_that_is_not_a_demo_one(): void
    {
        // What a fat-fingered env var looks like: a demo build aimed at the
        // production install's data directory.
        $this->useDatabase('database.sqlite');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not look like a demo data directory');

        Demo::guardDatabasePath();
    }

    public function test_a_demo_path_passes_the_guard(): void
    {
        $this->useDatabase('demo.sqlite');

        Demo::guardDatabasePath();

        $this->assertTrue(true); // no exception is the assertion
    }

    public function test_reset_refuses_to_overwrite_a_path_that_is_not_a_demo_one(): void
    {
        // The guard is on the destructive operation itself, not only on boot:
        // this is the case where the reset would otherwise land on real data.
        $live = $this->useDatabase('database.sqlite');
        $template = $this->dir.DIRECTORY_SEPARATOR.'demo-template.sqlite';

        $this->makeSqlite($template, 'seeded-baseline');
        $this->makeSqlite($live, 'somebody-real-data');

        try {
            $this->resetWithTemplate($template);
            $this->fail('Expected the path guard to refuse the reset.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not look like a demo data directory', $e->getMessage());
        }

        $this->assertSame('somebody-real-data', $this->markerIn($live));
    }

    /**
     * Demo::templatePath() is a fixed path, so drive
     * the reset through a temp template by swapping the file into place under
     * that name for the duration of the call.
     */
    private function resetWithTemplate(string $template): void
    {
        $real = Demo::templatePath();
        $backup = $real.'.test-backup';
        $hadReal = is_file($real);

        if ($hadReal) {
            rename($real, $backup);
        }

        try {
            if (is_file($template)) {
                copy($template, $real);
            }

            Demo::resetFromTemplate();
        } finally {
            @unlink($real);

            if ($hadReal) {
                rename($backup, $real);
            }
        }
    }
}
