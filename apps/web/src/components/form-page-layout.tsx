import type {
  ComponentType,
  FormHTMLAttributes,
  HTMLAttributes,
  ReactNode,
  SVGProps,
} from 'react'
import { cn } from '@/lib/utils'
import { Separator } from '@/components/ui/separator'

/*
 * Shared, binding form-page primitives for routed add/edit destinations.
 *
 * The approved DocumentCreateScreen encodes the page-level contract every
 * routed add/edit page in the system must satisfy:
 *
 *   • full canvas width — no narrow `max-w-2xl rounded-lg border p-4`
 *     island that swallows the form into a postcard;
 *   • desktop two-region grid (main + sticky review) that collapses to a
 *     single column at mobile so the document stays first in DOM order;
 *   • flat sibling surfaces — never nested cards; sections inside a
 *     region are separated by `border-t pt-6`, not by an extra bounded
 *     container;
 *   • semantic sectioning (`<section aria-labelledby>`, `<dl>/<dt>/<dd>`,
 *     `<bdi dir="auto">` for free-form user text) and a localized file
 *     picker that drives an sr-only native `<input type="file">`.
 *
 * These primitives exist so every routed add/edit page renders the same
 * way. Migrating existing feature screens to them is a separate task; the
 * `docs/design/DESIGN-RULES.md` §2.7 rules are the binding contract for
 * that migration.
 *
 * The grid track `lg:grid-cols-[2fr_1fr]` is the only deliberate
 * arbitrary value these primitives introduce; it is documented and
 * permitted by DESIGN-RULES §2.7 because it pins the canonical
 * main/review ratio across every routed form.
 */

// ─────────────────────────── TwoRegionFormLayout ───────────────────────────

type TwoRegionFormProps = Omit<
  FormHTMLAttributes<HTMLFormElement>,
  'className' | 'children' | 'noValidate'
>

export interface TwoRegionFormLayoutProps extends TwoRegionFormProps {
  /**
   * Main intake surface content. Renders as the first DOM child of the
   * form so it stays first in tab order and screen-reader order, and so
   * RTL/LTR mirroring is natural without directional utilities.
   */
  main: ReactNode
  /**
   * Review + action surface content. Renders as the sibling `<aside>`
   * that becomes `lg:sticky lg:top-20` at desktop. The wrapper exposes
   * the review as a complementary landmark by default.
   */
  review: ReactNode
  /** Class names appended to the form root (merged via `cn`). */
  rootClassName?: string
  /** Class names appended to the main region wrapper. */
  mainClassName?: string
  /** Class names appended to the review `<aside>` wrapper. */
  reviewClassName?: string
  /** `data-testid` forwarded to the form root. */
  testId?: string
  /** `data-testid` forwarded to the main region wrapper. */
  mainTestId?: string
  /** `data-testid` forwarded to the review `<aside>` wrapper. */
  reviewTestId?: string
}

/*
 * The default routed form layout. Renders a real `<form noValidate>` at
 * full available width with a two-region grid at desktop
 * (`lg:grid-cols-[2fr_1fr]`) that collapses to a single column at mobile.
 * Main and review are siblings of the same level — no nested cards.
 * Caller-supplied form props (`onSubmit`, `id`, `aria-labelledby`, …)
 * are forwarded; `className`, `children`, and `noValidate` are owned by
 * the primitive.
 */
export function TwoRegionFormLayout({
  main,
  review,
  rootClassName,
  mainClassName,
  reviewClassName,
  testId,
  mainTestId,
  reviewTestId,
  ...formProps
}: TwoRegionFormLayoutProps) {
  return (
    <form
      data-testid={testId}
      className={cn(
        'grid gap-6 lg:grid-cols-[2fr_1fr] lg:items-start',
        rootClassName,
      )}
      {...formProps}
      noValidate
    >
      <div
        data-testid={mainTestId}
        className={cn('grid gap-6 rounded-xl border bg-card p-4 sm:p-6', mainClassName)}
      >
        {main}
      </div>
      <aside
        data-testid={reviewTestId}
        className={cn(
          'grid gap-4 rounded-xl border bg-card p-4 lg:sticky lg:top-20',
          reviewClassName,
        )}
      >
        {review}
      </aside>
    </form>
  )
}

// ─────────────────────────── SingleRegionFormLayout ────────────────────────

type SingleRegionFormProps = Omit<
  FormHTMLAttributes<HTMLFormElement>,
  'className' | 'children' | 'noValidate'
>

export interface SingleRegionFormLayoutProps extends SingleRegionFormProps {
  /** Form body content. */
  children: ReactNode
  /**
   * Optional actions footer (typically one or two Buttons). When
   * present, the actions render inside a `border-t pt-6` slot so they
   * read as a distinct footer separated from the form content above.
   */
  actions?: ReactNode
  /** Class names appended to the form root. */
  rootClassName?: string
  /** Class names appended to the actions footer wrapper. */
  actionsClassName?: string
  /** `data-testid` forwarded to the form root. */
  testId?: string
  /** `data-testid` forwarded to the actions footer wrapper. */
  actionsTestId?: string
}

/*
 * Short focused forms (single-step intake, confirm-and-act, …). Centered
 * `max-w-3xl` bounded card with a comfortable content grid; the optional
 * `actions` footer is separated by `border-t pt-6` so the primary action
 * reads as a distinct slot. Callers decide the actions' wording and
 * ordering.
 */
