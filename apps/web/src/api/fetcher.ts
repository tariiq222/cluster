/**
 * The single transport for every generated API call.
 *
 * Orval is configured to route all operations through this mutator, so cookie
 * credentials and non-JSON error bodies are handled in exactly one place. A proxy
 * returning an HTML 503 must still surface as a typed problem response rather than
 * a `SyntaxError` from `JSON.parse`, which is why the body is parsed defensively.
 */
export type FetchedResponse = {
  data: unknown
  status: number
  headers: Headers
}

const EMPTY_BODY_STATUSES = [204, 205, 304]

export const customFetch = async <T>(url: string, options: RequestInit): Promise<T> => {
  const response = await fetch(url, { credentials: 'include', ...options })

  const body = EMPTY_BODY_STATUSES.includes(response.status) ? null : await response.text()

  let data: unknown = {}
  if (body) {
    try {
      data = JSON.parse(body)
    } catch {
      // Not a JSON body (proxy error page, truncated response). `unwrap` turns this
      // into a metadata-safe problem using the status alone.
      data = {}
    }
  }

  return { data, status: response.status, headers: response.headers } as T
}
