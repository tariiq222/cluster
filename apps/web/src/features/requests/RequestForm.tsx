import { type FormEvent, useEffect, useRef, useState } from 'react'
import { useLocale, useToken } from '../../app/session-context'
import { ApiError, createWorkRecord, type WorkRecord } from '../../api'
import { text } from '../../app/copy'
import { Button, Field, Page, PageHeader } from '../../ui'

export function RequestForm({ onCreated, onBack }: {
  onCreated: (record: WorkRecord) => void
  onBack: () => void
}) {
  const locale = useLocale()
  const token = useToken()
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
      <Page>
        <section className="success-panel" aria-labelledby="success-heading" aria-live="polite">
          <h1 id="success-heading" ref={successRef} tabIndex={-1}>{copy.success}</h1>
          <p className="submitted-title">{created.payload.title}</p>
          <p>{copy.successBody}</p>
          <a href="/" className="primary-link" onClick={(event) => { event.preventDefault(); onBack() }}>{copy.backToRequests}</a>
        </section>
      </Page>
    )
  }

  return (
    <Page>
      <section className="request-form-section" aria-labelledby="new-request-heading">
        <PageHeader id="new-request-heading" title={text[locale].submitANewRequest} />
        {(formError || errors.title || errors.description) && (
          <div className="error-summary" role="alert" tabIndex={-1} ref={summaryRef}>
            <strong>{copy.validationError}</strong>
            {formError && <p>{copy.submitError}</p>}
          </div>
        )}
        <form onSubmit={(event) => void submit(event)} noValidate>
          <Field id="request-title" label={copy.requestTitle} required error={errors.title ? copy.titleRequired : undefined}>
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
          </Field>
          <Field id="request-description" label={copy.requestDescription} required error={errors.description ? copy.descriptionRequired : undefined}>
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
          </Field>
          <Button type="submit" disabled={submitting}>{submitting ? copy.submitting : copy.submit}</Button>
        </form>
      </section>
    </Page>
  )
}
