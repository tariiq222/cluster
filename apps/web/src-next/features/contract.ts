// Feature screen shared contract for the rebuild.
// Every feature screen lives at src-next/features/<feature>/<Name>Screen.tsx
// and receives ScreenProps from the shell.

export interface ScreenProps {
  navigate: (path: string) => void
}

// Screen loading states shared across features.
export type ScreenState = 'loading' | 'ready' | 'empty' | 'forbidden' | 'not-found' | 'conflict' | 'stale' | 'error'

export function stateFrom(error: unknown): ScreenState {
  const status = (error as { status?: number })?.status
  switch (status) {
    case 403: return 'forbidden'
    case 404: return 'not-found'
    case 409: return 'conflict'
    case 412: return 'stale'
    default: return 'error'
  }
}
