<template>
  <div
    class="eval-player"
    data-testid="evaluation-player"
    :data-revealed="result ? 'true' : undefined"
  >
    <div class="eval-player__board">
      <ChessBoard
        :fen="data.position.fen"
        :orientation="data.position.turn"
        :animation-duration="0"
      />
      <div class="eval-player__chips">
        <span class="eval-player__chip" data-testid="evaluation-turn">{{
          data.position.turn === 'white'
            ? 'Trait aux Blancs'
            : 'Trait aux Noirs'
        }}</span>
        <span
          v-if="data.position.tagLabel"
          class="eval-player__chip eval-player__chip--tag"
          >{{ data.position.tagLabel }}</span
        >
      </div>
    </div>

    <div class="eval-player__panel column q-gutter-md">
      <slot name="header" />

      <ProfBubble
        :prof="prof"
        :kicker="bubble.kicker"
        :text="bubble.text"
        :kind="timeline.kind.value"
        :step="timeline.step.value"
        text-testid="evaluation-bubble"
      />

      <div
        v-if="!result"
        class="eval-player__clock"
        data-testid="evaluation-clock"
      >
        <div
          class="eval-player__ring"
          :style="{
            background: `conic-gradient(${clockColor} ${clockDeg}deg, #FFF5D1 0)`
          }"
        >
          <span>⏱︎</span>
        </div>
        <div>
          <div class="eval-player__clock-label"
            >Temps restant · {{ data.seconds }} s max</div
          >
          <div
            class="eval-player__clock-value"
            :class="{ 'eval-player__clock-value--low': remainingMs <= 20_000 }"
            >{{ clock(remainingMs) }}</div
          >
        </div>
      </div>

      <div
        v-if="!result"
        class="eval-player__card"
        data-testid="evaluation-ask"
      >
        <div class="eval-player__question">Qui est mieux ?</div>
        <div class="eval-player__choices">
          <button
            v-for="c in EVAL_CHOICES"
            :key="c.value"
            type="button"
            class="eval-player__choice"
            :class="{ 'eval-player__choice--on': guess === c.value }"
            :aria-pressed="guess === c.value"
            :disabled="sending"
            :data-testid="`evaluation-choice-${c.value}`"
            @click="guess = c.value"
          >
            <span class="eval-player__choice-sym">{{ c.sym }}</span>
            <span class="eval-player__choice-label">{{ c.short }}</span>
          </button>
        </div>
        <template v-if="data.askPlan">
          <div class="eval-player__question"
            >Le plan <span class="cm-muted">(facultatif)</span></div
          >
          <div class="eval-player__plans">
            <button
              v-for="p in data.plans"
              :key="p.value"
              type="button"
              class="eval-player__plan"
              :class="{ 'eval-player__plan--on': plan === p.value }"
              :aria-pressed="plan === p.value"
              :disabled="sending"
              :data-testid="`evaluation-plan-${p.value}`"
              @click="plan = plan === p.value ? null : p.value"
              >{{ p.label }}</button
            >
          </div>
        </template>
        <q-btn
          unelevated
          no-caps
          color="dark"
          class="eval-player__validate"
          label="Valider mon évaluation"
          :disable="guess === null || sending"
          :loading="sending"
          data-testid="evaluation-validate"
          @click="send(guess)"
        />
      </div>

      <div v-else class="eval-player__card" data-testid="evaluation-correction">
        <div class="eval-player__verdict">
          <span data-testid="evaluation-verdict">{{
            VERDICTS[result.status]
          }}</span>
        </div>
        <div class="eval-player__gauge" aria-hidden="true">
          <div class="eval-player__gauge-bar" />
          <div
            class="eval-player__gauge-engine"
            :style="{ left: gaugeX(result.evalCp) }"
          />
          <div
            v-if="result.guess !== null"
            class="eval-player__gauge-guess"
            :style="{ left: guessX(result.guess) }"
          />
        </div>
        <div class="eval-player__legend">
          <span
            ><i class="eval-player__dot eval-player__dot--guess" />Toi :
            {{ choiceLabel(result.guess) }}</span
          >
          <span data-testid="evaluation-engine"
            ><i class="eval-player__dot eval-player__dot--engine" />Moteur :
            {{ result.engine }}</span
          >
        </div>
        <ul class="eval-player__ideas" data-testid="evaluation-ideas">
          <li v-for="(idea, i) in result.ideas" :key="i"
            ><span>✓</span>{{ idea }}</li
          >
        </ul>
        <div
          v-if="result.planLabel"
          class="eval-player__plan-line"
          data-testid="evaluation-plan-result"
          >Meilleur plan : <b>{{ result.planLabel }}</b
          ><template v-if="planLine"> · {{ planLine }}</template></div
        >
        <div v-if="result.source" class="eval-player__source">{{
          result.source
        }}</div>
      </div>

      <div class="eval-player__dots" aria-hidden="true">
        <span
          v-for="n in data.count"
          :key="n"
          class="eval-player__step"
          :class="{ 'eval-player__step--on': n === data.index }"
        />
      </div>

      <ResultSheet
        v-if="result && timeline.kind.value"
        :kind="timeline.kind.value"
        :palette="feedback.palette"
        :badge="feedback.badge"
        :step="timeline.step.value"
        :run="timeline.run.value"
        :title="feedback.title"
        :sub="feedback.sub"
        :xp="xp"
        :actions="actions"
        :confetti="feedback.kind === 'win'"
      />
    </div>
  </div>
