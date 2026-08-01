import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { useCluster, useFacilities } from '../../../api/hooks'
import { ApiError, requestInit, stateFromError, unwrap } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { organizationCopy } from '../organization-copy'
import { CODE_PATTERN, displayName, facilityTypes, useCapabilities } from '../organization-utils'

export function FacilitiesTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const clusterQuery = useCluster()
  const facilitiesQuery = useFacilities()
  const [drawer, setDrawer] = useState<'closed' | 'create' | 'edit-facility'>(() => 'closed')
  const [editing, setEditing] = useState<generated.Facility | null>(null)

  const canManage = capabilities.includes('organization.facility.manage')
  const clusterMissing = clusterQuery.error instanceof ApiError && clusterQuery.error.status === 404
  const cluster = clusterMissing ? null : ((clusterQuery.data as generated.Cluster | null) ?? null)
  const facilities = (facilitiesQuery.data as generated.FacilityCollection | undefined)?.items ?? []

  const state = facilitiesQuery.isError
    ? stateFromError(facilitiesQuery.error)
    : facilitiesQuery.isLoading ? 'loading'
    : 'ready'

  const columns = useMemo<ColumnDef<generated.Facility>[]>(
    () => [
      {
        accessorKey: 'name_ar',
        header: text.nameAr,
        cell: ({ row }) => <span className="font-medium">{displayName(locale, row.original)}</span>,
      },
      {
        accessorKey: 'code',
        header: text.identifier,
        cell: ({ row }) => <span className="font-mono text-sm" dir="ltr">{row.original.code}</span>,
      },
      {
        accessorKey: 'type_code',
        header: text.type,
        cell: ({ row }) => <span>{facilityTypeLabel(locale, row.original.type_code)}</span>,
      },
      {
        accessorKey: 'status',
        header: text.status,
        cell: ({ row }) => (
          <Badge variant="outline">{row.original.status === 'active' ? text.active : text.inactive}</Badge>
        ),
      },
    ],
    [locale, text],
  )

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        {cluster && canManage ? (
          <Button
            size="sm"
            onClick={() => {
              setEditing(null)
              setDrawer('create')
            }}
          >
            <Plus aria-hidden="true" />
            {text.addFacility}
          </Button>
        ) : null}
      </div>

      <DataTable
        columns={columns}
        data={facilities}
        state={state}
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale={locale}
        onRowClick={
          canManage
            ? (facility) => {
                setEditing(facility)
                setDrawer('edit-facility')
              }
            : undefined
        }
        empty={<p className="text-muted-foreground py-8 text-center text-sm">{text.noFacilities}</p>}
      />

      {cluster ? (
        <FacilitySheet
          open={drawer !== 'closed'}
          cluster={cluster}
          facility={drawer === 'edit-facility' ? editing : null}
          onClose={() => setDrawer('closed')}
        />
      ) : null}
    </div>
  )
}

function facilityTypeLabel(locale: 'ar' | 'en', typeCode: string): string {
  const text = organizationCopy[locale]
  const match = facilityTypes.find(([code]) => code === typeCode)
  return match ? text[match[1]] : typeCode
}

function FacilitySheet({
  open,
  cluster,
  facility,
  onClose,
}: {
  open: boolean
  cluster: generated.Cluster
  facility: generated.Facility | null
  onClose: () => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const editing = facility !== null
  const [typeCode, setTypeCode] = useState('hospital')
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [failure, setFailure] = useState<'validation' | 'stale' | 'save' | null>(null)

  useEffect(() => {
    if (!open) return
    setTypeCode(facility?.type_code ?? 'hospital')
    setCode(facility?.code ?? '')
    setName(facility?.name_ar ?? '')
    setNameEn(facility?.name_en ?? '')
    setFailure(null)
  }, [open, facility])

  const mutation = useMutation({
    mutationFn: async ({ nextTypeCode, nextCode, nextName, nextNameEn }: { nextTypeCode: string; nextCode: string; nextName: string; nextNameEn: string }) => {
      if (editing && facility) {
        const fresh = unwrap<generated.Facility>(await generated.getFacility(facility.id, requestInit(token)))
        return unwrap<generated.Facility>(
          await generated.updateFacility(
            facility.id,
            { name: nextName },
            requestInit(token, { command: true, idempotency: 'facility-update', lockVersion: fresh.lock_version }),
          ),
        )
      }
      return unwrap<generated.Facility>(
        await generated.createFacility(
          { cluster_id: cluster.id, type_code: nextTypeCode, code: nextCode, name: nextName, name_en: nextNameEn.trim() || null },
          requestInit(token, { command: true, idempotency: 'facility' }),
        ),
      )
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['facilities'] })
      onClose()
    },
    onError: (caught) => {
      setFailure(caught instanceof ApiError && caught.status === 412 ? 'stale' : 'save')
    },
  })
  const submitting = mutation.isPending

  const failureMessage =
    failure === 'validation' ? text.validation : failure === 'stale' ? text.stale : failure === 'save' ? text.saveError : null

  return (
    <Sheet open={open} onOpenChange={(next) => { if (!next && !submitting) onClose() }}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{editing ? text.editFacilityTitle : text.createFacilityTitle}</SheetTitle>
          <SheetDescription>{text.facilities}</SheetDescription>
        </SheetHeader>
        <form
          className="grid gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            if (!name.trim() || (!editing && (!CODE_PATTERN.test(code) || typeCode === ''))) {
              setFailure('validation')
              return
            }
            setFailure(null)
            mutation.mutate({ nextTypeCode: typeCode, nextCode: code, nextName: name.trim(), nextNameEn: nameEn })
          }}
          noValidate
        >
          {failureMessage ? <p className="text-destructive text-sm" role="alert">{failureMessage}</p> : null}
          {!editing ? (
            <>
              <div className="grid gap-2">
                <Label htmlFor="org-facility-type">{text.type}</Label>
                <Select value={typeCode} onValueChange={setTypeCode}>
                  <SelectTrigger id="org-facility-type">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {facilityTypes.map(([value, key]) => (
                      <SelectItem key={value} value={value}>
                        {text[key]}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="grid gap-2">
                <Label htmlFor="org-facility-code">{text.code}</Label>
                <Input
                  id="org-facility-code"
                  dir="ltr"
                  value={code}
                  aria-invalid={failure === 'validation' || undefined}
                  onChange={(event) => setCode(event.target.value.toUpperCase())}
                />
                <p className="text-muted-foreground text-xs">{text.codeHint}</p>
              </div>
            </>
          ) : null}
          <div className="grid gap-2">
            <Label htmlFor="org-facility-name">{text.nameAr}</Label>
            <Input
              id="org-facility-name"
              value={name}
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setName(event.target.value)}
            />
          </div>
          {!editing ? (
            <div className="grid gap-2">
              <Label htmlFor="org-facility-name-en">{text.nameEn}</Label>
              <Input id="org-facility-name-en" value={nameEn} onChange={(event) => setNameEn(event.target.value)} />
            </div>
          ) : null}
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
