import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { renderBootFailure, startAtTopOnReload } from './lib/boot'
import { installInertiaGuard } from './lib/inertia-guard'
import { installErrorReporting, reportClientError, setPageComponent } from './lib/report'
import { registerServiceWorker } from './lib/sw'
import { startQueue } from './lib/queue'
import { ensurePersistentStorage } from './lib/storage'

/*
 * BEFORE ANYTHING ELSE, AND IN THIS ORDER.
 *
 * `installErrorReporting` is first: it is the only thing that can describe a
 * failure in the lines below it. This app draws every pixel from an empty
 * `#app`, so a startup throw is a blank white screen with a 200 for the HTML, a
 * 200 for the bundle and no other symptom; the handlers turn that into a row in
 * `client_errors` with a stack and a build hash.
 *
 * `startAtTopOnReload` must precede `createInertiaApp`, which reads
 * `history.state` and on a reload scrolls the document back one animation frame
 * after the component swap — before the photographs and charts have laid out.
 * An out-of-range programmatic scroll during load is how iOS WebKit ends up
 * showing an unpainted white rectangle instead of the app; see lib/boot.js.
 */
installErrorReporting()

/*
 * `installInertiaGuard` must also precede `createInertiaApp`: it registers an
 * axios response interceptor, and one added after the router has issued a
 * request does not apply to that request. It stands between this app and its
 * one recurring client-side fault — a response carrying the `x-inertia` header
 * whose body did not arrive, which @inertiajs/core reads as a page object and
 * dereferences, producing the unhandled rejection
 * `undefined is not an object (evaluating 'e.toString')` and a tap that
 * silently does nothing. See lib/inertia-guard.js for the derivation from the
 * three `client_errors` rows.
 */
installInertiaGuard()

startAtTopOnReload()

/**
 * Inertia entry point. Pages resolve eagerly (`eager: true`): there are three
 * of them and the whole bundle is a few tens of kilobytes, so shipping all
 * three up front beats a network round trip per page on a phone with a
 * lift-shaft signal. Revisit if this ever grows a photo editor.
 */
createInertiaApp({
  title: (title) => (title ? `${title} · Health` : 'Health'),

  resolve: (name) => {
    /*
     * WHICH SCREEN A REPORT BELONGS TO IS DECIDED HERE, AND NOT ON `navigate`.
     * `inertia:navigate` fires one microtask after Vue has mounted — and
     * possibly already crashed in — the new page, and is skipped entirely on a
     * replace visit, so the event alone files a first-render crash under the
     * page BEFORE it (`client_errors` row 7). The listener in
     * lib/inertia-guard.js stays as the backstop, because this runs on the page
     * that is only ABOUT to be shown.
     * See docs/rationale-frontend.md § "Page attribution for error reports"
     */
    setPageComponent(name)

    const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })

    return pages[`./Pages/${name}.vue`]
  },

  setup({ el, App, props, plugin }) {
    /*
     * The first component name, before any `inertia:navigate` has fired.
     * Without it the reports from the very first page — where a boot failure
     * lives — would be the only ones unable to say which screen was on show.
     */
    setPageComponent(props.initialPage?.component ?? null)

    /*
     * THE MOUNT IS INSIDE A try/catch, AND THAT IS THE WHOLE ERROR BOUNDARY.
     * Nothing is above it — no server-rendered HTML underneath, no retry, no
     * framework-level boundary — so if `mount()` throws, `#app` is empty and
     * stays empty, the symptom this branch was opened for. Report, then draw a
     * sentence and a link where the app should have been.
     */
    try {
      const app = createApp({ render: () => h(App, props) })

      /*
       * Errors thrown during render, in a watcher, or in a lifecycle hook: Vue
       * logs them and carries on, leaving the screen wrong and nothing knowing.
       * `info` is Vue's own "render function"/"setup function", worth more than
       * a minified stack, so it rides as `source`. Set BEFORE mount — the first
       * render is likeliest to throw and looks exactly like a blank page.
       */
      app.config.errorHandler = (error, instance, info) => {
        reportClientError('vue', error, { source: info })

        // Still logged, so a developer with a console open need not read the
        // database to find out what happened.
        console.error('[health] vue error:', info, error)
      }

      app.use(plugin).mount(el)
    } catch (error) {
      reportClientError('vue', error, { source: 'mount' })

      renderBootFailure(el)

      // The PWA layer below is not worth starting on top of an app that
      // did not start.
      return
    }

    /*
     * The PWA layer (step 8), started AFTER the mount so none of it can delay
     * first paint. The service worker registers itself on `load`, later still;
     * the queue reads IndexedDB and flushes anything waiting (on a normal
     * launch, an empty store and one microtask); and `storage.persist()` is
     * asked for ONLY when somebody is signed in, for the reasons in
     * lib/storage.js.
     */
    registerServiceWorker()

    startQueue()

    if (props.initialPage?.props?.auth?.user) {
      ensurePersistentStorage()
    }
  },

  // A thin progress bar, so a day-swipe on a slow connection looks like it is
  // doing something.
  progress: {
    color: '#0d9488',
    showSpinner: false,
  },
}).catch((error) => {
  /*
   * `createInertiaApp` is async and can reject BEFORE `setup` ever runs: a
   * malformed `data-page` attribute, a component that fails to resolve, or a
   * chunk that 404s because a deploy replaced the build this document names —
   * the leading theory for the original report, and until this line a
   * completely silent blank page.
   */
  reportClientError('error', error, { source: 'createInertiaApp' })

  renderBootFailure(document.getElementById('app'))
})
