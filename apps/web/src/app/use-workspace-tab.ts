import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'

/*
 * URL-backed active tab for a tabbed workspace (`?tab=<value>`).
 *
 * The tab is part of the route so a form page can return the user to the
 * exact resource it came from (`/access?tab=roles`) and a deep link opens
 * the right tab after a browser refresh. Changes replace the current
 * history entry so tab switching never floods the back stack; an external
 * URL change (back/forward, a form page navigating back) is re-read and
 * applied.
 */
export function useWorkspaceTab<T extends string>(
  key: string,
  defaultValue: T,
): [T, (next: T) => void] {
  const [params, setParams] = useSearchParams()
  const [value, setValue] = useState<T>(() => (params.get(key) as T | null) ?? defaultValue)

  useEffect(() => {
    const fromUrl = params.get(key) as T | null
    if (fromUrl !== null && fromUrl !== value) setValue(fromUrl)
  }, [key, params, value])

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
