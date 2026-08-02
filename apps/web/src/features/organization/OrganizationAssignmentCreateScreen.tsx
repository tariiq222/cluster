import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight } from 'lucide-react'
import { useLocale, useSessionToken } from '../../app/session-context'
import { useNavigate } from '../../app/navigation-context'
import { usePeople, usePositions } from '../../api/hooks'
import { requestInit, unwrap } from '../../api/http'
import * as generated from '../../api/generated/cluster'
import { PageHeader, PageLayout } from '@/components/page-layout'
import {
  FormActionStack,
  FormSection,
  ReviewSummary,
  TwoRegionFormLayout,
} from '@/components/form-page-layout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DeniedState } from '@/components/states'
import { organizationCopy } from './organization-copy'
import { toUtcIso, useCapabilities } from './organization-utils'

/*
 * Full-page replacement for the former AssignmentSheet
 * (route `/organization/assignments/new`).
 *
 * Only active people and active positions are selectable; the blocked
 * state copy is preserved from the original sheet.
 */
export function OrganizationAssignmentCreateScreen() {
  const locale = useLocale()
  const token = useSessionToken()
  const navigate = useNavigate()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const peopleQuery = usePeople()
  const positionsQuery = usePositions()

  const canManage = capabilities.includes('organization.assignment.manage')

  const people = peopleQuery.data?.items ?? []
  const positions = (positionsQuery.data as generated.PositionCollection | undefined)?.items ?? []

  const [personId, setPersonId] = useState('')
  const [positionId, setPositionId] = useState('')
  const [startAt, setStartAt] = useState('')
  const [failure, setFailure] = useState<'validation' | 'save' | null>(null)

  const mutation = useMutation({
    mutationFn: async ({ nextPersonId, nextPositionId, nextStartAt }: { nextPersonId: string; nextPositionId: string; nextStartAt: string }) =>
      unwrap<generated.Assignment>(
        await generated.createAssignment(
          { person_id: nextPersonId, position_id: nextPositionId, start_at: nextStartAt },
          requestInit(token, { command: true, idempotency: 'assignment' }),
        ),
      ),
    onSuccess: () => {
      navigate('/organization?tab=assignments')
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  const back = () => navigate('/organization?tab=assignments')

  if (!canManage) {
    return (
      <PageLayout data-testid="assignment-create-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  const activePeople = people.filter((person) => person.status === 'active')
  const activePositions = positions.filter((position) => position.is_active)
  const selectedPerson = activePeople.find((person) => person.id === personId)
  const selectedPosition = activePositions.find((position) => position.id === positionId)
  const selectedPersonLabel = selectedPerson?.display_name_ar ?? text.notProvided
  const selectedPositionLabel = selectedPosition?.title_ar ?? text.notProvided

  return (
    <PageLayout data-testid="assignment-create-screen">
      <div>
        <Button variant="ghost" size="sm" onClick={back} className="-ms-2">
          {locale === 'ar' ? <ArrowRight aria-hidden="true" /> : <ArrowLeft aria-hidden="true" />}
          {text.backToAssignments}
        </Button>
      </div>

      <PageHeader title={text.createAssignmentTitle} description={text.assignments} />

      {activePeople.length === 0 || activePositions.length === 0 ? (
        <div className="rounded-xl border bg-card p-4 sm:p-6">
          <p className="text-muted-foreground text-sm">
            {activePeople.length === 0 ? text.noActivePeople : text.noActivePositions}
          </p>
        </div>
      ) : (
        <TwoRegionFormLayout
          testId="assignment-create-form"
          mainTestId="assignment-create-main"
          reviewTestId="assignment-create-review"
          onSubmit={(event) => {
            event.preventDefault()
            if (!personId || !positionId || !startAt) {
              setFailure('validation')
              return
            }
            setFailure(null)
            mutation.mutate({ nextPersonId: personId, nextPositionId: positionId, nextStartAt: toUtcIso(startAt) ?? startAt })
          }}
          main={
            <div className="grid gap-6">
              {failure === 'validation' ? (
                <p className="text-destructive text-sm" role="alert">{text.validation}</p>
              ) : failure === 'save' ? (
                <p className="text-destructive text-sm" role="alert">{text.saveError}</p>
              ) : null}
              <FormSection headingId="assignment-parties-heading" title={text.assignmentPartiesSection}>
                <div className="grid gap-2">
                  <Label htmlFor="org-assignment-person">{text.person}</Label>
                  <Select value={personId} onValueChange={setPersonId}>
                    <SelectTrigger id="org-assignment-person" className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {activePeople.map((person) => (
                        <SelectItem key={person.id} value={person.id}>
                          {person.display_name_ar}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="org-assignment-position">{text.position}</Label>
                  <Select value={positionId} onValueChange={setPositionId}>
                    <SelectTrigger id="org-assignment-position" className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {activePositions.map((position) => (
                        <SelectItem key={position.id} value={position.id}>
                          {position.title_ar}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </FormSection>
              <FormSection
                headingId="assignment-timing-heading"
                title={text.assignmentTimingSection}
                divided
              >
                <div className="grid gap-2">
                  <Label htmlFor="org-assignment-start-at">{text.startAt}</Label>
                  <Input
                    id="org-assignment-start-at"
                    type="datetime-local"
                    value={startAt}
                    onChange={(event) => setStartAt(event.target.value)}
                  />
                </div>
              </FormSection>
            </div>
          }
          review={
            <div className="grid gap-4">
              <FormSection headingId="assignment-review-heading" title={text.reviewTitle} density="tight">
                <ReviewSummary
                  testId="assignment-create-review-summary"
                  rows={[
                    { label: text.person, value: selectedPersonLabel, isolate: true },
                    { label: text.position, value: selectedPositionLabel, isolate: true },
                    { label: text.startAt, value: startAt, empty: text.notProvided, isolate: true },
                  ]}
                />
              </FormSection>
              <FormActionStack testId="assignment-create-actions">
                <Button type="submit" className="w-full" disabled={submitting}>
                  {submitting ? text.saving : text.save}
                </Button>
                <Button type="button" variant="outline" className="w-full" onClick={back} disabled={submitting}>
                  {text.cancel}
                </Button>
              </FormActionStack>
            </div>
          }
        />
      )}
    </PageLayout>
  )
}
