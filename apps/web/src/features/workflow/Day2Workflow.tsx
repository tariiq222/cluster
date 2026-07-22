import { useRef, useState } from 'react'
import { useLocale } from '../../app/session-context'
import { type Session } from '../../api'
import {
  createRequest,
  createWorkDefinition,
  createWorkDefinitionVersion,
  createWorkflowDefinition,
  listWorkDefinitions,
  listWorkflowDefinitions,
  listWorkflowVersions,
  listWorkDefinitionVersions,
  publishWorkDefinitionVersion,
  publishWorkflowVersion,
  startWorkflow,
  getWorkflowInstance,
  createTaskFromStep,
  submitRequest,
  returnRequest,
  completeRequest,
  transitionTask,
  type Day2Entity,
} from '../../api/r1'
import { Button, Field, Page, PageHeader, Panel, PanelGrid } from '../../ui'

const copy = {
  ar: {
    title: 'مسار الطلبات وسير العمل',
    admin: 'إعداد التعريفات',
    request: 'إنشاء طلب',
    code: 'رمز تعريف العمل',
    name: 'اسم التعريف',
    source: 'نوع السجل المصدر',
    save: 'حفظ ونشر',
    requestTitle: 'عنوان الطلب',
    description: 'الوصف',
    submit: 'إنشاء وإرسال الطلب',
    task: 'المهمة الناتجة',
    complete: 'إكمال المهمة',
    returned: 'إعادة المهمة',
    done: 'اكتمل الإجراء',
    error: 'تعذر تنفيذ الإجراء. تحقق من الصلاحية أو حدّث الصفحة.',
    required: 'أكمل الحقول المطلوبة.',
    working: 'جارٍ تنفيذ الخطوة…',
    setupHint: 'الخطوة ١: أنشئ التعريف أو أعد استخدام تعريف منشور.',
    requestHint: 'الخطوة ٢: أنشئ الطلب وأرسله بعد إكمال الإعداد.',
    taskHint: 'الخطوة ٣: نفّذ المهمة الناتجة.',
  },
  en: {
    title: 'Request workflow',
    admin: 'Definition setup',
    request: 'Create request',
    code: 'Work definition code',
    name: 'Definition name',
    source: 'Source record type',
    save: 'Save and publish',
    requestTitle: 'Request title',
    description: 'Description',
    submit: 'Create and submit request',
    task: 'Generated task',
    complete: 'Complete task',
    returned: 'Return task',
    done: 'Action completed',
    error: 'The action could not be completed. Check access or refresh.',
    required: 'Complete the required fields.',
    working: 'Working…',
    setupHint: 'Step 1: create or reuse a published definition.',
    requestHint: 'Step 2: create and submit a request after setup.',
    taskHint: 'Step 3: act on the generated task.',
  },
} as const

