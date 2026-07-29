import { useEffect, useRef, useState } from 'react'

import {
  listAssignments,
  listPeople,
  listPositions,
  stateFromError,
  type Assignment,
  type Person,
  type Position,
} from '../../api'
import { useLocale, useToken } from '../../app/session-context'
import { InlineError, Button, Page, PageHeader, PanelGrid, SkeletonList } from '../../ui'
import { AssignmentsPanel } from './AssignmentsPanel'
import { PeoplePanel } from './PeoplePanel'

export type OrganizationLocale = 'ar' | 'en'

export type PeopleAssignmentsProps = {
  /**
   * Optional handler invoked when the user activates the secondary
   * "Import employees" action. When provided, the page header renders a
   * secondary button labelled in the active locale; otherwise the action is
   * absent so a standalone embed remains uncluttered.
   */
  onImport?: () => void
}

export const peopleAssignmentsCopy = {
  ar: {
    title: 'الموظفون والتكليفات الوظيفية',
    intro: 'إدارة سجل الموظفين والتكليفات الزمنية المرتبطة بالمناصب.',
    loading: 'جارٍ تحميل الموظفين والتكليفات…',
    forbidden: 'لا تملك صلاحية إدارة الموظفين والتكليفات.',
    error: 'تعذر تحميل الموظفين والتكليفات.',
    retry: 'إعادة المحاولة',
    people: 'الموظفون',
    assignments: 'التكليفات',
    employee: 'الموظف',
    employeeNumber: 'الرقم الوظيفي',
    nameAr: 'الاسم بالعربية',
    nameEn: 'الاسم بالإنجليزية',
    status: 'الحالة',
    person: 'الموظف',
    jobTitle: 'المسمى الوظيفي',
    startAt: 'بداية التكليف',
    endAt: 'نهاية التكليف',
    endReason: 'سبب الإنهاء',
    current: 'الحالة الحالية',
    actions: 'الإجراءات',
    addPerson: 'إضافة موظف',
    editPerson: 'تعديل بيانات الموظف',
    createAssignment: 'إنشاء تكليف',
    endAssignment: 'إنهاء التكليف',
    noPeople: 'لا يوجد موظفون بعد.',
    noAssignments: 'لا توجد تكليفات بعد.',
    noActivePeople: 'أضف موظفاً نشطاً واحداً على الأقل قبل إنشاء تكليف.',
    noActivePositions: 'أضف منصباً نشطاً واحداً على الأقل قبل إنشاء تكليف.',
    noActivePeopleOrPositions: 'أضف موظفاً نشطاً ومنصباً نشطاً قبل إنشاء تكليف.',
    active: 'نشط',
    suspended: 'موقوف',
    left: 'غادر',
    archived: 'مؤرشف',
    pending: 'قادم',
    ended: 'منتهٍ',
    primary: 'تكليف أساسي',
    primaryHelp: 'يجعل هذا التكليف المرجع الأساسي للموظف خلال فترة سريانه.',
    close: 'إغلاق',
    cancel: 'إلغاء',
    save: 'حفظ',
    saving: 'جارٍ الحفظ…',
    creating: 'جارٍ الإنشاء…',
    ending: 'جارٍ الإنهاء…',
    savePerson: 'حفظ الموظف',
    saveAssignment: 'حفظ التكليف',
    validation: 'أكمل الحقول المطلوبة وتحقق من الفترة.',
    saveError: 'لم يُحفظ التغيير. راجع البيانات ثم أعد المحاولة.',
    stale: 'تغيرت البيانات في مكان آخر. حدّث القائمة ثم أعد المحاولة.',
    endedSuccess: 'تم إنهاء التكليف.',
    endAtRequired: 'أدخل وقت نهاية التكليف وسبباً واضحاً بعد بداية التكليف.',
    importEmployees: 'استيراد موظفين',
  },
  en: {
    title: 'Employees and job assignments',
    intro: 'Manage the employee registry and the dated assignments linked to positions.',
    loading: 'Loading employees and assignments…',
    forbidden: 'You do not have permission to manage employees and assignments.',
    error: 'Employees and assignments could not be loaded.',
    retry: 'Try again',
    people: 'Employees',
    assignments: 'Assignments',
    employee: 'Employee',
    employeeNumber: 'Employee number',
    nameAr: 'Name in Arabic',
    nameEn: 'Name in English',
    status: 'Status',
    person: 'Employee',
    jobTitle: 'Job title',
    startAt: 'Assignment start',
    endAt: 'Assignment end',
    endReason: 'End reason',
    current: 'Current state',
    actions: 'Actions',
    addPerson: 'Add employee',
    editPerson: 'Edit employee details',
    createAssignment: 'Create assignment',
    endAssignment: 'End assignment',
    noPeople: 'No employees yet.',
    noAssignments: 'No assignments yet.',
    noActivePeople: 'Add at least one active employee before creating an assignment.',
    noActivePositions: 'Add at least one active position before creating an assignment.',
    noActivePeopleOrPositions: 'Add an active employee and an active position before creating an assignment.',
    active: 'Active',
    suspended: 'Suspended',
    left: 'Left',
    archived: 'Archived',
    pending: 'Pending',
    ended: 'Ended',
    primary: 'Primary assignment',
    primaryHelp: 'Makes this the employee’s reference assignment while it is effective.',
    close: 'Close',
    cancel: 'Cancel',
    save: 'Save',
    saving: 'Saving…',
    creating: 'Creating…',
    ending: 'Ending…',
    savePerson: 'Save employee',
    saveAssignment: 'Save assignment',
    validation: 'Complete the required fields and check the assignment period.',
    saveError: 'The change was not saved. Review the data and try again.',
    stale: 'The data changed elsewhere. Refresh the list and try again.',
    endedSuccess: 'Assignment ended.',
    endAtRequired: 'Enter an end time and a clear reason after the assignment start.',
    importEmployees: 'Import employees',
  },
} as const

