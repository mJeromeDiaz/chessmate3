import { useAuthStore } from '@/stores/auth'

/**
 * The Roi des cendres' synthesized sounds (Web Audio, no file): the rain while a position is
 * played, the clock's tick in its last seconds, the wind of the board turning over. They follow the
 * player's « Sons » preference, as the other sounds; silent failures (no Web Audio, blocked).
 */

/** @type {AudioContext|null} */
let context = null

/** The shared context, null when sounds are off or Web Audio is missing. */
function audio() {
  if (useAuthStore().profile?.moveSound !== true) return null
  try {
    context ??= new AudioContext()
    if (context.state === 'suspended') context.resume().catch(() => {})
    return context
  } catch {
    return null
  }
}

/**
 * Two seconds of white noise, looped by its players.
 *
 * @param {AudioContext} ctx
 */
function noise(ctx) {
  const buffer = ctx.createBuffer(1, ctx.sampleRate * 2, ctx.sampleRate)
  const data = buffer.getChannelData(0)
  for (let i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1
  return buffer
}

/**
 * The rain, until stopped: white noise through a band-pass, faded in and out.
 *
 * @returns {() => void} stops it
 */
export function startRain() {
  const ctx = audio()
  if (!ctx) return () => {}
  try {
    const source = ctx.createBufferSource()
    source.buffer = noise(ctx)
    source.loop = true
    const filter = ctx.createBiquadFilter()
    filter.type = 'bandpass'
    filter.frequency.value = 1400
    filter.Q.value = 0.6
    const gain = ctx.createGain()
    gain.gain.setValueAtTime(0, ctx.currentTime)
    gain.gain.linearRampToValueAtTime(0.12, ctx.currentTime + 1.5)
    source.connect(filter).connect(gain).connect(ctx.destination)
    source.start()
    return () => {
      try {
        gain.gain.cancelScheduledValues(ctx.currentTime)
        gain.gain.setValueAtTime(gain.gain.value, ctx.currentTime)
        gain.gain.linearRampToValueAtTime(0, ctx.currentTime + 0.4)
        source.stop(ctx.currentTime + 0.45)
      } catch {
        // Already stopped.
      }
    }
  } catch {
    return () => {}
  }
}

/** The clock's tick: a short, dry click. */
export function playTick() {
  const ctx = audio()
  if (!ctx) return
  try {
    const osc = ctx.createOscillator()
    osc.type = 'square'
    osc.frequency.value = 1800
    const gain = ctx.createGain()
    gain.gain.setValueAtTime(0.08, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.05)
    osc.connect(gain).connect(ctx.destination)
    osc.start()
    osc.stop(ctx.currentTime + 0.06)
  } catch {
    // No sound.
  }
}

/** The wind of the board turning over: noise swept down through a low-pass. */
export function playWhoosh() {
  const ctx = audio()
  if (!ctx) return
  try {
    const source = ctx.createBufferSource()
    source.buffer = noise(ctx)
    const filter = ctx.createBiquadFilter()
    filter.type = 'lowpass'
    filter.frequency.setValueAtTime(3000, ctx.currentTime)
    filter.frequency.exponentialRampToValueAtTime(200, ctx.currentTime + 0.7)
    const gain = ctx.createGain()
    gain.gain.setValueAtTime(0.0001, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.15)
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.8)
    source.connect(filter).connect(gain).connect(ctx.destination)
    source.start()
    source.stop(ctx.currentTime + 0.85)
  } catch {
    // No sound.
  }
}
