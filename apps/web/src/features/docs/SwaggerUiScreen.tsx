import { useMemo } from 'react'
import * as yaml from 'js-yaml'
import SwaggerUI from 'swagger-ui-react'
import 'swagger-ui-react/swagger-ui.css'

import type { Locale } from '../../app/copy'
import { text } from '../../app/copy'

import openapiYaml from '../../../.orval/cluster.openapi.yaml?raw'

type Props = {
  locale: Locale
}

export function SwaggerUiScreen({ locale }: Props) {
  const copy = text[locale]
  const spec = useMemo(() => yaml.load(openapiYaml) as Record<string, unknown>, [])

  return (
    <section className="state-panel swagger-ui-screen" aria-labelledby="swagger-ui-heading">
      <h1 id="swagger-ui-heading">{copy.apiDocs}</h1>
      <p>{copy.apiDocsBody}</p>
      <div className="swagger-ui-host">
        <SwaggerUI
          spec={spec}
          deepLinking
          displayRequestDuration
          docExpansion="list"
          defaultModelsExpandDepth={-1}
          defaultModelExpandDepth={-1}
        />
      </div>
    </section>
  )
}
