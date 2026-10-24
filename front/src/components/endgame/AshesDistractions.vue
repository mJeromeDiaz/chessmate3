<template>
  <div
    class="ashes"
    :class="{
      'ashes--shake': shaking,
      'ashes--still': reducedMotion
    }"
    :data-tier="tier ?? 0"
    data-testid="ashes-distractions"
  >
    <slot />
    <template v-if="running">
      <div
        v-if="raining"
        class="ashes__rain"
        data-testid="ashes-rain"
        aria-hidden="true"
      >
        <span
          v-for="(drop, i) in DROPS"
          :key="`d${i}`"
          class="ashes__drop"
          :class="{ 'ashes__drop--back': drop.back }"
          :style="{
            left: `${drop.left}%`,
            height: `${drop.length}px`,
            opacity: drop.opacity,
            animationDelay: `${drop.delay}s`,
            animationDuration: `${drop.duration}s`,
            '--still-top': drop.delay * 90
          }"
        />
        <span
          v-for="(splash, i) in SPLASHES"
          :key="`s${i}`"
          class="ashes__splash"
          :style="{
            left: `${splash.left}%`,
            top: `${splash.top}%`,
            animationDelay: `${splash.delay}s`
          }"
        />
      </div>
      <div v-if="veiled" class="ashes__veil" aria-hidden="true" />
      <div
        v-if="flashing"
        class="ashes__flash"
        data-testid="ashes-flash"
        aria-hidden="true"
      />
      <div v-if="drifting" class="ashes__drift" aria-hidden="true">
        <span
          v-for="(glyph, i) in DRIFT"
          :key="i"
          class="ashes__piece"
          :style="{ animationDelay: `${i * 0.5}s`, top: `${18 + i * 24}%` }"
          >{{ glyph }}</span
        >
      </div>
      <span
        v-for="square in ashes"
        :key="square"
        class="ashes__cloud"
        :style="cloudStyle(square)"
        data-testid="ashes-cloud"
        aria-hidden="true"
      />
    </template>
  </div>
</template>

<script setup>
/**
 * The Roi des cendres' distractions around its board (docs/ENDGAMES.md, § 10), by the tier of the
 * position played: rain in showers (`nextShower`: drops of various lengths and speeds on two
 * planes, splashes on the board, its sound); and, drawn at the tier's pace (`drawEffect`), the board
 * shaking, pieces drifting across, thunder darkening it (tier 1), lightning, stray move sounds,
 * false arrows (2), pieces veiled with ashes, the board turned over (3). The false arrow and the
 * turning are the board's: they go up (`decoy`, `flip`). Photosensitivity: one lightning in 4 s at
 * most, on the board only, never full white; when the system asks for less motion, only what does
 * not move or flash stays (still rain, thunder's sound, stray sounds, false arrows, ashes).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import {
  ashSquares,
  decoyMove,
  drawEffect,
  EFFECT_MS,
  nextShower,
  rainDrops,
  rainSplashes,
  squareBox,
  TIER_EVERY_MS
} from '@/utils/ashes'
import { playWhoosh, startRain } from '@/utils/ashesSounds'
import { playMoveSound } from '@/utils/chess/moveSounds'
import { playAmbientSound } from '@/utils/sounds'

const props = defineProps({
  /** 1 to 3, null: no distraction (any other position). */
  tier: { type: Number, default: null },
  /** While the position is played. */
  active: { type: Boolean, default: false },
  /** The position on the board (ashes on its pieces, false arrows among its moves). */
  fen: { type: String, required: true },
  /** @type {import('vue').PropType<'white'|'black'>} the side shown at the bottom */
  orientation: { type: String, default: 'white' }
})

const emit = defineEmits({
  /** A false arrow to draw, null: none any more. */
  decoy: move => move === null || typeof move?.from === 'string',
  /** The board turned over (true) or back (false). */
  flip: value => typeof value === 'boolean'
})

/** What drifts across the board. */
const DRIFT = ['♞', '♜', '♛']
/** A shower's drops and splashes, drawn once. */
const DROPS = rainDrops(42)
const SPLASHES = rainSplashes(8)

