import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
  type PointerEvent as ReactPointerEvent,
  type FormEvent,
} from 'react'
import {
  Briefcase,
  ChevronDown,
  ChevronRight,
  ChevronUp,
  Info,
  Maximize2,
  Minimize2,
  Minus,
  Network,
  Plus,
  RotateCcw,
  Users,
} from 'lucide-react'

import type { Facility, OrganizationUnit, Position, UpdateOrganizationUnitInput, UpdatePositionInput } from '../../api'
import { Button, Drawer, Field, Select, cx } from '../../ui'
import {
  autoLayoutCards,
  buildEdges,
  CARD_HEIGHT,
  CARD_WIDTH,
  fitToViewport,
  loadBoardLayout,
  saveBoardLayout,
  screenDeltaToWorld,
  zoomAround,
  DEFAULT_VIEWPORT,
  type BoardCardPosition,
  type BoardViewport,
} from './board-layout'
import { computeDescendantsByUnit, computeHiddenIds, countPositionsByBranch, wouldCreateCycle } from './tree'

type Locale = 'ar' | 'en'

const copy = {
  ar: {
    title: 'لوحة الهيكل التنظيمي',
    canvasLabel: 'لوحة بصرية للهيكل التنظيمي قابلة للسحب والتكبير',
    toolbarLabel: 'أدوات اللوحة',
    zoomIn: 'تكبير',
    zoomOut: 'تصغير',
    reset: 'إعادة الضبط',
    fullscreen: 'ملء الشاشة',
    exitFullscreen: 'إنهاء ملء الشاشة',
    zoomLevel: (zoom: number) => `مستوى التكبير ${Math.round(zoom * 100)}%`,
    zoomLive: (zoom: number) => `${Math.round(zoom * 100)}%`,
    shortcutsHint:
      'اسحب الخلفية الفارغة للتحريك، استخدم العجلة أو +/- للتكبير، 0 للإعادة، F للملاءمة، Enter لفتح البطاقة، Esc للإغلاق.',
    noUnits: 'لا توجد وحدات بعد.',
    noUnitsBody: 'أضف أول وحدة من زر "إضافة وحدة" بالأعلى لتظهر في اللوحة.',
    cardAria: (unit: OrganizationUnit) => `${unit.name_ar}، رمز ${unit.code}`,
    positionsShort: 'مناصب',
    childrenShort: 'وحدات فرعية',
    popupTitle: 'تفاصيل الوحدة',
    fieldCode: 'الرمز',
    fieldNameAr: 'الاسم بالعربية',
    fieldNameEn: 'الاسم بالإنجليزية',
    fieldType: 'النوع',
    fieldStatus: 'الحالة',
    fieldDepth: 'العمق',
    fieldParent: 'الوحدة الأم',
    noParent: 'لا يوجد (مرتبطة بالقطاع/المنشأة)',
    fieldChildren: 'الوحدات الفرعية',
    fieldPositions: 'المناصب المرتبطة',
    noChildren: 'لا توجد وحدات فرعية.',
    noPositions: 'لا توجد مناصب مرتبطة مباشرة بهذه الوحدة.',
    addChild: 'إضافة وحدة فرعية',
    addPosition: 'إضافة منصب هنا',
    active: 'نشط',
    inactive: 'غير نشط',
    archived: 'مؤرشف',
    edit: 'تحرير الوحدة',
    cancel: 'إلغاء',
    save: 'حفظ التغيير',
    saving: 'جارٍ الحفظ…',
    updateError: 'تعذر حفظ التغيير. قد تكون البيانات قديمة؛ أعد المحاولة.',
    parentCycle: 'لا يمكن نقل الوحدة إلى نفسها أو إلى وحدة تابعة لها.',
    parentUnavailable: 'لا يمكن تغيير هذا الأصل من هذه الشاشة.',
    editPosition: 'تعديل المنصب',
    positionTitle: 'مسمى المنصب',
    savePosition: 'حفظ المنصب',
    collapse: (name: string) => `طي أبناء ${name}`,
    expand: (name: string) => `إظهار أبناء ${name}`,
    collapsedBadge: 'مطوي',
    hasHiddenChildren: (count: number) => `${count} مطوي`,
    openDetails: (name: string) => `فتح تفاصيل ${name}`,
    details: 'تفاصيل',
  },
  en: {
    title: 'Organization board',
    canvasLabel: 'Visual board of the organization tree, draggable and zoomable',
    toolbarLabel: 'Board controls',
    zoomIn: 'Zoom in',
    zoomOut: 'Zoom out',
    reset: 'Reset',
    fullscreen: 'Fullscreen',
    exitFullscreen: 'Exit fullscreen',
    zoomLevel: (zoom: number) => `Zoom level ${Math.round(zoom * 100)}%`,
    zoomLive: (zoom: number) => `${Math.round(zoom * 100)}%`,
    shortcutsHint:
      'Drag empty background to pan. Use the wheel or +/- to zoom, 0 to reset, F to fit, Enter to open a card, Esc to close.',
    noUnits: 'No units yet.',
    noUnitsBody: 'Use the "Add unit" button above to add your first unit and it will appear on the board.',
    cardAria: (unit: OrganizationUnit) => `${unit.name_ar}, code ${unit.code}`,
    positionsShort: 'positions',
    childrenShort: 'children',
    popupTitle: 'Unit details',
    fieldCode: 'Code',
    fieldNameAr: 'Arabic name',
    fieldNameEn: 'English name',
    fieldType: 'Type',
    fieldStatus: 'Status',
    fieldDepth: 'Depth',
    fieldParent: 'Parent unit',
    noParent: 'None (attached to cluster/facility)',
    fieldChildren: 'Child units',
    fieldPositions: 'Positions in this unit',
    noChildren: 'No child units.',
    noPositions: 'No positions directly attached to this unit.',
    addChild: 'Add child unit',
    addPosition: 'Add position here',
    active: 'Active',
    inactive: 'Inactive',
    archived: 'Archived',
    edit: 'Edit unit',
    cancel: 'Cancel',
    save: 'Save change',
    saving: 'Saving…',
    updateError: 'The change could not be saved. The data may be stale; try again.',
    parentCycle: 'A unit cannot move under itself or one of its descendants.',
    parentUnavailable: 'This parent cannot be changed from this screen.',
    editPosition: 'Edit position',
    positionTitle: 'Position title',
    savePosition: 'Save position',
    collapse: (name: string) => `Collapse children of ${name}`,
    expand: (name: string) => `Expand children of ${name}`,
    collapsedBadge: 'Collapsed',
    hasHiddenChildren: (count: number) => `${count} hidden`,
    openDetails: (name: string) => `Open details of ${name}`,
    details: 'Details',
  },
} as const

