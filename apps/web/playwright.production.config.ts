import { defineConfig } from '@playwright/test'

const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]'])

function validatedWebOrigin(value: string | undefined): string {
  if (!value) {
    throw new Error('W1_1_WEB_ORIGIN is required for the production-bundle browser tests.')
  }

  let origin: URL
  try {
    origin = new URL(value)
  } catch {
    throw new Error('W1_1_WEB_ORIGIN must be a valid localhost URL.')
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
    throw new Error('W1_1_WEB_ORIGIN must contain only an http(s) localhost origin with no credentials, path, query, or fragment.')
  }

  return origin.origin
}

const webOrigin = validatedWebOrigin(process.env.W1_1_WEB_ORIGIN)

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: true,
  retries: 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: webOrigin,
    ignoreHTTPSErrors: process.env.W1_1_ALLOW_SELF_SIGNED === '1',
    locale: 'ar-SA',
    trace: 'retain-on-failure',
  },
})