const auth = useAuthStore()
const reducedMotion = ref(false)
const shaking = ref(false)
const drifting = ref(false)
const veiled = ref(false)
const flashing = ref(false)
/** A shower falling. */
const raining = ref(false)
/** @type {ReturnType<typeof setTimeout>|undefined} the next shower's start or this one's end */
let showerTimer
/** @type {import('vue').Ref<string[]>} squares veiled with ashes */
const ashes = ref([])
/** @type {number|null} */
let lastLightningAt = null
/** @type {ReturnType<typeof setInterval>|undefined} */
let ticker
/** @type {Map<string, ReturnType<typeof setTimeout>>} each effect's end */
const timers = new Map()
let stopRain = () => {}

const running = computed(() => props.active && props.tier !== null)

/** @type {MediaQueryList|null} */
let motionQuery = null
const onMotion = () => {
  reducedMotion.value = !!motionQuery?.matches
}

onMounted(() => {
  if (typeof window === 'undefined' || !window.matchMedia) return
  motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
  onMotion()
  motionQuery.addEventListener?.('change', onMotion)
})

/**
 * An effect on for its time, then off (`off` runs at its end).
 *
 * @param {import('@/utils/ashes').AshesEffect} effect
 * @param {() => void} off
 */
function hold(effect, off) {
  clearTimeout(timers.get(effect))
  timers.set(effect, setTimeout(off, EFFECT_MS[effect]))
}

function tick() {
  const now = Date.now()
  const effect = drawEffect(props.tier, {
    reducedMotion: reducedMotion.value,
    now,
    lastLightningAt
  })
  switch (effect) {
    case 'shake':
      shaking.value = true
      hold(effect, () => (shaking.value = false))
      break
    case 'drift':
      drifting.value = true
      hold(effect, () => (drifting.value = false))
      break
    case 'thunder':
      playAmbientSound('thunder', { volume: 0.7 })
      if (!reducedMotion.value) {
        veiled.value = true
        hold(effect, () => (veiled.value = false))
      }
      break
    case 'lightning':
      lastLightningAt = now
      flashing.value = true
      playAmbientSound('thunder', { volume: 0.9 })
      hold(effect, () => (flashing.value = false))
      break
    case 'parasite':
      if (auth.profile?.moveSound === true)
        playMoveSound(Math.random() < 0.5 ? 'move' : 'capture')
      break
    case 'decoy':
      emit('decoy', decoyMove(props.fen))
      hold(effect, () => emit('decoy', null))
      break
    case 'ash':
      ashes.value = ashSquares(props.fen)
      hold(effect, () => (ashes.value = []))
      break
    case 'flip':
      playWhoosh()
      emit('flip', true)
      hold(effect, () => emit('flip', false))
      break
  }
}

/**
 * The rain's rhythm: a dry spell, a shower (its sound with it), and again.
 *
 * @param {boolean} first the position's first shower
 */
function scheduleShower(first) {
  const { dryMs, showerMs } = nextShower(first)
  showerTimer = setTimeout(() => {
    raining.value = true
    stopRain = startRain()
    showerTimer = setTimeout(() => {
      raining.value = false
      stopRain()
      stopRain = () => {}
      scheduleShower(false)
    }, showerMs)
  }, dryMs)
}

/** Everything off, the board given back as it was. */
function clear() {
  clearTimeout(showerTimer)
  raining.value = false
  for (const timer of timers.values()) clearTimeout(timer)
  timers.clear()
  shaking.value = false
  drifting.value = false
  veiled.value = false
  flashing.value = false
  ashes.value = []
  emit('decoy', null)
  emit('flip', false)
}

watch(
  [running, () => props.tier],
  ([on]) => {
    clearInterval(ticker)
    stopRain()
    stopRain = () => {}
    clear()
    if (!on || props.tier === null) return
    scheduleShower(true)
    ticker = setInterval(tick, TIER_EVERY_MS[props.tier] ?? TIER_EVERY_MS[1])
  },
  { immediate: true }
)

/** @param {string} square */
function cloudStyle(square) {
  const box = squareBox(
    square,
    /** @type {'white'|'black'} */ (props.orientation)
  )
  return { left: `${box.left}%`, top: `${box.top}%` }
}

onBeforeUnmount(() => {
  clearInterval(ticker)
  clear()
  stopRain()
  motionQuery?.removeEventListener?.('change', onMotion)
})
</script>

