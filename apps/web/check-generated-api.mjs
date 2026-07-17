import { spawnSync } from 'node:child_process'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'

const projectRoot = fileURLToPath(new URL('.', import.meta.url))
const generatedClient = new URL('./src/api/generated/cluster.ts', import.meta.url)

let before
try {
  before = await readFile(generatedClient)
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

const after = await readFile(generatedClient)
if (!before.equals(after)) {
  console.error('Generated API client was stale; commit the regenerated output.')
  process.exit(1)
}

console.log('Generated API client matches the OpenAPI contract.')
