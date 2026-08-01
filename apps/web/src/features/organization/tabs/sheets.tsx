import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { requestInit, unwrap } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { organizationCopy } from '../organization-copy'
import { CODE_PATTERN, displayName, unitTypes } from '../organization-utils'

export function UnitSheet({
  open,
  onClose,
  cluster,
  units,
  onSaved,
}: {
  open: boolean
  onClose: () => void
  cluster: generated.Cluster
  units: generated.OrganizationUnit[]
  onSaved: (unit: generated.OrganizationUnit) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const [parentId, setParentId] = useState('')
  const [typeCode, setTypeCode] = useState('department')
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [failure, setFailure] = useState<'validation' | 'save' | null>(null)

  useEffect(() => {
    if (!open) return
    setParentId('')
    setTypeCode('department')
    setCode('')
    setName('')
    setFailure(null)
  }, [open])

  const mutation = useMutation({
    mutationFn: async ({ nextParentId, nextTypeCode, nextCode, nextName }: { nextParentId: string; nextTypeCode: string; nextCode: string; nextName: string }) =>
      unwrap<generated.OrganizationUnit>(
        await generated.createOrganizationUnit(
          {
            cluster_id: cluster.id,
            parent_id: nextParentId || undefined,
            type_code: nextTypeCode,
            code: nextCode,
            name: nextName,
          },
          requestInit(token, { command: true, idempotency: 'organization-unit' }),
        ),
      ),
    onSuccess: (saved) => {
      void queryClient.invalidateQueries({ queryKey: ['organization-units'] })
      onSaved(saved)
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  const parentOptions: Array<{ value: string; label: string }> = [
    { value: '', label: text.rootLevel },
    ...units.map((unit) => ({ value: unit.id, label: displayName(locale, unit) })),
  ]

  return (
    <Sheet open={open} onOpenChange={(next) => { if (!next && !submitting) onClose() }}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{text.createUnitTitle}</SheetTitle>
          <SheetDescription>{text.unitsAtCluster}</SheetDescription>
        </SheetHeader>
        <form
          className="grid gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            if (!name.trim() || !CODE_PATTERN.test(code)) {
              setFailure('validation')
              return
            }
            setFailure(null)
            mutation.mutate({ nextParentId: parentId, nextTypeCode: typeCode, nextCode: code, nextName: name.trim() })
          }}
          noValidate
        >
          {failure === 'validation' ? (
            <p className="text-destructive text-sm" role="alert">{text.validation}</p>
          ) : failure === 'save' ? (
            <p className="text-destructive text-sm" role="alert">{text.saveError}</p>
          ) : null}
          <div className="grid gap-2">
            <Label htmlFor="org-unit-parent">{text.parent}</Label>
            <Select value={parentId} onValueChange={setParentId}>
              <SelectTrigger id="org-unit-parent">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {parentOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-unit-type">{text.unitType}</Label>
            <Select value={typeCode} onValueChange={setTypeCode}>
              <SelectTrigger id="org-unit-type">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {unitTypes.map(([value, key]) => (
                  <SelectItem key={value} value={value}>
                    {text[key]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-unit-code">{text.code}</Label>
            <Input
              id="org-unit-code"
              dir="ltr"
              value={code}
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setCode(event.target.value.toUpperCase())}
            />
            <p className="text-muted-foreground text-xs">{text.codeHint}</p>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-unit-name">{text.nameAr}</Label>
            <Input
              id="org-unit-name"
              value={name}
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setName(event.target.value)}
            />
          </div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
              {text.cancel}
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? text.saving : text.save}
            </Button>
          </div>
        </form>
      </SheetContent>
    </Sheet>
  )
}

export function PositionSheet({
  open,
  onClose,
  units,
  jobTitles,
  preselectedUnitId,
  onSaved,
}: {
  open: boolean
  onClose: () => void
  units: generated.OrganizationUnit[]
  jobTitles: generated.JobTitle[]
  preselectedUnitId?: string
  onSaved: (position: generated.Position) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const [unitId, setUnitId] = useState('')
  const [code, setCode] = useState('')
  const [title, setTitle] = useState('')
  const [jobTitleId, setJobTitleId] = useState('')
  const [failure, setFailure] = useState<'validation' | 'save' | null>(null)

  useEffect(() => {
    if (!open) return
    setUnitId(preselectedUnitId ?? '')
    setCode('')
    setTitle('')
    setJobTitleId('')
    setFailure(null)
  }, [open, preselectedUnitId])

  const mutation = useMutation({
    mutationFn: async ({ nextUnitId, nextCode, nextTitle, nextJobTitleId }: { nextUnitId: string; nextCode: string; nextTitle: string; nextJobTitleId: string }) =>
      unwrap<generated.Position>(
        await generated.createPosition(
          {
            organization_unit_id: nextUnitId,
            code: nextCode,
            title: nextTitle,
            ...(nextJobTitleId ? { job_title_id: nextJobTitleId } : {}),
          },
          requestInit(token, { command: true, idempotency: 'position' }),
        ),
      ),
    onSuccess: (saved) => {
      void queryClient.invalidateQueries({ queryKey: ['positions'] })
      onSaved(saved)
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  return (
    <Sheet open={open} onOpenChange={(next) => { if (!next && !submitting) onClose() }}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{text.createPositionTitle}</SheetTitle>
          <SheetDescription>{text.unitPositions}</SheetDescription>
        </SheetHeader>
        <form
          className="grid gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            if (!unitId || !CODE_PATTERN.test(code)) {
              setFailure('validation')
              return
            }
            setFailure(null)
            mutation.mutate({ nextUnitId: unitId, nextCode: code, nextTitle: title.trim(), nextJobTitleId: jobTitleId })
          }}
          noValidate
        >
          {failure === 'validation' ? (
            <p className="text-destructive text-sm" role="alert">{text.validation}</p>
          ) : failure === 'save' ? (
            <p className="text-destructive text-sm" role="alert">{text.saveError}</p>
          ) : null}
          <div className="grid gap-2">
            <Label htmlFor="org-position-unit">{text.parent}</Label>
            <Select value={unitId} onValueChange={setUnitId}>
              <SelectTrigger id="org-position-unit">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {units.map((unit) => (
                  <SelectItem key={unit.id} value={unit.id}>
                    {displayName(locale, unit)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-position-code">{text.code}</Label>
            <Input
              id="org-position-code"
              dir="ltr"
              value={code}
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setCode(event.target.value.toUpperCase())}
            />
            <p className="text-muted-foreground text-xs">{text.codeHint}</p>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-position-title">{text.positionTitle}</Label>
            <Input id="org-position-title" value={title} onChange={(event) => setTitle(event.target.value)} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-position-job-title">{text.jobTitle}</Label>
            <Select value={jobTitleId} onValueChange={setJobTitleId}>
              <SelectTrigger id="org-position-job-title">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {jobTitles.map((titleItem) => (
                  <SelectItem key={titleItem.id} value={titleItem.id}>
                    {titleItem.title_ar}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
              {text.cancel}
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? text.saving : text.save}
            </Button>
          </div>
        </form>
      </SheetContent>
    </Sheet>
  )
}
