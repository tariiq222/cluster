import { ClipboardList, FileText, Workflow } from 'lucide-react'

import { WorkspaceTabs } from '../../app/WorkspaceTabs'
import { PageHeader } from '../../ui'
import { text, type Locale } from '../../app/copy'
import type { Session } from '../../api'
import { Day2Workflow } from './Day2Workflow'
import { WorkDefinitionsScreen, WorkflowAdminScreen } from '../r1/R1Screens'

type ProcessRoute = 'workflow-day2' | 'work-definitions' | 'workflow-admin'

export type ProcessWorkspaceProps = {
  locale: Locale
  session: Session
  activeRouteName: ProcessRoute
  navigate: (path: string) => void
}

/**
 * Shared workspace for operating requests and maintaining their definitions.
 * The tab URLs intentionally remain the existing deep links so bookmarks and
 * direct navigation continue to resolve to the same screen.
 */
export function ProcessWorkspace({
  locale,
  session,
  activeRouteName,
  navigate,
}: ProcessWorkspaceProps) {
  const tabs = [
    {
      key: 'operations',
      label: text[locale].operationsAndTasks,
      path: '/admin/workflow/day2',
      active: activeRouteName === 'workflow-day2',
      icon: <ClipboardList size={18} strokeWidth={1.8} />,
    },
    {
      key: 'work-definitions',
      label: text[locale].workDefinitions2,
      path: '/admin/work-definitions',
      active: activeRouteName === 'work-definitions',
      icon: <FileText size={18} strokeWidth={1.8} />,
    },
    {
      key: 'workflow-definitions',
      label: text[locale].workflowDefinitions,
      path: '/admin/workflow',
      active: activeRouteName === 'workflow-admin',
      icon: <Workflow size={18} strokeWidth={1.8} />,
    },
  ]

  return (
    <div className="process-workspace">
      <PageHeader
        id="process-workspace-heading"
        title={text[locale].processesAndWorkflow}
        description={text[locale].operateWorkThenDefineThe}
      />
      <WorkspaceTabs
        label={text[locale].processAndWorkflowSections}
        tabs={tabs}
        onNavigate={navigate}
      />
      {activeRouteName === 'workflow-day2' ? (
        <Day2Workflow session={session} />
      ) : activeRouteName === 'work-definitions' ? (
        <WorkDefinitionsScreen />
      ) : (
        <WorkflowAdminScreen />
      )}
    </div>
  )
}
