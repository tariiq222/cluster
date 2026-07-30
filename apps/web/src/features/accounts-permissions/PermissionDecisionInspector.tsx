import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'

import { Button, Field, Page, PageHeader, Panel, Select } from '../../ui'
import { directionForLocale, type Locale } from '../../app/copy'
import { useToken } from '../../app/session-context'
import { ApiError } from '../../api'
import { explainAccessDecision, simulateAccessDecision, type AccessDecision, type AccessDecisionRequest } from '../../api/r1'

type Clearance = 'public' | 'internal' | 'confidential' | 'top_secret'
type Classification = Clearance

type FormState = {
  action: string
  subjectId: string
  tenantId: string
  clearance: Clearance
  correlationId: string
  factsVersion: string
  sourceModule: string
  recordType: string
  recordId: string
  clusterId: string
  classification: Classification
  lifecycleState: string
  fieldPolicyKey: string
}

const EMPTY_FORM: FormState = {
  action: '', subjectId: '', tenantId: '', clearance: 'internal', correlationId: '',
  factsVersion: '', sourceModule: '', recordType: '', recordId: '', clusterId: '',
  classification: 'internal', lifecycleState: '', fieldPolicyKey: '',
}

const COPY = {
  ar: {
    title: 'فاحص قرار الصلاحية', intro: 'أرسل سياق وصول وحقائق سجل كاملين لشرح قرار الخادم.',
    action: 'الإجراء', subjectId: 'معرّف الحساب', tenantId: 'معرّف المستأجر', clearance: 'مستوى التصريح', correlationId: 'معرّف الترابط',
    factsVersion: 'إصدار الحقائق', sourceModule: 'وحدة المصدر', recordType: 'نوع السجل', recordId: 'معرّف السجل', clusterId: 'معرّف التجمع', classification: 'التصنيف', lifecycleState: 'حالة دورة الحياة', fieldPolicyKey: 'مفتاح سياسة الحقول',
    submit: 'محاكاة القرار', pending: 'جارٍ المحاكاة…', error: 'تعذر تنفيذ المحاكاة.', result: 'نتيجة القرار',
    public: 'عام', internal: 'داخلي', confidential: 'سري', top_secret: 'سري للغاية',
  },
  en: {
    title: 'Permission Decision Inspector', intro: 'Send a complete access context and record facts to explain the server decision.',
    action: 'Action', subjectId: 'Account ID', tenantId: 'Tenant ID', clearance: 'Clearance', correlationId: 'Correlation ID',
    factsVersion: 'Facts version', sourceModule: 'Source module', recordType: 'Record type', recordId: 'Record ID', clusterId: 'Cluster ID', classification: 'Classification', lifecycleState: 'Lifecycle state', fieldPolicyKey: 'Field policy key',
    submit: 'Simulate decision', pending: 'Simulating…', error: 'Could not run the simulation.', result: 'Decision result',
    public: 'Public', internal: 'Internal', confidential: 'Confidential', top_secret: 'Top secret',
  },
} as const satisfies Record<Locale, Record<string, string>>

export type PermissionDecisionInspectorProps = { locale: Locale; decisionId?: string }

function buildRequest(form: FormState): AccessDecisionRequest {
  return {
    action: form.action.trim(),
    access_context: {
      subject_id: form.subjectId.trim(), tenant_id: form.tenantId.trim(), clearance: form.clearance,
      correlation_id: form.correlationId.trim(),
    },
    record_facts: {
      facts_version: form.factsVersion.trim(), source_module: form.sourceModule.trim(), record_type: form.recordType.trim(),
      record_id: form.recordId.trim(), cluster_id: form.clusterId.trim(), owner_facility_id: null, owner_organization_unit_id: null,
      created_by_user_id: null, owner_user_id: null, responsible_user_id: null, shared_unit_ids: [], shared_user_ids: [], participant_ids: [],
      classification: form.classification, lifecycle_state: form.lifecycleState.trim(), field_policy_key: form.fieldPolicyKey.trim(), lock_version: 1,
      fact_time: new Date().toISOString(),
    },
  }
}

