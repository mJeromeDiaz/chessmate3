<template>
  <canvas ref="canvas" class="confetti-burst" aria-hidden="true" />
</template>

<script setup>
/**
 * A burst of confetti over its positioned parent (design "Fin de séance"): two side cannons, then
 * a pop from the middle, fading out in about four seconds. A new `key` replays it. Nothing for a
 * user who asked for reduced motion. The end-of-exercise feedback (design "Animation Puzzle")
 * fires a smaller one, in its verdict's colours, with a few chess pieces and no pop.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
  /** @type {import('vue').PropType<string[]>} '' or empty: the app's palette */
  colors: { type: Array, default: () => [] },
  /** Confetti per cannon. */
  count: { type: Number, default: 90 },
  /** The second burst from the middle. */
  pop: { type: Boolean, default: true },
  /** One confetto in five is a chess piece. */
  pieces: { type: Boolean, default: false }
})

const PIECES = ['♞', '♝', '♜', '♛', '♚', '♟'].map(g => `${g}\uFE0E`)

const COLORS = [
  '#C6F432',
  '#FF6FAE',
  '#FFD43B',
  '#6A4CFF',
  '#4FB2FF',
  '#FF8A3D',
  '#2ED3A8'
]

const canvas = ref(/** @type {HTMLCanvasElement|null} */ (null))
let frame = 0
/** @type {ReturnType<typeof setTimeout>|undefined} */
let popTimer

onMounted(() => {
  const cv = canvas.value
  const parent = cv?.parentElement
  const ctx = cv?.getContext?.('2d')
  if (!cv || !parent || !ctx) return
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return
  const W = parent.offsetWidth
  const H = parent.offsetHeight
  const dpr = window.devicePixelRatio || 1
  cv.width = W * dpr
  cv.height = H * dpr
  ctx.scale(dpr, dpr)
  const k = Math.min(1.4, Math.max(0.8, H / 800))
  const colors = props.colors.length ? props.colors : COLORS
  /** @type {{x: number, y: number, vx: number, vy: number, w: number, h: number, r: number, vr: number, c: string, round: boolean, glyph: string|null, ph: number}[]} */
  const parts = []
  /** @param {number} x @param {number} y @param {number} angle @param {number} spread @param {number} speed @param {number} n */
  const add = (x, y, angle, spread, speed, n) => {
    for (let i = 0; i < n; i++) {
      const a = angle + (Math.random() - 0.5) * spread
      const v = speed * (0.55 + Math.random() * 0.6) * k
      parts.push({
        x,
        y,
        vx: Math.cos(a) * v,
        vy: Math.sin(a) * v,
        w: 6 + Math.random() * 6,
        h: 4 + Math.random() * 5,
        r: Math.random() * 6,
        vr: (Math.random() - 0.5) * 0.35,
        c: colors[i % colors.length],
        round: Math.random() < 0.25,
        glyph: props.pieces && i % 5 === 0 ? PIECES[i % PIECES.length] : null,
        ph: Math.random() * 6
      })
    }
  }
  add(0, H * 0.78, -Math.PI / 3.2, 0.7, 19, props.count)
  add(W, H * 0.78, -Math.PI + Math.PI / 3.2, 0.7, 19, props.count)
  if (props.pop) {
    popTimer = setTimeout(
      () => add(W / 2, H * 0.35, -Math.PI / 2, Math.PI * 2, 9, 70),
      450
    )
  }
  let f = 0
  const tick = () => {
    f++
    ctx.clearRect(0, 0, W, H)
    let alive = 0
    for (const p of parts) {
      p.vy += 0.32 * k
      p.vx *= 0.985
      p.vy *= 0.985
      p.x += p.vx + Math.sin(f / 9 + p.ph) * 0.6
      p.y += p.vy
      p.r += p.vr
      if (p.y < H + 30) alive++
      ctx.save()
      ctx.translate(p.x, p.y)
      ctx.rotate(p.r)
      ctx.fillStyle = p.c
      ctx.globalAlpha = f > 200 ? Math.max(0, 1 - (f - 200) / 60) : 1
      if (p.glyph) {
        ctx.fillStyle = '#1B1530'
        ctx.font = '22px "Segoe UI Symbol","DejaVu Sans",serif'
        ctx.textAlign = 'center'
        ctx.textBaseline = 'middle'
        ctx.fillText(p.glyph, 0, 0)
      } else if (p.round) {
        ctx.beginPath()
        ctx.arc(0, 0, p.h / 1.6, 0, 7)
        ctx.fill()
      } else {
        const flip = Math.abs(Math.cos(f / 6 + p.ph))
        ctx.fillRect(-p.w / 2, (-p.h / 2) * flip, p.w, p.h * flip + 1)
        // White on a pale sheet: outlined in the burst's first colour.
        if (p.c === '#FFFFFF') {
          ctx.strokeStyle = colors[0]
          ctx.lineWidth = 1.5
          ctx.strokeRect(-p.w / 2, (-p.h / 2) * flip, p.w, p.h * flip + 1)
        }
      }
      ctx.restore()
    }
    if (alive && f < 260) frame = requestAnimationFrame(tick)
    else ctx.clearRect(0, 0, W, H)
  }
  frame = requestAnimationFrame(tick)
})

onBeforeUnmount(() => {
  cancelAnimationFrame(frame)
  clearTimeout(popTimer)
})
</script>

<style scoped>
.confetti-burst {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  z-index: 30;
}
</style>
