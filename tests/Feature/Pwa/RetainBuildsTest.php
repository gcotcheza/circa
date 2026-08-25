<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use Tests\TestCase;
use Illuminate\Support\Facades\File;

/**
 * `php artisan build:retain` — what makes it safe for a deploy to stop wiping
 * `public/build`. Both ways of getting it wrong are serious.
 *
 * KEEP TOO LITTLE and a page open across a deploy asks for the photo capture or
 * review-sheet chunk by its old hashed name, gets a 404, and the button does
 * nothing forever — the bug this command was written for. KEEP TOO MUCH and
 * `emptyOutDir: false` makes every deploy a permanent addition: slower, and ends
 * with a full disk. So these tests are all boundary.
 */
final class RetainBuildsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/build-'.bin2hex(random_bytes(4)));

        File::ensureDirectoryExists($this->dir.'/assets');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_it_says_so_and_does_nothing_when_there_is_no_build(): void
    {
        $this->runArtisan('build:retain', ['--dir' => $this->dir])
            ->expectsOutputToContain('nothing to retain')
            ->assertSuccessful();
    }

    public function test_a_single_build_keeps_all_of_its_own_files(): void
    {
        $this->writeBuild(['app-AAA.js'], ['app-AAA.css'], ['zxing-AAA.wasm']);

        $this->runArtisan('build:retain', ['--dir' => $this->dir])->assertSuccessful();

        // `file`, `css` and `assets` are three manifest keys, all three files a
        // running page can ask for; missing one deletes what this build needs.
        $this->assertFileExists($this->dir.'/assets/app-AAA.js');
        $this->assertFileExists($this->dir.'/assets/app-AAA.css');
        $this->assertFileExists($this->dir.'/assets/zxing-AAA.wasm');
    }

    public function test_the_previous_builds_survive_and_the_older_ones_do_not(): void
    {
        /*
         * Three deploys in a day happened on the day the blank-page report came
         * in, which is why the default window is three rather than two.
         */
        $this->deploy('AAA');
        $this->deploy('BBB');
        $this->deploy('CCC');

        $this->assertFileExists($this->dir.'/assets/app-AAA.js');
        $this->assertFileExists($this->dir.'/assets/app-BBB.js');
        $this->assertFileExists($this->dir.'/assets/app-CCC.js');

        // The fourth pushes the first out.
        $this->deploy('DDD');

        $this->assertFileDoesNotExist($this->dir.'/assets/app-AAA.js');
        $this->assertFileExists($this->dir.'/assets/app-BBB.js');
        $this->assertFileExists($this->dir.'/assets/app-DDD.js');
    }

    public function test_a_chunk_two_builds_share_outlives_the_first_of_them(): void
    {
        // What a naive "delete everything older than the newest manifest" gets
        // wrong: most deploys leave the barcode reader's hash untouched, so one
        // file is named by several builds and outlives the first of them.
        $this->writeBuild(['app-AAA.js', 'shared-XYZ.js']);
        $this->runArtisan('build:retain', ['--dir' => $this->dir, '--keep' => 2])->assertSuccessful();

        $this->writeBuild(['app-BBB.js', 'shared-XYZ.js']);
        $this->runArtisan('build:retain', ['--dir' => $this->dir, '--keep' => 2])->assertSuccessful();

        $this->writeBuild(['app-CCC.js', 'shared-XYZ.js']);
        $this->runArtisan('build:retain', ['--dir' => $this->dir, '--keep' => 2])->assertSuccessful();

        $this->assertFileDoesNotExist($this->dir.'/assets/app-AAA.js');
        $this->assertFileExists($this->dir.'/assets/app-BBB.js');
        $this->assertFileExists($this->dir.'/assets/shared-XYZ.js');
    }

    public function test_running_twice_on_the_same_build_changes_nothing(): void
    {
        // It runs on the daily schedule too, so the usual case is a run with no
        // new build. Re-recording the current one would look like a deploy and
        // push a genuinely older build out a day early.
        $this->deploy('AAA');
        $this->deploy('BBB');

        $this->runArtisan('build:retain', ['--dir' => $this->dir])
            ->expectsOutputToContain('was already recorded')
            ->assertSuccessful();

        $this->assertFileExists($this->dir.'/assets/app-AAA.js');
        $this->assertFileExists($this->dir.'/assets/app-BBB.js');
    }

    public function test_an_orphan_nobody_ever_recorded_is_deleted(): void
    {
        // Left by a build from before this command existed, or an interrupted
        // one. Nothing names it; it is dead weight.
        File::put($this->dir.'/assets/ghost-ZZZ.js', 'x');

        $this->writeBuild(['app-AAA.js']);

        $this->runArtisan('build:retain', ['--dir' => $this->dir])->assertSuccessful();

        $this->assertFileDoesNotExist($this->dir.'/assets/ghost-ZZZ.js');
        $this->assertFileExists($this->dir.'/assets/app-AAA.js');
    }

    /**
     * A kept file keeps its source map; a dropped one does not.
     *
     * `vite.config.js` sets `build.sourcemap: true` so a production stack — all
     * this app's error reporter can collect — resolves to real filenames. Vite
     * does NOT name `app-AAA.js.map` in the manifest, so a prune driven purely by
     * manifest names deleted every map after each deploy. Nothing goes red: the
     * symptom arrives months later, as an inspector that cannot resolve a frame
     * on the one morning somebody needs it to.
     */
    public function test_a_source_map_is_kept_with_the_file_it_belongs_to(): void
    {
        $this->deploy('AAA');
        $this->deploy('BBB');
        $this->deploy('CCC');

        $this->assertFileExists($this->dir.'/assets/app-CCC.js.map');
        $this->assertFileExists($this->dir.'/assets/app-AAA.js.map', 'the oldest retained build keeps its map too');

        // Four builds, three retained: AAA goes, and its map goes with it.
        $this->deploy('DDD');

        $this->assertFileDoesNotExist($this->dir.'/assets/app-AAA.js');
        $this->assertFileDoesNotExist($this->dir.'/assets/app-AAA.js.map');
        $this->assertFileExists($this->dir.'/assets/app-DDD.js.map');
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $this->deploy('AAA');
        $this->deploy('BBB');
        $this->deploy('CCC');
        $this->deploy('DDD', dryRun: true);

        $this->assertFileExists($this->dir.'/assets/app-AAA.js');
    }

    public function test_it_never_deletes_its_own_bookkeeping(): void
    {
        $this->deploy('AAA');

        // Ledger and manifest live beside `assets/`; a prune walking the whole
        // tree would eat them.
        $this->assertFileExists($this->dir.'/manifest.json');
        $this->assertDirectoryExists($this->dir.'/builds');
        $this->assertNotEmpty(File::glob($this->dir.'/builds/*.json'));
    }

    /** One deploy: write the new build's files and manifest, then retain. */
    private function deploy(string $tag, bool $dryRun = false): void
    {
        $this->writeBuild(["app-{$tag}.js"], ["app-{$tag}.css"]);

        $options = ['--dir' => $this->dir];

        if ($dryRun) {
            $options['--dry-run'] = true;
        }

        $this->runArtisan('build:retain', $options)->assertSuccessful();
    }

    /**
     * Write a Vite-shaped manifest and the files it names. Files are ADDED,
     * never replaced wholesale — the point of `emptyOutDir: false`; a helper
     * that cleared the directory would test a build flow this project no
     * longer has.
     *
     * @param  list<string>  $js
     * @param  list<string>  $css
     * @param  list<string>  $assets
     */
    private function writeBuild(array $js, array $css = [], array $assets = []): void
    {
        $manifest = [];

        foreach ($js as $index => $file) {
            $manifest["resources/js/entry-{$index}.js"] = array_filter([
                'file'    => 'assets/'.$file,
                'isEntry' => $index === 0,
                'css'     => array_map(static fn (string $c): string => 'assets/'.$c, $index === 0 ? $css : []),
                'assets'  => array_map(static fn (string $a): string => 'assets/'.$a, $index === 0 ? $assets : []),
            ]);
        }

        foreach ([...$js, ...$css, ...$assets] as $file) {
            File::put($this->dir.'/assets/'.$file, 'built');
        }

        // Vite writes `app-AAA.js.map` beside `app-AAA.js` without naming it in
        // the manifest — the whole reason `pruneAssets` knows about maps, so the
        // fixture reproduces it rather than pretending manifest == listing.
        foreach ([...$js, ...$css] as $file) {
            File::put($this->dir.'/assets/'.$file.'.map', '{"version":3}');
        }

        File::put($this->dir.'/manifest.json', (string) json_encode($manifest));
    }
}
