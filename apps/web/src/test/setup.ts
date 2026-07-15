export const walkingSkeletonFixtures = {
  accountA: {
    username: 'fixture-account-a',
    password: 'fixture-password-a',
    title: 'طلب حساب أ',
    description: 'وصف لا يراه إلا حساب المنشأة أ.',
  },
  accountB: {
    username: 'fixture-account-b',
    password: 'fixture-password-b',
  },
  unavailableRecordId: '018f6f7d-0c00-7000-8000-000000000010',
} as const

export const walkingSkeletonLocales = {
  arabic: { lang: 'ar', dir: 'rtl' },
  english: { lang: 'en', dir: 'ltr' },
} as const
