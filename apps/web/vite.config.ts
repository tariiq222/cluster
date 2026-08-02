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
  }
})