<style scoped lang="scss">
// Exactly the board's box (ChessBoard's own width rules), so that ashes land on their squares.
.ashes {
  position: relative;
  width: 100%;
  max-width: var(--board-max, min(92vw, 70vh, 560px));
  margin: 0 auto;

  &--shake {
    animation: ashes-shake 0.3s ease-in-out 3;
  }
}

// A shower: thin drops falling on two planes, splashes on the board; still when the system asks
// for less motion. Light, the board stays readable.
.ashes__rain {
  position: absolute;
  inset: 0;
  overflow: hidden;
  // The drops fall the board's whole height (cqh).
  container-type: size;
  pointer-events: none;
  animation: ashes-shower 1.2s ease-out;
}

.ashes__drop {
  position: absolute;
  top: -40px;
  width: 1.5px;
  border-radius: 1px;
  background: linear-gradient(transparent, rgb(200 215 235));
  transform: rotate(12deg);
  animation: ashes-drop 0.6s linear infinite;

  &--back {
    width: 1px;
    filter: blur(0.6px);
  }

  .ashes--still & {
    top: calc(var(--still-top, 30) * 1%);
    animation: none;
  }
}

.ashes__splash {
  position: absolute;
  width: 10px;
  height: 4px;
  border-radius: 50%;
  border: 1px solid rgb(210 222 240 / 0.6);
  opacity: 0;
  animation: ashes-splash 1.2s ease-out infinite;

  .ashes--still & {
    display: none;
  }
}

.ashes__veil {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: radial-gradient(
    circle at 50% 40%,
    rgb(20 10 30 / 0.25),
    rgb(20 10 30 / 0.8)
  );
  animation: ashes-veil 1.6s ease-in-out;
}

// One soft flash on the board only (photosensitivity: no full white, a single peak).
.ashes__flash {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: linear-gradient(
    115deg,
    transparent 30%,
    rgb(235 240 255 / 0.7) 50%,
    transparent 70%
  );
  animation: ashes-flash 0.5s ease-out;
}

.ashes__drift {
  position: absolute;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
}

.ashes__piece {
  position: absolute;
  left: -15%;
  font-size: 44px;
  opacity: 0.6;
  color: #3a2c2c;
  animation: ashes-drift 3.2s linear;
}

// Ashes over a square: the piece under it vanishes for a while.
.ashes__cloud {
  position: absolute;
  width: 12.5%;
  height: 12.5%;
  pointer-events: none;
  border-radius: 30%;
  background: radial-gradient(circle, #5b5552 35%, #6e6662 60%, #8a827d);
  box-shadow: 0 0 10px 6px rgb(110 102 98 / 0.85);
  animation: ashes-cloud 2s ease-in-out;

  .ashes--still & {
    animation: none;
  }
}

@keyframes ashes-shake {
  0%,
  100% {
    transform: rotate(0);
  }
  25% {
    transform: rotate(-1.5deg) translateX(-4px);
  }
  75% {
    transform: rotate(1.5deg) translateX(4px);
  }
}

@keyframes ashes-shower {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

@keyframes ashes-drop {
  to {
    transform: translate(-30px, calc(100cqh + 80px)) rotate(12deg);
  }
}

@keyframes ashes-splash {
  0%,
  60% {
    opacity: 0;
    transform: scale(0.3);
  }
  70% {
    opacity: 1;
  }
  100% {
    opacity: 0;
    transform: scale(1.6);
  }
}

@keyframes ashes-veil {
  0%,
  100% {
    opacity: 0;
  }
  40%,
  70% {
    opacity: 1;
  }
}

@keyframes ashes-flash {
  0% {
    opacity: 0;
  }
  20% {
    opacity: 1;
  }
  100% {
    opacity: 0;
  }
}

@keyframes ashes-drift {
  0% {
    transform: translate(0, 0) rotate(0);
  }
  100% {
    transform: translate(130vw, -40px) rotate(360deg);
  }
}

@keyframes ashes-cloud {
  0% {
    opacity: 0;
    transform: scale(0.6);
  }
  15%,
  85% {
    opacity: 1;
    transform: scale(1);
  }
  100% {
    opacity: 0;
  }
}

// The system's « less motion » (the script keeps out the moving effects too).
@media (prefers-reduced-motion: reduce) {
  .ashes--shake {
    animation: none;
  }

  .ashes__drift,
  .ashes__flash,
  .ashes__veil {
    display: none;
  }
}
</style>
