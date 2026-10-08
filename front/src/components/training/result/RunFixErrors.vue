<template>
  <div class="fix" data-testid="run-fix">
    <div
      class="fix__flood"
      :style="{ clipPath: `circle(${step >= 1 ? '130%' : '0%'} at 50% 40%)` }"
    >
      <div class="fix__shade" />
      <div class="fix__glow" />
      <FloatingPieces v-if="step >= 1" :count="16" marks />
    </div>

    <div class="fix__column">
      <h2 class="fix__title" :aria-label="FIX_TITLE">
        <span
          v-for="(w, i) in words"
          :key="i"
          class="fix__word"
          :style="{
            color: w.accent ? '#FFFFFF' : '#1B1530',
            opacity: w.op,
            transform: w.tf,
            transitionDelay: w.delay
          }"
          aria-hidden="true"
          >{{ w.t }}</span
        >
      </h2>

      <ProfStage
        :key="`stage-${run}`"
        class="fix__stage"
        :image="prof.image"
        :glyph="prof.glyph"
        :shown="step >= 2"
        :rings="step >= 2"
        disc="#5A3BE0"
        ring="#CBBDFF"
        dash="rgba(255,255,255,.35)"
        :mood="step >= 3 ? 'bob' : 'still'"
        :swap="step >= 4"
      >
        <template v-if="step >= 4">
          <span
            v-for="s in sparks"
            :key="`${run}-${s.key}`"
            class="fix__spark"
            :style="s.style"
            aria-hidden="true"
          />
        </template>
      </ProfStage>

      <div class="fix__cards" aria-hidden="true">
        <div
          v-for="(c, i) in cards"
          :key="`${run}-${i}`"
          class="fix__card"
          :style="{
            transform: c.transform,
            transitionDelay: c.delay,
            zIndex: i
          }"
        >
          <div
            class="fix__card-lift"
            :class="{ 'fix__card-lift--on': step >= 4 }"
            :style="{ animationDelay: c.liftDelay }"
          >
            <div class="fix__card-face">
              <div class="fix__card-board">{{ c.glyph }}</div>
              <div class="fix__card-n">#{{ c.number }}</div>
            </div>
            <div
              v-if="step >= 4"
              class="fix__stamp"
              :style="{ animationDelay: c.stampDelay }"
              >✕</div
            >
          </div>
        </div>
      </div>

      <div
        class="fix__count"
        :style="{
          opacity: step >= 5 ? 1 : 0,
          transform: `translateY(${step >= 5 ? 0 : 14}px)`
        }"
      >
        <span class="fix__pill">
          <span class="fix__pill-n" data-testid="run-fix-count">{{
            shownCount
          }}</span
          >{{ label }}
        </span>
      </div>

      <div class="fix__spacer" />

      <div
        class="fix__buttons"
        :style="{
          opacity: step >= 6 ? 1 : 0,
          transform: `translateY(${step >= 6 ? 0 : 40}px)`,
          pointerEvents: step >= 6 ? 'auto' : 'none'
        }"
      >
        <button
          type="button"
          class="fix__btn fix__btn--main"
          data-testid="run-fix-start"
          @click="emit('start')"
        >
          Corriger mes erreurs
        </button>
        <button
          type="button"
          class="fix__btn fix__btn--soft"
          data-testid="run-fix-later"
          @click="emit('later')"
        >
          Plus tard
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Before the mistakes are played again (design "Corriger ses erreurs"): the screen floods in
 * violet, the title falls word by word, the professor thinks then points at the missed items dealt
 * as cards (up to seven, each with its number, stamped ✕), and their count.
 */
import { computed, onMounted } from 'vue'
import FloatingPieces from '@/components/training/result/FloatingPieces.vue'
import ProfStage from '@/components/training/result/ProfStage.vue'
import { useResultSteps } from '@/composables/training/useResultSteps'
import {
  FIX_COUNT_MS,
  FIX_COUNT_STEP,
  FIX_STEPS_MS,
  FIX_TITLE,
  fixCards,
  fixLabel,
  fixWords,
  resultProf
} from '@/utils/runResult'

const props = defineProps({
  /** The API's module (its professor and its word for an item). */
  module: { type: String, required: true },
  /** Repertoire: segment or line. */
  unit: { type: String, default: undefined },
  /** @type {import('vue').PropType<number[]>} the numbers of the items to correct */
  numbers: { type: Array, required: true }
})

const emit = defineEmits({
  /** Play the mistakes again. */
  start: null,
  later: null
})

const { step, k, run, play } = useResultSteps(
  FIX_STEPS_MS,
  FIX_COUNT_STEP,
  FIX_COUNT_MS
)

const words = computed(() => fixWords(step.value))
const prof = computed(() =>
  resultProf(props.module, step.value >= 4 ? 'pointer' : 'think')
)
const cards = computed(() =>
  fixCards(/** @type {number[]} */ (props.numbers), step.value >= 4)
)
const shownCount = computed(() => Math.round(props.numbers.length * k.value))
const label = computed(() =>
  fixLabel(props.module, props.numbers.length, props.unit)
)

