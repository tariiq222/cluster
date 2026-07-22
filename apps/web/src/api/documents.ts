import * as generated from './generated/cluster'
import type {
  CompleteDocumentUploadBody,
  DocumentUploadCompletion,
  DocumentUploadInitiateRequest,
  DocumentUploadInitiated,
  DocumentUploadStatus,
  DocumentCreate,
  DocumentPatch,
  DocumentVersionCreate,
  DocumentLinkCreate,
  DocumentGrantRequest,
  ReasonAction,
  ListDocumentsParams,
  ListDocumentVersionsParams,
  ListDocumentLinksParams,
  Entity,
  EntityCollection,
} from './generated/cluster'
import { ApiError, requestInit, unwrap } from './http'

export type InitiateDocumentUploadInput = DocumentUploadInitiateRequest
export type CompleteDocumentUploadInput = CompleteDocumentUploadBody
export type DocumentUploadIntent = DocumentUploadInitiated
export type DocumentUploadState = DocumentUploadStatus
export type DocumentUploadCompletionResult = DocumentUploadCompletion
export type DocumentRecord = Entity
export type DocumentCollection = EntityCollection

export async function listDocumentRecords(params: ListDocumentsParams = {}): Promise<DocumentCollection> {
  return unwrap<DocumentCollection>(await generated.listDocuments(params, requestInit()))
}
export async function createDocumentRecord(csrfToken: string, input: DocumentCreate): Promise<DocumentRecord> {
  return unwrap<DocumentRecord>(await generated.createDocument(input, requestInit(csrfToken, { command: true, idempotency: 'document-create' })))
}
export async function getDocumentRecord(documentId: string): Promise<DocumentRecord> {
  return unwrap<DocumentRecord>(await generated.getDocument(documentId, requestInit()))
}
export async function updateDocumentRecord(csrfToken: string, documentId: string, input: DocumentPatch, lockVersion?: number): Promise<DocumentRecord> {
  return unwrap<DocumentRecord>(await generated.updateDocument(documentId, input, requestInit(csrfToken, { mutation: true, lockVersion })))
}
export async function listDocumentRecordVersions(documentId: string, params: ListDocumentVersionsParams = {}): Promise<DocumentCollection> {
  return unwrap<DocumentCollection>(await generated.listDocumentVersions(documentId, params, requestInit()))
}
export async function addDocumentRecordVersion(csrfToken: string, documentId: string, input: DocumentVersionCreate): Promise<DocumentUploadIntent> {
  return unwrap<DocumentUploadIntent>(await generated.addDocumentVersion(documentId, input, requestInit(csrfToken, { command: true, idempotency: 'document-version' })))
}
export async function listDocumentRecordLinks(documentId: string, params: ListDocumentLinksParams = {}): Promise<DocumentCollection> {
  return unwrap<DocumentCollection>(await generated.listDocumentLinks(documentId, params, requestInit()))
}
export async function linkDocumentRecord(csrfToken: string, documentId: string, input: DocumentLinkCreate, lockVersion?: number): Promise<DocumentRecord> {
  return unwrap<DocumentRecord>(await generated.linkDocument(documentId, input, requestInit(csrfToken, { command: true, idempotency: 'document-link', lockVersion })))
}
export async function transitionDocumentRecord(csrfToken: string, documentId: string, action: 'archive' | 'place-hold' | 'release-hold', input: ReasonAction, lockVersion?: number): Promise<DocumentRecord> {
  return unwrap<DocumentRecord>(await generated.transitionDocument(documentId, action, input, requestInit(csrfToken, { command: true, idempotency: `document-${action}`, lockVersion })))
}
export async function grantDocumentAccess(csrfToken: string, documentId: string, grantType: 'preview' | 'download', input: DocumentGrantRequest): Promise<DocumentRecord> {
  return unwrap<DocumentRecord>(await generated.createDocumentAccessGrant(documentId, grantType, input, requestInit(csrfToken, { command: true, idempotency: 'document-grant' })))
}

export async function sha256ForFile(file: File): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer())
  return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('')
}

export async function initiateDocumentUpload(
  csrfToken: string,
  input: InitiateDocumentUploadInput,
): Promise<DocumentUploadIntent> {
  return unwrap<DocumentUploadIntent>(
    await generated.initiateDocumentUpload(input, requestInit(csrfToken, { command: true, idempotency: 'document-upload' })),
  )
}

export async function getDocumentUploadStatus(uploadId: string): Promise<DocumentUploadState> {
  return unwrap<DocumentUploadState>(
    await generated.getDocumentUploadStatus(uploadId, requestInit()),
  )
}

export async function completeDocumentUpload(
  csrfToken: string,
  uploadId: string,
  input: CompleteDocumentUploadInput,
): Promise<DocumentUploadCompletionResult> {
  return unwrap<DocumentUploadCompletionResult>(
    await generated.completeDocumentUpload(
      uploadId,
      input,
      requestInit(csrfToken, { command: true, idempotency: 'document-upload-complete' }),
    ),
  )
}

/**
 * Streams the file to the storage ticket returned by `initiateDocumentUpload`.
 *
 * This is the one request in the app that does not go through a generated client:
 * the target is a pre-signed URL on the storage host, not a platform endpoint, so
 * it carries no session credentials and is deliberately isolated here.
 */
export async function putUploadTicket(
  uploadUrl: string,
  file: Blob,
  headers: Record<string, string> = {},
  method = 'PUT',
): Promise<void> {
  const response = await fetch(uploadUrl, { method, body: file, headers })
  if (!response.ok) {
    throw new ApiError(response.status, {
      type: 'about:blank',
      title: 'Upload to storage failed',
      status: response.status,
    })
  }
}
