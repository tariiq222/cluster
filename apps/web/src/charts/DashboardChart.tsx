import { useEffect, useRef, type CSSProperties } from 'react'
import * as echarts from 'echarts/core'
import { BarChart, LineChart, PieChart } from 'echarts/charts'
import {
  GridComponent,
  LegendComponent,
  TitleComponent,
  TooltipComponent,
} from 'echarts/components'
import { SVGRenderer } from 'echarts/renderers'
import { cx } from '../ui/cx'

echarts.use([BarChart, LineChart, PieChart, GridComponent, TooltipComponent, LegendComponent, TitleComponent, SVGRenderer])

export interface DashboardChartSummaryRow {
  label: string
  value: string | number
  unit?: string
}

export interface DashboardChartProps {
  option: echarts.EChartsCoreOption
  tabularSummary: DashboardChartSummaryRow[]
  caption?: string
  height?: number
  className?: string
}

const DEFAULT_HEIGHT = 220

export function DashboardChart({ option, tabularSummary, caption, height, className }: DashboardChartProps) {
  const chartRef = useRef<HTMLDivElement | null>(null)
  const instanceRef = useRef<echarts.ECharts | null>(null)

  useEffect(() => {
    const node = chartRef.current
    if (!node) return
    const instance = echarts.init(node, undefined, { renderer: 'svg' })
    instance.setOption(option)
    instanceRef.current = instance
    return () => {
      instance.dispose()
      instanceRef.current = null
    }
  }, [option])

  const canvasStyle: CSSProperties = { blockSize: height ?? DEFAULT_HEIGHT }

  return (
    <figure className={cx('dashboard-chart', className)}>
      <div
        ref={chartRef}
        className="dashboard-chart-canvas"
        role="img"
        aria-label={caption}
        style={canvasStyle}
      />
      <figcaption className="dashboard-chart-figcaption">
        <table className="dashboard-chart-table">
          <caption className="visually-hidden">{caption ?? ''}</caption>
          <thead>
            <tr>
              <th scope="col">{caption ?? ''}</th>
              <th scope="col">القيمة</th>
            </tr>
          </thead>
          <tbody>
            {tabularSummary.map((row, index) => (
              <tr key={`${row.label}-${index}`}>
                <th scope="row">{row.label}</th>
                <td>
                  {row.value}
                  {row.unit ? ` ${row.unit}` : ''}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </figcaption>
    </figure>
  )
}

export default DashboardChart