/** A burst of sparks when the professor points. */
const sparks = Array.from({ length: 10 }, (_, i) => {
  const a = (i / 10) * Math.PI * 2
  return {
    key: i,
    style: {
      borderRadius: i % 2 ? '50%' : '2px',
      background: ['#C6F432', '#FFD43B', '#FFFFFF', '#FF6FAE'][i % 4],
      '--dx': `${Math.cos(a) * 60}px`,
      '--dy': `${Math.sin(a) * 60}px`
    }
  }
})

onMounted(play)
</script>

<style scoped lang="scss">
.fix {
  position: absolute;
  inset: 0;
  overflow: hidden;
  background: #1b1530;
  color: #1b1530;
}

.fix__flood {
  position: absolute;
  inset: 0;
  background: #8b6bff;
  transition: clip-path 0.8s cubic-bezier(0.5, 0, 0.2, 1);
}

.fix__shade {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 62%;
  background: #5a3be0;
  opacity: 0.6;
  mask-image: linear-gradient(to bottom, transparent, #000);
}

.fix__glow {
  position: absolute;
  left: 50%;
  top: 18%;
  width: 520px;
  height: 520px;
  margin-left: -260px;
  background: radial-gradient(circle, rgba(255, 255, 255, 0.3), transparent 62%);
}

.fix__column {
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
  overflow-y: auto;
  overflow-x: hidden;
}

.fix__title {
  margin: 12px 2px 0;
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  column-gap: 10px;
}

.fix__word {
  display: inline-block;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 40px;
  line-height: 1.05;
  letter-spacing: -0.02em;
  transition:
    transform 0.6s cubic-bezier(0.34, 1.9, 0.64, 1),
    opacity 0.2s;
}

.fix__stage {
  margin-top: 8px;
}

.fix__spark {
  position: absolute;
  right: 18px;
  top: 70px;
  width: 8px;
  height: 8px;
  animation: fix-spark 0.7s cubic-bezier(0.2, 0.7, 0.3, 1) forwards;
}

.fix__cards {
  position: relative;
  height: 140px;
  flex: none;
  margin-top: 8px;
}

.fix__card {
  position: absolute;
  left: 50%;
  top: 18px;
  width: 70px;
  height: 94px;
  margin-left: -35px;
  transform-origin: 50% 120%;
  transition: transform 0.7s cubic-bezier(0.34, 1.45, 0.64, 1);
}

.fix__card-lift {
  position: absolute;
  inset: 0;

  &--on {
    animation: fix-lift 2.8s cubic-bezier(0.34, 1.56, 0.64, 1) infinite;
  }
}

.fix__card-face {
  position: absolute;
  inset: 0;
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 4px 0 #4a2fe0;
  padding: 5px;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.fix__card-board {
  flex: 1;
  border-radius: 10px;
  background-color: #ffe6f1;
  background-image: repeating-conic-gradient(#ffc7df 0 25%, transparent 0 50%);
  background-size: 50% 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
  font-size: 34px;
  line-height: 1;
  color: #1b1530;
}

.fix__card-n {
  font-size: 11px;
  font-weight: 800;
  text-align: center;
  color: #c02670;
}

.fix__stamp {
  position: absolute;
  right: -8px;
  top: -8px;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: #e03c86;
  color: #fff;
  border: 2.5px solid #fff;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 800;
  opacity: 0;
  animation: fix-stamp 0.45s cubic-bezier(0.34, 1.8, 0.64, 1) forwards;
}

.fix__count {
  display: flex;
  justify-content: center;
  transition:
    opacity 0.35s,
    transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.fix__pill {
  background: #1b1530;
  color: #fff;
  font-weight: 800;
  font-size: 13px;
  letter-spacing: 0.06em;
  padding: 7px 14px;
  border-radius: 999px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.fix__pill-n {
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  box-sizing: border-box;
  border-radius: 999px;
  background: #ff6fae;
  color: #1b1530;
  display: flex;
  align-items: center;
  justify-content: center;
  font-variant-numeric: tabular-nums;
}

.fix__spacer {
  flex: 1;
  min-height: 24px;
}

.fix__buttons {
  display: flex;
  flex-direction: column;
  gap: 10px;
  transition:
    opacity 0.35s,
    transform 0.55s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.fix__btn {
  border: none;
  font-family: inherit;
  cursor: pointer;

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
    box-shadow: 0 4px 0 #4a2fe0;
  }
}

@keyframes fix-lift {
  0%,
  70%,
  100% {
    transform: translateY(0) scale(1);
  }
  80% {
    transform: translateY(-22px) scale(1.08);
  }
  90% {
    transform: translateY(-4px) scale(1);
  }
}

@keyframes fix-stamp {
  0% {
    transform: scale(2.4) rotate(-30deg);
    opacity: 0;
  }
  60% {
    transform: scale(0.85) rotate(-8deg);
    opacity: 1;
  }
  100% {
    transform: scale(1) rotate(-12deg);
    opacity: 1;
  }
}

@keyframes fix-spark {
  from {
    transform: translate(0, 0) scale(1);
    opacity: 1;
  }
  to {
    transform: translate(var(--dx), var(--dy)) scale(0.2);
    opacity: 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .fix *:not(.fix__stamp) {
    animation: none !important;
    transition: none !important;
  }

  .fix__stamp {
    animation-duration: 0s !important;
  }
}
</style>
