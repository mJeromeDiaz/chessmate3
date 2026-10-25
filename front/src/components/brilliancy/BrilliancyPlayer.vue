<template>
  <div
    class="bob-player"
    data-testid="brilliancy-player"
    :data-revealed="result ? 'true' : undefined"
  >
    <div class="bob-player__board">
      <ChessBoard
        :fen="boardFen"
        :orientation="data.position.color"
        :highlights="highlights"
        :arrows="arrows"
        :glyphs="glyphs"
      />
    </div>

    <div class="bob-player__panel column q-gutter-md">
      <slot name="header" />

      <ProfBubble
        :prof="prof"
        :kicker="bubble.kicker"
        :text="bubble.text"
        :kind="timeline.kind.value"
        :step="timeline.step.value"
        text-testid="brilliancy-bubble"
      />

      <div v-if="!result" class="bob-player__card" data-testid="brilliancy-ask">
        <div class="bob-player__move">
          <span class="cm-muted">{{
            $t(`brilliancy.player.plays.${data.position.color}`)
          }}</span>
          <b data-testid="brilliancy-move">{{ shownMove }}</b>
        </div>
        <div class="bob-player__question">{{
          $t('brilliancy.player.question')
        }}</div>
        <div class="bob-player__choices">
          <button
            v-for="c in BRILLIANCY_CHOICES"
            :key="c.value"
            type="button"
            class="bob-player__choice"
            :class="`bob-player__choice--${c.value}`"
            :disabled="sending"
            :data-testid="`brilliancy-choice-${c.value}`"
            @click="send(c.value)"
          >
            <span class="bob-player__choice-glyph">{{ c.glyph }}</span>
            <span class="bob-player__choice-label">{{
              $t(`brilliancy.kinds.${c.value}`)
            }}</span>
          </button>
        </div>
        <div class="bob-player__keys cm-muted">{{
          $t('brilliancy.player.keys')
        }}</div>
      </div>

      <div v-else class="bob-player__card" data-testid="brilliancy-correction">
        <div class="bob-player__verdict">
          <span
            class="bob-player__badge"
            :class="`bob-player__badge--${result.kind}`"
            >{{ result.kind === 'brilliant' ? '!!' : '??' }}</span
          >
          <span data-testid="brilliancy-verdict">{{
            $t(`brilliancy.player.was.${result.kind}`)
          }}</span>
        </div>
        <div class="bob-player__replay">
          <q-btn
            flat
            round
            dense
            icon="chevron_left"
            :aria-label="$t('brilliancy.player.previous')"
            :disable="cursor === 0"
            data-testid="brilliancy-previous"
            @click="goTo(cursor - 1)"
          />
          <q-btn
            flat
            round
            dense
            :icon="replaying ? 'pause' : 'play_arrow'"
            :aria-label="
              replaying
                ? $t('brilliancy.player.pause')
                : $t('brilliancy.player.replay')
            "
            data-testid="brilliancy-play"
            @click="replaying ? stopReplay() : replay()"
          />
          <q-btn
            flat
            round
            dense
            icon="chevron_right"
            :aria-label="$t('brilliancy.player.next')"
            :disable="cursor >= steps.length"
            data-testid="brilliancy-next"
            @click="goTo(cursor + 1)"
          />
        </div>
        <div class="bob-player__line" data-testid="brilliancy-line">
          <button
            v-for="(s, i) in steps"
            :key="i"
            type="button"
            class="bob-player__ply"
            :class="{
              'bob-player__ply--on': cursor === i + 1,
              [`bob-player__ply--${result.kind}`]: i === 0
            }"
            @click="goTo(i + 1)"
            >{{ s.label }}</button
          >
        </div>
        <div class="bob-player__explain">{{
          $t(`brilliancy.player.explain.${result.kind}`)
        }}</div>
        <a
          v-if="result.puzzle"
          class="bob-player__source"
          :href="`https://lichess.org/training/${result.puzzle.id}`"
          target="_blank"
          rel="noopener"
          data-testid="brilliancy-source"
          >{{
            $t('brilliancy.player.source', {
              id: result.puzzle.id,
              rating: result.puzzle.rating
            })
          }}</a
        >
      </div>

      <ResultSheet
        v-if="result && timeline.kind.value"
        :kind="timeline.kind.value"
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
 * A move to judge in a timed run of « Brillant ou Blunder » (docs/BRILLIANCY.md): the position,
 * then the move played on it (seen from the side that plays it, so that the board never tells
 * which kind it is); two buttons, or B / N (1 / 2). Then the correction: the badge on the move's
 * arrival square, the puzzle's line replayed by itself (the winning combination, or the blunder
 * and its punishment), steps back and forth, and the result sheet with « Suivante » at once.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import ProfBubble from '@/components/feedback/ProfBubble.vue'
