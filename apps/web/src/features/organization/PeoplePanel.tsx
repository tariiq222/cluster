import { useState } from 'react'
import { Users } from 'lucide-react'

import type { Person } from '../../api'
import { Button, EmptyState, Panel, StatusBadge } from '../../ui'
import { PersonDrawer } from './PersonDrawer'
import { peopleAssignmentsCopy, type OrganizationLocale } from './PeopleAssignments'

export function PeoplePanel({
  locale,
  token,
  people,
  onSaved,
}: {
  readonly locale: OrganizationLocale
  readonly token: string
  readonly people: readonly Person[]
  readonly onSaved: (person: Person) => void
}) {
  const text = peopleAssignmentsCopy[locale]
  const [selectedPerson, setSelectedPerson] = useState<Person | null>(null)
  const [creating, setCreating] = useState(false)

  function closeDrawer() {
    setCreating(false)
    setSelectedPerson(null)
  }

  return (
    <Panel
      id="people-list-heading"
      title={text.people}
      level={2}
      actions={<Button onClick={() => setCreating(true)}>{text.addPerson}</Button>}
    >
      {people.length === 0 ? <EmptyState icon={<Users />} title={text.noPeople} action={<Button onClick={() => setCreating(true)}>{text.addPerson}</Button>} /> : (
        <div className="table-scroll" tabIndex={0} role="region" aria-label={text.people}>
          <table>
            <thead><tr><th scope="col">{text.employee}</th><th scope="col">{text.employeeNumber}</th><th scope="col">{text.status}</th><th scope="col">{text.actions}</th></tr></thead>
            <tbody>{people.map((person) => <tr key={person.id}>
              <td>{personName(person, locale)}</td>
              <td dir="ltr">{person.employee_number}</td>
              <td><StatusBadge>{text[person.status]}</StatusBadge></td>
              <td><Button variant="quiet" onClick={() => setSelectedPerson(person)}>{text.editPerson}</Button></td>
            </tr>)}</tbody>
          </table>
        </div>
      )}
      <PersonDrawer open={creating || selectedPerson !== null} person={selectedPerson} locale={locale} token={token} onClose={closeDrawer} onSaved={(saved) => { onSaved(saved); closeDrawer() }} />
    </Panel>
  )
}

function personName(person: Person, locale: OrganizationLocale) {
  return locale === 'en' && person.display_name_en ? person.display_name_en : person.display_name_ar
}
