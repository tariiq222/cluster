import { useCallback, useEffect, useRef, useState } from 'react'

export type SectionState = 'loading' | 'ready' | 'empty' | 'forbidden' | 'error'

export interface SectionLoad<T> {
  state: SectionState
  data: T | null
  reload: () => void
}

export function isEmptyCollection(items: readonly unknown[] | null | undefined): boolean {
  return items === undefined || items === null || items.length === 0
}

/**
 * Loads a section payload, mapping transport errors onto the section state
 * machine. Re-runs whenever the fetcher identity, the `scopeEpoch`, or the
 * manual reload revision changes so a principal scope switch refreshes every
 * section and failed loads can be retried.
 */
export function useSectionLoad<T>(
  fetcher: () => Promise<T>,
  isEmpty: (value: T) => boolean,
  scopeEpoch: number,
): SectionLoad<T> {
  const [state, setState] = useState<SectionState>('loading')
  const [data, setData] = useState<T | null>(null)
  const [revision, setRevision] = useState(0)
  const fetcherRef = useRef(fetcher)
  fetcherRef.current = fetcher
  const isEmptyRef = useRef(isEmpty)
  isEmptyRef.current = isEmpty

  useEffect(() => {
    let cancelled = false
    setState('loading')
    fetcherRef.current()
      .then((value) => {
        if (cancelled) return
        setData(value)
        setState(isEmptyRef.current(value) ? 'empty' : 'ready')
      })
      .catch((error: unknown) => {
        if (cancelled) return
        setState(stateFromSectionError(error))
      })
    return () => {
      cancelled = true
    }
  }, [revision, scopeEpoch])

  const reload = useCallback(() => {
    setRevision((value) => value + 1)
  }, [])

  return { state, data, reload }
}

export function stateFromSectionError(error: unknown): SectionState {
  const status = (error as { status?: number } | null)?.status
  if (status === 403) return 'forbidden'
  return 'error'
}

/** Action availability is server-driven through `allowed_actions`. */
export function actionAllowed(allowedActions: readonly string[] | undefined, action: string): boolean {
  return allowedActions?.includes(action) === true
}
