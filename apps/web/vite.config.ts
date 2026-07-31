import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import { frontmanPlugin } from '@frontman-ai/vite'

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

export default defineConfig(({ command, mode }) => {
  const environment = loadEnv(mode, process.cwd(), '')
  const proxyTarget = command === 'serve'
    ? localApiOrigin(process.env.W1_1_API_ORIGIN ?? environment.W1_1_API_ORIGIN)
    : undefined

  return {
    plugins: [frontmanPlugin({ host: 'api.frontman.sh' }), react()],
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
