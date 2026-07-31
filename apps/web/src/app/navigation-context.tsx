import { useNavigate as useRouterNavigate } from 'react-router-dom'

/**
 * Thin adapter over React Router's useNavigate so feature screens keep the
 * same `useNavigate(): (path: string) => void` contract.
 */
export function useNavigate(): (path: string) => void {
  return useRouterNavigate()
}
