declare module 'js-yaml' {
  export function load(input: string): unknown
}

declare module 'swagger-ui-react' {
  import type { ComponentType } from 'react'
  const SwaggerUI: ComponentType<Record<string, unknown>>
  export default SwaggerUI
}
