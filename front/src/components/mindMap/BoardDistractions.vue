<template>
  <div
    class="board-distractions"
    :class="{ 'board-distractions--wobble': wobbling }"
    :data-distraction="level"
    data-testid="board-distractions"
  >
    <slot />
    <div
      v-if="flashing"
      class="board-distractions__flash"
      data-testid="board-distractions-flash"
      aria-hidden="true"
    />
    <div
      v-if="shadowing"
      class="board-distractions__shadow"
      aria-hidden="true"
    />
    <div
      v-if="running && balls.length"
      class="board-distractions__balls"
      aria-hidden="true"
    >
      <span
        v-for="(glyph, i) in balls"
        :key="i"
        class="board-distractions__ball"
        :style="{
          animationDelay: `${i * 0.9}s`,
          top: `${20 + ((i * 27) % 60)}%`
        }"
        >{{ glyph }}</span
      >
    </div>
  </div>
</template>

<script setup>
/**
 * The distractions of a mind map boss around its board (docs/MIND_MAP.md, § 2): none in the
 * exercises, `light` (the board wobbles now and then, two pieces drift across it), `medium` (more
 * often, three pieces, and a stray move sound now and then while `active`), `strong` (the
 * Gargouille: lightning flashing across the board with its thunder, a dark veil passing, the board
 * wobbling, four pieces). Nothing moves when the system asks for less motion; the sounds follow the
 * player's « Sons » preference.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { playMoveSound } from '@/utils/chess/moveSounds'
import { playAmbientSound } from '@/utils/sounds'

const props = defineProps({
  /** @type {import('vue').PropType<'none'|'light'|'medium'|'strong'>} */
  level: { type: String, default: 'none' },
  /** While the player memorizes, listens or answers. */
  active: { type: Boolean, default: false }
})

/** By level: how often something happens (ms), its chance then, the pieces drifting, a sound. */
const RHYTHMS = {
  light: { every: 4000, chance: 0.35, balls: ['♞', '♝'], sound: false },
  medium: { every: 2500, chance: 0.5, balls: ['♞', '♝', '♜'], sound: true },
  strong: { every: 1500, chance: 0.7, balls: ['♞', '♝', '♜', '♛'], sound: true }
}
const WOBBLE_MS = 900
const FLASH_MS = 700
const SHADOW_MS = 1600

const auth = useAuthStore()
const wobbling = ref(false)
const flashing = ref(false)
const shadowing = ref(false)
/** @type {ReturnType<typeof setInterval>|undefined} */
let ticker
/** @type {ReturnType<typeof setTimeout>|undefined} */
let wobbleTimer
/** @type {ReturnType<typeof setTimeout>|undefined} */
let effectTimer

const rhythm = computed(
  () => RHYTHMS[/** @type {keyof typeof RHYTHMS} */ (props.level)] ?? null
)
const running = computed(() => props.active && rhythm.value !== null)
const balls = computed(() => rhythm.value?.balls ?? [])

/**
 * A short effect: on, then off after $ms.
 *
 * @param {import('vue').Ref<boolean>} flag
 * @param {number} ms
 */
function pulse(flag, ms) {
  flag.value = true
  clearTimeout(effectTimer)
  effectTimer = setTimeout(() => (flag.value = false), ms)
}

function tick() {
  const r = rhythm.value
  if (!r || Math.random() > r.chance) return
  if (props.level === 'strong') {
    const roll = Math.random()
    if (roll < 0.35) {
      pulse(flashing, FLASH_MS)
      playAmbientSound('thunder', { volume: 0.8 })
      return
    }
    if (roll < 0.5) {
      pulse(shadowing, SHADOW_MS)
      return
    }
  }
  if (Math.random() < 0.6) {
    wobbling.value = true
    clearTimeout(wobbleTimer)
    wobbleTimer = setTimeout(() => (wobbling.value = false), WOBBLE_MS)
  } else if (r.sound && auth.profile?.moveSound === true) {
    playMoveSound(Math.random() < 0.5 ? 'move' : 'capture')
  }
}

watch(
  running,
  on => {
    clearInterval(ticker)
    if (on && rhythm.value) ticker = setInterval(tick, rhythm.value.every)
    else wobbling.value = false
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  clearInterval(ticker)
  clearTimeout(wobbleTimer)
  clearTimeout(effectTimer)
})
</script>

<style scoped lang="scss">
.board-distractions {
  position: relative;

  &--wobble {
    animation: board-distractions-wobble 0.3s ease-in-out 3;
  }
}

.board-distractions__flash {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: linear-gradient(
    115deg,
    transparent 35%,
    rgb(255 255 255 / 0.95) 48%,
    transparent 62%
  );
  animation: board-distractions-flash 0.7s ease-out;
}

.board-distractions__shadow {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: radial-gradient(
    circle at 50% 40%,
    rgb(20 10 40 / 0.2),
    rgb(20 10 40 / 0.85)
  );
  animation: board-distractions-shadow 1.6s ease-in-out;
}

.board-distractions__balls {
  position: absolute;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
}

.board-distractions__ball {
  position: absolute;
  left: -15%;
  font-size: 44px;
  opacity: 0.7;
  animation: board-distractions-drift 3.2s linear infinite;
}

@keyframes board-distractions-wobble {
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

@keyframes board-distractions-flash {
  0% {
    opacity: 0;
  }
  10% {
    opacity: 1;
  }
  30% {
    opacity: 0.2;
  }
  40% {
    opacity: 0.9;
  }
  100% {
    opacity: 0;
  }
}

@keyframes board-distractions-shadow {
  0%,
  100% {
    opacity: 0;
  }
  40%,
  70% {
    opacity: 1;
  }
}

@keyframes board-distractions-drift {
  0% {
    transform: translate(0, 0) rotate(0);
  }
  50% {
    transform: translate(65vw, -80px) rotate(200deg);
  }
  100% {
    transform: translate(130vw, 0) rotate(400deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .board-distractions--wobble {
    animation: none;
  }

  .board-distractions__balls,
  .board-distractions__flash,
  .board-distractions__shadow {
    display: none;
  }
}
</style>
