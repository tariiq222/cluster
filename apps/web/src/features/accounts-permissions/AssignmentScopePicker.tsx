import { useEffect, useId, useMemo, useRef, useState } from 'react'

import type { Locale } from '../../app/copy'
import { Field, InlineError, Select, type SelectOption } from '../../ui'
import type {
  AssignmentScopeParentType,
  AssignmentScopeType,
} from '../../api/r1'
import { useAssignmentScopeTargets } from './useAssignmentScopeTargets'

const HELPER_ID = 'assignment-scope-record-set-helper'

const LEVEL_LABEL_BY_TYPE: Record<Locale, Record<AssignmentScopeType, string>> = {
  ar: { cluster: 'التجمع', facility: 'المنشأة', unit: 'الوحدة', record_set: 'مجموعة السجلات' },
  en: { cluster: 'Cluster', facility: 'Facility', unit: 'Unit', record_set: 'Record set' },
}

const PICKER_COPY: Record<Locale, {
  helper: string
  loading: string
  empty: string
  error: string
  forbidden: string
  unsupported: string
  retry: string
  targetPlaceholder: string
  facilityPlaceholder: string
  parent: string
  target: string
  legend: string
}> = {
  ar: {
    helper: 'نطاقات مجموعة السجلات غير متاحة بعد.',
    loading: 'جارٍ تحميل أهداف النطاق…',
    empty: 'لا توجد أهداف نطاق متاحة لهذا المستوى.',
    error: 'تعذر تحميل أهداف النطاق.',
    forbidden: 'لا تملك صلاحية عرض أهداف النطاق.',
    unsupported: 'مستوى النطاق هذا غير مدعوم.',
    retry: 'إعادة تحميل الأهداف',
    targetPlaceholder: 'اختر هدف النطاق',
    facilityPlaceholder: 'اختر المنشأة',
    parent: 'المنشأة الأصل',
    target: 'هدف النطاق',
    legend: 'مستوى النطاق',
  },
  en: {
    helper: 'Record-set scope is not yet available.',
    loading: 'Loading scope targets…',
    empty: 'No scope targets available for this level.',
    error: 'Could not load scope targets.',
    forbidden: 'You do not have permission to view scope targets.',
    unsupported: 'This scope level is not supported.',
    retry: 'Reload targets',
    targetPlaceholder: 'Select scope target',
    facilityPlaceholder: 'Select facility',
    parent: 'Parent facility',
    target: 'Scope target',
    legend: 'Scope level',
  },
}

export type AssignmentScopeValue = {
  scope_type: AssignmentScopeType
  scope_id: string
}

export type AssignmentScopePickerProps = {
  value: AssignmentScopeValue | null
  onChange: (next: AssignmentScopeValue | null) => void
  locale: Locale
  token: string
  canAssign: boolean
  initialAncestry?: readonly AssignmentScopeValue[]
  idPrefix?: string
  testId?: string
}

/**
 * Catalog-driven assignment scope picker. Renders:
 *  - a `<fieldset>` with a `<legend>` "Scope level" and four radios in spec
 *    order — cluster, facility, unit, record_set. The first three are enabled;
 *    `record_set` is disabled with `aria-describedby` pointing at a helper
 *    paragraph that explains the level is not selectable.
 *  - a parent facility Select that only resolves once `unit` is the active
 *    level (it auto-loads facilities when the user picks "unit").
 *  - a target Select that always renders, driven by the catalog hook.
 *
 * Selecting a target commits `{ scope_type, scope_id }` via `onChange`. The
 * cascade invariant is enforced by `handleLevelChange` / `handleTargetChange`:
 *   - cluster clears facility/unit.
 *   - facility clears unit.
 *   - unit requires facility + cluster (the picker surfaces nothing until
 *     the previous level resolves).
 */
