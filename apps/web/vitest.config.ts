import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
    exclude: ['**/node_modules/**'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'lcov'],
      reportsDirectory: './coverage',
      include: ['src/api/http.ts', 'src/api/session.ts', 'src/i18n.ts'],
      thresholds: {
        branches: 30,
        functions: 25,
        lines: 35,
        statements: 35,
      },
    },
  },
})
