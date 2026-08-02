import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Check, MapPin } from 'lucide-react'
import { usePrincipal } from '../../../app/principal-context'
import { useLocale } from '../../../app/session-context'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DeniedState, ErrorState, LoadingState } from '@/components/states'
import { accessCopy } from '../me-copy'
import { purgeScopeBoundRows } from '../scope-invalidation'

interface ScopeOption {
  scopeType: string
  scopeId: string
  label: string
}

function groupCapabilities(capabilities: string[]): Array<{ module: string; codes: string[] }> {
  const byModule = new Map<string, string[]>()
  for (const capability of capabilities) {
    const module = capability.includes('.') ? capability.slice(0, capability.indexOf('.')) : capability
    const codes = byModule.get(module) ?? []
    codes.push(capability)
    byModule.set(module, codes)
  }
  return Array.from(byModule.entries())
    .map(([module, codes]) => ({ module, codes: codes.sort() }))
    .sort((a, b) => a.module.localeCompare(b.module))
}

export function AccessTab() {
  const locale = useLocale()
  const principal = usePrincipal()
  const queryClient = useQueryClient()
  const text = accessCopy[locale]
  const [switching, setSwitching] = useState(false)
  const [scopeError, setScopeError] = useState(false)

  const effective = principal.effectiveScope
  const scopes = principal.availableScopes ?? []
  const capabilities = principal.capabilities ?? []
  const groups = groupCapabilities(capabilities)

  const isEffective = (scope: ScopeOption) =>
    effective !== null && effective.scopeType === scope.scopeType && effective.scopeId === scope.scopeId

  async function changeScope(scope: ScopeOption) {
    if (isEffective(scope) || switching) return
    const previousEpoch = principal.scopeEpoch
    setSwitching(true)
    setScopeError(false)
    /*
     * Remove rows cached under the previous scope epoch BEFORE requesting the
     * switch: while selectScope is in flight the old-scope rows are still
     * serviceable, and serving them during that window would display data the
     * user may no longer be entitled to.
     */
    purgeScopeBoundRows(queryClient, previousEpoch)
    try {
      await principal.selectScope(scope.scopeType, scope.scopeId)
    } catch {
      setScopeError(true)
    } finally {
      setSwitching(false)
    }
  }

  if (principal.state === 'loading') return <LoadingState rows={3} />
  if (principal.state === 'denied') return <DeniedState locale={locale} />
  if (principal.state === 'error') return <ErrorState locale={locale} onRetry={principal.refresh} />

  return (
    <div className="grid gap-6 lg:grid-cols-2 lg:items-start">
      <section aria-labelledby="access-scopes-heading" className="space-y-3">
        <div>
          <h2 id="access-scopes-heading" className="text-base font-semibold">
            {text.scopes}
          </h2>
          <p className="text-muted-foreground text-sm">{text.scopesHint}</p>
        </div>
        {scopeError && (
          <p className="text-destructive text-sm" role="alert">
            {text.scopeError}
          </p>
        )}
        {scopes.length === 0 ? (
          <p className="text-muted-foreground text-sm">{text.noScopes}</p>
        ) : (
          <ul className="space-y-2">
            {scopes.map((scope) => {
              const active = isEffective(scope)
              return (
                <li key={`${scope.scopeType}:${scope.scopeId}`}>
                  <Button
                    variant="outline"
                    className="h-auto w-full justify-between gap-2 p-3 text-start"
                    aria-pressed={active}
                    disabled={switching}
                    onClick={() => void changeScope(scope)}
                  >
                    <span className="flex min-w-0 items-center gap-2">
                      <MapPin aria-hidden="true" className={`size-4 shrink-0 ${active ? 'text-primary' : 'text-muted-foreground'}`} />
                      <span className="truncate font-medium">{scope.label}</span>
                    </span>
                    {active && (
                      <Badge variant="outline" className="shrink-0">
                        <Check aria-hidden="true" className="size-3 text-primary" />
                        {text.effective}
                      </Badge>
                    )}
                  </Button>
                </li>
              )
            })}
          </ul>
        )}
        {switching && (
          <p className="text-muted-foreground text-sm" role="status">
            {text.switching}
          </p>
        )}
      </section>

      <section aria-labelledby="access-capabilities-heading" className="space-y-3">
        <div>
          <h2 id="access-capabilities-heading" className="text-base font-semibold">
            {text.capabilities}
          </h2>
          <p className="text-muted-foreground text-sm">{text.capabilitiesHint}</p>
        </div>
        {groups.length === 0 ? (
          <p className="text-muted-foreground text-sm">{text.noCapabilities}</p>
        ) : (
          <div className="space-y-4">
            {groups.map((group) => (
              <div key={group.module}>
                <h3 className="text-sm font-medium">{group.module}</h3>
                <ul className="mt-2 flex flex-wrap gap-1">
                  {group.codes.map((code) => (
                    <li key={code}>
                      <Badge variant="secondary" className="font-mono text-xs">
                        {code}
                      </Badge>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  )
}