export function Day2Workflow({
  session,
}: {
  session: Session
}) {
  const locale = useLocale()
  const t = copy[locale]
  const [code, setCode] = useState('request-day2')
  const [name, setName] = useState('Request workflow')
  const [source, setSource] = useState('work_record')
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [task, setTask] = useState<Day2Entity | null>(null)
  const [record, setRecord] = useState<Day2Entity | null>(null)
  const [workflowVersion, setWorkflowVersion] = useState<Day2Entity | null>(
    null,
  )
  const [status, setStatus] = useState('')
  const [busy, setBusy] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)
  async function run(action: () => Promise<unknown>, success: string) {
    setBusy(true)
    setStatus(t.working)
    try {
      await action()
      setStatus(success)
    } catch {
      setStatus(t.error)
      window.requestAnimationFrame(() => errorRef.current?.focus())
    } finally {
      setBusy(false)
    }
  }
  async function setup() {
    if (!code.trim() || !name.trim() || !source.trim()) {
      setStatus(t.required)
      return
    }
    await run(async () => {
      const workDefinitions = await listWorkDefinitions(session.access_token)
      const existingWork = workDefinitions.items.find((item) => item.code === code)
      const work = existingWork ?? await createWorkDefinition(session.access_token, {
        code, name, default_classification: 'internal',
      })
      const workVersions = await listWorkDefinitionVersions(session.access_token, String(work.id))
      const publishedWork = workVersions.items.find((item) => item.status === 'published')
      const workVersion = publishedWork ?? workVersions.items[0] ?? await createWorkDefinitionVersion(
        session.access_token, String(work.id), {
          schema_document: {
            type: 'object',
            properties: {
              title: { type: 'string' },
              description: { type: 'string' },
            },
          },
          field_policy_key: 'default',
        })
      if (workVersion.status !== 'published') await publishWorkDefinitionVersion(session.access_token, String(workVersion.id), Number(workVersion.lock_version ?? 1))
      const flowCode = `${code}_flow`
      const flows = await listWorkflowDefinitions(session.access_token)
      const existingFlow = flows.items.find((item) => item.code === flowCode)
      const flow = existingFlow
        ? { definition: existingFlow, version: null }
        : await createWorkflowDefinition(session.access_token, { code: flowCode, name, source_record_type: source })
      const versions = await listWorkflowVersions(session.access_token, String(flow.definition.id))
      const candidate = versions.items.find((item) => item.status === 'published') ?? versions.items[0] ?? flow.version
      if (!candidate?.id) throw new Error('published-workflow-version-required')
      const published = candidate.status === 'published'
        ? candidate
        : await publishWorkflowVersion(session.access_token, String(candidate.id), Number(candidate.lock_version ?? 1))
      setWorkflowVersion(published)
    }, t.done)
  }
  async function submit() {
    if (!title.trim() || !description.trim() || !workflowVersion?.id) {
      setStatus(t.required)
      return
    }
    await run(async () => {
      const request = await createRequest(session.access_token, {
        work_definition_code: code,
        title,
        description,
      })
      const submitted = await submitRequest(
        session.access_token,
        String(request.id),
        Number(request.lock_version ?? 1),
      )
      setRecord(submitted)
      const instance = await startWorkflow(session.access_token, {
        workflow_version_id: String(workflowVersion.id),
        source_module: 'work_records',
        record_type: 'work_record',
        record_id: String(submitted.id),
      })
      const detail = await getWorkflowInstance(
        session.access_token,
        String(instance.id),
      )
      const step =
        detail.steps.find((item) => item.status === 'active') ?? detail.steps[0]
      if (step?.id)
        setTask(
          await createTaskFromStep(
            session.access_token,
            String(step.id),
            title,
          ),
        )
    }, t.done)
  }
  return (
    <Page className="content-page" aria-labelledby="day2-heading">
      <PageHeader id="day2-heading" title={t.title} />
      {status ? (
        <p ref={errorRef} className="status-message" tabIndex={-1} role="status" aria-live="polite">
          {status}
        </p>
      ) : null}
      <PanelGrid>
        <Panel id="day2-admin-heading" title={t.admin} level={2}>
          <p>{t.setupHint}</p>
          <Field id="day2-work-code" label={t.code} required>
            <input id="day2-work-code" value={code} onChange={(e) => setCode(e.target.value)} />
          </Field>
          <Field id="day2-work-name" label={t.name} required>
            <input id="day2-work-name" value={name} onChange={(e) => setName(e.target.value)} />
          </Field>
          <Field id="day2-source-record" label={t.source} required>
            <input id="day2-source-record" value={source} onChange={(e) => setSource(e.target.value)} />
          </Field>
          <Button disabled={busy} onClick={() => void setup()}>
            {t.save}
          </Button>
        </Panel>
        <Panel id="day2-request-heading" title={t.request} level={2}>
          <p>{t.requestHint}</p>
          <Field id="day2-request-title" label={t.requestTitle} required>
            <input id="day2-request-title" value={title} onChange={(e) => setTitle(e.target.value)} />
          </Field>
          <Field id="day2-request-description" label={t.description} required>
            <textarea
              id="day2-request-description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />
          </Field>
          <Button disabled={busy || !workflowVersion} onClick={() => void submit()}>
            {t.submit}
          </Button>
          {task && (
            <div role="region" aria-label={t.task}>
              <p>{t.taskHint}</p>
              <h2>{t.task}</h2>
              <p>{String(task.title ?? task.id ?? '')}</p>
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() =>
                  void run(async () => {
                    const returnedTask = await transitionTask(
                      session.access_token,
                      String(task.id),
                      'return-completion',
                      Number(task.lock_version ?? 1),
                    )
                    setTask(returnedTask)
                    if (record)
                      setRecord(
                        await returnRequest(
                          session.access_token,
                          String(record.id),
                          Number(record.lock_version ?? 1),
                        ),
                      )
                  }, t.done)
                }
              >
                {t.returned}
              </Button>
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() =>
                  void run(async () => {
                    const completedTask = await transitionTask(
                      session.access_token,
                      String(task.id),
                      'complete',
                      Number(task.lock_version ?? 1),
                    )
                    setTask(completedTask)
                    if (record)
                      setRecord(
                        await completeRequest(
                          session.access_token,
                          String(record.id),
                          Number(record.lock_version ?? 1),
                        ),
                      )
                  }, t.done)
                }
              >
                {t.complete}
              </Button>
            </div>
          )}
        </Panel>
      </PanelGrid>
    </Page>
  )
}
