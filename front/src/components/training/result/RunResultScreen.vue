<template>
  <div
    class="rr"
    :class="{ 'rr--shake': kind === 'fail' && step === 5 }"
    :data-kind="kind"
    data-testid="run-result"
  >
    <div
      class="rr__flood"
      :style="{
        background: screen.bg,
        clipPath: `circle(${step >= 1 ? '130%' : '0%'} at 50% 40%)`
      }"
    >
      <div class="rr__shade" :style="{ background: screen.deep }" />
      <div class="rr__glow" />
      <FloatingPieces
        v-if="step >= 1"
        :ink="kind === 'fail' ? '#1B1530' : '#FFFFFF'"
        :dir="kind === 'fail' ? 'down' : 'up'"
      />
    </div>

    <div class="rr__column">
      <h2 class="rr__title" :aria-label="`${screen.line1} ${screen.line2}`">
        <span class="rr__line" aria-hidden="true">
          <span
            v-for="(l, i) in line1"
            :key="i"
            class="rr__letter"
            :style="letterStyle(l)"
            >{{ l.ch }}</span
          >
        </span>
        <span class="rr__line" aria-hidden="true">
          <span
            v-for="(l, i) in line2"
            :key="i"
            class="rr__letter"
            :style="{ ...letterStyle(l), color: screen.accentInk }"
            >{{ l.ch }}</span
          >
        </span>
      </h2>

      <ProfStage
        :key="`stage-${run}`"
        :image="prof.image"
        :glyph="prof.glyph"
        :shown="step >= 2"
        :rings="step >= 2"
        :disc="screen.deep"
        :ring="kind === 'fail' ? '#FFE6F1' : '#FFFFFF'"
        :mood="step >= 3 ? (kind === 'fail' ? 'rage' : 'bob') : 'still'"
      />

      <div
        class="rr__score"
        :style="{
          opacity: step >= 4 ? 1 : 0,
          transform: `translateY(${step >= 4 ? 0 : 20}px)`
        }"
      >
        <div class="rr__ring" :class="{ 'rr__ring--pop': step >= 5 }">
          <svg viewBox="0 0 100 100" aria-hidden="true">
            <circle cx="50" cy="50" r="44" fill="#fff" fill-opacity=".9" />
            <circle
              cx="50"
              cy="50"
              r="44"
              fill="none"
              :stroke="screen.track"
              stroke-width="8"
            />
            <circle
              cx="50"
              cy="50"
              r="44"
              fill="none"
              :stroke="screen.fill"
              stroke-width="8"
              stroke-linecap="round"
              :stroke-dasharray="CIRC"
              :stroke-dashoffset="dash"
            />
          </svg>
          <div class="rr__count">
            <span class="rr__number" data-testid="run-result-score">{{
              shownCount
            }}</span
            ><span class="rr__unit" :style="{ color: screen.fill }">{{
              screen.unit
            }}</span>
          </div>
          <template v-if="step >= 5">
            <span
              v-for="(x, i) in extras"
              :key="`${run}-${i}`"
              class="rr__extra"
              :class="`rr__extra--${kind === 'fail' ? 'zap' : 'star'}`"
              :style="x"
              aria-hidden="true"
              >{{ kind === 'fail' ? '⚡︎' : '✦' }}</span
            >
          </template>
        </div>
        <div class="rr__caption" :style="{ color: screen.ink }">{{
          screen.caption
        }}</div>
      </div>

      <div class="rr__spacer" />

      <div
        class="rr__buttons"
        :style="{
          opacity: step >= 6 ? 1 : 0,
          transform: `translateY(${step >= 6 ? 0 : 40}px)`,
          pointerEvents: step >= 6 ? 'auto' : 'none'
        }"
      >
        <button
          type="button"
          class="rr__btn rr__btn--main"
          :data-testid="`run-end-${buttons.primary.action}`"
          :disabled="busy && buttons.primary.action === 'next'"
          @click="emit('action', buttons.primary.action)"
        >
          {{ label(buttons.primary) }}
        </button>
        <button
          type="button"
          class="rr__btn rr__btn--soft"
          :style="{ boxShadow: `0 4px 0 ${screen.deep}` }"
          :data-testid="`run-end-${buttons.secondary.action}`"
          :disabled="busy && buttons.secondary.action === 'next'"
          @click="emit('action', buttons.secondary.action)"
        >
          {{ label(buttons.secondary) }}
        </button>
        <div v-if="error" class="rr__error">{{ error }}</div>
      </div>
    </div>

    <ConfettiBurst
      v-if="confetti && step >= 2"
      :key="`confetti-${run}`"
      :colors="RESULT_CONFETTI"
      pieces
    />
  </div>
