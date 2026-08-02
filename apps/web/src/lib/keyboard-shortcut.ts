interface NavigatorLike {
  userAgent?: string
  userAgentData?: { platform?: string }
}

export function commandShortcut(
  navigatorValue: NavigatorLike | undefined = typeof navigator === 'undefined'
    ? undefined
    : navigator,
): '⌘K' | 'Ctrl+K' {
  const platform =
    navigatorValue?.userAgentData?.platform ?? navigatorValue?.userAgent ?? ''
  return /Mac|iPhone|iPad|iPod/i.test(platform) ? '⌘K' : 'Ctrl+K'
}
