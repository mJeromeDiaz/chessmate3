<template>
  <q-dialog
    :model-value="!!notice"
    persistent
    :maximized="$q.screen.lt.sm"
    aria-labelledby="streak-title"
    transition-show="fade"
    transition-hide="fade"
    @hide="onHide"
  >
    <div
      v-if="notice"
      class="streak"
      :class="`streak--p${phase}`"
      data-testid="streak-celebration"
    >
      <div class="streak__bubble">
        <img
          v-if="profImg"
          :src="profImg"
          alt=""
          class="streak__prof"
          width="76"
          height="76"
        />
        <div class="streak__say">
          <div class="streak__who">ALBERT</div>
          <div class="streak__msg" data-testid="streak-celebration-message">{{
            message
          }}</div>
        </div>
      </div>

      <div class="streak__group">
        <div class="streak__flame" aria-hidden="true">
          <div class="streak__glow" />
          <div v-if="phase >= 2" :key="`ring-${run}`" class="streak__ring" />
          <div class="streak__shadow" />
          <div class="streak__drops">
            <div class="streak__drop streak__drop--outer" />
            <div class="streak__drop streak__drop--inner" />
            <span class="streak__knight">♞&#xFE0E;</span>
          </div>
          <template v-if="phase >= 2">
            <span
              v-for="spark in sparks"
              :key="`${run}-${spark.i}`"
              class="streak__spark"
              :style="spark.style"
            />
          </template>
        </div>
        <div class="streak__counter">
          <div class="streak__prev" aria-hidden="true">{{ previous }}</div>
          <div class="streak__count" data-testid="streak-celebration-count">{{
            notice.streak
          }}</div>
        </div>
        <h2 id="streak-title" class="streak__label"
          >{{ notice.streak > 1 ? 'jours' : 'jour' }} de série !</h2
        >
        <div
          v-if="badge"
          class="streak__badge"
          data-testid="streak-celebration-badge"
          >Nouveau badge : {{ badge.name }}</div
        >
      </div>

      <div class="streak__week" aria-label="Ta semaine">
        <div
          v-for="(day, i) in week"
          :key="i"
          class="streak__day"
          :class="{
            'streak__day--today': day.today,
            'streak__day--on': day.done && (day.today ? phase >= 6 : phase >= 5)
          }"
          :style="{ '--delay': `${day.today ? 0 : i * 90}ms` }"
        >
          <span class="streak__day-label">{{ day.label }}</span>
          <span class="streak__day-dot"
            ><span class="streak__day-check">✓</span></span
          >
        </div>
      </div>

      <div class="streak__foot">
        <div class="streak__next" data-testid="streak-celebration-next">{{
          next
        }}</div>
        <button
          type="button"
          class="streak__cta"
          data-testid="streak-celebration-continue"
          @click="gamification.closeCelebration()"
          >Continuer</button
        >
      </div>

      <ConfettiBurst
        v-if="phase >= 2"
        :key="`confetti-${run}`"
        :colors="CONFETTI"
        pieces
        class="streak__confetti"
      />
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * The streak celebration (design "Série"): the flame lights up, the counter goes from yesterday's
 * streak to today's, the week ticks, Albert speaks, the next milestone shows. Shown once a day
 * (stores/gamification.js, celebrateStreak); "Continuer" closes it and the player goes on where
 * they were. With reduced motion, the final state at once.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { useAuthStore } from '@/stores/auth'
import { useGamificationStore } from '@/stores/gamification'
import { profImage } from '@/utils/prof/images'
import {
  nextMilestoneText,
  streakBadge,
  streakMessage,
  weekRow
} from '@/utils/streak'
import ConfettiBurst from '@/components/training/ConfettiBurst.vue'

/** The design's timeline: [ms, phase]. */
const STEPS = [
  [150, 1],
  [1050, 2],
  [1650, 3],
  [2300, 4],
  [2700, 5],
  [3700, 6]
]
const CONFETTI = [
  '#C6F432',
  '#FF6FAE',
  '#FFD43B',
  '#8B6BFF',
  '#4FB2FF',
  '#FF8A3D'
]