</template>

<script setup>
/**
 * The result of a run (design "Résultat de leçon"): the screen floods with the verdict's colour,
 * the title falls letter by letter, the professor rises, the score counts up in its ring, then the
 * buttons. Three verdicts (perfect, close, fail) and free study's "Séance terminée". Confetti only
 * for a perfect lesson whose end was seen (`confetti`).
 */
import { computed, onMounted, watch } from 'vue'
import ConfettiBurst from '@/components/training/ConfettiBurst.vue'
import FloatingPieces from '@/components/training/result/FloatingPieces.vue'
import ProfStage from '@/components/training/result/ProfStage.vue'
import { useResultSteps } from '@/composables/training/useResultSteps'
import {
  RESULT_CONFETTI,
  RESULT_COUNT_MS,
  RESULT_COUNT_STEP,
  RESULT_SCREENS,
  RESULT_STEPS_MS,
  countAt,
  fallingLetters,
  resultButtons,
  resultProf
} from '@/utils/runResult'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/runResult').ResultKind>} */
  kind: { type: String, required: true },
  /** The API's module (its professor). */
  module: { type: String, required: true },
  /** Percent solved, or minutes of free study. */
  score: { type: Number, required: true },
  /** Missed items that can be corrected. */
  fixable: { type: Number, default: 0 },
  confetti: { type: Boolean, default: false },
  /** The next lesson is starting. */
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' }
})

const emit = defineEmits({
  /** A button: close, next or fix. */
  action: value => ['close', 'next', 'fix'].includes(value)
})

const CIRC = 276.5

const { step, k, run, play } = useResultSteps(
  RESULT_STEPS_MS,
  RESULT_COUNT_STEP,
  RESULT_COUNT_MS
)

const screen = computed(() => RESULT_SCREENS[props.kind])
const prof = computed(() => resultProf(props.module, props.kind))
const buttons = computed(() => resultButtons(props.kind, props.fixable))
const line1 = computed(() => fallingLetters(screen.value.line1, step.value))
const line2 = computed(() =>
  fallingLetters(screen.value.line2, step.value, screen.value.line1.length)
)
/** Free study's minutes are a count, not a share: the ring is full. */
const percent = computed(() => (props.kind === 'done' ? 100 : props.score))
const shownCount = computed(() => countAt(props.kind, props.score, k.value))
const dash = computed(
  () =>
    CIRC *
    (1 -
      (props.kind === 'done'
        ? k.value
        : countAt(props.kind, percent.value, k.value) / 100))
)

/** Sparkles (zaps after a failure) around the ring. */
const extras = computed(() => {
  const pos = [
    [-16, 18],
    [150, 30],
    [-8, 120],
    [146, 128],
    [64, -20]
  ]
  const n = props.kind === 'close' ? 3 : props.kind === 'fail' ? 4 : 5
  return pos.slice(0, n).map(([x, y], i) => ({
    left: `${x}px`,
    top: `${y}px`,
    color:
      props.kind === 'fail'
        ? '#B4231A'
        : i % 2
          ? '#FFFFFF'
          : props.kind === 'close'
            ? '#8A6A00'
            : '#FFD43B',
    fontSize: `${props.kind === 'fail' ? 24 : 18 + (i % 2) * 6}px`,
    animationDelay: `${i * (props.kind === 'fail' ? 0.12 : 0.4)}s`,
    '--rot': `${i * 40}deg`
  }))
})

/** @param {{op: number, tf: string, delay: string}} l */
function letterStyle(l) {
  return { opacity: l.op, transform: l.tf, transitionDelay: l.delay }
}

/** @param {import('@/utils/runResult').ResultButton} b */
function label(b) {
  return props.busy && b.action === 'next' ? 'Lancement…' : b.label
}

onMounted(play)
watch(() => props.kind, play)

defineExpose({ play })
</script>

<style scoped lang="scss">
.rr {
  position: absolute;
  inset: 0;
  overflow: hidden;
  background: #1b1530;
  color: #1b1530;

  &--shake {
    animation: rr-shake 0.6s ease-out;
  }
}

