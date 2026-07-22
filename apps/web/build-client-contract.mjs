/**
 * Builds the single client-surface contract that Orval generates from.
 *
 * The governed contracts are split per milestone (W1.1, W1.2, R1 screens) and all
 * $ref the master `openapi.yaml`. Generating one client per milestone produced the
 * same operation two or three times over. This merges the bundles into one surface:
 * the master supplies every documented path, and a milestone declaration overrides it
 * when the milestone models the route the backend actually serves (for example the
 * templated `/work-records/{recordId}/{recordAction}` lifecycle route).
 */
import { readFile, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'

import { parse, stringify } from 'yaml'

const bundleDir = new URL('./.orval/', import.meta.url)

// Ordered lowest to highest precedence: later entries override earlier ones.
const sources = [
  'cluster-master.openapi.yaml',
  'cluster.openapi.yaml',
  'cluster-w1-2.openapi.yaml',
  'cluster-r1-screens.openapi.yaml',
]

async function readBundle(name) {
  const contents = await readFile(new URL(name, bundleDir), 'utf8')
  return parse(contents)
}

const bundles = await Promise.all(sources.map(readBundle))
const [master] = bundles

const merged = {
  ...master,
  info: {
    ...master.info,
    title: 'Cluster Platform Client Surface',
    description:
      'Generated union of the governed milestone contracts. Do not edit; run npm run api:bundle.',
  },
  paths: {},
  components: { ...master.components },
}

const overrides = []

for (const bundle of bundles) {
  for (const [path, definition] of Object.entries(bundle.paths ?? {})) {
    if (merged.paths[path] && bundle !== master) overrides.push(path)
    merged.paths[path] = definition
  }
  for (const [group, entries] of Object.entries(bundle.components ?? {})) {
    merged.components[group] = { ...entries, ...(merged.components[group] ?? {}) }
  }
}

const operationIds = new Set()
const duplicates = new Set()
for (const definition of Object.values(merged.paths)) {
  for (const [method, operation] of Object.entries(definition)) {
    if (method === 'parameters' || method === '$ref') continue
    const id = operation?.operationId
    if (!id) continue
    if (operationIds.has(id)) duplicates.add(id)
    operationIds.add(id)
  }
}

if (duplicates.size > 0) {
  console.error(
    `Client contract has duplicate operationIds across paths: ${[...duplicates].join(', ')}`,
  )
  process.exit(1)
}

const target = new URL('cluster-client.openapi.yaml', bundleDir)
await writeFile(target, stringify(merged, { lineWidth: 0 }), 'utf8')

console.log(
  `Client surface contract: ${Object.keys(merged.paths).length} paths, ${operationIds.size} operations` +
    (overrides.length > 0 ? ` (milestone overrides: ${overrides.join(', ')})` : ''),
)
console.log(`Wrote ${fileURLToPath(target)}`)
