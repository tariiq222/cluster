export const walkingSkeletonFixtures = {
  // The W1.3 journey seeder provisions these real accounts on facilities A
  // and B; the UI and session APIs only accept server-issued identities.
  accountA: {
    username: 'w13-e2e-account-a',
    password: 'North!River7Quartz2026',
    title: 'طلب حساب أ',
    description: 'وصف لا يراه إلا حساب المنشأة أ.',
  },
  accountB: {
    username: 'w13-e2e-account-b',
    password: 'Cedar!Orbit8Harbor2026',
  },
  unavailableRecordId: '018f6f7d-0c00-7000-8000-000000000010',
} as const

export const walkingSkeletonLocales = {
  arabic: { lang: 'ar', dir: 'rtl' },
  english: { lang: 'en', dir: 'ltr' },
} as const
