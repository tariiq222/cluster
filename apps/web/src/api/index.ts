/**
 * The application's API surface.
 *
 * Every call goes through the single Orval-generated client in `./generated/cluster.ts`,
 * wrapped per domain below. There is no hand-written HTTP client and no `fetch` in screens;
 * the one exception is the pre-signed storage upload in `./documents.ts`, which targets the
 * storage host rather than the platform API.
 */
export {
  ApiError,
  isRetryable,
  registerSessionExpiredHandler,
  stateFromError,
  parseStrongEtag,
  requestInit,
  unwrap,
  unwrapEmpty,
  unwrapEnvelope,
  unwrapWithEtag,
  uuidV7,
  type ProblemDetails,
  type ProblemFieldError,
  type RequestOptions,
  type ResourceState,
} from './http'

export * from './session'
export * from './organization'
export * from './identity'
export * from './documents'
export * from './work-records'
