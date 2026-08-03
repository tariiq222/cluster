import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'

/*
 * URL-backed active tab for a tabbed workspace (`?tab=<value>`).
 *
 * The tab is part of the route so a form page can return the user to the
 * exact resource it came from (`/access?tab=roles`) and a deep link opens
 * the right tab after a browser refresh. Changes replace the current
 * history entry so tab switching never floods the back stack; an external
 * URL change (back/forward, a form page navigating back, a redirect that
 * drops or rewrites the param) is re-read and applied.
 *
 * The hook normalizes the value to the supplied default when:
 *
 *  - the param is absent (e.g. `?tab=` was stripped by a redirect or by
 *    a user typing the bare path),
 *  - or the value is rejected by the optional `isValid` predicate, so a
 *    stale or attacker-controlled value cannot pin the tab to a key the
 *    screen no longer renders.
 *
 * Caller-driven changes (the second tuple element) skip the validator
 * because the call site is already strongly typed: passing an invalid
 * value would be a compile-time error at the call site, not a runtime
 * concern that the hook has to guard.
 */
export function useWorkspaceTab<T extends string>(
  key: string,
  defaultValue: T,
  isValid?: (value: string) => value is T,
): [T, (next: T) => void] {
  const [params, setParams] = useSearchParams()
  const [value, setValue] = useState<T>(() => normalize(params, key, defaultValue, isValid))

  useEffect(() => {
    const next = normalize(params, key, defaultValue, isValid)
    if (next !== value) setValue(next)
  }, [key, params, value, defaultValue, isValid])

  const change = useCallback(
    (next: T) => {
      setValue(next)
      setParams(
        (current) => {
          const copy = new URLSearchParams(current)
          copy.set(key, next)
          return copy
        },
        { replace: true },
      )
    },
    [key, setParams],
  )

  return [value, change]
}

function normalize<T extends string>(
  params: URLSearchParams,
  key: string,
  defaultValue: T,
  isValid: ((value: string) => value is T) | undefined,
): T {
  const fromUrl = params.get(key)
  if (fromUrl === null) return defaultValue
  if (isValid && !isValid(fromUrl)) return defaultValue
  return fromUrl as T
}
