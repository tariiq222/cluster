// @vitest-environment jsdom
import { useCallback, useEffect, useState } from 'react'
import { Search } from 'lucide-react'
import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import { useToken } from '../../app/session-context'
import { ApiError } from '../../api'
import { explainAccessDecision } from '../../api/r1'
import type { AccessDecision } from '../../api/r1'
import { EmptyState, Field, InlineError, Page, PageHeader, Panel, SkeletonList } from '../../ui'

const copy = {
  ar: {
    title: 'فحص قرار الوصول',
    subtitle: 'استعلام اختياري عن قرار صلاحية محدد لتتبع المنطق في الخادم.',
    loading: 'جارٍ تحميل القرار…',
    error: 'تعذر تحميل القرار.',
    retry: 'إعادة المحاولة',
    empty: 'لم يتم تزويد معرّف قرار.',
    decisionId: 'معرّف القرار',
    explanation: 'الشرح',
  },
  en: {
    title: 'Access decision explainer',
    subtitle: 'Optional lookup of a single decision for tracing server-side logic.',
    loading: 'Loading decision…',
    error: 'We could not load the decision.',
    retry: 'Try again',
    empty: 'No decision id supplied.',
    decisionId: 'Decision ID',
    explanation: 'Explanation',
  },
} as const satisfies Record<Locale, Record<string, string>>

export function AccessDecisionWorkspace({ locale, decisionId }: { locale: Locale; decisionId?: string }) {
  const t = copy[locale]
  const token = useToken()
  const [state, setState] = useState<'loading' | 'ready' | 'denied' | 'error' | 'empty'>('empty')
  const [decision, setDecision] = useState<AccessDecision | null>(null)

  const load = useCallback(async () => {
    if (!decisionId) {
      setState('empty')
      return
    }
    setState('loading')
    try {
      setDecision(await explainAccessDecision(decisionId, token))
      setState('ready')
    } catch (error) {
      setState(error instanceof ApiError && error.status === 403 ? 'denied' : 'error')
    }
  }, [token, decisionId])

  useEffect(() => {
    void load()
  }, [load])

  return (
    <div dir={directionForLocale(locale)}>
      <Page aria-labelledby="access-decision-heading">
        <PageHeader id="access-decision-heading" title={t.title} description={t.subtitle} />
        {state === 'loading' ? <SkeletonList label={t.loading} /> : null}
        {state === 'empty' ? <EmptyState icon={<Search aria-hidden="true" />} title={t.empty} /> : null}
        {state === 'denied' ? <Panel id="access-decision-denied" title="403" level={2}><p>{t.error}</p></Panel> : null}
        {state === 'error' ? <InlineError message={t.error} retryLabel={t.retry} onRetry={() => void load()} /> : null}
        {state === 'ready' && decision ? (
          <Panel id="access-decision-panel" title={t.explanation} level={2}>
            <Field id="access-decision-id" label={t.decisionId}><code dir="ltr">{decision.decision_id}</code></Field>
            <p>{`${decision.decision} — ${decision.action} (${decision.resource_type})`}</p>
            <p>{decision.reason_codes.join(', ')}</p>
            <p dir="ltr">{decision.evaluated_at}</p>
          </Panel>
        ) : null}
      </Page>
    </div>
  )
}