export function SingleRegionFormLayout({
  children,
  actions,
  rootClassName,
  actionsClassName,
  testId,
  actionsTestId,
  ...formProps
}: SingleRegionFormLayoutProps) {
  return (
    <form
      data-testid={testId}
      className={cn('mx-auto w-full max-w-3xl', rootClassName)}
      {...formProps}
      noValidate
    >
      <div className="rounded-xl border bg-card p-4 sm:p-6 grid gap-6">
        {children}
        {actions ? (
          <div
            data-testid={actionsTestId}
            className={cn('grid gap-2 border-t pt-6', actionsClassName)}
          >
            {actions}
          </div>
        ) : null}
      </div>
    </form>
  )
}

// ─────────────────────────── FormSection ───────────────────────────────────

export type FormSectionDensity = 'comfortable' | 'tight'

/** Minimal contract for the optional leading icon. */
export type FormSectionIcon = ComponentType<
  Pick<SVGProps<SVGSVGElement>, 'aria-hidden' | 'className'>
>

export interface FormSectionProps extends Omit<HTMLAttributes<HTMLElement>, 'title'> {
  /** Required id for the heading; mirrored on the section via aria-labelledby. */
  headingId: string
  /** Section heading content rendered inside the `<h2>`. */
  title: ReactNode
  /**
   * Vertical density of the section grid.
   *   • `comfortable` (default): `gap-4` — full intake forms.
   *   • `tight`: `gap-2` — dense metadata or review blocks.
   */
  density?: FormSectionDensity
  /**
   * When true, applies `border-t pt-6` so the section reads as a
   * divider-following sibling rather than the first section in the
   * region. Use on every section after the first inside a region.
   */
  divided?: boolean
  /**
   * Optional lucide icon rendered inline beside the heading text.
   * Marked `aria-hidden` automatically so the heading copy stays the
   * sole announced label.
   */
  leadingIcon?: FormSectionIcon
  children?: ReactNode
  /** Class names appended to the section root. */
  className?: string
  /** `data-testid` forwarded to the section root. */
  testId?: string
}

/*
 * Semantic section inside a form region. Renders `<section
 * aria-labelledby>` with a required `headingId` + `title`; never wraps
 * its content in a card. `comfortable`/`tight` picks the section's
 * internal gap; `divided` adds the canonical `border-t pt-6` separator
 * between sibling sections.
 */
export function FormSection({
  headingId,
  title,
  density = 'comfortable',
  divided = false,
  leadingIcon: LeadingIcon,
  children,
  className,
  testId,
}: FormSectionProps) {
  const gapClass = density === 'comfortable' ? 'gap-4' : 'gap-2'
  return (
    <section
      data-testid={testId}
      aria-labelledby={headingId}
      className={cn(
        'grid',
        gapClass,
        divided ? 'border-t pt-6' : null,
        className,
      )}
    >
      <h2
        id={headingId}
        className="text-base font-semibold flex items-center gap-2"
      >
        {LeadingIcon ? (
          <LeadingIcon aria-hidden="true" className="size-4 shrink-0" />
        ) : null}
        <span>{title}</span>
      </h2>
      {children}
    </section>
  )
}

// ─────────────────────────── ReviewSummary ─────────────────────────────────

export interface ReviewSummaryRow {
  /** `<dt>` content — typically a short localized label. */
  label: ReactNode
  /** `<dd>` content. Rendered as-is when not a string or when `isolate` is false. */
  value: ReactNode
  /** Rendered in place of `value` when `value` is null/undefined/empty string. */
  empty?: ReactNode
  /**
   * When true and the rendered value is a string, wraps it in
   * `<bdi dir="auto">` so user-supplied free-form text (titles,
   * filenames, names) does not invert bidi direction or push past the
   * label column.
   */
  isolate?: boolean
}

export interface ReviewSummaryProps {
  rows: ReviewSummaryRow[]
  /** Class names appended to the `<dl>` root. */
  className?: string
  /** `data-testid` forwarded to the `<dl>` root. */
  testId?: string
}

/*
 * Semantic review list. `<dl>` of rows; each row is a responsive grid
 * that stacks label/value at mobile and aligns them in a fixed label
 * column at `sm` and above. String values with `isolate: true` flow
 * through `<bdi dir="auto">` so the surrounding `dt`/`dd` layout cannot
 * be inverted by long free-form text.
 */
export function ReviewSummary({ rows, className, testId }: ReviewSummaryProps) {
  return (
    <dl
      data-testid={testId}
      className={cn('grid gap-3 text-sm', className)}
    >
      {rows.map((row, index) => {
        const isEmpty =
          row.value === null ||
          row.value === undefined ||
          row.value === ''
        const raw = isEmpty ? (row.empty ?? null) : row.value
        const wrapped =
          row.isolate && typeof raw === 'string' ? (
            <bdi dir="auto">{raw}</bdi>
          ) : (
            raw
          )
        return (
          <div
            key={index}
            className="grid gap-1 sm:grid-cols-[7rem_1fr] sm:items-baseline"
          >
            <dt className="text-muted-foreground text-xs sm:text-sm">
              {row.label}
            </dt>
            <dd className="min-w-0 break-words">{wrapped}</dd>
          </div>
        )
      })}
    </dl>
  )
}

// ─────────────────────────── FormActionStack ───────────────────────────────

export interface FormActionStackProps {
  /** Action elements (typically Buttons). The caller decides wording. */
  children: ReactNode
  /** Class names appended to the action slot grid. */
  className?: string
  /** `data-testid` forwarded to the action slot grid. */
  testId?: string
}

/*
 * Structural helper for the review + action surface: a `Separator`
 * followed by a full-width stacked action slot. The primitive only
 * encodes the separator and the vertical rhythm; callers decide which
 * actions render and what each button says.
 */
export function FormActionStack({
  children,
  className,
  testId,
}: FormActionStackProps) {
  return (
    <>
      <Separator />
      <div
        data-testid={testId}
        className={cn('grid gap-2', className)}
      >
        {children}
      </div>
    </>
  )
}