const COLLAPSED_STORAGE_KEY = 'cluster.org-board.tree.collapsed.v1';

interface Copy {
  title: string
  canvasLabel: string
  toolbarLabel: string
  zoomIn: string
  zoomOut: string
  reset: string
  fullscreen: string
  exitFullscreen: string
  zoomLevel: (zoom: number) => string
  zoomLive: (zoom: number) => string
  shortcutsHint: string
  noUnits: string
  noUnitsBody: string
  cardAria: (unit: OrganizationUnit) => string
  positionsShort: string
  childrenShort: string
  popupTitle: string
  fieldCode: string
  fieldNameAr: string
  fieldNameEn: string
  fieldType: string
  fieldStatus: string
  fieldDepth: string
  fieldParent: string
  noParent: string
  fieldChildren: string
  fieldPositions: string
  noChildren: string
  noPositions: string
  addChild: string
  addPosition: string
  active: string
  inactive: string
  archived: string
  edit: string
  cancel: string
  save: string
  saving: string
  updateError: string
  parentCycle: string
  parentUnavailable: string
  editPosition: string
  positionTitle: string
  savePosition: string
  collapse: (name: string) => string
  expand: (name: string) => string
  collapsedBadge: string
  hasHiddenChildren: (count: number) => string
  openDetails: (name: string) => string
}

const typeLabel = (locale: Locale, code: string): string => {
  const map: Record<Locale, Record<string, string>> = {
    ar: { sector: 'قطاع', department: 'إدارة', section: 'قسم', unit: 'وحدة' },
    en: { sector: 'Sector', department: 'Department', section: 'Section', unit: 'Unit' },
  }
  return map[locale][code] ?? code
}

interface Props {
  locale: Locale
  units: OrganizationUnit[]
  facilities: Facility[]
  positions: Position[]
  selectedUnitId: string | null
  onSelectUnit: (unitId: string | null) => void
  onAddChild: (parentUnitId: string) => void
  onAddPosition: (unitId: string) => void
  onUpdateUnit: (unit: OrganizationUnit, patch: UpdateOrganizationUnitInput) => Promise<OrganizationUnit>
  onUpdatePosition: (position: Position, patch: UpdatePositionInput) => Promise<Position>
}

function loadCollapsedSet(): Set<string> {
  if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') return new Set();
  try {
    const raw = window.localStorage.getItem(COLLAPSED_STORAGE_KEY);
    if (raw === null) return new Set();
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return new Set();
    return new Set(parsed.filter((value): value is string => typeof value === 'string' && value.length > 0));
  } catch {
    return new Set();
  }
}

function saveCollapsedSet(set: Set<string>): void {
  if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') return;
  try {
    window.localStorage.setItem(COLLAPSED_STORAGE_KEY, JSON.stringify(Array.from(set)));
  } catch {
    /* localStorage may be unavailable in private mode — ignore. */
  }
}

/**
 * Free-form board that replaces the side-by-side tree + positions table.
 *
 * Architecture:
 * - The outer `.org-board-canvas` element is the pan/zoom target. Pointer
 *   events captured here drive the background pan; child cards stop
 *   propagation so their own pointer events do not trigger it.
 * - The inner `.org-board-content` is positioned via a single CSS transform
 *   (`translate(pan) scale(zoom)`). All cards and the SVG edge layer share
 *   that transform so connectors stay glued to card edges under zoom.
 * - Card positions and the viewport are persisted to localStorage so the
 *   operator's layout survives reloads.
 */
