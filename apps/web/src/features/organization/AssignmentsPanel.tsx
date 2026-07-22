import { useState } from 'react'
import { CalendarClock } from 'lucide-react'

import type { Assignment, Person, Position } from '../../api'
import { Button, EmptyState, Panel, StatusBadge } from '../../ui'
import { AssignmentDrawer } from './AssignmentDrawer'
import { EndAssignmentDrawer } from './EndAssignmentDrawer'
import { peopleAssignmentsCopy, type OrganizationLocale } from './PeopleAssignments'

export function AssignmentsPanel({
  locale,
  token,
  people,
  positions,
  assignments,
  onCreated,
  onEnded,
}: {
  readonly locale: OrganizationLocale
  readonly token: string
  readonly people: readonly Person[]
  readonly positions: readonly Position[]
  readonly assignments: readonly Assignment[]
  readonly onCreated: (assignment: Assignment) => void
  readonly onEnded: (assignment: Assignment) => void
}) {
  const text = peopleAssignmentsCopy[locale]
  const [creating, setCreating] = useState(false)
  const [ending, setEnding] = useState<Assignment | null>(null)
  const [notice, setNotice] = useState('')
  const activePeople = people.filter((person) => person.status === 'active')
  const activePositions = positions.filter((position) => position.is_active)
  const canCreate = activePeople.length > 0 && activePositions.length > 0
  const peopleById = new Map(people.map((person) => [person.id, personName(person, locale)]))
  const positionsById = new Map(positions.map((position) => [position.id, position.title_ar]))
  const prerequisite = activePeople.length === 0 && activePositions.length === 0 ? text.noActivePeopleOrPositions : activePeople.length === 0 ? text.noActivePeople : activePositions.length === 0 ? text.noActivePositions : null

  return (
    <Panel id="assignments-list-heading" title={text.assignments} level={2} actions={canCreate ? <Button onClick={() => setCreating(true)}>{text.createAssignment}</Button> : undefined}>
      {assignments.length === 0 ? <EmptyState icon={<CalendarClock />} title={text.noAssignments} action={canCreate ? <Button onClick={() => setCreating(true)}>{text.createAssignment}</Button> : undefined} /> : (
        <div className="table-scroll" tabIndex={0} role="region" aria-label={text.assignments}>
          <table><thead><tr><th scope="col">{text.person}</th><th scope="col">{text.jobTitle}</th><th scope="col">{text.startAt}</th><th scope="col">{text.current}</th><th scope="col">{text.actions}</th></tr></thead>
            <tbody>{assignments.map((assignment) => <tr key={assignment.id}>
              <td>{peopleById.get(assignment.person_id) ?? '—'}</td>
              <td>{positionsById.get(assignment.position_id) ?? '—'}</td>
              <td><time dateTime={assignment.start_at}>{date(assignment.start_at, locale)}</time></td>
              <td><StatusBadge>{text[assignment.status]}</StatusBadge></td>
              <td>{assignment.status === 'active' ? <Button variant="quiet" onClick={() => setEnding(assignment)}>{text.endAssignment}</Button> : null}</td>
            </tr>)}</tbody>
          </table>
        </div>
      )}
      {prerequisite ? <p className="status-message" role="status">{prerequisite}</p> : null}
      {notice ? <p className="status-message" role="status">{notice}</p> : null}
      <AssignmentDrawer open={creating} locale={locale} token={token} people={activePeople} positions={activePositions} onClose={() => setCreating(false)} onCreated={(saved) => { onCreated(saved); setCreating(false) }} />
      <EndAssignmentDrawer open={ending !== null} assignment={ending} locale={locale} token={token} onClose={() => setEnding(null)} onEnded={(saved) => { onEnded(saved); setEnding(null); setNotice(text.endedSuccess) }} />
    </Panel>
  )
}

function personName(person: Person, locale: OrganizationLocale) {
  return locale === 'en' && person.display_name_en ? person.display_name_en : person.display_name_ar
}

function date(value: string, locale: OrganizationLocale) {
  return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA' : 'en', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Riyadh' }).format(new Date(value))
}
