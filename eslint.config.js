// =============================================================================
// Health Tracker — front-end lint (ESLint flat config)
// =============================================================================
//   npm run lint          # report
//   npm run lint:fix      # report and fix what is mechanically fixable
//   scripts/ci.sh         # runs `npm run lint` and fails the build on an error
//
// WHY THIS EXISTS
// Half of this app runs in a browser, and the faults that half produces do not
// show up in `php artisan test` or in the nginx log — they show up as a blank
// white screen on a phone. `tests/js` covers the two modules written in
// response to exactly that, but a test suite only catches what it was pointed
// at. Lint catches the class of thing nobody thought to point at: a variable
// that was renamed in one branch of a function, a `v-for` with no `:key`, a
// component prop referenced by a name it no longer has.
//
// WHY NO PRETTIER
// Same reasoning the PHP side uses for Pint: one enforcer, not two negotiating.
// A formatter and a linter that both have opinions about where a line breaks
// will eventually disagree, and the resolution is always a config file nobody
// enjoys. ESLint's `--fix` plus eslint-plugin-vue's template rules already
// cover the mechanical part, and `.editorconfig` covers indent width for every
// editor in the loop. Adding Prettier later is a deliberate decision, not a
// default.
// =============================================================================

import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

export default [
    {
        // Build output, dependencies, and the PHP side. `public/build` in
        // particular holds minified bundles AND their source maps, and linting
        // a 300 KB single-line chunk is a good way to make the run look broken.
        ignores: [
            'public/**',
            'node_modules/**',
            'vendor/**',
            'storage/**',
            'bootstrap/cache/**',
        ],
    },

    js.configs.recommended,

    // `flat/recommended` is eslint-plugin-vue's vue3-recommended: the base
    // rules plus the ones that catch real template faults — missing `:key`,
    // duplicate attributes, a component used before it is defined, mutating a
    // prop. It also carries the plugin's template FORMATTING rules; the ones
    // this codebase deliberately disagrees with are turned off below, with the
    // reason, rather than by reformatting 30 components to match a default.
    ...pluginVue.configs['flat/recommended'],

    {
        files: ['**/*.js', '**/*.vue'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                // vite.config.js injects this at build time (`define:`), so it
                // is a real global in the bundle and undefined everywhere else.
                __ZXING_WASM_VERSION__: 'readonly',
            },
        },
        rules: {
            // --- Correctness, tightened ------------------------------------
            // The default `no-unused-vars` also flags an unused caught error,
            // which this codebase uses on purpose: `catch (e) { /* ignore */ }`
            // is how the storage and queue helpers survive a browser that
            // refuses IndexedDB. Arguments are only flagged after the last used
            // one, so a callback that ignores its first parameter still passes.
            'no-unused-vars': ['error', {
                args: 'after-used',
                caughtErrors: 'none',
                ignoreRestSiblings: true,
            }],

            // A bare `console.log` left in a commit ships to a phone and stays
            // there, so `log` and `debug` are errors. `warn` and `error` are
            // how this app reports a fault it decided not to throw on, and
            // `info` has exactly one deliberate use — lib/storage.js prints the
            // result of `navigator.storage.persist()`, which is the number you
            // read off a phone attached to a laptop when a queued meal has gone
            // missing. All three are reporting levels, not leftovers.
            'no-console': ['error', { allow: ['warn', 'error', 'info'] }],

            eqeqeq: ['error', 'always', { null: 'ignore' }],
            'no-var': 'error',
            'prefer-const': 'error',
            'object-shorthand': ['error', 'properties'],

            // --- Vue formatting rules this codebase disagrees with ----------
            // Every one of these is off because the existing style is
            // deliberate and consistent, not because the rule is wrong.

            // Default is one attribute per line once an element has more than
            // one. These templates put short attributes on a single line and
            // only break when the line gets long, which is what makes a
            // component readable on a laptop split-screened with the app.
            'vue/max-attributes-per-line': 'off',

            // Requires a newline between an element's tags and its content, so
            // `<span>{{ n }}</span>` becomes three lines. In a UI made largely
            // of small labelled numbers that triples the vertical size of every
            // card for no gain in clarity.
            'vue/singleline-html-element-content-newline': 'off',

            // Templates in this project are indented two spaces, not the four
            // .editorconfig sets for everything else — which is the Vue SFC
            // convention and what all 30 components already do, since a
            // template nests far deeper than the script beside it.
            //
            // THIS ONE HAS TO BE ON FOR THE TWO BELOW TO BE SAFE.
            // `vue/first-attribute-linebreak` and
            // `vue/html-closing-bracket-newline` both fix by INSERTING a line
            // break, and they ask this rule where the new line starts. With it
            // off they still fire and still fix, and the fix lands at column
            // zero: a `class=` and a bare `>` hard against the left margin.
            // That was measured, not guessed — the first run of this config
            // produced exactly that in four files.
            'vue/html-indent': ['error', 2],

            // Wants a self-closing `<div/>` for void content. Vue's compiler
            // accepts both and the DOM parser does not, so this one is a taste
            // question that only matters when it churns a diff.
            'vue/html-self-closing': 'off',

            // --- Vue correctness, kept loud --------------------------------
            'vue/no-unused-components': 'error',
            'vue/require-explicit-emits': 'error',
            'vue/no-v-html': 'error',

            // Single-word component names (`Day.vue`, `Report.vue`) are the
            // Inertia page convention: the file name IS the route the server
            // renders, so renaming them to `DayPage` would break the mapping
            // between `Inertia::render('Day')` and the component it resolves.
            'vue/multi-word-component-names': 'off',
        },
    },

    {
        // The service worker runs in ServiceWorkerGlobalScope: no `window`, no
        // `document`, but `self`, `caches`, `clients` and `skipWaiting`. Linted
        // with browser globals it is a wall of no-undef.
        files: ['resources/js/service-worker.js', 'resources/js/lib/sw.js'],
        languageOptions: {
            globals: {
                ...globals.serviceworker,
                ...globals.browser,
                // This file is NOT bundled. It is served verbatim by
                // App\Http\Controllers\Pwa\ServiceWorkerController, which
                // substitutes `__SW_PRECACHE__` with the precache list off the
                // Vite manifest before the browser ever sees it. At rest the
                // token is a free identifier, so lint has to be told it is one
                // — the alternative is a no-undef error on a line that is
                // correct, which is how a check gets switched off.
                __SW_PRECACHE__: 'readonly',
            },
        },
    },

    {
        // Inertia PAGE components take their props from the server on every
        // render — `Inertia::render('Day', [...])` is the single source, and
        // the page is never mounted by hand anywhere else. A client-side
        // default for `date` or `summary` would be a second source of truth
        // that can only ever disagree with the first, and it would hide the
        // failure it pretends to guard: a page rendered with a prop the
        // controller forgot should be loudly undefined, not quietly `{}`.
        //
        // The rule stays ON for resources/js/Components, where a component IS
        // mounted from several call sites and an omitted optional prop is a
        // genuine footgun.
        files: ['resources/js/Pages/**/*.vue'],
        rules: {
            'vue/require-default-prop': 'off',
        },
    },

    {
        // `node --test`, run by ci.sh. Node globals, and `console.log` is the
        // point rather than a leftover.
        files: ['tests/js/**/*.js'],
        languageOptions: {
            globals: { ...globals.node },
        },
        rules: {
            'no-console': 'off',
        },
    },

    {
        // Build tooling: runs in node, not in the browser.
        files: ['vite.config.js', 'eslint.config.js'],
        languageOptions: {
            globals: { ...globals.node },
        },
    },
]
