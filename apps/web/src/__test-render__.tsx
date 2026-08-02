/** @jsxImportSource react */
import { renderToStaticMarkup } from 'react-dom/server'
import { TwoRegionFormLayout } from './components/form-page-layout'

const html = renderToStaticMarkup(
  <TwoRegionFormLayout
    // @ts-expect-error
    noValidate={false}
    main={<span />}
    review={<span />}
  />,
)
console.log('OUTPUT:', html)