export function PermissionDecisionInspector({ locale, decisionId }: PermissionDecisionInspectorProps) {
  const labels = COPY[locale]
  const token = useToken()
  const [form, setForm] = useState<FormState>(EMPTY_FORM)
  const [decision, setDecision] = useState<AccessDecision | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  const update = <K extends keyof FormState>(key: K, value: FormState[K]) => setForm((current) => ({ ...current, [key]: value }))

  useEffect(() => {
    if (!decisionId) return

    let cancelled = false
    setPending(true)
    setError(null)
    void explainAccessDecision(decisionId, token)
      .then((loadedDecision) => {
        if (!cancelled) setDecision(loadedDecision)
      })
      .catch((caught) => {
        if (!cancelled) setError(caught instanceof ApiError ? caught.message : labels.error)
      })
      .finally(() => {
        if (!cancelled) setPending(false)
      })

    return () => { cancelled = true }
  }, [decisionId, labels.error, token])

  const submit = useCallback(async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setPending(true); setError(null)
    try { setDecision(await simulateAccessDecision(buildRequest(form), token)) }
    catch (caught) { setError(caught instanceof ApiError ? caught.message : labels.error) }
    finally { setPending(false) }
  }, [form, labels.error, token])

  const clearanceOptions = (['public', 'internal', 'confidential', 'top_secret'] as const).map((value) => ({ value, label: labels[value] }))
  return <div dir={directionForLocale(locale)}><Page>
    <PageHeader id="permission-decision-inspector-heading" title={labels.title} description={labels.intro} />
    <form className="inline-form" onSubmit={submit}>
      {([
        ['action', 'action'], ['subjectId', 'subjectId'], ['tenantId', 'tenantId'], ['correlationId', 'correlationId'],
        ['factsVersion', 'factsVersion'], ['sourceModule', 'sourceModule'], ['recordType', 'recordType'], ['recordId', 'recordId'],
        ['clusterId', 'clusterId'], ['lifecycleState', 'lifecycleState'], ['fieldPolicyKey', 'fieldPolicyKey'],
      ] as const).map(([key, labelKey]) => <Field key={key} id={`inspector-${key}`} label={labels[labelKey]}>
        <input id={`inspector-${key}`} value={form[key]} onChange={(event) => update(key, event.target.value)} dir="ltr" required aria-required="true" />
      </Field>)}
      <Field id="inspector-clearance" label={labels.clearance}><Select id="inspector-clearance" value={form.clearance} options={clearanceOptions} onChange={(value) => update('clearance', value as Clearance)} /></Field>
      <Field id="inspector-classification" label={labels.classification}><Select id="inspector-classification" value={form.classification} options={clearanceOptions} onChange={(value) => update('classification', value as Classification)} /></Field>
      <Button type="submit" disabled={pending}>{pending ? labels.pending : labels.submit}</Button>
    </form>
    {error ? <p className="error-summary" role="alert">{error}</p> : null}
    {decision ? <section className="inspector-result" role="region" aria-labelledby="decision-result-heading"><Panel id="decision-result-heading" title={labels.result} level={2}>
      {decision.applies_in_plain_language ? <p>{decision.applies_in_plain_language}</p> : null}
      {decision.reason_codes.length ? <p>{decision.reason_codes.join(', ')}</p> : null}
      {decision.assignment_summaries?.length ? <ul>{decision.assignment_summaries.map((summary) => <li key={summary.scope_label}>{summary.scope_label}</li>)}</ul> : null}
      {decision.policy_references?.length ? <ul>{decision.policy_references.map((policy) => <li key={policy.policy_label}>{policy.policy_label}</li>)}</ul> : null}
    </Panel></section> : null}
  </Page></div>
}
