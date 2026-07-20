import { type FormEvent, useEffect, useRef, useState } from 'react'
import { ApiError, createWorkRecord, type WorkRecord } from '../../api'
import { text, type Locale } from '../../app/copy'

export function RequestForm({ locale, token, onSessionExpired, onCreated, onBack }: {
  locale: Locale
  token: string
  onSessionExpired: () => void
  onCreated: (record: WorkRecord) => void
  onBack: () => void
}) {
  const copy = text[locale]
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [errors, setErrors] = useState<{ title?: boolean; description?: boolean }>({})
  const [formError, setFormError] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [created, setCreated] = useState<WorkRecord | null>(null)
  const summaryRef = useRef<HTMLDivElement>(null)
  const successRef = useRef<HTMLHeadingElement>(null)

  useEffect(() => {
    if (created) successRef.current?.focus()
  }, [created])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const nextErrors = {
      title: title.trim() ? undefined : true,
      description: description.trim() ? undefined : true,
    }
    setErrors(nextErrors)
    setFormError(false)
    if (nextErrors.title || nextErrors.description) {
      window.requestAnimationFrame(() => summaryRef.current?.focus())
      return
    }
    setSubmitting(true)
    try {
      const record = await createWorkRecord(token, {
        work_definition_code: 'request',
        title: title.trim(),
        description: description.trim(),
      })
      setCreated(record)
      onCreated(record)
      window.requestAnimationFrame(() => successRef.current?.focus())
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        onSessionExpired()
        return
      }
      if (error instanceof ApiError && error.problem.errors?.length) {
        const fieldErrors: { title?: boolean; description?: boolean } = {}
        for (const fieldError of error.problem.errors) {
          if (fieldError.pointer.endsWith('/title')) fieldErrors.title = true
          if (fieldError.pointer.endsWith('/description')) fieldErrors.description = true
        }
        setErrors(fieldErrors)
      }
      setFormError(true)
      window.requestAnimationFrame(() => summaryRef.current?.focus())
    } finally {
      setSubmitting(false)
    }
  }

  if (created) {
    return (
      <section className="success-panel" aria-labelledby="success-heading" aria-live="polite">
        <h1 id="success-heading" ref={successRef} tabIndex={-1}>{copy.success}</h1>
        <p className="submitted-title">{created.payload.title}</p>
        <p>{copy.successBody}</p>
        <a href="/" className="primary-link" onClick={(event) => { event.preventDefault(); onBack() }}>{copy.backToRequests}</a>
      </section>
    )
  }

  return (
    <section className="request-form-section" aria-labelledby="new-request-heading">
      <h1 id="new-request-heading">{locale === 'ar' ? 'إرسال طلب جديد' : 'Submit a new request'}</h1>
      {(formError || errors.title || errors.description) && (
        <div className="error-summary" role="alert" tabIndex={-1} ref={summaryRef}>
          <strong>{copy.validationError}</strong>
          {formError && <p>{copy.submitError}</p>}
        </div>
      )}
      <form onSubmit={(event) => void submit(event)} noValidate>
        <div className="field">
          <label htmlFor="request-title">{copy.requestTitle}</label>
          <input
            id="request-title"
            value={title}
            required
            aria-required="true"
            aria-invalid={Boolean(errors.title)}
            aria-describedby={`request-title-help${errors.title ? ' request-title-error' : ''}`}
            onChange={(event) => setTitle(event.target.value)}
          />
          <p id="request-title-help" className="field-help">{copy.titleHelp}</p>
          {errors.title && <p id="request-title-error" className="field-error">{copy.titleRequired}</p>}
        </div>
        <div className="field">
          <label htmlFor="request-description">{copy.requestDescription}</label>
          <textarea
            id="request-description"
            value={description}
            rows={6}
            required
            aria-required="true"
            aria-invalid={Boolean(errors.description)}
            aria-describedby={`request-description-help${errors.description ? ' request-description-error' : ''}`}
            onChange={(event) => setDescription(event.target.value)}
          />
          <p id="request-description-help" className="field-help">{copy.descriptionHelp}</p>
          {errors.description && <p id="request-description-error" className="field-error">{copy.descriptionRequired}</p>}
        </div>
        <button type="submit" className="primary-button" disabled={submitting}>{submitting ? copy.submitting : copy.submit}</button>
      </form>
    </section>
  )
}