const $q = useQuasar()
const auth = useAuthStore()
const gamification = useGamificationStore()

const notice = computed(() => gamification.celebration)
const phase = ref(0)
/** A new key per showing: the sparks, ring and confetti play again. */
const run = ref(0)
/** @type {ReturnType<typeof setTimeout>[]} */
let timers = []

const profImg = profImage('albert-joy')
const previous = computed(() =>
  notice.value && notice.value.streak > 1 ? notice.value.streak - 1 : ''
)
const badge = computed(() => streakBadge(notice.value?.badge))
const message = computed(() =>
  notice.value
    ? streakMessage(notice.value, auth.profile?.displayName ?? null)
    : ''
)
const next = computed(() =>
  notice.value
    ? nextMilestoneText(notice.value.streak, notice.value.nextMilestone)
    : ''
)
const week = computed(() =>
  notice.value ? weekRow(notice.value.week, notice.value.localDate) : []
)

const sparks = Array.from({ length: 14 }, (_, i) => {
  const a = (i / 14) * Math.PI * 2 + 0.2
  const r = 120 + (i % 3) * 26
  const size = i % 2 ? 9 : 7
  return {
    i,
    style: {
      width: `${size}px`,
      height: `${size}px`,
      borderRadius: i % 3 ? '50%' : '2px',
      background: CONFETTI[i % CONFETTI.length],
      '--dx': `${Math.cos(a) * r}px`,
      '--dy': `${Math.sin(a) * r}px`
    }
  }
})

function clear() {
  timers.forEach(clearTimeout)
  timers = []
}

function play() {
  clear()
  run.value++
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
    phase.value = 6
    return
  }
  phase.value = 0
  timers = STEPS.map(([ms, p]) => setTimeout(() => (phase.value = p), ms))
}

watch(notice, value => (value ? play() : clear()), { immediate: true })

/** Closed some other way than "Continuer" (it is persistent, so hardly): the same as it. */
function onHide() {
  if (gamification.celebration) gamification.closeCelebration()
}

onBeforeUnmount(clear)
</script>

<style scoped lang="scss">
// A dark screen in both themes, like the design: the flame shines on the night.
.streak {
  --s-bg: #14101f;
  --s-panel: #1f1a30;
  --s-line: #3a3350;
  --s-ink: #f3f1fa;
  --s-muted: #a29db8;
  --s-orange: #ff8a3d;
  --s-orange-deep: #b84a0e;
  --s-yellow: #ffd43b;
  --s-lime: #c6f432;
  --s-lime-deep: #8dba0a;
  --s-night: #1b1530;

  position: relative;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 18px;
  width: 420px;
  max-width: 100vw;
  min-height: min(780px, 100vh);
  padding: 28px 20px 24px;
  overflow: hidden;
  background: var(--s-bg);
  color: var(--s-ink);
  border-radius: 32px;

  @media (max-width: $breakpoint-xs-max) {
    width: 100%;
    min-height: 100%;
    border-radius: 0;
    padding-top: max(28px, env(safe-area-inset-top));
    padding-bottom: max(24px, env(safe-area-inset-bottom));
  }
}