import ResultSheet from '@/components/feedback/ResultSheet.vue'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'
import { feedbackProf } from '@/utils/feedback'
import { playOutcomeSound } from '@/utils/sounds'
import { t } from '@/i18n'
import {
  BRILLIANCY_CHOICES,
  brilliancyFeedback,
  choiceForKey,
  lineSteps,
  verdictGlyph
} from '@/utils/brilliancy'

/** Delay before the move is played on the position, so that it is seen moving. */
const MOVE_DELAY_MS = 350
/** The badge stays on the move this long before the line goes on. */
const BADGE_PAUSE_MS = 1200
/** One move of the line replayed. */
const REPLAY_MS = 700

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').RunItem>} the brilliancy_position item; a new one starts a new position */
  item: { type: Object, required: true },
  /** @type {import('vue').PropType<import('@/utils/brilliancy').BrilliancyResult|null>} this position's verdict and correction, once answered */
  result: { type: Object, default: null },
  /** The submission's `xp` (undefined until it answered). */
  xp: { type: [Number, null], default: undefined },
  /** @type {import('vue').PropType<import('@/components/feedback/ResultSheet.vue').SheetAction[]>} the result sheet's buttons */
  actions: { type: Array, default: () => [] }
})

const emit = defineEmits({
  /** The answer: brilliant or blunder. */
  resolve: choice => choice === 'brilliant' || choice === 'blunder'
})

const data = computed(() => props.item.data)
const sending = ref(false)
const timeline = useFeedbackTimeline()
/** 0: the position before the move, n: after the line's n-th move. */
const cursor = ref(0)
const replaying = ref(false)
/** @type {ReturnType<typeof setTimeout>|undefined} */
let timer

/** The move shown, then, once answered, the whole line from it. */
const steps = computed(() =>
  lineSteps(
    data.value.position.fen,
    props.result?.line?.length ? props.result.line : [data.value.position.move]
  )
)
const shownMove = computed(
  () => steps.value[0]?.label ?? data.value.position.move.san
)

const boardFen = computed(() =>
  cursor.value === 0
    ? data.value.position.fen
    : (steps.value[cursor.value - 1]?.fen ?? data.value.position.fen)
)
const current = computed(() =>
  cursor.value > 0 ? (steps.value[cursor.value - 1] ?? null) : null
)
/** @type {import('vue').ComputedRef<import('@/components/chess/ChessBoard.vue').Highlight[]>} */
const highlights = computed(() =>
  current.value
    ? [
        { square: current.value.from, type: 'lastMove' },
        { square: current.value.to, type: 'lastMove' }
      ]
    : []
)
/** The move to judge, pointed at until the answer. */
const arrows = computed(() =>
  !props.result && current.value
    ? [{ from: current.value.from, to: current.value.to, type: 'hint' }]
    : []
)
const glyphs = computed(() =>
  props.result && cursor.value === 1 && steps.value[0]
    ? [verdictGlyph(props.result.kind, steps.value[0].to)]
    : []
)

function clearTimer() {
  if (timer !== undefined) clearTimeout(timer)
  timer = undefined
}

/** @param {number} index */
function goTo(index) {
  stopReplay()
  cursor.value = Math.max(0, Math.min(steps.value.length, index))
}

function stopReplay() {
  clearTimer()
  replaying.value = false
}

/** Plays the line by itself from the move shown, its badge first. */
function replay() {
  clearTimer()
  replaying.value = true
  if (cursor.value >= steps.value.length || cursor.value === 0) cursor.value = 1
  const step = () => {
    if (cursor.value >= steps.value.length) {
      replaying.value = false
      return
    }
    cursor.value++
    timer = setTimeout(step, REPLAY_MS)
  }
  timer = setTimeout(step, cursor.value === 1 ? BADGE_PAUSE_MS : REPLAY_MS)
}

/** @param {'brilliant'|'blunder'} choice */
function send(choice) {
  if (sending.value || props.result) return
  sending.value = true
  emit('resolve', choice)
}

/** A new position: shown before its move, then the move played. */
function showMove() {
  stopReplay()
  cursor.value = 0
  timer = setTimeout(() => (cursor.value = 1), MOVE_DELAY_MS)
}

