// @vitest-environment jsdom
import { useMemo, useState } from 'react'
import type { Locale } from '../../app/copy'
import {
  Button,
  Field,
  Page,
  PageHeader,
  PageSection,
  Panel,
  Select,
} from '../../ui'
import {
  directionForWorkflow,
  fieldTypeLabel,
  procedureFieldTypes,
  workflowCopy,
  type ProcedureFieldType,
} from './workflow-copy'

type RequestField = { id: number; label: string; type: ProcedureFieldType; description: string }

export function NewProcedureRequest({ locale }: { locale: Locale }) {
  const copy = workflowCopy[locale]
  const [name, setName] = useState('')
  const [code, setCode] = useState('')
  const [usage, setUsage] = useState('')
  const [chain, setChain] = useState('')
  const [fields, setFields] = useState<RequestField[]>([{ id: 1, label: '', type: 'short_text', description: '' }])
  const [feedback, setFeedback] = useState<string | null>(null)
  const [nextId, setNextId] = useState(2)

  const options = useMemo(() => procedureFieldTypes.map((type) => ({ value: type, label: fieldTypeLabel(locale, type) })), [locale])

  function addField() {
    setFields((current) => [...current, { id: nextId, label: '', type: 'short_text', description: '' }])
    setNextId((current) => current + 1)
  }

  function removeField(id: number) {
    setFields((current) => current.length === 1 ? current : current.filter((field) => field.id !== id))
  }

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!name.trim()) return setFeedback(copy.nameRequired)
    if (!code.trim()) return setFeedback(copy.codeRequired)
    if (!usage.trim()) return setFeedback(copy.usageRequired)
    if (fields.some((field) => !field.label.trim() || !field.type)) return setFeedback(copy.fieldRequired)
    if (!chain.trim()) return setFeedback(copy.chainRequired)
    setFeedback(copy.reqRequestPrepared)
  }

  return (
    <div dir={directionForWorkflow(locale)}>
      <Page aria-labelledby="new-procedure-request-heading">
        <PageHeader id="new-procedure-request-heading" title={copy.reqNewProcedureRequest} description={copy.reqNewProcedureRequestDescription} />
        {feedback ? <p role="status" aria-live="polite" className="status-message">{feedback}</p> : null}
        <p role="note" className="status-message"><strong>{copy.reqApiUpdating}</strong> {copy.reqApiUpdatingBody}</p>
        <form className="resource-form" onSubmit={submit} noValidate>
          <PageSection id="procedure-request-identification" title={copy.identification}>
            <Panel id="procedure-request-identification-panel" title={copy.identification} level={3}>
              <Field id="procedure-request-name" label={copy.procedureName} required help={copy.procedureNameHelp}><input id="procedure-request-name" value={name} onChange={(event) => setName(event.target.value)} required aria-required="true" /></Field>
              <Field id="procedure-request-code" label={copy.procedureCode} required help={copy.procedureCodeHelp}><input id="procedure-request-code" value={code} onChange={(event) => setCode(event.target.value)} required aria-required="true" dir="ltr" /></Field>
              <Field id="procedure-request-usage" label={copy.usageDescription} required help={copy.usageDescriptionHelp}><textarea id="procedure-request-usage" value={usage} onChange={(event) => setUsage(event.target.value)} required aria-required="true" /></Field>
            </Panel>
          </PageSection>
          <PageSection id="procedure-request-fields" title={copy.fields}>
            <p>{copy.fieldsDescription}</p>
            <div className="table-scroll"><table><caption>{copy.fields}</caption><thead><tr><th scope="col">{copy.fieldLabel}</th><th scope="col">{copy.fieldType}</th><th scope="col">{copy.fieldDescription}</th><th scope="col"><span className="sr-only">{copy.removeField}</span></th></tr></thead><tbody>{fields.map((field, index) => <tr key={field.id}><td><input aria-label={`${copy.fieldLabel} ${index + 1}`} value={field.label} onChange={(event) => setFields((current) => current.map((item) => item.id === field.id ? { ...item, label: event.target.value } : item))} /></td><td><Select id={`procedure-request-field-type-${field.id}`} value={field.type} onChange={(value) => setFields((current) => current.map((item) => item.id === field.id ? { ...item, type: value as ProcedureFieldType } : item))} options={options} ariaLabel={copy.fieldType} /></td><td><input aria-label={`${copy.fieldDescription} ${index + 1}`} value={field.description} onChange={(event) => setFields((current) => current.map((item) => item.id === field.id ? { ...item, description: event.target.value } : item))} /></td><td><Button type="button" variant="quiet" onClick={() => removeField(field.id)} aria-label={`${copy.removeField} ${index + 1}`}>{copy.removeField}</Button></td></tr>)}</tbody></table></div>
            <Button type="button" variant="secondary" onClick={addField}>{copy.addField}</Button>
          </PageSection>
          <PageSection id="procedure-request-chain" title={copy.chain}>
            <Field id="procedure-request-chain-input" label={copy.chain} required help={copy.chainDescription}><textarea id="procedure-request-chain-input" value={chain} onChange={(event) => setChain(event.target.value)} placeholder={copy.chainPlaceholder} required aria-required="true" /></Field>
          </PageSection>
          <PageSection id="procedure-request-attachments" title={copy.attachments}>
            <Panel id="procedure-request-attachments-panel" title={copy.attachments} level={3}><p>{copy.attachmentsPlaceholder}</p><p role="note">{copy.attachmentNote}</p></Panel>
          </PageSection>
          <Button type="submit">{copy.submitForReview}</Button>
        </form>
      </Page>
    </div>
  )
}
