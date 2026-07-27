import {
  createAuditExport as createGeneratedAuditExport,
  getAuditEvent as getGeneratedAuditEvent,
  getAuditExport as getGeneratedAuditExport,
  getDownloadAuditExportUrl,
  listAuditEvents as listGeneratedAuditEvents,
  verifyAuditIntegrity as verifyGeneratedAuditIntegrity,
  type AuditEvent,
  type AuditEventCollection,
  type AuditExportCreate,
  type AuditExportDescriptor,
  type AuditIntegrityRequest,
  type AuditIntegrityResult,
  type ListAuditEventsParams,
} from './generated/cluster'
import { requestInit, unwrap } from './http'

export type {
  AuditEvent,
  AuditEventCollection,
  AuditExportCreate,
  AuditExportDescriptor,
  AuditIntegrityRequest,
  AuditIntegrityResult,
  ListAuditEventsParams,
}

export type AuditExportDownload = {
  blob: Blob
  filename: string
}

export async function listAuditEvents(
  token: string,
  params: ListAuditEventsParams = {},
): Promise<AuditEventCollection> {
  return unwrap<AuditEventCollection>(
    await listGeneratedAuditEvents(
      { limit: 50, ...params },
      requestInit(token),
    ),
  )
}

export async function getAuditEvent(
  token: string,
  eventId: string,
): Promise<AuditEvent> {
  return unwrap<AuditEvent>(
    await getGeneratedAuditEvent(eventId, requestInit(token)),
  )
}

export async function createAuditExport(
  token: string,
  input: AuditExportCreate,
): Promise<AuditExportDescriptor> {
  return unwrap<AuditExportDescriptor>(
    await createGeneratedAuditExport(
      input,
      requestInit(token, { command: true, idempotency: 'audit-export' }),
    ),
  )
}

export async function getAuditExport(
  token: string,
  exportId: string,
): Promise<AuditExportDescriptor> {
  return unwrap<AuditExportDescriptor>(
    await getGeneratedAuditExport(exportId, requestInit(token)),
  )
}

export async function downloadAuditExport(
  token: string,
  exportId: string,
): Promise<AuditExportDownload> {
  const response = await fetch(getDownloadAuditExportUrl(exportId), {
    ...requestInit(token, {
      headers: {
        Accept: 'text/csv, application/x-ndjson, application/problem+json',
      },
    }),
    method: 'GET',
  })

  if (!response.ok) {
    let problem: unknown = {}
    try {
      problem = await response.json()
    } catch {
      // `unwrap` supplies a metadata-safe generic problem when the body is not JSON.
    }
    unwrap({
      status: response.status,
      data: problem,
      headers: response.headers,
    })
  }

  const disposition = response.headers.get('Content-Disposition')
  const encodedName = disposition?.match(/filename\*=UTF-8''([^;]+)/i)?.[1]
  const quotedName = disposition?.match(/filename="([^"]+)"/i)?.[1]
  const extension = response.headers.get('Content-Type')?.includes('ndjson')
    ? 'ndjson'
    : 'csv'
  const filename = encodedName
    ? decodeURIComponent(encodedName)
    : (quotedName ?? `audit-export-${exportId}.${extension}`)

  return { blob: await response.blob(), filename }
}

export async function verifyAuditIntegrity(
  token: string,
  input: AuditIntegrityRequest,
): Promise<AuditIntegrityResult> {
  return unwrap<AuditIntegrityResult>(
    await verifyGeneratedAuditIntegrity(
      input,
      requestInit(token, {
        command: true,
        idempotency: 'audit-integrity-verify',
      }),
    ),
  )
}
