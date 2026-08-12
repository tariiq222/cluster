import { fileURLToPath, URL } from 'node:url'
import { defineConfig, loadEnv, type PluginOption } from 'vite'
import react from '@vitejs/plugin-react'
import { frontmanPlugin } from '@frontman-ai/vite'
import tailwindcss from '@tailwindcss/vite'

const LOCAL_API_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]'])

function localApiOrigin(value: string | undefined): string {
  if (!value) {
    throw new Error('W1_1_API_ORIGIN is required and must identify a pre-running localhost API.')
  }

  let origin: URL
  try {
    origin = new URL(value)
  } catch {
    throw new Error('W1_1_API_ORIGIN must be a valid localhost URL.')
  }

  if (
    !['http:', 'https:'].includes(origin.protocol)
    || !LOCAL_API_HOSTS.has(origin.hostname)
    || origin.username
    || origin.password
    || (origin.pathname !== '/' && origin.pathname !== '')
    || origin.search
    || origin.hash
  ) {
    throw new Error('W1_1_API_ORIGIN must contain only an http(s) localhost origin with no credentials, path, query, or fragment.')
  }

  return origin.origin
}

/* FRONTMAN-OPT-IN-01: the third-party `/frontman` surface served by
 * `frontmanPlugin` is a vendor remote, not a product destination. It
 * shares the dev origin, so accidentally landing on it is easy to confuse
 * with the product UI (screenshot-confusing dark/orange chrome). Default
 * development and every build omit the plugin; only an explicit
 * `FRONTMAN_ENABLED=true` invocation on `npm run dev` (or a `.env` that
 * opts the local team in) registers the plugin, and even then only in
 * `serve` mode so production bundles never reference it. The dependency
 * stays installed because intentional users keep the opt-in available. */
function frontmanEnabled(
  command: string,
  environment: Record<string, string>,
): boolean {
  if (command !== 'serve') return false
  const value = process.env.FRONTMAN_ENABLED ?? environment.FRONTMAN_ENABLED
  return value === 'true'
}

export default defineConfig(({ command, mode }) => {
  const environment = loadEnv(mode, process.cwd(), '')
  const proxyTarget = command === 'serve'
    ? localApiOrigin(process.env.W1_1_API_ORIGIN ?? environment.W1_1_API_ORIGIN)
    : undefined

  const plugins: PluginOption[] = [react(), tailwindcss()]
  if (frontmanEnabled(command, environment)) {
    plugins.unshift(frontmanPlugin({ host: 'api.frontman.sh' }))
  }

  return {
    plugins,
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      host: '127.0.0.1',
      proxy: proxyTarget
        ? {
            '/api': {
              target: proxyTarget,
              changeOrigin: false,
            },
          }
        : undefined,
    },
    /*
     * Deterministic vendor chunk split (POSTCOMMIT-WEB-WARNINGS). The
     * default Vite/Rollup output put the React core, all Radix UI
     * primitives, TanStack Query, the shared icon set, Sonner, and
     * the app shell into a single 595.97 kB entry chunk — which trips
     * the 500 kB warning on every build. Grouping node_modules by
     * package family keeps the entry chunk under the limit and lets
     * lazy screens (e.g. ReportsMonitoringScreen, which alone pulls
     * in ~250 kB of recharts) avoid paying for charts they don't use.
     *
     * The function-based form is deterministic and avoids the chunk-
     * graph cycles the static `manualChunks` object form can produce
     * when a package's path collides with a substring of another
     * package's path (for example `@radix-ui/react-direction` and the
     * bare `react/` package — every rule below is anchored to either
     * a `/node_modules/<scope>/` segment or a specific package path
     * to keep the assignments unambiguous).
     */
    build: {
      rollupOptions: {
        output: {
          manualChunks(id) {
            if (!id.includes('node_modules')) return undefined

            // Recharts is a leaf visualization library used only by
            // DashboardsScreen. Isolating it drops ReportsMonitoringScreen
            // below the threshold and keeps charts out of the entry chunk.
            if (id.includes('/recharts/')) return 'vendor-recharts'

            // Radix UI primitives — both the unified `radix-ui` package
            // and the legacy `@radix-ui/*` namespaced packages. The regex
            // form is required because `radix-ui` (no scope) is a
            // different package from `@radix-ui/*`; the leading `@` is
            // the only signal that distinguishes them on disk.
            if (
              id.includes('@radix-ui/')
              || /[/\\]node_modules[/\\]radix-ui[/\\]/.test(id)
            ) {
              return 'vendor-radix'
            }

            // TanStack Query (data fetching) and TanStack Table (headless
            // table logic) share the same `@tanstack/` scope; grouping
            // them avoids splitting the React Query internals across
            // chunks while a table screen uses them.
            if (id.includes('@tanstack/')) return 'vendor-tanstack'

            // Icons already split per file under Vite's default. Grouping
            // them under one named chunk keeps the cache key stable and
            // signals intent to readers.
            if (id.includes('/lucide-react/')) return 'vendor-icons'

            // Sonner (toast) is used by the global Toaster and LoginScreen;
            // it is a self-contained runtime, so it is a clean chunk.
            if (id.includes('/sonner/')) return 'vendor-sonner'

            // React core: `react`, `react-dom`, `react-router`,
            // `react-router-dom`, plus the React-owned helpers
            // (`scheduler`, `react-is`). The paths are anchored so
            // `@radix-ui/react-*` is never picked up here — those belong
            // to the Radix chunk above.
            if (
              /[/\\]node_modules[/\\]react[/\\]/.test(id)
              || id.includes('/react-dom/')
              || id.includes('/react-router-dom/')
              || id.includes('/react-router/')
              || id.includes('/scheduler/')
              || id.includes('/react-is/')
            ) {
              return 'vendor-react'
            }

            // Form state: `react-hook-form` and `@hookform/resolvers`.
            // LoginScreen (eager) and a handful of lazy screens share
            // these, so one chunk avoids per-route duplication.
            if (
              id.includes('/react-hook-form/')
              || id.includes('/@hookform/')
            ) {
              return 'vendor-forms'
            }

            // Catch-all for the remaining small packages (zod, clsx,
            // tailwind-merge, cmdk, class-variance-authority,
            // next-themes, tw-animate-css, …). One shared chunk avoids
            // fragmenting tiny modules into many HTTP requests without
            // pushing any of them into the hot entry chunk.
            return 'vendor-misc'
          },
        },
      },
    },
  }
})
