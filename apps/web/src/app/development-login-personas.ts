import type { Locale } from '../i18n'

/**
 * Development-only quick-account chooser.
 *
 * These fixtures mirror the exact three standard personas provisioned by
 * `Database\Seeders\DevelopmentJourneyAuthorizationSeeder`, which is refused
 * by any non-local / non-testing environment. They are intended solely for
 * local development, browser smoke tests, and the W1.x journey scripts.
 *
 * SECURITY: This module is gated behind `import.meta.env.DEV`. Vite eliminates
 * the dead branch during production builds, so neither the usernames nor the
 * passwords ever reach the production bundle. Do not import this module from
 * any path that is reachable in production code, and never log, persist, or
 * surface these values outside the local login form.
 */

export interface DevelopmentLoginPersona {
  readonly id: string
  readonly username: string
  readonly password: string
  readonly roleLabel: Readonly<Record<Locale, string>>
  readonly summary: Readonly<Record<Locale, string>>
}

export const developmentLoginPersonas: readonly DevelopmentLoginPersona[] = [
  {
    id: 'platform-admin',
    username: 'platform-admin',
    password: 'Admin!Cluster9Owner2026',
    roleLabel: {
      ar: 'مدير المنصة',
      en: 'Platform administrator',
    },
    summary: {
      ar: 'صلاحيات إدارية على مستوى التجمع.',
      en: 'Cluster-wide administrative capabilities.',
    },
  },
  {
    id: 'w13-account-a',
    username: 'w13-e2e-account-a',
    password: 'North!River7Quartz2026',
    roleLabel: {
      ar: 'مشغّل R1 ومسؤول صلاحيات (المنشأة أ)',
      en: 'R1 operator + authorization admin (Facility A)',
    },
    summary: {
      ar: 'تشغيل رحلات R1 ضمن المنشأة أ مع إدارة الصلاحيات.',
      en: 'Drives R1 journeys in Facility A with authorization admin scope.',
    },
  },
  {
    id: 'w13-account-b',
    username: 'w13-e2e-account-b',
    password: 'Cedar!Orbit8Harbor2026',
    roleLabel: {
      ar: 'مشغّل R1 (المنشأة ب)',
      en: 'R1 operator (Facility B)',
    },
    summary: {
      ar: 'تشغيل رحلات R1 ضمن المنشأة ب فقط.',
      en: 'Drives R1 journeys in Facility B only.',
    },
  },
] as const

export const developmentLoginCopy: Readonly<Record<Locale, {
  heading: string
  instruction: string
  seededNote: string
}>> = {
  ar: {
    heading: 'حسابات التطوير',
    instruction: 'اختر حساباً لتعبئة بيانات الدخول.',
    seededNote: 'بيانات اختبار مهيّأة عبر DevelopmentJourneyAuthorizationSeeder لبيئة التطوير فقط.',
  },
  en: {
    heading: 'Development accounts',
    instruction: 'Choose an account to fill the sign-in fields.',
    seededNote: 'Seeded for local development only via DevelopmentJourneyAuthorizationSeeder.',
  },
} as const
