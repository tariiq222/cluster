import { useMemo } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { toast } from 'sonner'
import { KeyRound } from 'lucide-react'
import * as generated from '../../../api/generated/cluster'
import { requestInit, unwrapEmpty } from '../../../api/http'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { Button } from '@/components/ui/button'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Progress } from '@/components/ui/progress'
import { securityCopy } from '../me-copy'

const MINIMUM_PASSWORD_LENGTH = 14

interface PasswordFormValues {
  currentPassword: string
  newPassword: string
  confirmation: string
}

function strengthScore(value: string): number {
  if (!value) return 0
  let score = 0
  if (value.length >= MINIMUM_PASSWORD_LENGTH) score += 40
  else if (value.length >= 10) score += 25
  else if (value.length >= 8) score += 15
  if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score += 20
  if (/\d/.test(value)) score += 20
  if (/[^a-zA-Z0-9]/.test(value)) score += 20
  return Math.min(100, score)
}

function strengthLabel(score: number, text: (typeof securityCopy)[keyof typeof securityCopy]): string {
  if (score === 0) return text.strengthEmpty
  if (score < 40) return text.weak
  if (score < 70) return text.fair
  if (score < 90) return text.strong
  return text.veryStrong
}

export function SecurityTab() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = securityCopy[locale]

  const schema = useMemo(
    () =>
      z
        .object({
          currentPassword: z.string().min(1),
          newPassword: z.string().min(MINIMUM_PASSWORD_LENGTH, text.tooShort),
          confirmation: z.string(),
        })
        .refine((values) => values.newPassword === values.confirmation, {
          message: text.mismatch,
          path: ['confirmation'],
        }),
    [text],
  )

  const form = useForm<PasswordFormValues>({
    resolver: zodResolver(schema),
    defaultValues: { currentPassword: '', newPassword: '', confirmation: '' },
  })

  const newPassword = form.watch('newPassword') ?? ''
  const score = strengthScore(newPassword)

  const submit = form.handleSubmit(async (values) => {
    try {
      await unwrapEmpty(
        await generated.changeIdentityPassword(
          {
            current_password: values.currentPassword,
            new_password: values.newPassword,
            new_password_confirmation: values.confirmation,
          },
          requestInit(csrfToken, { mutation: true }),
        ),
      )
      // Success is confirmed by toast only — the session is NOT signed out.
      form.reset()
      toast(text.success)
    } catch {
      form.setError('currentPassword', { message: text.failed })
    }
  })

  return (
    <section aria-labelledby="security-tab-heading" className="max-w-xl space-y-4">
      <div>
        <h2 id="security-tab-heading" className="flex items-center gap-2 text-lg font-semibold">
          <KeyRound aria-hidden="true" className="size-4 text-muted-foreground" />
          {text.panel}
        </h2>
        <p className="text-muted-foreground text-sm">{text.lengthHint}</p>
      </div>

      <Form {...form}>
        <form onSubmit={(event) => void submit(event)} className="grid gap-4" noValidate>
          <FormField
            control={form.control}
            name="currentPassword"
            render={({ field }) => (
              <FormItem>
                <FormLabel htmlFor="me-current-password">{text.currentPassword}</FormLabel>
                <FormControl>
                  <Input
                    id="me-current-password"
                    type="password"
                    autoComplete="current-password"
                    disabled={form.formState.isSubmitting}
                    {...field}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="newPassword"
            render={({ field }) => (
              <FormItem>
                <FormLabel htmlFor="me-new-password">{text.newPassword}</FormLabel>
                <FormControl>
                  <Input
                    id="me-new-password"
                    type="password"
                    autoComplete="new-password"
                    disabled={form.formState.isSubmitting}
                    {...field}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="confirmation"
            render={({ field }) => (
              <FormItem>
                <FormLabel htmlFor="me-confirm-password">{text.confirmPassword}</FormLabel>
                <FormControl>
                  <Input
                    id="me-confirm-password"
                    type="password"
                    autoComplete="new-password"
                    disabled={form.formState.isSubmitting}
                    {...field}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />

          <div className="space-y-1">
            <div className="flex items-center justify-between gap-2">
              <span className="text-muted-foreground text-xs">{text.strength}</span>
              <span className="text-muted-foreground text-xs" role="status">
                {strengthLabel(score, text)}
              </span>
            </div>
            <Progress value={score} aria-label={text.strength} aria-valuetext={strengthLabel(score, text)} />
          </div>

          <div>
            <Button type="submit" disabled={form.formState.isSubmitting}>
              {form.formState.isSubmitting ? text.saving : text.submit}
            </Button>
          </div>
        </form>
      </Form>
    </section>
  )
}
