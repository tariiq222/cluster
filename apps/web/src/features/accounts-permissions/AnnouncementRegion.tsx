import { forwardRef, useImperativeHandle, useRef, useState } from 'react'
import { directionForLocale, type Locale } from '../../app/copy'
import { accountsPermissionsText, type AnnouncementKey } from './copy'

export type AnnouncementRegionHandle = { announce: (variant: AnnouncementKey, message?: string) => void; announceError: (message: string) => void }
type AnnouncementRegionProps = { locale: Locale }

export const AnnouncementRegion = forwardRef<AnnouncementRegionHandle, AnnouncementRegionProps>(function AnnouncementRegion({ locale }, ref) {
  const [announcement, setAnnouncement] = useState({ message: '', sequence: 0 })
  const sequence = useRef(0)
  const outputRef = useRef<HTMLOutputElement>(null)
  useImperativeHandle(ref, () => ({
    announce: (variant, message) => {
      sequence.current += 1
      setAnnouncement({ message: message || accountsPermissionsText(locale).announcements[variant], sequence: sequence.current })
    },
    announceError: (message) => {
      sequence.current += 1
      setAnnouncement({ message, sequence: sequence.current })
      outputRef.current?.focus()
    },
  }), [locale])
  return <output ref={outputRef} tabIndex={-1} role="status" aria-live="polite" aria-atomic="true" dir={directionForLocale(locale)} data-announcement-sequence={announcement.sequence}>{announcement.message}</output>
})