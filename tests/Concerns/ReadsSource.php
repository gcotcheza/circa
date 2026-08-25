<?php

declare(strict_types=1);

namespace Tests\Concerns;

use SplFileInfo;
use RuntimeException;
use FilesystemIterator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * Reading a front-end file as text, for the tests that assert on its source.
 *
 * A Vue single-file component has no PHP to exercise and there is no headless
 * browser here to drive one, so these tests read the file and assert that the
 * specific line whose absence caused a bug is still there. Two things every
 * one of them needs. The read must fail loudly when a path stops resolving,
 * because an unreadable file is '' and '' contains nothing — the assertions
 * would go on passing against nothing. And the prose has to go first, so that
 * a comment quoting the code cannot stand in for the code.
 */
trait ReadsSource
{
    /** The file's text, guarded against a path that no longer resolves. */
    protected function source(string $path): string
    {
        $source = (string) file_get_contents(base_path($path));

        self::assertNotSame('', $source, $path.' is empty or missing.');

        return $source;
    }

    /** The same, minus an SFC's two kinds of prose: HTML and JS block comments. */
    protected function sourceWithoutComments(string $path): string
    {
        return $this->sourceWithout(['/<!--.*?-->/s', '#/\*.*?\*/#s'], $path);
    }

    /** The same, minus block comments alone — all the prose a stylesheet or a plain script has. */
    protected function sourceWithoutBlockComments(string $path): string
    {
        return $this->sourceWithout(['#/\*.*?\*/#s'], $path);
    }

    /**
     * The read with $patterns stripped out of it, for a caller whose file has
     * its own kinds of prose. Failure is raised rather than asserted so that
     * these reads cost the same one assertion however they are composed:
     * preg_replace answers null when the engine gives up — a catastrophic
     * backtrack over a long file being the realistic way — and casting that to
     * string would hand back an empty haystack that every negative assertion
     * in the caller then passes against.
     *
     * @param  list<string>  $patterns
     */
    protected function sourceWithout(array $patterns, string $path): string
    {
        $stripped = preg_replace($patterns, '', $this->source($path));

        if ($stripped === null) {
            throw new RuntimeException(
                $path.' could not be stripped of its comments: '.preg_last_error_msg()
            );
        }

        return $stripped;
    }

    /**
     * Every `$extension` file under `$root`, both given and returned relative
     * to the repo root. Walked rather than globbed, because `resources/js/**`
     * does not recurse in PHP's glob and a component one directory deeper is
     * one the sweep never looks at — the sweeps here fail by finding nothing.
     * Sorted, so a failure names the same file on the next run. The caller
     * asserts that the list is not empty: what counts as too few is its own.
     *
     * @return list<string>
     */
    protected function sourceFiles(string $root, string $extension): array
    {
        $paths = [];

        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === $extension) {
                $paths[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        sort($paths);

        return $paths;
    }
}