export type PeopleAssignmentsText = (typeof peopleAssignmentsCopy)[OrganizationLocale]

export function PeopleAssignments({ onImport }: PeopleAssignmentsProps = {}) {
  const locale = useLocale()
  const token = useToken()
  const text = peopleAssignmentsCopy[locale]
  const [people, setPeople] = useState<Person[]>([])
  const [positions, setPositions] = useState<Position[]>([])
  const [assignments, setAssignments] = useState<Assignment[]>([])
  const [loading, setLoading] = useState(true)
  const [state, setState] = useState<'ready' | 'forbidden' | 'error'>('ready')
  /**
   * Track in-flight load requests so a superseded token change cannot
   * overwrite the freshest snapshot. The unmount cleanup bumps the ref so no
   * async callback writes after the people/assignments route is torn down.
   */
  const activeRef = useRef(true)
  const loadRequestRef = useRef(0)
  useEffect(() => () => { activeRef.current = false; loadRequestRef.current += 1 }, [])

  async function load() {
    const epoch = ++loadRequestRef.current
    activeRef.current = true
    setLoading(true)
    setState('ready')
    try {
      const [peoplePage, positionPage, assignmentPage] = await Promise.all([
        listPeople(token),
        listPositions(token),
        listAssignments(token),
      ])
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setPeople(peoplePage.items)
      setPositions(positionPage.items)
      setAssignments(assignmentPage.items)
    } catch (caught) {
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setPeople([])
      setPositions([])
      setAssignments([])
      setState(stateFromError(caught) === 'forbidden' ? 'forbidden' : 'error')
    } finally {
      if (activeRef.current && epoch === loadRequestRef.current) setLoading(false)
    }
  }

  useEffect(() => {
    void load()
  }, [token])

  return (
    <Page>
      <PageHeader
        id="people-heading"
        title={text.title}
        description={text.intro}
        actions={onImport ? <Button variant="secondary" onClick={onImport}>{text.importEmployees}</Button> : undefined}
      />
      {loading ? <SkeletonList label={text.loading} /> : null}
      {!loading && state === 'forbidden' ? <div className="state-panel" role="status"><p>{text.forbidden}</p></div> : null}
      {!loading && state === 'error' ? <InlineError message={text.error} retryLabel={text.retry} onRetry={() => void load()} /> : null}
      {!loading && state === 'ready' ? (
        <PanelGrid>
          <PeoplePanel locale={locale} token={token} people={people} onSaved={(saved) => setPeople((current) => current.some((person) => person.id === saved.id) ? current.map((person) => person.id === saved.id ? saved : person) : [...current, saved])} />
          <AssignmentsPanel locale={locale} token={token} people={people} positions={positions} assignments={assignments} onCreated={(saved) => setAssignments((current) => [...current, saved])} onEnded={(saved) => setAssignments((current) => current.map((assignment) => assignment.id === saved.id ? saved : assignment))} />
        </PanelGrid>
      ) : null}
    </Page>
  )
}