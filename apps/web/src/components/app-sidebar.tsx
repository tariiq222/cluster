import type { ComponentProps, CSSProperties } from 'react'
import { Sidebar, useSidebar } from '@/components/ui/sidebar'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'

type AppSidebarProps = ComponentProps<typeof Sidebar> & {
  mobileTitle: string
  mobileDescription: string
}

export function AppSidebar({
  mobileTitle,
  mobileDescription,
  side = 'left',
  collapsible = 'offcanvas',
  dir,
  children,
  ...props
}: AppSidebarProps) {
  const { isMobile, openMobile, setOpenMobile } = useSidebar()

  if (!isMobile || collapsible === 'none') {
    return (
      <Sidebar side={side} collapsible={collapsible} dir={dir} {...props}>
        {children}
      </Sidebar>
    )
  }

  return (
    <Sheet open={openMobile} onOpenChange={setOpenMobile}>
      <SheetContent
        dir={dir}
        data-sidebar="sidebar"
        data-slot="sidebar"
        data-mobile="true"
        className="w-(--sidebar-width) bg-sidebar p-0 text-sidebar-foreground"
        style={{ '--sidebar-width': '18rem' } as CSSProperties}
        side={side}
        showCloseButton={false}
      >
        <SheetHeader className="sr-only">
          <SheetTitle>{mobileTitle}</SheetTitle>
          <SheetDescription>{mobileDescription}</SheetDescription>
        </SheetHeader>
        <div className="flex h-full w-full flex-col">{children}</div>
      </SheetContent>
    </Sheet>
  )
}