export function OrganizationBoard({
  locale,
  units,
  facilities,
  positions,
  selectedUnitId,
  onSelectUnit,
  onAddChild,
  onAddPosition,
  onUpdateUnit,
  onUpdatePosition,
}: Props) {
  const text: Copy = copy[locale]
  const containerRef = useRef<HTMLDivElement>(null)
  const hostRef = useRef<HTMLDivElement>(null)
  const panRef = useRef<{ pointerId: number; startX: number; startY: number; vx: number; vy: number } | null>(null)
  const cardDragRef = useRef<{
    id: string
    pointerId: number
    startX: number
    startY: number
    origX: number
    origY: number
    moved: boolean
  } | null>(null)
  const initialisedRef = useRef(false)
  const hasStoredViewportRef = useRef(false)
  const autoFitDoneRef = useRef(false)

  const [viewport, setViewport] = useState<BoardViewport>({ ...DEFAULT_VIEWPORT })
  const [cards, setCards] = useState<Record<string, BoardCardPosition>>({})
  const [size, setSize] = useState<{ width: number; height: number }>({ width: 0, height: 0 })
  const [popupUnitId, setPopupUnitId] = useState<string | null>(null)
  const [fullscreenMode, setFullscreenMode] = useState<'none' | 'native' | 'fallback'>('none')
  const [collapsedIds, setCollapsedIds] = useState<Set<string>>(() => loadCollapsedSet())

  // Persist collapse state whenever it changes.
  useEffect(() => {
    saveCollapsedSet(collapsedIds)
  }, [collapsedIds])

  // Build descendant map so we can hide entire subtrees when a node is
  // collapsed — counts and edges then reflect only the visible units.
  const descendantsByUnit = useMemo(() => computeDescendantsByUnit(units), [units])

  const hiddenIds = useMemo(
    () => computeHiddenIds(collapsedIds, descendantsByUnit),
    [collapsedIds, descendantsByUnit],
  )

  const visibleUnits = useMemo(
    () => units.filter((unit) => !hiddenIds.has(unit.id)),
    [units, hiddenIds],
  )

  // Mirror of `cards` for use inside event listeners registered once.
  const cardsRef = useRef(cards)
  useEffect(() => {
    cardsRef.current = cards
  }, [cards])

  // First-time initialisation: merge persisted layout with auto-layout for
  // any units that have no saved position. Re-runs only when the unit set
  // changes; thereafter the user owns the layout.
  useEffect(() => {
    const stored = initialisedRef.current ? null : loadBoardLayout()
    const auto = autoLayoutCards(visibleUnits)
    const merged: Record<string, BoardCardPosition> = {}
    for (const u of visibleUnits) {
      const saved = stored?.cards?.[u.id]
      merged[u.id] = saved ?? auto[u.id] ?? { x: 0, y: 0 }
    }
    setCards(merged)
    if (!initialisedRef.current) {
      initialisedRef.current = true
      if (stored?.viewport) {
        hasStoredViewportRef.current = true
        setViewport(stored.viewport)
      }
    }
  }, [visibleUnits])

  // Auto-centre on first load: when there is no persisted viewport, fit the
  // whole board into the visible canvas once its size is known. Runs exactly
  // once per mount so the operator keeps control afterwards.
  useEffect(() => {
    if (autoFitDoneRef.current) return
    if (hasStoredViewportRef.current) return
    if (visibleUnits.length === 0) return
    if (size.width === 0 || size.height === 0) return
    autoFitDoneRef.current = true
    setViewport(fitToViewport(cards, size.width, size.height))
    // cards/size are read once; re-running on every drag would fight the user.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [size.width, size.height, visibleUnits.length])

  // Track the canvas size for fit-to-viewport math.
  useEffect(() => {
    const el = containerRef.current
    if (el === null) return
    const update = () => {
      const rect = el.getBoundingClientRect()
      setSize({ width: rect.width, height: rect.height })
    }
    update()
    const ro = new ResizeObserver(update)
    ro.observe(el)
    return () => ro.disconnect()
  }, [])

  // Persist layout whenever the viewport or card positions change.
  useEffect(() => {
    if (!initialisedRef.current) return
    saveBoardLayout({ viewport, cards })
  }, [viewport, cards])

  const edges = useMemo(() => buildEdges(visibleUnits, cards), [visibleUnits, cards])
  const counts = useMemo(() => countPositionsByBranch(positions, visibleUnits), [positions, visibleUnits])
  const unitNames = useMemo(() => new Map(units.map((u) => [u.id, u])), [units])

  const handleToggleCollapse = useCallback((unitId: string) => {
    setCollapsedIds((current) => {
      const next = new Set(current)
      if (next.has(unitId)) {
        next.delete(unitId)
      } else {
        next.add(unitId)
        // Hide the drawer and selection if they point at a now-hidden node.
        setPopupUnitId((openId) => (openId !== null && (openId === unitId || hiddenIds.has(openId)) ? null : openId))
        if (selectedUnitId !== null && (selectedUnitId === unitId || hiddenIds.has(selectedUnitId))) {
          onSelectUnit(null)
        }
      }
      return next
    })
  }, [hiddenIds, onSelectUnit, selectedUnitId])

  const hiddenChildCountByUnit = useMemo(() => {
    const result = new Map<string, number>()
    for (const unit of units) {
      const all = descendantsByUnit.get(unit.id) ?? []
      if (all.length === 0) continue
      const hidden = all.reduce((count, id) => count + (hiddenIds.has(id) ? 1 : 0), 0)
      if (hidden > 0) result.set(unit.id, hidden)
    }
    return result
  }, [units, descendantsByUnit, hiddenIds])

  const handleBackgroundPointerDown = useCallback((event: ReactPointerEvent<HTMLDivElement>) => {
    if (event.target !== event.currentTarget) return
    if (event.button !== 0) return
    event.currentTarget.setPointerCapture(event.pointerId)
    panRef.current = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      vx: viewport.panX,
      vy: viewport.panY,
    }
  }, [viewport.panX, viewport.panY])

  const handleCanvasPointerMove = useCallback((event: ReactPointerEvent<HTMLDivElement>) => {
    // Card drag (in-flight).
    if (cardDragRef.current !== null && cardDragRef.current.pointerId === event.pointerId) {
      const drag = cardDragRef.current
      const dx = event.clientX - drag.startX
      const dy = event.clientY - drag.startY
      if (Math.abs(dx) > 3 || Math.abs(dy) > 3) drag.moved = true
      const world = screenDeltaToWorld(dx, dy, viewport.zoom)
      const next = { x: drag.origX + world.dx, y: drag.origY + world.dy }
      setCards((current) => ({ ...current, [drag.id]: next }))
      return
    }
    // Background pan.
    if (panRef.current !== null && panRef.current.pointerId === event.pointerId) {
      const pan = panRef.current
      setViewport((v) => ({
        ...v,
        panX: pan.vx + (event.clientX - pan.startX),
        panY: pan.vy + (event.clientY - pan.startY),
      }))
    }
  }, [viewport.zoom])

  const handleCanvasPointerUp = useCallback((event: ReactPointerEvent<HTMLDivElement>) => {
    if (panRef.current !== null && panRef.current.pointerId === event.pointerId) {
      try { event.currentTarget.releasePointerCapture(event.pointerId) } catch { /* ignore */ }
      panRef.current = null
    }
    if (cardDragRef.current !== null && cardDragRef.current.pointerId === event.pointerId) {
      const drag = cardDragRef.current
      try { event.currentTarget.releasePointerCapture(event.pointerId) } catch { /* ignore */ }
      if (!drag.moved) {
        setPopupUnitId(drag.id)
        onSelectUnit(drag.id)
      }
      cardDragRef.current = null
    }
  }, [onSelectUnit])

  // Native wheel listener: React registers onWheel as passive at the root,
  // so event.preventDefault() inside a JSX handler silently does nothing and
  // the page scrolls behind the canvas. A non-passive listener is required
  // for zoom-on-wheel to work.
  useEffect(() => {
    const el = containerRef.current
    if (el === null) return
    const onWheel = (event: WheelEvent) => {
      event.preventDefault()
      const rect = el.getBoundingClientRect()
      const originX = event.clientX - rect.left
      const originY = event.clientY - rect.top
      const factor = event.deltaY < 0 ? 1.1 : 1 / 1.1
      setViewport((v) => zoomAround(v, factor, originX, originY))
    }
    el.addEventListener('wheel', onWheel, { passive: false })
    return () => el.removeEventListener('wheel', onWheel)
  }, [])

  const handleCanvasKeyDown = useCallback((event: ReactKeyboardEvent<HTMLDivElement>) => {
    if (popupUnitId !== null) return
    const rect = containerRef.current?.getBoundingClientRect()
    const cx = rect !== undefined ? rect.width / 2 : 0
    const cy = rect !== undefined ? rect.height / 2 : 0
    switch (event.key) {
      case '+':
      case '=':
        event.preventDefault()
        setViewport((v) => zoomAround(v, 1.2, cx, cy))
        return
      case '-':
      case '_':
        event.preventDefault()
        setViewport((v) => zoomAround(v, 1 / 1.2, cx, cy))
        return
      case '0':
        event.preventDefault()
        setViewport({ ...DEFAULT_VIEWPORT })
        return
      case 'f':
      case 'F':
        event.preventDefault()
        setViewport(() => fitToViewport(cards, size.width, size.height))
        return
      case 'Escape':
        if (selectedUnitId !== null) {
          event.preventDefault()
          onSelectUnit(null)
        }
        return
      default:
        return
    }
  }, [popupUnitId, cards, size.width, size.height, selectedUnitId, onSelectUnit])

  const handleCardPointerDown = useCallback((unitId: string) => (event: ReactPointerEvent<HTMLDivElement>) => {
    if (event.button !== 0) return
    const pos = cards[unitId]
    if (pos === undefined) return
    event.stopPropagation()
    event.currentTarget.setPointerCapture(event.pointerId)
    cardDragRef.current = {
      id: unitId,
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      origX: pos.x,
      origY: pos.y,
      moved: false,
    }
  }, [cards])

  const handleReset = useCallback(() => {
    setViewport({ ...DEFAULT_VIEWPORT })
  }, [])

  // Re-fit the content into the current host surface. Used when entering
  // fullscreen so the larger surface does not leave the board off-centre.
  const scheduleFit = useCallback(() => {
    window.requestAnimationFrame(() => {
      const host = hostRef.current
      if (host === null) return
      const rect = host.getBoundingClientRect()
      if (rect.width === 0 || rect.height === 0) return
      setViewport(fitToViewport(cardsRef.current, rect.width, rect.height, 32))
    })
  }, [])

  // Fullscreen toggle: prefer the native Fullscreen API; fall back to a
  // fixed-position expanded class where element fullscreen is unsupported
  // (e.g. iPhone Safari) or rejected.
  const toggleFullscreen = useCallback(() => {
    const host = hostRef.current
    if (host === null) return
    if (document.fullscreenElement === host) {
      void document.exitFullscreen()
      return
    }
    setFullscreenMode((current) => {
      if (current === 'fallback') return 'none'
      if (typeof host.requestFullscreen === 'function') {
        host.requestFullscreen().catch(() => setFullscreenMode('fallback'))
        return current
      }
      return 'fallback'
    })
  }, [])

  // Keep React state in sync when the user exits native fullscreen with Esc
  // or the browser UI. Re-fit once on entry so the board uses the new space.
  useEffect(() => {
    const onChange = () => {
      const active = document.fullscreenElement === hostRef.current
      if (active) {
        setFullscreenMode('native')
        scheduleFit()
      } else {
        setFullscreenMode((current) => (current === 'native' ? 'none' : current))
      }
    }
    document.addEventListener('fullscreenchange', onChange)
    return () => document.removeEventListener('fullscreenchange', onChange)
  }, [scheduleFit])

  // Fallback expanded mode: fit on entry and close on Escape.
  useEffect(() => {
    if (fullscreenMode !== 'fallback') return
    scheduleFit()
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setFullscreenMode('none')
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [fullscreenMode, scheduleFit])

  const handleZoomIn = useCallback(() => {
    setViewport((v) => zoomAround(v, 1.2, size.width / 2, size.height / 2))
  }, [size.width, size.height])

  const handleZoomOut = useCallback(() => {
    setViewport((v) => zoomAround(v, 1 / 1.2, size.width / 2, size.height / 2))
  }, [size.width, size.height])

  const isFullscreen = fullscreenMode !== 'none'

  if (units.length === 0) {
    return (
      <div className="org-board-empty" role="status">
        <span className="org-board-empty-icon" aria-hidden="true"><Network /></span>
        <strong>{text.noUnits}</strong>
        <p>{text.noUnitsBody}</p>
      </div>
    )
  }

  const popupUnit = popupUnitId !== null ? unitNames.get(popupUnitId) ?? null : null
  const popupPositions = popupUnit !== null
    ? positions.filter((p) => p.organization_unit_id === popupUnit.id)
    : []
  const popupChildren = popupUnit !== null
    ? units.filter((u) => u.parent_type === 'unit' && u.parent_id === popupUnit.id)
    : []
  const popupParent = popupUnit !== null && popupUnit.parent_type === 'unit'
    ? unitNames.get(popupUnit.parent_id) ?? null
    : null

  const transform = `translate(${viewport.panX}px, ${viewport.panY}px) scale(${viewport.zoom})`

  return (
    <div
      ref={hostRef}
      className={cx('org-board-host', fullscreenMode === 'fallback' && 'org-board-host-expanded')}
    >
      <div className="org-board-toolbar" role="toolbar" aria-label={text.toolbarLabel}>
        <span className="org-board-toolbar-title">{text.title}</span>
        <span className="org-board-toolbar-zoom" aria-live="polite">{text.zoomLive(viewport.zoom)}</span>
        <div className="org-board-toolbar-actions">
          <Button
            variant="secondary"
            type="button"
            className="org-board-toolbar-button"
            onClick={handleZoomOut}
            aria-label={text.zoomOut}
            title={text.zoomOut}
          >
            <Minus aria-hidden="true" />
          </Button>
          <Button
            variant="secondary"
            type="button"
            className="org-board-toolbar-button"
            onClick={handleZoomIn}
            aria-label={text.zoomIn}
            title={text.zoomIn}
          >
            <Plus aria-hidden="true" />
          </Button>
          <Button
            variant="secondary"
            type="button"
            className="org-board-toolbar-button"
            onClick={handleReset}
            aria-label={text.reset}
            title={text.reset}
          >
            <RotateCcw aria-hidden="true" />
          </Button>
          <Button
            variant="secondary"
            type="button"
            className="org-board-toolbar-button"
            onClick={toggleFullscreen}
            aria-label={isFullscreen ? text.exitFullscreen : text.fullscreen}
            title={isFullscreen ? text.exitFullscreen : text.fullscreen}
            aria-pressed={isFullscreen}
          >
            {isFullscreen ? <Minimize2 aria-hidden="true" /> : <Maximize2 aria-hidden="true" />}
          </Button>
        </div>
      </div>
      <div
        ref={containerRef}
        className="org-board-canvas"
        role="application"
        tabIndex={0}
        aria-label={text.canvasLabel}
        aria-describedby="org-board-shortcuts"
        onPointerDown={handleBackgroundPointerDown}
        onPointerMove={handleCanvasPointerMove}
        onPointerUp={handleCanvasPointerUp}
        onPointerCancel={handleCanvasPointerUp}
        onKeyDown={handleCanvasKeyDown}
      >
        <p id="org-board-shortcuts" className="org-board-shortcuts">{text.shortcutsHint}</p>
        <div
          className="org-board-content"
          style={{ transform, transformOrigin: '0 0' }}
        >
          <svg className="org-board-edges" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <defs>
              <linearGradient id="org-board-edge-gradient" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="var(--color-primary)" stopOpacity="0.55" />
                <stop offset="100%" stopColor="var(--color-primary)" stopOpacity="0.2" />
              </linearGradient>
            </defs>
            {edges.map((edge) => {
              const x1 = edge.from.x + CARD_WIDTH / 2
              const y1 = edge.from.y + CARD_HEIGHT
              const x2 = edge.to.x + CARD_WIDTH / 2
              const y2 = edge.to.y
              const mid = (y1 + y2) / 2
              return (
                <path
                  key={edge.key}
                  d={`M ${x1} ${y1} C ${x1} ${mid}, ${x2} ${mid}, ${x2} ${y2}`}
                  fill="none"
                  stroke="url(#org-board-edge-gradient)"
                  strokeWidth={1.6}
                  className="org-board-edge"
                />
              )
            })}
          </svg>
          {visibleUnits.map((unit) => {
            const pos = cards[unit.id]
            if (pos === undefined) return null
            const selected = unit.id === selectedUnitId
            const popupOpen = unit.id === popupUnitId
            const directChildCount = directChildCountFor(unit.id, visibleUnits)
            const totalDescendantCount = (descendantsByUnit.get(unit.id) ?? []).length
            const isCollapsed = collapsedIds.has(unit.id)
            const hiddenDescendants = hiddenChildCountByUnit.get(unit.id) ?? 0
            return (
              <BoardCard
                key={unit.id}
                unit={unit}
                position={pos}
                positionCount={counts.get(unit.id) ?? 0}
                directChildCount={directChildCount}
                totalDescendantCount={totalDescendantCount}
                isCollapsed={isCollapsed && directChildCount > 0}
                hiddenDescendants={hiddenDescendants}
                selected={selected}
                popupOpen={popupOpen}
                locale={locale}
                text={text}
                onPointerDown={handleCardPointerDown(unit.id)}
                onOpen={() => { setPopupUnitId(unit.id); onSelectUnit(unit.id) }}
                onToggleCollapse={() => handleToggleCollapse(unit.id)}
              />
            )
          })}
        </div>
      </div>
      <Drawer
        open={popupUnit !== null}
        onClose={() => setPopupUnitId(null)}
        title={popupUnit?.name_ar ?? text.popupTitle}
      >
        {popupUnit !== null && (
          <UnitDetailsPanel
            unit={popupUnit}
            parent={popupParent}
            childUnits={popupChildren}
            positions={popupPositions}
            locale={locale}
            text={text}
            units={units}
            facilities={facilities}
            onAddChild={() => { setPopupUnitId(null); onAddChild(popupUnit.id) }}
            onAddPosition={() => { setPopupUnitId(null); onAddPosition(popupUnit.id) }}
            onUpdateUnit={onUpdateUnit}
            onUpdatePosition={onUpdatePosition}
          />
        )}
      </Drawer>
    </div>
  )
}

function childCountFor(unitId: string, units: OrganizationUnit[]): number {
  let count = 0
  for (const u of units) {
    if (u.parent_type === 'unit' && u.parent_id === unitId) count += 1
  }
  return count
}

function directChildCountFor(unitId: string, units: OrganizationUnit[]): number {
  return childCountFor(unitId, units)
}

interface BoardCardProps {
  unit: OrganizationUnit
  position: BoardCardPosition
  positionCount: number
  directChildCount: number
  totalDescendantCount: number
  isCollapsed: boolean
  hiddenDescendants: number
  selected: boolean
  popupOpen: boolean
  locale: Locale
  text: Copy
  onPointerDown: (event: ReactPointerEvent<HTMLDivElement>) => void
  onOpen: () => void
  onToggleCollapse: () => void
}

function BoardCard({
  unit,
  position,
  positionCount,
  directChildCount,
  totalDescendantCount,
  isCollapsed,
  hiddenDescendants,
  selected,
  popupOpen,
  locale,
  text,
  onPointerDown,
  onOpen,
  onToggleCollapse,
}: BoardCardProps) {
  const status = unit.status === 'active' ? text.active : unit.status === 'archived' ? text.archived : text.inactive
  const hasDirectChildren = directChildCount > 0
  const showHiddenBadge = isCollapsed && hiddenDescendants > 0
  const hiddenBadgeText = `${hiddenDescendants}+ ${text.collapsedBadge}`
  const collapseLabel = isCollapsed ? text.expand(unit.name_ar) : text.collapse(unit.name_ar)
  return (
    <div
      role="button"
      tabIndex={0}
      aria-label={text.cardAria(unit)}
      aria-pressed={popupOpen}
      className={cx('org-board-card', selected && 'org-board-card-selected', popupOpen && 'org-board-card-open', isCollapsed && 'org-board-card-collapsed')}
      style={{
        left: `${position.x}px`,
        top: `${position.y}px`,
        width: `${CARD_WIDTH}px`,
        minHeight: `${CARD_HEIGHT}px`,
      }}
      onPointerDown={onPointerDown}
      onKeyDown={(event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault()
          onOpen()
        } else if ((event.key === 'c' || event.key === 'C') && hasDirectChildren) {
          event.preventDefault()
          onToggleCollapse()
        }
      }}
    >
      <div className="org-board-card-header">
        <span className="org-board-card-code" dir="ltr">{unit.code}</span>
        <span className="org-board-card-type">{typeLabel(locale, unit.type_code)}</span>
      </div>
      <div className="org-board-card-name">{unit.name_ar}</div>
      <div className="org-board-card-meta">
        <span className="org-board-card-status" data-status={unit.status}>{status}</span>
        <span className="org-board-card-stat" title={text.positionsShort}>
          <Briefcase aria-hidden="true" />
          <span>{positionCount}</span>
        </span>
        {hasDirectChildren && (
          <span className="org-board-card-stat" title={text.childrenShort}>
            {isCollapsed ? <ChevronRight aria-hidden="true" /> : <ChevronDown aria-hidden="true" />}
            <span>{directChildCount}{showHiddenBadge ? ` (${hiddenBadgeText})` : totalDescendantCount > directChildCount ? ` / ${totalDescendantCount}` : ''}</span>
          </span>
        )}
        {hasDirectChildren && (
          <Button
            variant="quiet"
            type="button"
            className="org-board-card-collapse"
            onPointerDown={(event) => event.stopPropagation()}
            onClick={(event) => { event.stopPropagation(); onToggleCollapse() }}
            aria-expanded={!isCollapsed}
            aria-label={collapseLabel}
            title={collapseLabel}
          >
            {isCollapsed ? <ChevronRight aria-hidden="true" /> : <ChevronDown aria-hidden="true" />}
            <span className="org-board-card-collapse-text">{isCollapsed ? text.collapsedBadge : text.expand(unit.name_ar)}</span>
          </Button>
        )}
      </div>
      <div className="org-board-card-foot">
        <span className="org-board-card-depth">D{unit.depth}</span>
        <Button
          variant="quiet"
          type="button"
          className="org-board-card-open-hint"
          onPointerDown={(event) => event.stopPropagation()}
          onClick={(event) => { event.stopPropagation(); onOpen() }}
          onKeyDown={(event) => {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault()
              event.stopPropagation()
              onOpen()
            }
          }}
          aria-label={text.openDetails(unit.name_ar)}
          title={text.openDetails(unit.name_ar)}
        >
          <Info aria-hidden="true" />
          <span>{copy[locale].details}</span>
        </Button>
      </div>
    </div>
  )
}

