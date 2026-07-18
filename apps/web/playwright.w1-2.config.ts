import { defineConfig } from '@playwright/test'

const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]'])

function validatedWebOrigin(value: string | undefined): string {
  if (!value) {
    throw new Error('W1_2_WEB_ORIGIN is required to run W1.2 browser tests through a local origin.')
  }

  let origin: URL
  try {
    origin = new URL(value)
  } catch {
    throw new Error('W1_2_WEB_ORIGIN must be a valid local HTTP(S) URL.')
  }

  if (
    !['http:', 'https:'].includes(origin.protocol)
    || !LOCAL_HOSTS.has(origin.hostname)
    || origin.username
    || origin.password
    || (origin.pathname !== '/' && origin.pathname !== '')
    || origin.search
    || origin.hash
  ) {
    throw new Error('W1_2_WEB_ORIGIN must contain only an HTTP(S) localhost origin with no credentials, path, query, or fragment.')
  }

  return origin.origin
}

export default defineConfig({
  testDir: './e2e',
  testMatch: 'w1-2-cookie-csrf.spec.ts',
  fullyParallel: false,
  forbidOnly: true,
  retries: 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: validatedWebOrigin(process.env.W1_2_WEB_ORIGIN),
    ignoreHTTPSErrors: process.env.W1_2_ALLOW_SELF_SIGNED === '1',
    locale: 'ar-SA',
    trace: 'retain-on-failure',
  },
})
