import { ApiError } from '../../api'

/**
 * Surface the server-provided problem detail when it fits, otherwise fall back
 * to a localized message. Caps the detail at 500 characters so a runaway
 * payload cannot flood the screen.
 */
export const PROBLEM_DETAIL_LIMIT = 500

export function problemMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const detail = error.problem.detail?.trim()
    if (detail) {
      return detail.length > PROBLEM_DETAIL_LIMIT ? `${detail.slice(0, PROBLEM_DETAIL_LIMIT - 1)}…` : detail
    }
    const title = error.problem.title?.trim()
    if (title) return title
  }
  return fallback
}