watch(
  () => props.item.id,
  () => {
    sending.value = false
    timeline.reset()
    showMove()
  }
)
onMounted(showMove)

/** The submission failed: the position can be answered again (the parent calls it). */
function unlock() {
  sending.value = false
}
defineExpose({ unlock })

watch(
  () => props.result,
  value => {
    if (!value) return
    const kind = value.status === 'right' ? 'win' : 'miss'
    playOutcomeSound(kind === 'win' ? 'puzzleDone' : 'puzzleMissed')
    timeline.play(kind)
    replay()
  }
)

/** @param {KeyboardEvent} event */
function onKey(event) {
  if (event.ctrlKey || event.metaKey || event.altKey) return
  const target = /** @type {HTMLElement|null} */ (event.target)
  if (target?.closest?.('input, textarea, [contenteditable="true"]')) return
  if (!props.result) {
    const choice = choiceForKey(event.key)
    if (choice) {
      event.preventDefault()
      send(choice)
    }
    return
  }
  if (event.key === 'ArrowLeft') goTo(cursor.value - 1)
  if (event.key === 'ArrowRight') goTo(cursor.value + 1)
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  clearTimer()
})

const feedback = computed(() =>
  props.result
    ? brilliancyFeedback(props.result, data.value.index)
    : { kind: 'win', badge: '!!', title: '', sub: '', kicker: '', bubble: '' }
)

const reacting = computed(
  () => timeline.kind.value !== null && timeline.step.value >= 2
)
const prof = computed(() =>
  feedbackProf(
    'brilliancy',
    reacting.value ? (props.result?.status === 'right' ? 'win' : 'miss') : null
  )
)
const bubble = computed(() =>
  props.result
    ? { kicker: feedback.value.kicker, text: feedback.value.bubble }
    : {
        kicker: t('brilliancy.player.tipKicker'),
        text: t('brilliancy.player.tip')
      }
)
</script>

<style scoped lang="scss">
.bob-player {
  display: grid;
  gap: 20px;
  max-width: 1100px;
  margin: 0 auto;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: start;
  }
}

.bob-player__board {
  width: 100%;
  max-width: 560px;
  margin: 0 auto;
}

.bob-player__panel {
  position: relative;
  min-width: 0;
  padding-bottom: 8px;
}

.bob-player__card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: var(--cm-surface);
  border-radius: 22px;
  padding: 14px;
  box-shadow: var(--cm-card-shadow);
}

.bob-player__move {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 14px;

  b {
    font-family: var(--cm-heading);
    font-size: 22px;
    font-weight: 800;
  }
}

.bob-player__question {
  font-weight: 700;
  font-size: 14px;
}

.bob-player__choices {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.bob-player__choice {
  height: 76px;
  border-radius: 18px;
  border: 0;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  padding: 0;
  font-weight: 800;
  transition: transform 0.1s;

  &:active {
    transform: scale(0.97);
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }

  &:disabled {
    opacity: 0.6;
    cursor: default;
  }

  &--brilliant {
    background: #1bc5b4;
    color: #fff;
  }

  &--blunder {
    background: #e5383b;
    color: #000;
  }
}

.bob-player__choice-glyph {
  font-family: var(--cm-heading);
  font-size: 26px;
  line-height: 1;
}

.bob-player__choice-label {
  font-size: 13px;
}

.bob-player__keys {
  font-size: 11.5px;
  text-align: center;
}

.bob-player__verdict {
  display: flex;
  align-items: center;
  gap: 10px;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 18px;
}

.bob-player__badge {
  flex: none;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;

  &--brilliant {
    background: #1bc5b4;
    color: #fff;
  }

  &--blunder {
    background: #e5383b;
    color: #000;
  }
}

.bob-player__replay {
  display: flex;
  justify-content: center;
  gap: 8px;
}

.bob-player__line {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.bob-player__ply {
  height: 28px;
  padding: 0 8px;
  border-radius: 9px;
  border: 1.5px solid var(--cm-line);
  background: transparent;
  color: inherit;
  font-weight: 600;
  font-size: 12.5px;
  cursor: pointer;

  &--brilliant {
    border-color: #1bc5b4;
  }

  &--blunder {
    border-color: #e5383b;
  }

  &--on {
    background: var(--cm-ink);
    border-color: var(--cm-ink);
    color: var(--cm-surface);
  }
}

.bob-player__explain {
  font-size: 13.5px;
  line-height: 1.4;
}

.bob-player__source {
  font-size: 12.5px;
  color: var(--cm-muted);
}
</style>
