/**
 * Live-region feedback shared by every role/assignment/policy mutation. The
 * provider mounts the `AnnouncementRegion` and exposes a hook that mutation
 * handlers call after success or a 412/version conflict. The live region
 * never renders visible UI; it only emits `aria-live=polite` text on
 * announcements and refocuses itself on errors.
 */
import { createContext, useContext, useMemo, type ReactNode } from 'react'

import { AnnouncementRegion, type AnnouncementRegionHandle } from './AnnouncementRegion'
import type { Locale } from '../../app/copy'

export type AuthorizationMutationAnnounce = {
  announce: AnnouncementRegionHandle['announce']
  announceError: AnnouncementRegionHandle['announceError']
}

const noopAnnounce: AuthorizationMutationAnnounce = {
  announce: () => {},
  announceError: () => {},
}

const AuthorizationMutationFeedbackContext = createContext<AuthorizationMutationAnnounce>(noopAnnounce)

export function AuthorizationMutationFeedbackProvider({
  locale,
  regionRef,
  children,
}: {
  locale: Locale
  regionRef: React.RefObject<AnnouncementRegionHandle | null>
  children: ReactNode
}) {
  const value = useMemo<AuthorizationMutationAnnounce>(() => {
    return {
      announce: (key, message) => regionRef.current?.announce(key, message),
      announceError: (message) => regionRef.current?.announceError(message),
    }
  }, [regionRef])
  return (
    <AuthorizationMutationFeedbackContext.Provider value={value}>
      <AnnouncementRegion ref={regionRef} locale={locale} />
      {children}
    </AuthorizationMutationFeedbackContext.Provider>
  )
}

export function useAuthorizationMutationFeedback(): AuthorizationMutationAnnounce {
  return useContext(AuthorizationMutationFeedbackContext)
}
