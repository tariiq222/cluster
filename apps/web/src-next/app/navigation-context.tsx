import { createContext, useContext } from 'react'

export interface ScreenProps {
  navigate: (path: string) => void
}

const NavigationContext = createContext<(path: string) => void>(() => {})

export function NavigationProvider({ navigate, children }: { navigate: (path: string) => void; children: React.ReactNode }) {
  return <NavigationContext.Provider value={navigate}>{children}</NavigationContext.Provider>
}

export function useNavigate(): (path: string) => void {
  return useContext(NavigationContext)
}
