<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pragmatic guard: a Blade view must not carry English UI text in its markup.
 * Everything a user reads goes through __() / @lang / the pos.ui helper.
 *
 * It looks at text nodes (">Some words<") and at placeholder/title/aria-label/alt
 * attributes, after dropping comments, <script>/<style>, Blade echoes and PHP blocks.
 */
class NoHardcodedStringsTest extends TestCase
{
    /** Text that is meant to stay as written. */
    private const ALLOWED = [
        'LebaSouk',                       // brand
        'Build Syntax',                   // brand
        'English',                        // language endonyms, shown in the language's own name
        'Visa', 'MasterCard', 'Amex',     // card networks
    ];

    /** Views that are intentionally not translated through lang files. */
    private const SKIP_PATH = [
        '/vendor/',                       // framework pagination (published, already uses __())
    ];

    /** @return array<int, string> "path:line  text" */
    private function violations(): array
    {
        $root = str_replace('\\', '/', realpath(__DIR__.'/../../resources/views'));
        $found = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (! str_ends_with($path, '.blade.php')) {
                continue;
            }
            foreach (self::SKIP_PATH as $skip) {
                if (str_contains($path, $skip)) {
                    continue 2;
                }
            }

            $raw = file_get_contents($path);
            // Blank (not delete) the parts we ignore so line numbers survive.
            $blank = fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]);
            $src = preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $raw);
            $src = preg_replace_callback('/<script\b.*?<\/script>/s', $blank, $src);
            $src = preg_replace_callback('/<style\b.*?<\/style>/s', $blank, $src);
            $src = preg_replace_callback('/@php.*?@endphp/s', $blank, $src);
            $src = preg_replace_callback('/<\?php.*?\?>/s', $blank, $src);
            $src = preg_replace_callback('/\{\{.*?\}\}|\{!!.*?!!\}/s', fn ($m) => preg_replace('/[^\n]/', '§', $m[0]), $src);
            // Blade directives with arguments, e.g. @if (...), @foreach (...)
            $src = preg_replace_callback('/@(?:if|elseif|foreach|forelse|unless|isset|empty|can|auth|includeWhen|include|class|selected|checked|disabled|error|json|js)\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/s', $blank, $src);

            $patterns = [
                '/>\s*([A-Z][a-z]+[^<§>@]*)</',
                '/\b(?:placeholder|title|aria-label|alt)="([A-Z][a-z][^"§]*)"/',
            ];
            foreach ($patterns as $re) {
                preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE);
                foreach ($m[1] as [$text, $offset]) {
                    $text = trim(html_entity_decode($text));
                    if ($text === '' || in_array($text, self::ALLOWED, true)) {
                        continue;
                    }
                    $line = substr_count(substr($src, 0, $offset), "\n") + 1;
                    $found[] = str_replace($root.'/', '', $path).":{$line}  {$text}";
                }
            }
        }

        sort($found);

        return $found;
    }

    public function test_views_contain_no_hardcoded_english_text(): void
    {
        $this->assertSame([], $this->violations(), "Hardcoded English in views — move it to lang/{en,ar} and use __():\n");
    }

    public function test_the_guard_would_catch_a_new_english_heading(): void
    {
        $dir = sys_get_temp_dir().'/guard-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/x.blade.php", "<div>\n<h2 class=\"a\">Brand New Heading</h2>\n</div>\n");

        $re = '/>\s*([A-Z][a-z]+[^<§>@]*)</';
        preg_match_all($re, file_get_contents("{$dir}/x.blade.php"), $m);
        unlink("{$dir}/x.blade.php");
        rmdir($dir);

        $this->assertSame(['Brand New Heading'], $m[1]);
    }
}
