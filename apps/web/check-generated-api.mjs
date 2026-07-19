import { spawnSync } from 'node:child_process'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'

const projectRoot = fileURLToPath(new URL('.', import.meta.url))
const generatedClients = [
  new URL('./src/api/generated/cluster.ts', import.meta.url),
  new URL('./src/api/generated/w1-2.ts', import.meta.url),
  new URL('./src/api/generated/r1-screens.ts', import.meta.url),
]

const before = new Map()
try {
  for (const generatedClient of generatedClients) {
    before.set(generatedClient.href, await readFile(generatedClient))
  }
} catch (error) {
  if (error && typeof error === 'object' && 'code' in error && error.code === 'ENOENT') {
    console.error('Generated API client is missing; run npm run api:generate and commit the result.')
    process.exit(1)
  }
  throw error
}

const generation = spawnSync('npm', ['run', 'api:generate'], {
  cwd: projectRoot,
  stdio: 'inherit',
  shell: process.platform === 'win32',
})
if (generation.status !== 0) {
  process.exit(generation.status ?? 1)
}

for (const generatedClient of generatedClients) {
  const after = await readFile(generatedClient)
  if (!before.get(generatedClient.href)?.equals(after)) {
    console.error('Generated API clients were stale; commit the regenerated output.')
    process.exit(1)
  }
}

console.log('Generated API clients match the W1.1, W1.2, and complete R1 screen contracts.')
