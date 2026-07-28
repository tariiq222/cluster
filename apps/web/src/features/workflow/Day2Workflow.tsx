import { Page, PageHeader } from '../../ui'

/**
 * Day 2 vertical was the legacy work-management + workflow journey. With
 * work_management disabled end-to-end the route renders a short notice and
 * is not part of the active task-only workspace; the equivalent task-only
 * journey is wired through TaskCreateScreen, TaskDetail, and the
 * transition endpoint.
 */
export function Day2Workflow(_: { locale?: 'ar' | 'en'; session: unknown }) {
  return (
    <Page>
      <PageHeader
        id="day2-disabled"
        title="Day 2 workflow (retired)"
        description="إدارة العمل معطّلة حالياً؛ المهام هي مسار العمل الوحيد المتاح."
      />
    </Page>
  )
}