export function AssignmentScopePicker({
  value,
  onChange,
  locale,
  token,
  canAssign,
  initialAncestry,
  idPrefix = 'assignment-scope',
  testId,
}: AssignmentScopePickerProps) {
  const isAr = locale === 'ar'
  const labels = useMemo(
    () => ({
      levelByType: LEVEL_LABEL_BY_TYPE[locale],
      ...PICKER_COPY[locale],
    }),
    [locale],
  )

  const [cluster, setCluster] = useState<AssignmentScopeValue | null>(
    () => findLevel(initialAncestry, 'cluster'),
  )
  const [facility, setFacility] = useState<AssignmentScopeValue | null>(
    () => findLevel(initialAncestry, 'facility'),
  )
  const [unit, setUnit] = useState<AssignmentScopeValue | null>(
    () => findLevel(initialAncestry, 'unit'),
  )

  const [requestedLevel, setRequestedLevel] = useState<AssignmentScopeType>(
    () => value?.scope_type ?? deepestLevel(initialAncestry) ?? 'cluster',
  )

  const preserveAncestryOnNullRef = useRef(false)
  const lastValueRef = useRef<AssignmentScopeValue | null>(value ?? null)
  useEffect(() => {
    const previous = lastValueRef.current
    if (value === null && previous !== null) {
      if (preserveAncestryOnNullRef.current) {
        preserveAncestryOnNullRef.current = false
      } else {
        setCluster(null)
        setFacility(null)
        setUnit(null)
        setRequestedLevel('cluster')
      }
    }
    lastValueRef.current = value ?? null
  }, [value])

  const queryParentScopeType: AssignmentScopeParentType | undefined =
    requestedLevel === 'facility' && cluster ? 'cluster' :
    requestedLevel === 'unit' && facility ? 'facility' :
    undefined
  const queryParentScopeId: string | undefined =
    requestedLevel === 'facility' && cluster ? cluster.scope_id :
    requestedLevel === 'unit' && facility ? facility.scope_id :
    undefined

  const targets = useAssignmentScopeTargets(token, {
    scopeType: requestedLevel,
    ...(queryParentScopeType ? { parentScopeType: queryParentScopeType } : {}),
    ...(queryParentScopeId ? { parentScopeId: queryParentScopeId } : {}),
    enabled: canAssign,
  })

  const scopeTargetOptions: SelectOption[] = useMemo(
    () =>
      targets.items.map((target) => ({
        value: target.scope_id,
        label: isAr ? target.label_ar : target.label_en,
      })),
    [targets.items, isAr],
  )

  function commit(next: AssignmentScopeValue | null) {
    onChange(next)
  }

  function handleLevelChange(next: AssignmentScopeType) {
    if (next === 'record_set') return
    preserveAncestryOnNullRef.current = true
    setRequestedLevel(next)
    if (next === 'cluster') {
      setCluster(null)
      setFacility(null)
      setUnit(null)
      commit(null)
      return
    }
    if (next === 'facility') {
      setFacility(null)
      setUnit(null)
      commit(null)
      return
    }
    if (next === 'unit') {
      setUnit(null)
      commit(null)
    }
  }

  function handleTargetChange(scopeId: string) {
    const row = targets.items.find((item) => item.scope_id === scopeId)
    if (!row) {
      commit(null)
      return
    }
    if (requestedLevel === 'cluster') {
      setCluster({ scope_type: 'cluster', scope_id: scopeId })
      setFacility(null)
      setUnit(null)
      commit({ scope_type: 'cluster', scope_id: scopeId })
      return
    }
    if (requestedLevel === 'facility' && cluster) {
      setFacility({ scope_type: 'facility', scope_id: scopeId })
      setUnit(null)
      commit({ scope_type: 'facility', scope_id: scopeId })
      return
    }
    if (requestedLevel === 'unit' && facility) {
      setUnit({ scope_type: 'unit', scope_id: scopeId })
      commit({ scope_type: 'unit', scope_id: scopeId })
      return
    }
    commit(null)
  }

  const radioLegend = useId()
  const helperId = `${idPrefix}-${HELPER_ID}`

  const disabled = !canAssign
  const clusterChecked = requestedLevel === 'cluster'
  const facilityChecked = requestedLevel === 'facility'
  const unitChecked = requestedLevel === 'unit'
  const selectedAtRequestedLevel =
    requestedLevel === 'cluster' ? cluster : requestedLevel === 'facility' ? facility : unit
  const targetValue = selectedAtRequestedLevel?.scope_id ?? ''

  return (
    <fieldset
      className="assignment-scope-picker"
      data-testid={testId ?? `${idPrefix}-fieldset`}
    >
      <legend id={radioLegend}>{labels.legend}</legend>
      <div role="radiogroup" aria-labelledby={radioLegend} className="assignment-scope-picker-radios">
        <label className="assignment-scope-radio">
          <input
            type="radio"
            name={`${idPrefix}-level`}
            value="cluster"
            checked={clusterChecked}
            disabled={disabled}
            onChange={() => handleLevelChange('cluster')}
          />
          <span>{labels.levelByType.cluster}</span>
        </label>
        <label className="assignment-scope-radio">
          <input
            type="radio"
            name={`${idPrefix}-level`}
            value="facility"
            checked={facilityChecked}
            disabled={disabled || !cluster}
            onChange={() => handleLevelChange('facility')}
          />
          <span>{labels.levelByType.facility}</span>
        </label>
        <label className="assignment-scope-radio">
          <input
            type="radio"
            name={`${idPrefix}-level`}
            value="unit"
            checked={unitChecked}
            disabled={disabled || !cluster || !facility}
            onChange={() => handleLevelChange('unit')}
          />
          <span>{labels.levelByType.unit}</span>
        </label>
        <label className="assignment-scope-radio">
          <input
            type="radio"
            name={`${idPrefix}-level`}
            value="record_set"
            checked={false}
            disabled
            aria-describedby={helperId}
            onChange={() => undefined}
          />
          <span>{labels.levelByType.record_set}</span>
        </label>
      </div>
      <p id={helperId} className="field-help">{labels.helper}</p>
      <Field id={`${idPrefix}-target`} label={labels.target}>
        <Select
          id={`${idPrefix}-target`}
          value={targetValue}
          options={scopeTargetOptions}
          placeholder={labels.targetPlaceholder}
          disabled={disabled || targets.state !== 'ready'}
          onChange={handleTargetChange}
        />
        {targets.state === 'loading' ? (
          <p className="field-help" role="status" aria-live="polite">{labels.loading}</p>
        ) : null}
        {targets.state === 'ready' && targets.items.length === 0 ? (
          <p className="field-help" role="status" aria-live="polite">{labels.empty}</p>
        ) : null}
        {targets.state === 'error' || targets.state === 'forbidden' || targets.state === 'unsupported' ? (
          <InlineError
            message={
              targets.state === 'forbidden' ? labels.forbidden :
              targets.state === 'unsupported' ? labels.unsupported :
              targets.error ?? labels.error
            }
            {...(targets.state === 'error' ? { retryLabel: labels.retry, onRetry: targets.retry } : {})}
          />
        ) : null}
      </Field>
    </fieldset>
  )
}

function findLevel(
  ancestry: readonly AssignmentScopeValue[] | undefined,
  level: AssignmentScopeType,
): AssignmentScopeValue | null {
  if (!ancestry) return null
  for (const row of ancestry) {
    if (row.scope_type === level) return row
  }
  return null
}

function deepestLevel(ancestry: readonly AssignmentScopeValue[] | undefined): AssignmentScopeType | null {
  if (findLevel(ancestry, 'unit')) return 'unit'
  if (findLevel(ancestry, 'facility')) return 'facility'
  if (findLevel(ancestry, 'cluster')) return 'cluster'
  return null
}