.rr__flood {
  position: absolute;
  inset: 0;
  transition: clip-path 0.8s cubic-bezier(0.5, 0, 0.2, 1);
}

.rr__shade {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 62%;
  opacity: 0.55;
  mask-image: linear-gradient(to bottom, transparent, #000);
}

.rr__glow {
  position: absolute;
  left: 50%;
  top: 14%;
  width: 520px;
  height: 520px;
  margin-left: -260px;
  background: radial-gradient(circle, rgba(255, 255, 255, 0.32), transparent 62%);
}

.rr__column {
  position: relative;
  z-index: 1;
  height: 100%;
  max-width: 430px;
  margin: 0 auto;
  box-sizing: border-box;
  padding: max(24px, env(safe-area-inset-top)) 18px
    max(24px, env(safe-area-inset-bottom));
  display: flex;
  flex-direction: column;
  align-items: stretch;
  overflow-y: auto;
}

.rr__title {
  margin: 12px 0 0;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.rr__line {
  display: flex;
  justify-content: center;
}

.rr__letter {
  display: inline-block;
  white-space: pre;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 54px;
  line-height: 1;
  letter-spacing: -0.02em;
  color: #1b1530;
  transition:
    transform 0.6s cubic-bezier(0.34, 1.9, 0.64, 1),
    opacity 0.2s;
}

.rr__score {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  margin-top: 16px;
  transition:
    opacity 0.35s,
    transform 0.55s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.rr__ring {
  position: relative;
  width: 156px;
  height: 156px;

  &--pop {
    animation: rr-pop 0.5s cubic-bezier(0.34, 1.8, 0.64, 1);
  }

  svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    transform: rotate(-90deg);
  }
}

.rr__count {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 2px;
}

.rr__number {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 50px;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.rr__unit {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
  padding-top: 12px;
}

.rr__extra {
  position: absolute;

  &--star {
    animation: rr-twinkle 1.6s ease-in-out infinite;
  }

  &--zap {
    font-weight: 800;
    animation: rr-zap 0.5s ease-in-out infinite;
  }
}

.rr__caption {
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.06em;
}

.rr__spacer {
  flex: 1;
  min-height: 24px;
}

.rr__buttons {
  display: flex;
  flex-direction: column;
  gap: 10px;
  transition:
    opacity 0.35s,
    transform 0.55s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.rr__btn {
  border: none;
  font-family: inherit;
  cursor: pointer;

  &:disabled {
    cursor: default;
    opacity: 0.7;
  }

  &--main {
    height: 58px;
    border-radius: 18px;
    background: #1b1530;
    color: #fff;
    font-weight: 800;
    font-size: 16px;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    box-shadow: 0 4px 0 #4c475e;
  }

  &--soft {
    height: 52px;
    border-radius: 16px;
    background: #fff;
    color: #1b1530;
    font-weight: 700;
    font-size: 14px;
  }
}

.rr__error {
  color: #fff;
  background: #b4231a;
  border-radius: 12px;
  padding: 8px 12px;
  font-size: 13px;
}

@keyframes rr-pop {
  0% {
    transform: scale(1);
  }
  40% {
    transform: scale(1.2);
  }
  100% {
    transform: scale(1);
  }
}

@keyframes rr-shake {
  0%,
  100% {
    transform: translate(0, 0);
  }
  15% {
    transform: translate(-10px, 4px) rotate(-1deg);
  }
  30% {
    transform: translate(9px, -5px) rotate(1deg);
  }
  45% {
    transform: translate(-7px, 3px);
  }
  60% {
    transform: translate(6px, -2px);
  }
  75% {
    transform: translate(-3px, 1px);
  }
}

@keyframes rr-twinkle {
  0%,
  100% {
    transform: scale(0.4) rotate(0);
    opacity: 0.2;
  }
  50% {
    transform: scale(1.1) rotate(45deg);
    opacity: 1;
  }
}

@keyframes rr-zap {
  0%,
  100% {
    transform: rotate(var(--rot)) scale(0.7);
    opacity: 0.4;
  }
  50% {
    transform: rotate(var(--rot)) scale(1.15);
    opacity: 1;
  }
}

@media (prefers-reduced-motion: reduce) {
  .rr,
  .rr * {
    animation: none !important;
    transition: none !important;
  }
}
</style>
