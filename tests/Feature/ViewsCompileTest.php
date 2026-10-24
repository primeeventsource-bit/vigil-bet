<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * Every Blade file compiles to valid PHP.
 *
 * This exists because a template can break in a way nothing else notices.
 * Blade compiled `@php($x = $row['y'])` into a bare `<?php(...)` - an
 * unclosed tag - which turned the rest of that file into literal text and
 * then failed with "unexpected endif" from a line hundreds down, nowhere
 * near the cause. Only a test that rendered that exact page would have
 * caught it, and a page with no test is exactly where it hides.
 *
 * Compiling is cheap; rendering needs data for every branch. This checks
 * the thing that is cheap to check, across every view at once.
 */
it('compiles every blade view to valid php', function () {
    $views = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $views[] = $file->getPathname();
    }

    expect($views)->not->toBeEmpty();

    $broken = [];

    foreach ($views as $path) {
        $compiled = Blade::compileString((string) file_get_contents($path));
        $relative = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $path);

        // The specific failure above, named: an opening tag swallowing the
        // file reads as valid-ish PHP for a long way before it stops.
        if (str_contains($compiled, '<?php(')) {
            $broken[] = "{$relative}: inline @php compiled to an unclosed tag";

            continue;
        }

        $temp = tempnam(sys_get_temp_dir(), 'blade').'.php';
        file_put_contents($temp, $compiled);

        $lint = (string) shell_exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temp).' 2>&1');

        @unlink($temp);

        if (! str_contains($lint, 'No syntax errors')) {
            $broken[] = $relative.': '.trim(str_replace($temp, $relative, $lint));
        }
    }

    expect($broken)->toBe([]);
});
