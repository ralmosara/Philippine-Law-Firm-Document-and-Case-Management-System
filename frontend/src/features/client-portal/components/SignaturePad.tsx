import { Eraser } from 'lucide-react'
import { useCallback, useEffect, useRef, type PointerEvent } from 'react'
import { Button } from '@/shared/ui/Button'

/**
 * Draw-your-signature pad (mouse, pen or finger). Reports a PNG data URL
 * after each stroke, or null when cleared. The typed-name option next to it
 * is the keyboard-accessible alternative.
 */
export function SignaturePad({ onChange, label }: { onChange: (dataUrl: string | null) => void; label: string }) {
  const canvas = useRef<HTMLCanvasElement>(null)
  const drawing = useRef(false)
  const hasInk = useRef(false)

  // Match the backing store to the displayed size for crisp lines.
  useEffect(() => {
    const el = canvas.current
    if (!el) return
    const ratio = Math.max(window.devicePixelRatio || 1, 1)
    el.width = el.offsetWidth * ratio
    el.height = el.offsetHeight * ratio
    const ctx = el.getContext('2d')
    if (!ctx) return
    ctx.scale(ratio, ratio)
    ctx.lineWidth = 2.2
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
    ctx.strokeStyle = '#111827'
  }, [])

  const point = (e: PointerEvent<HTMLCanvasElement>) => {
    const rect = e.currentTarget.getBoundingClientRect()
    return { x: e.clientX - rect.left, y: e.clientY - rect.top }
  }

  const start = (e: PointerEvent<HTMLCanvasElement>) => {
    const ctx = e.currentTarget.getContext('2d')
    if (!ctx) return
    e.currentTarget.setPointerCapture(e.pointerId)
    drawing.current = true
    const { x, y } = point(e)
    ctx.beginPath()
    ctx.moveTo(x, y)
    ctx.lineTo(x + 0.1, y + 0.1)
    ctx.stroke()
  }

  const move = (e: PointerEvent<HTMLCanvasElement>) => {
    if (!drawing.current) return
    const ctx = e.currentTarget.getContext('2d')
    if (!ctx) return
    const { x, y } = point(e)
    ctx.lineTo(x, y)
    ctx.stroke()
    hasInk.current = true
  }

  const end = () => {
    if (!drawing.current) return
    drawing.current = false
    if (hasInk.current && canvas.current) onChange(canvas.current.toDataURL('image/png'))
  }

  const clear = useCallback(() => {
    const el = canvas.current
    el?.getContext('2d')?.clearRect(0, 0, el.width, el.height)
    hasInk.current = false
    onChange(null)
  }, [onChange])

  return (
    <div>
      <div className="relative rounded-[3px] border border-outline bg-white">
        <canvas
          ref={canvas}
          role="img"
          aria-label={label}
          className="block h-40 w-full cursor-crosshair touch-none"
          onPointerDown={start}
          onPointerMove={move}
          onPointerUp={end}
          onPointerCancel={end}
          onPointerLeave={end}
        />
        <span aria-hidden="true" className="pointer-events-none absolute right-4 bottom-8 left-4 border-b border-dashed border-gray-300" />
      </div>
      <div className="mt-2 flex justify-end">
        <Button variant="text" size="sm" icon={<Eraser className="size-4" />} onClick={clear}>Clear</Button>
      </div>
    </div>
  )
}
