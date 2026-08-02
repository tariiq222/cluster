#!/usr/bin/env node
/*
 * Sheet surface guard (DESIGN-RULES §2.6).
 *
 * Business create/edit/manage/detail flows must be full pages; the shared
 * `components/ui/sheet` primitive is reserved for the two functional shell
 * surfaces (mobile navigation and contextual help) plus the generic
 * sidebar's own mobile branch. This check fails the build when any other
 * production file imports the primitive, so a reintroduced business Sheet
 * cannot silently ship.
 *
 * Usage: node check-no-business-sheets.mjs
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = fileURLToPath(new URL('.', import.meta.url))
const SRC = join(ROOT, 'src')

/*
 * The only production files allowed to import the Sheet primitive:
 * shell chrome (mobile navigation, generic sidebar mobile branch) and
 * the contextual help surface. The primitive's own file is its source,
 * not an import, and is exempt by construction.
 */
const ALLOWED = new Set([
  join(SRC, 'components/app-sidebar.tsx'),
  join(SRC, 'components/contextual-help.tsx'),
  join(SRC, 'components/ui/sidebar.tsx'),
])

const IMPORT_PATTERNS = [
  /from\s+['"]@\/components\/ui\/sheet['"]/,
  /from\s+['"][^'"]*\/components\/ui\/sheet['"]/,
]

function walk(dir, out = []) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    if (statSync(full).isDirectory()) {
      walk(full, out)
    } else if (full.endsWith('.ts') || full.endsWith('.tsx')) {
      out.push(full)
    }
  }
  return out
}

const offenders = []
for (const file of walk(SRC)) {
  const rel = relative(ROOT, file)
  if (rel.includes('.test.')) continue
  if (ALLOWED.has(file)) continue
  const body = readFileSync(file, 'utf-8')
  if (IMPORT_PATTERNS.some((pattern) => pattern.test(body))) {
    offenders.push(rel)
  }
}

if (offenders.length > 0) {
  console.error('Sheet guard: business files must not import components/ui/sheet:')
  for (const file of offenders) console.error(`  - ${file}`)
  console.error('Full-page routes are required for business forms (DESIGN-RULES §2.6).')
  process.exit(1)
}

console.log('Sheet guard: ok — only shell surfaces may use the Sheet primitive.')
