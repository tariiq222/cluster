import type { ReactNode } from 'react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { cn } from '@/lib/utils'

/*
 * WorkspaceTabs is the shared line-tab implementation every tabbed
 * workspace and tabbed detail page adopts. The wrapper composes the
 * generated Radix-backed Tabs primitive (it never edits it) and is the
 * single owner of:
 *
 *  - the line variant with a full-width structural bottom border,
 *    transparent background, no pill container, and start alignment;
 *  - the overflow-safe nav (`<nav>`) that owns the horizontal scroll
 *    so the tab strip can grow past the viewport on mobile / 200% zoom
 *    without ever pushing the document wider;
 *  - the 44px mobile touch target (WCAG 2.5.5) on every trigger and
 *    the tab list itself, reverting to the Organization 36px rhythm
 *    from the `sm` breakpoint to keep the desktop density documented
 *    in the binding design rules;
 *  - the active-tab underline aligned exactly to the structural bottom
 *    border (`after:inset-x-0 after:-bottom-px after:h-0.5
 *    data-[state=active]:after:opacity-100`) so the indicator sits
 *    flush with the line instead of the default `bottom:-5px`;
 *  - the `pt-6` panel rhythm, the `min-w-0 max-w-full` width clamps
 *    that keep panels shrinkable inside the vertical stack, and the
 *    default `focus-visible:ring-2 focus-visible:ring-ring/50
 *    focus-visible:outline-none rounded-md` panel ring so the focus
 *    indicator is consistent with the rest of the design system
 *    whenever programmatic focus lands on a tabpanel.
 *
 * Capability filtering is intentionally NOT the wrapper's
 * responsibility: callers pass only the items the principal is allowed
 * to see, so the wrapper never infers authorization. The page shell
 * remains the single point that filters tabs out before render.
 */

export interface WorkspaceTabItem {
  value: string
  label: string
  content: ReactNode
  /**
   * Optional extra classes merged into the active tabpanel — used for
   * e.g. the access diagnostics panel that owns its own horizontal
   * scroll, or any other panel-level responsive concern that is not
   * the wrapper's default.
   */
  contentClassName?: string
}

export interface WorkspaceTabsProps {
  /** Accessible label for the tablist (the rendered `<nav>` carries it). */
  label: string
  /** Controlled value of the active tab. */
  value: string
  /** Notifies the caller of a tab change so it can update its state. */
  onValueChange: (value: string) => void
  /** Items the caller is already willing to render. */
  items: readonly WorkspaceTabItem[]
  /** Optional test id for the Radix Tabs root. */
  testId?: string
  /** Optional test id for the nav scroll container. */
  navTestId?: string
  /** Optional className merged into the Tabs root. */
  className?: string
}

export function WorkspaceTabs({
  label,
  value,
  onValueChange,
  items,
  testId,
  navTestId,
  className,
}: WorkspaceTabsProps) {
  return (
    <Tabs
      value={value}
      onValueChange={onValueChange}
      data-testid={testId}
      className={cn('flex-col min-w-0 max-w-full', className)}
    >
      {/*
       * The nav is the horizontal scroll owner: `max-w-full overflow-x-auto
       * overscroll-x-contain` keeps the tab strip operable by touch,
       * mouse-wheel, and keyboard arrow nav without ever pushing the
       * document wider than the viewport. The scrollbar is hidden so the
       * line strip stays visually clean, but content is never clipped —
       * the inner TabsList carries the intrinsic content width and the
       * user can still scroll.
       */}
      <nav
        aria-label={label}
        data-testid={navTestId}
        className="max-w-full overflow-x-auto overscroll-x-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      >
        <TabsList
          variant="line"
          aria-label={label}
          className="h-auto min-h-11 w-full min-w-max justify-start gap-1 overflow-x-visible rounded-none border-b border-border bg-transparent p-0 sm:h-auto sm:min-h-0"
        >
          {/*
           * Each trigger carries the line-variant alignment:
           * `after:inset-x-0 after:-bottom-px after:h-0.5
           * data-[state=active]:after:opacity-100` positions the active
           * underline exactly on the structural bottom border. The
           * default `bottom:-5px` would sit 5px below the border line
           * and look misaligned. The `data-[state=active]:text-foreground`
           * class is already produced by the generated trigger.
           */}
          {items.map((item) => (
            <TabsTrigger
              key={item.value}
              value={item.value}
              className="h-9 min-h-11 flex-none rounded-none border-0 px-3 after:inset-x-0 after:-bottom-px after:h-0.5 data-[state=active]:after:opacity-100 sm:min-h-0"
            >
              {item.label}
            </TabsTrigger>
          ))}
        </TabsList>
      </nav>
      {items.map((item) => (
        <TabsContent
          key={item.value}
          value={item.value}
          className={cn(
            'min-w-0 max-w-full rounded-md pt-6 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none',
            item.contentClassName,
          )}
        >
          {item.content}
        </TabsContent>
      ))}
    </Tabs>
  )
}