</template>

<script setup>
/**
 * A position to evaluate in a timed run (design "Évaluation", docs/EVALUATION.md): who stands
 * better (five answers) and, when the position has one, the plan, before the position's own
 * deadline on the server's clock. At zero, "no answer" is sent. Then the correction: the gauge
 * "you / the engine", the key ideas, the best plan, and the result sheet.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import ProfBubble from '@/components/feedback/ProfBubble.vue'
import ResultSheet from '@/components/feedback/ResultSheet.vue'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'
import { feedbackProf } from '@/utils/feedback'
import { playOutcomeSound } from '@/utils/sounds'
import {
  EVAL_CHOICES,
  VERDICTS,
  choiceLabel,
  clock,
  evaluationFeedback,
  gaugeX,
  guessX,
  planResult
} from '@/utils/evaluation'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').RunItem>} the evaluation_position item; a new one starts a new position */
  item: { type: Object, required: true },
  /** @type {import('vue').PropType<import('@/utils/evaluation').EvaluationResult|null>} this position's verdict and correction, once answered */
  result: { type: Object, default: null },
  /** The submission's `xp` (undefined until it answered). */
  xp: { type: [Number, null], default: undefined },
  /** Server clock minus local clock, ms. */
  offsetMs: { type: Number, default: 0 },
  /** @type {import('vue').PropType<import('@/components/feedback/ResultSheet.vue').SheetAction[]>} the result sheet's buttons */
  actions: { type: Array, default: () => [] }
})

const emit = defineEmits({
  /** The answer: the category (null: none in time) and the plan. */
  resolve: answer => typeof answer === 'object'
})

const data = computed(() => props.item.data)
/** @type {import('vue').Ref<number|null>} */
const guess = ref(null)
/** @type {import('vue').Ref<string|null>} */
const plan = ref(null)
const sending = ref(false)
const now = ref(Date.now())
const timeline = useFeedbackTimeline()

const timer = setInterval(() => {
  now.value = Date.now()
  if (!props.result && !sending.value && remainingMs.value <= 0) send(null)
}, 250)
onBeforeUnmount(() => clearInterval(timer))

const remainingMs = computed(() =>
  Math.max(0, Date.parse(data.value.deadlineAt) - (now.value + props.offsetMs))
)
const clockDeg = computed(
  () => (remainingMs.value / (data.value.seconds * 1000)) * 360
)
const clockColor = computed(() =>
  remainingMs.value <= 20_000
    ? '#FF6FAE'
    : remainingMs.value <= 60_000
      ? '#FF8A3D'
      : '#FFD43B'
)

/** @param {number|null} value the category, null when time is up */
function send(value) {
  sending.value = true
  emit('resolve', { guess: value, plan: value === null ? null : plan.value })
}

watch(
  () => props.item.id,
  () => {
    guess.value = null
    plan.value = null
    sending.value = false
    timeline.reset()
  }
)

/** The submission failed: the position can be answered again (the parent calls it). */
function unlock() {
  sending.value = false
}
defineExpose({ unlock })

watch(
  () => props.result,
  value => {
    if (!value) return
    const kind = value.status === 'exact' ? 'win' : 'miss'
    playOutcomeSound(kind === 'win' ? 'puzzleDone' : 'puzzleMissed')
    timeline.play(kind)
  }
)

const feedback = computed(() =>
  props.result
    ? evaluationFeedback(props.result, data.value.index)
    : {
        kind: 'win',
        palette: null,
        badge: '?',
        title: '',
        sub: '',
        kicker: '',
        bubble: ''
      }
)
const planLine = computed(() =>
  props.result ? planResult(props.result, data.value.plans) : ''
)

const reacting = computed(
  () => timeline.kind.value !== null && timeline.step.value >= 2
)
const prof = computed(() =>
  feedbackProf(
    'evaluation',
    reacting.value ? (props.result?.status === 'exact' ? 'win' : 'miss') : null
  )
)
const bubble = computed(() =>
  props.result
    ? { kicker: feedback.value.kicker, text: feedback.value.bubble }
    : {
        kicker: 'CONSEIL',
        text:
          data.value.tip ??
          'Compte le matériel, puis regarde la sécurité des rois et l’activité des pièces.'
      }
)
</script>