interface DetailsProps {
  unit: OrganizationUnit
  parent: OrganizationUnit | null
  childUnits: OrganizationUnit[]
  positions: Position[]
  locale: Locale
  text: Copy
  units: OrganizationUnit[]
  facilities: Facility[]
  onAddChild: () => void
  onAddPosition: () => void
  onUpdateUnit: (unit: OrganizationUnit, patch: UpdateOrganizationUnitInput) => Promise<OrganizationUnit>
  onUpdatePosition: (position: Position, patch: UpdatePositionInput) => Promise<Position>
}

function UnitDetailsPanel({ unit, parent, childUnits, positions, locale, text, units, facilities, onAddChild, onAddPosition, onUpdateUnit, onUpdatePosition }: DetailsProps) {
  const [editing, setEditing] = useState(false)
  const [name, setName] = useState(unit.name_ar)
  const [parentId, setParentId] = useState(unit.parent_type === 'cluster' ? '' : unit.parent_id)
  const [status, setStatus] = useState<OrganizationUnit['status']>(unit.status)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(false)
  const [editingPositionId, setEditingPositionId] = useState<string | null>(null)

  useEffect(() => {
    setName(unit.name_ar)
    setParentId(unit.parent_type === 'cluster' ? '' : unit.parent_id)
    setStatus(unit.status)
    setEditing(false)
    setError(false)
  }, [unit])

  const parentOptions = [
    ...facilities
      .filter((facility) => facility.status !== 'archived')
      .map((facility) => ({ value: facility.id, label: facility.name_ar, hint: facility.code })),
    ...units
    .filter((candidate) => candidate.status !== 'archived' && candidate.id !== unit.id && !wouldCreateCycle(units, unit.id, candidate.id))
    .map((candidate) => ({ value: candidate.id, label: candidate.name_ar, hint: candidate.code })),
  ]

  async function saveUnit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const trimmed = name.trim()
    if (!trimmed || (parentId !== '' && wouldCreateCycle(units, unit.id, parentId))) {
      setError(true)
      return
    }
    const patch: UpdateOrganizationUnitInput = { name: trimmed, status }
    if (parentId !== '' && parentId !== unit.parent_id) patch.parent_id = parentId
    setSaving(true)
    setError(false)
    try {
      await onUpdateUnit(unit, patch)
      setEditing(false)
    } catch {
      setError(true)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="org-board-details">
      <div className="org-board-details-actions">
        <Button variant="secondary" type="button" onClick={() => setEditing((current) => !current)}>
          {editing ? text.cancel : text.edit}
        </Button>
      </div>
      {editing && (
        <form className="resource-form" onSubmit={(event) => void saveUnit(event)} noValidate>
          {error && <p className="error-summary" role="alert">{parentId !== '' && wouldCreateCycle(units, unit.id, parentId) ? text.parentCycle : text.updateError}</p>}
          <Field id="organization-unit-name" label={text.fieldNameAr} required>
            <input id="organization-unit-name" value={name} required onChange={(event) => setName(event.target.value)} />
          </Field>
          <Field id="organization-unit-parent" label={text.fieldParent}>
            <Select id="organization-unit-parent" value={parentId} onChange={setParentId} options={parentOptions} placeholder={text.noParent} />
          </Field>
          <Field id="organization-unit-status" label={text.fieldStatus}>
            <Select id="organization-unit-status" value={status} onChange={(next) => setStatus(next as OrganizationUnit['status'])} options={[
              { value: 'active', label: text.active },
              { value: 'inactive', label: text.inactive },
              { value: 'archived', label: text.archived },
            ]} />
          </Field>
          <Button type="submit" disabled={saving}>{saving ? text.saving : text.save}</Button>
        </form>
      )}
      <dl className="org-board-details-grid">
        <dt>{text.fieldCode}</dt><dd dir="ltr">{unit.code}</dd>
        <dt>{text.fieldNameAr}</dt><dd>{unit.name_ar}</dd>
        <dt>{text.fieldNameEn}</dt><dd>{unit.name_en ?? '—'}</dd>
        <dt>{text.fieldType}</dt><dd>{typeLabel(locale, unit.type_code)}</dd>
        <dt>{text.fieldStatus}</dt><dd>
          <span className="org-board-card-status" data-status={unit.status}>
            {unit.status === 'active' ? text.active : unit.status === 'archived' ? text.archived : text.inactive}
          </span>
        </dd>
        <dt>{text.fieldDepth}</dt><dd>{unit.depth}</dd>
        <dt>{text.fieldParent}</dt><dd>{parent !== null ? parent.name_ar : text.noParent}</dd>
      </dl>
      <section className="org-board-details-section">
        <header className="org-board-details-section-head">
          <h3>
            <Users aria-hidden="true" />
            <span>{text.fieldChildren}</span>
          </h3>
          <Button variant="quiet" type="button" className="org-board-details-link" onClick={onAddChild}>
            <Plus aria-hidden="true" />
            <span>{text.addChild}</span>
          </Button>
        </header>
        {childUnits.length === 0 ? (
          <p className="org-board-details-empty">{text.noChildren}</p>
        ) : (
          <ul className="org-board-details-list">
            {childUnits.map((child) => (
              <li key={child.id}>
                <span className="org-board-card-code" dir="ltr">{child.code}</span>
                <span>{child.name_ar}</span>
              </li>
            ))}
          </ul>
        )}
      </section>
      <section className="org-board-details-section">
        <header className="org-board-details-section-head">
          <h3>
            <Briefcase aria-hidden="true" />
            <span>{text.fieldPositions}</span>
          </h3>
          <Button variant="quiet" type="button" className="org-board-details-link" onClick={onAddPosition}>
            <Plus aria-hidden="true" />
            <span>{text.addPosition}</span>
          </Button>
        </header>
        {positions.length === 0 ? (
          <p className="org-board-details-empty">{text.noPositions}</p>
        ) : (
          <ul className="org-board-details-list">
            {positions.map((position) => (
              <li key={position.id}>
                <span className="org-board-card-code" dir="ltr">{position.code}</span>
                {editingPositionId === position.id ? (
                  <PositionEditRow position={position} locale={locale} text={text} onSave={onUpdatePosition} onClose={() => setEditingPositionId(null)} />
                ) : (
                  <>
                    <span>{position.title_ar}</span>
                    <Button variant="quiet" type="button" onClick={() => setEditingPositionId(position.id)}>{text.editPosition}</Button>
                  </>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

function PositionEditRow({
  position,
  locale,
  text,
  onSave,
  onClose,
}: {
  position: Position
  locale: Locale
  text: Copy
  onSave: (position: Position, patch: UpdatePositionInput) => Promise<Position>
  onClose: () => void
}) {
  const [title, setTitle] = useState(position.title_ar)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!title.trim()) {
      setError(true)
      return
    }
    setSaving(true)
    setError(false)
    try {
      await onSave(position, { title: title.trim() })
      onClose()
    } catch {
      setError(true)
    } finally {
      setSaving(false)
    }
  }

  return (
    <form className="resource-form org-position-edit" onSubmit={(event) => void submit(event)} noValidate>
      {error && <p className="error-summary" role="alert">{text.updateError}</p>}
      <Field id={`position-title-${position.id}`} label={text.positionTitle} required>
        <input id={`position-title-${position.id}`} value={title} required onChange={(event) => setTitle(event.target.value)} />
      </Field>
      <div className="org-board-details-actions">
        <Button type="submit" disabled={saving}>{saving ? text.saving : text.savePosition}</Button>
        <Button variant="quiet" type="button" onClick={onClose}>{text.cancel}</Button>
      </div>
      <span className="sr-only">{locale}</span>
    </form>
  )
}

// Silence unused-import warning for the icon we keep available for future use.
void ChevronUp
void ChevronRight
