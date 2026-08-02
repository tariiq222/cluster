import { ApiError, requestInit } from './http'

/*
 * Raw artifact downloads keep their fetch/header/blob handling out of the
 * screens. The generated clients route every call through the JSON transport,
 * which cannot stream a file, so the download flows use fetch directly with
 * `requestInit` for the correlation/CSRF headers.
 */

async function downloadBlob(
  url: string,
  csrfToken: string,
  fallbackName: string,
  accept: string,
): Promise<string> {
  const response = await fetch(url, {
    credentials: 'include',
    headers: {
      ...requestInit(csrfToken).headers,
      Accept: accept,
    },
  })
  if (!response.ok) {
    let problem: { type?: string; title?: string; status?: number } | null = null
    try {
      problem = (await response.json()) as { type?: string; title?: string; status?: number }
    } catch {
      problem = null
    }
    throw new ApiError(response.status, {
      type: typeof problem?.type === 'string' && problem.type !== '' ? problem.type : 'about:blank',
      title: typeof problem?.title === 'string' && problem.title !== '' ? problem.title : 'Export download failed',
      status: response.status,
    })
  }
  const disposition = response.headers.get('Content-Disposition') ?? ''
  const match = /filename="?([^";]+)"?/.exec(disposition)
  const filename = match?.[1] ?? fallbackName
  const blob = await response.blob()
  const objectUrl = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = objectUrl
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(objectUrl)
  return filename
}

export function downloadReportExport(
  exportId: string,
  format: 'csv' | 'json',
  csrfToken: string,
  fallbackName: string,
): Promise<string> {
  const accept = format === 'csv' ? 'text/csv' : 'application/json'
  return downloadBlob(`/api/v1/exports/${encodeURIComponent(exportId)}`, csrfToken, fallbackName, accept)
}