<style scoped lang="scss">
.eval-player {
  display: grid;
  gap: 20px;
  max-width: 1100px;
  margin: 0 auto;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: start;
  }
}

.eval-player__board {
  position: relative;
  width: 100%;
  max-width: 560px;
  margin: 0 auto;
}

.eval-player__chips {
  position: absolute;
  left: 10px;
  top: 10px;
  display: flex;
  gap: 6px;
  z-index: 2;
  pointer-events: none;
}

.eval-player__chip {
  background: #fff;
  color: #1b1530;
  font-weight: 700;
  font-size: 12px;
  padding: 5px 10px;
  border-radius: 999px;

  &--tag {
    background: #ffd43b;
  }
}

.eval-player__panel {
  position: relative;
  min-width: 0;
  padding-bottom: 8px;
}

.eval-player__clock,
.eval-player__card {
  background: var(--cm-surface);
  border-radius: 18px;
  padding: 14px;
}

.eval-player__clock {
  display: flex;
  align-items: center;
  gap: 14px;
}

.eval-player__ring {
  position: relative;
  width: 52px;
  height: 52px;
  flex: none;
  border-radius: 50%;

  span {
    position: absolute;
    inset: 6px;
    border-radius: 50%;
    background: var(--cm-surface);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
  }
}

.eval-player__clock-label {
  font-size: 12px;
  font-weight: 700;
  color: var(--cm-muted);
  text-transform: uppercase;
}

.eval-player__clock-value {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 26px;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;

  &--low {
    color: #c02670;
  }
}

.eval-player__card {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.eval-player__question {
  font-weight: 700;
  font-size: 14px;
}

.eval-player__choices {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 6px;
}

.eval-player__choice {
  height: 58px;
  border-radius: 14px;
  border: 2px solid var(--cm-line);
  background: var(--cm-bg, transparent);
  color: inherit;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1px;
  padding: 0;

  &--on {
    border-color: #e0ae00;
    background: #fff5d1;
    color: #1b1530;
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }
}

.eval-player__choice-sym {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 18px;
}

.eval-player__choice-label {
  font-size: 10px;
  font-weight: 700;
  color: var(--cm-muted);

  .eval-player__choice--on & {
    color: #6e6a80;
  }
}

.eval-player__plans {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.eval-player__plan {
  height: 34px;
  padding: 0 12px;
  border-radius: 11px;
  border: 1.5px solid var(--cm-line);
  background: transparent;
  color: inherit;
  font-weight: 600;
  font-size: 12.5px;
  cursor: pointer;

  &--on {
    border-color: #e0ae00;
    background: #fff5d1;
    color: #1b1530;
  }
}

.eval-player__validate {
  height: 50px;
  border-radius: 16px;
  margin-top: 4px;
}

.eval-player__verdict {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 18px;
}

.eval-player__gauge {
  position: relative;
  height: 30px;
}

.eval-player__gauge-bar {
  position: absolute;
  left: 0;
  right: 0;
  top: 11px;
  height: 8px;
  border-radius: 8px;
  background: linear-gradient(to right, #1b1530, #f0eef6 50%, #fff);
  box-shadow: inset 0 0 0 1.5px #dcd8e8;
}

.eval-player__gauge-engine {
  position: absolute;
  top: 0;
  width: 4px;
  height: 30px;
  border-radius: 4px;
  background: #ffd43b;
  box-shadow: 0 0 0 2px #1b1530;
  transform: translateX(-50%);
}

.eval-player__gauge-guess {
  position: absolute;
  top: 5px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: var(--cm-brand);
  border: 3px solid #fff;
  box-shadow: 0 0 0 1.5px var(--cm-brand);
  transform: translateX(-50%);
}

.eval-player__legend {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  font-size: 12px;
  font-weight: 700;

  span {
    display: flex;
    align-items: center;
    gap: 5px;
  }
}

.eval-player__dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;

  &--guess {
    background: var(--cm-brand);
  }

  &--engine {
    border-radius: 3px;
    background: #ffd43b;
    box-shadow: 0 0 0 1.5px #1b1530;
  }
}

.eval-player__ideas {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 6px;

  li {
    display: flex;
    gap: 8px;
    font-size: 13.5px;
    line-height: 1.4;
  }

  span {
    width: 20px;
    height: 20px;
    flex: none;
    border-radius: 7px;
    background: #fff5d1;
    color: #8a6a00;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 11px;
  }
}

.eval-player__plan-line,
.eval-player__source {
  font-size: 12.5px;
  color: var(--cm-muted);

  b {
    color: var(--cm-ink);
  }
}

.eval-player__dots {
  display: flex;
  justify-content: center;
  gap: 6px;
}

.eval-player__step {
  width: 8px;
  height: 8px;
  border-radius: 8px;
  background: var(--cm-line);

  &--on {
    width: 24px;
    background: var(--cm-ink);
  }
}
</style>
