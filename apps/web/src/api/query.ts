import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSessionToken } from '../app/session-context'
import { usePrincipal } from '../app/principal-context'
import { customFetch, unwrap, uuidV7 } from './http'

/* Core primitive: fetch any endpoint with auth + correlation via the shared transport. */
export function useApiQuery<T>(key: readonly unknown[], path: string, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: key,
    queryFn: async () =>
      unwrap<T>(
        await customFetch(path, {
          method: 'GET',
          headers: { Accept: 'application/json', 'X-Correlation-ID': uuidV7() },
        }),
      ),
    enabled: options?.enabled ?? true,
  })
}

export function useApiCommand<TBody, TResult>(
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  path: (variables: TBody) => string,
  options?: { idempotency?: string; invalidates?: readonly (readonly unknown[])[] },
) {
  const csrfToken = useSessionToken()
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (variables: TBody) => {
      const response = await customFetch(path(variables), {
        method,
        headers: {
          Accept: 'application/json, application/problem+json',
          'Content-Type': 'application/json',
          'X-Correlation-ID': uuidV7(),
          'X-CSRF-Token': csrfToken,
          ...(options?.idempotency ? { 'Idempotency-Key': `${options.idempotency}-${uuidV7()}` } : {}),
        },
        body: JSON.stringify(variables),
      })
      if (response.status >= 400) {
        unwrap(response)
      }
      return unwrap<TResult>(response)
    },
    onSuccess: () => {
      for (const key of options?.invalidates ?? []) {
        void queryClient.invalidateQueries({ queryKey: key })
      }
    },
  })
}

/* Scope-aware read key: scoped reads refetch when the principal scope epoch changes. */
export function useScopeEpoch(): number {
  return usePrincipal().scopeEpoch
}

export function scopeQueryKey(base: readonly unknown[]): readonly unknown[] {
  return [...base, 'scope']
}