.streak__bubble {
  display: flex;
  gap: 10px;
  align-items: flex-end;
  opacity: 0;
  transform: translateY(-20px);
  transition:
    opacity 0.5s ease,
    transform 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.streak__prof {
  flex: none;
  width: 76px;
  height: 76px;
  border-radius: 20px;
  background: var(--s-lime);
  box-shadow: 0 4px 0 var(--s-lime-deep);
  object-fit: cover;
}

.streak__say {
  flex: 1;
  background: var(--s-panel);
  border: 2px solid var(--s-line);
  border-radius: 20px 20px 20px 6px;
  padding: 12px 14px;
}

.streak__who {
  font-size: 11px;
  font-weight: 700;
  color: var(--s-lime);
  letter-spacing: 0.04em;
}

.streak__msg {
  font-size: 15.5px;
  line-height: 1.4;
  text-wrap: pretty;
}

.streak__group {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  transform: translateY(70px);
  transition: transform 0.8s cubic-bezier(0.34, 1.3, 0.64, 1);
}

.streak__flame {
  position: relative;
  width: 200px;
  height: 200px;
  display: flex;
  align-items: center;
  justify-content: center;
  transform: scale(0);
  transition: transform 0.7s cubic-bezier(0.34, 1.8, 0.64, 1);
}

.streak__glow {
  position: absolute;
  width: 230px;
  height: 230px;
  border-radius: 50%;
  background: radial-gradient(circle, var(--s-orange) 0%, transparent 65%);
  opacity: 0;
  transition: opacity 0.6s;
}

.streak__ring {
  position: absolute;
  top: 40px;
  width: 150px;
  height: 150px;
  border-radius: 50%;
  border: 4px solid var(--s-yellow);
  animation: streak-ring 0.8s ease-out forwards;
}

.streak__shadow {
  position: absolute;
  bottom: 6px;
  width: 120px;
  height: 22px;
  border-radius: 50%;
  background: #000;
  opacity: 0.35;
}

.streak__drops {
  position: relative;
  width: 140px;
  height: 140px;
  margin-top: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.streak__drop {
  position: absolute;
  border-radius: 50% 0 50% 50%;
  transform: rotate(-45deg);

  &--outer {
    width: 140px;
    height: 140px;
    background: var(--s-orange);
    box-shadow: inset -10px 10px 0 #ffa85e;
    animation: streak-flicker 1.6s ease-in-out infinite;
  }

  &--inner {
    top: 50px;
    width: 84px;
    height: 84px;
    background: var(--s-yellow);
    animation: streak-flicker-2 1.2s ease-in-out infinite;
  }
}

.streak__knight {
  position: relative;
  top: 22px;
  font-size: 52px;
  line-height: 1;
  color: var(--s-night);
  font-family: 'Segoe UI Symbol', 'DejaVu Sans', 'Noto Sans Symbols 2', serif;
}

.streak__spark {
  position: absolute;
  left: 50%;
  top: 55%;
  margin-left: -4px;
  animation: streak-spark 0.9s cubic-bezier(0.2, 0.7, 0.3, 1) forwards;
}

.streak__counter {
  position: relative;
  width: 260px;
  height: 132px;
  margin-top: 4px;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 128px;
  line-height: 132px;
  font-variant-numeric: tabular-nums;
  text-align: center;
}

.streak__prev,
.streak__count {
  position: absolute;
  inset: 0;
}

.streak__prev {
  color: var(--s-line);
  opacity: 0;
  transition:
    opacity 0.45s ease,
    transform 0.55s cubic-bezier(0.5, 0, 0.75, 0);
}

.streak__count {
  color: var(--s-orange);
  text-shadow: 0 6px 0 var(--s-orange-deep);
  opacity: 0;
  transform: translateY(-110px) scale(0.6);
  transition:
    opacity 0.2s ease,
    transform 0.6s cubic-bezier(0.34, 1.7, 0.64, 1);
}

.streak__label {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 30px;
  line-height: 1.2;
  color: var(--s-orange);
  opacity: 0;
  transform: translateY(14px);
  transition:
    opacity 0.4s ease,
    transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.streak__badge {
  margin-top: 10px;
  padding: 6px 14px;
  border-radius: 999px;
  background: var(--s-yellow);
  color: var(--s-night);
  font-weight: 800;
  font-size: 14px;
  opacity: 0;
  transform: scale(0.6);
  transition:
    opacity 0.3s ease,
    transform 0.5s cubic-bezier(0.34, 1.9, 0.64, 1);
}

.streak__week {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 6px;
  opacity: 0;
  transform: translateY(30px);
  transition:
    opacity 0.5s ease,
    transform 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.streak__day {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.streak__day-label {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 17px;
  color: var(--s-muted);

  .streak__day--today & {
    color: var(--s-orange);
  }
}

.streak__day-dot {
  position: relative;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: #2c2640;

  .streak__day--today.streak__day--on & {
    box-shadow: 0 0 0 4px rgba(255, 138, 61, 0.3);
  }
}

.streak__day-check {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: var(--s-orange);
  color: var(--s-night);
  font-weight: 800;
  font-size: 20px;
  transform: scale(0);
  transition: transform 0.45s cubic-bezier(0.34, 1.9, 0.64, 1);
  transition-delay: var(--delay);

  .streak__day--on & {
    transform: scale(1);
  }
}

.streak__foot {
  display: flex;
  flex-direction: column;
  gap: 10px;
  opacity: 0;
  transform: translateY(20px);
  transition:
    opacity 0.4s ease,
    transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.streak__next {
  text-align: center;
  font-size: 13px;
  color: var(--s-muted);
}

.streak__cta {
  height: 54px;
  border: none;
  border-radius: 18px;
  background: var(--s-lime);
  color: var(--s-night);
  font-weight: 700;
  font-size: 16px;
  box-shadow: 0 4px 0 var(--s-lime-deep);
  cursor: pointer;

  &:active {
    transform: translateY(2px);
    box-shadow: 0 2px 0 var(--s-lime-deep);
  }

  &:focus-visible {
    outline: 3px solid var(--s-yellow);
    outline-offset: 3px;
  }
}

.streak__confetti {
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 10;
}

// The timeline (phase 1 to 6), as in the design.
.streak--p1,
.streak--p2,
.streak--p3,
.streak--p4,
.streak--p5,
.streak--p6 {
  .streak__flame {
    transform: scale(1);
  }
}

.streak--p1 .streak__prev {
  opacity: 1;
}

.streak--p2,
.streak--p3,
.streak--p4,
.streak--p5,
.streak--p6 {
  .streak__glow {
    opacity: 0.45;
    animation: streak-glow 2.4s ease-in-out infinite;
  }

  .streak__prev {
    opacity: 0;
    transform: translateY(90px);
  }

  .streak__count {
    opacity: 1;
    transform: none;
  }
}

.streak--p3,
.streak--p4,
.streak--p5,
.streak--p6 {
  .streak__label {
    opacity: 1;
    transform: none;
  }
}

.streak--p4,
.streak--p5,
.streak--p6 {
  .streak__group {
    transform: scale(0.82);
  }

  .streak__bubble,
  .streak__week {
    opacity: 1;
    transform: none;
  }
}

.streak--p5,
.streak--p6 {
  .streak__badge {
    opacity: 1;
    transform: none;
  }
}

.streak--p6 .streak__foot {
  opacity: 1;
  transform: none;
}

@keyframes streak-flicker {
  0%,
  100% {
    transform: rotate(-45deg) scale(1, 1);
  }
  50% {
    transform: rotate(-43deg) scale(0.96, 1.05);
  }
}

@keyframes streak-flicker-2 {
  0%,
  100% {
    transform: rotate(-45deg) scale(1);
  }
  50% {
    transform: rotate(-47deg) scale(1.08, 0.94);
  }
}

@keyframes streak-spark {
  0% {
    transform: translate(0, 0) scale(1);
    opacity: 1;
  }
  100% {
    transform: translate(var(--dx), var(--dy)) scale(0.3);
    opacity: 0;
  }
}

@keyframes streak-ring {
  0% {
    transform: scale(0.4);
    opacity: 0.9;
  }
  100% {
    transform: scale(2.4);
    opacity: 0;
  }
}

@keyframes streak-glow {
  0%,
  100% {
    opacity: 0.35;
  }
  50% {
    opacity: 0.6;
  }
}

@media (prefers-reduced-motion: reduce) {
  .streak *,
  .streak *::before {
    animation: none !important;
    transition: none !important;
  }
}
</style>
