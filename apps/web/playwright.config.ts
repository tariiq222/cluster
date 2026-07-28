import { defineConfig } from '@playwright/test'

const LOCAL_API_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]'])
const webPort = process.env.W1_1_WEB_PORT ?? '4173'
if (!/^\d{2,5}$/.test(webPort) || Number(webPort) < 1024 || Number(webPort) > 65535) {
  throw new Error('W1_1_WEB_PORT must be a localhost TCP port between 1024 and 65535.')
}
const WEB_ORIGIN = `http://127.0.0.1:${webPort}`
const discoveryOnly = process.argv.includes('--list')

function validatedApiOrigin(value: string | undefined): string {
  if (!value) {
    if (discoveryOnly) {
      return 'http://127.0.0.1:8000'
    }

    throw new Error('W1_1_API_ORIGIN is required to run browser tests against a pre-running localhost API.')
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

const apiOrigin = validatedApiOrigin(process.env.W1_1_API_ORIGIN)

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: true,
  retries: 0,
  workers: 1,
  reporter: 'list',
  expect: {
    // Cold Vite dev servers on CI runners take well over the 5s default to
    // compile the first page load; give assertions CI headroom.
    timeout: 15_000,
  },
  use: {
    baseURL: WEB_ORIGIN,
    locale: 'ar-SA',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: `npm run dev -- --port ${webPort} --strictPort`,
    url: WEB_ORIGIN,
    reuseExistingServer: true,
    timeout: 30_000,
    env: {
      W1_1_API_ORIGIN: apiOrigin,
    },
  },
})
