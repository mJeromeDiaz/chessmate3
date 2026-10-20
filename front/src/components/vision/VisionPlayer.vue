<template>
  <PlayLayout
    class="vision-player"
    data-testid="vision-player"
    :data-phase="phase"
    :data-ask="item.data.ask"
  >
    <template v-if="$slots.header" #header>
      <slot name="header" />
    </template>

    <template #prof>
      <ProfBubble
        large
        :prof="prof"
        :kicker="bubble.kicker"
        :text="bubble.text"
        :kind="timeline.kind.value"
        :step="timeline.step.value"
        text-testid="vision-status"
      />
    </template>

    <template #chips>
      <span v-if="phase === 'show'" class="play-chip">{{
        $t('vision.player.toMemorize', { n: item.data.visibleSeconds })
      }}</span>
      <span
        v-if="['ask', 'sending', 'complete'].includes(phase)"
        class="play-chip play-chip--accent"
        data-testid="vision-question"
        >{{ question }}</span
      >
    </template>

    <template #board>
      <div class="vision-player__board">
        <ChessBoard
          :fen="boardFen"
          any-position
          :animation-duration="0"
          :show-legal-moves="false"
          :highlights="highlights"
          :square-input="phase === 'ask' && answersOnBoard"
          @square="onSquare"
        />
        <div
          v-if="phase === 'hidden' || phase === 'announce'"
          class="vision-player__veil"
          data-testid="vision-veil"
        >
          <div class="vision-player__veil-label">{{
            phase === 'announce'
              ? $t('vision.player.announced')
              : $t('vision.player.hidden')
          }}</div>
          <div
            v-if="phase === 'announce'"
            class="vision-player__veil-moves"
            data-testid="vision-announced"
            >{{ announcedSoFar }}</div
          >
        </div>
      </div>
    </template>

    <template v-if="phase === 'show'" #controls>
      <div class="vision-player__count" data-testid="vision-countdown">{{
        $t('vision.player.seconds', { n: countdown })
      }}</div>
      <q-btn
        unelevated
        no-caps
        class="play-btn play-btn--skip"
        :label="$t('vision.player.memorized')"
        data-testid="vision-memorized"
        @click="hide"
      />
    </template>
    <template
      v-else-if="item.data.ask === 'attackers' && phase !== 'complete'"
      #controls
    >
      <q-btn
        unelevated
        no-caps
        class="play-btn play-btn--skip"
        :label="$t('vision.player.validate')"
        :disable="phase !== 'ask'"
        :loading="phase === 'sending'"
        data-testid="vision-validate"
        @click="send({ squares: chosen })"
      />
    </template>

    <div class="column q-gutter-sm">
      <div
        v-if="announcedLine && ['ask', 'sending', 'complete'].includes(phase)"
        class="vision-player__line"
        data-testid="vision-line"
      >
        <span class="vision-player__line-label">{{
          $t('vision.player.announced')
        }}</span>
        {{ announcedLine }}
      </div>

      <template v-if="item.data.ask === 'what' && phase !== 'complete'">
        <PiecePalette
          :model-value="null"
          :disable="phase !== 'ask'"
          @update:model-value="onPiece"
        />
        <q-btn
          outline
          no-caps
          :label="$t('vision.player.empty')"
          :disable="phase !== 'ask'"
          data-testid="vision-empty"
          @click="send({ placement: [] })"
        />
      </template>

      <div
        v-if="item.data.ask === 'attackers' && phase === 'ask'"
        class="text-caption cm-muted"
        data-testid="vision-chosen"
      >
        {{ $t('vision.player.selected', { n: chosen.length }, chosen.length) }}
      </div>

      <div v-if="phase === 'complete' && result" data-testid="vision-result">
        <ResultSheet
          :kind="timeline.kind.value ?? (right ? 'win' : 'miss')"
          :step="timeline.step.value"
          :run="timeline.run.value"
          :title="
            right
              ? $t('vision.player.kickers.right')
              : $t('vision.player.kickers.wrong')
          "
          :sub="right ? '' : verdict"
          :xp="xp"
          :actions="actions"
          :confetti="right"
        />
      </div>
    </div>
  </PlayLayout>
</template>

<script setup>
/**
 * A question of the vision module (docs/VISION.md): the position shown for its seconds (or until
 * « J'ai mémorisé »), hidden, the moves announced one by one, then the question about the position
 * they reach, answered on an empty board: a click (where is a piece, a piece en prise), a piece of
 * the palette or « Case vide » (what stands on a square), every attacker clicked then « Valider ».
 * The page gives the server's verdict back (`result`): the position reached, the right squares in
 * green and the wrong ones in red, and the result sheet. Noctis, the blindfold professor, talks.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import PlayLayout from '@/components/chess/PlayLayout.vue'
import PiecePalette from '@/components/blindfold/PiecePalette.vue'
import ProfBubble from '@/components/feedback/ProfBubble.vue'
import ResultSheet from '@/components/feedback/ResultSheet.vue'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'
import { feedbackProf } from '@/utils/feedback'
import { numberedLine } from '@/utils/notation'
import { playOutcomeSound } from '@/utils/sounds'
import {
  ONE_SQUARE,
  correctionHighlights,
  questionText,
  toggleSquare,
  verdictText
} from '@/utils/vision'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').RunItem>} the vision question; a new one starts again */
  item: { type: Object, required: true },
  /** @type {import('vue').PropType<Record<string, any>|null>} the server's verdict on it */
  result: { type: Object, default: null },
  /** The submission's `xp` (undefined until it answered). */
  xp: { type: [Number, null], default: undefined },
  /** @type {import('vue').PropType<import('@/components/feedback/ResultSheet.vue').SheetAction[]>} the result sheet's buttons */
  actions: { type: Array, default: () => [] }
})

const emit = defineEmits({ resolve: null })

const EMPTY_BOARD = '8/8/8/8/8/8/8/8 w - - 0 1'
/** Between two moves announced, and after the last one. */
const ANNOUNCE_MS = 1500

const timeline = useFeedbackTimeline()

/** @type {import('vue').Ref<'show'|'hidden'|'announce'|'ask'|'sending'|'complete'>} */
const phase = ref('show')
const countdown = ref(0)
/** The moves announced so far. */
const announcedCount = ref(0)
/** The attackers clicked. */
const chosen = ref(/** @type {string[]} */ ([]))
/** @type {ReturnType<typeof setInterval>|undefined} */
let timer
/** @type {ReturnType<typeof setTimeout>|undefined} */
let pause

const right = computed(() => props.result?.status === 'right')
const answersOnBoard = computed(() =>
  [...ONE_SQUARE, 'attackers'].includes(props.item.data.ask)
)
const question = computed(() => questionText(props.item.data))
const verdict = computed(() =>
  props.result ? verdictText(props.item.data, props.result) : ''
)
const sans = computed(() =>
  (props.item.data.announced ?? []).map(
    (/** @type {{san: string}} */ m) => m.san
  )
)
/** The moves announced, numbered from the position shown, in the player's letters. */
const announcedLine = computed(() =>
  sans.value.length ? numberedLine(sans.value, props.item.data.fen) : ''
)
const announcedSoFar = computed(() =>
  announcedCount.value
    ? numberedLine(
        sans.value.slice(0, announcedCount.value),
        props.item.data.fen
      )
    : '…'
)

/** The position shown, an empty board to answer on, then the position reached. */
const boardFen = computed(() => {
  if (phase.value === 'show') return props.item.data.fen
  if (phase.value === 'complete' && props.result?.finalFen)
    return props.result.finalFen
  return EMPTY_BOARD
})

const highlights = computed(() => {
  if (phase.value === 'complete' && props.result)
    return correctionHighlights(props.item.data, props.result)
  if (!['ask', 'sending'].includes(phase.value)) return []
  const target = props.item.data.target
  const asked =
    target && ['what', 'attackers'].includes(props.item.data.ask)
      ? [{ square: target, type: 'hint' }]
      : []
  return [
    ...asked,
    ...chosen.value.map(square => ({ square, type: 'lastMove' }))
  ]
})

const prof = computed(() =>
  feedbackProf(
    'blindfold_recall',
    timeline.kind.value !== null && timeline.step.value >= 2
      ? timeline.kind.value
      : null
  )
)

const bubble = computed(() => {
  const key =
    phase.value === 'complete'
      ? right.value
        ? 'right'
        : 'wrong'
      : phase.value === 'show'
        ? 'memorize'
        : phase.value === 'hidden'
          ? 'visualize'
          : phase.value === 'announce'
            ? 'listen'
            : 'ask'
  const text =
    key !== 'ask'
      ? t(`vision.player.texts.${key}`)
      : props.item.data.ask === 'what'
        ? t('vision.player.texts.askPiece')
        : props.item.data.ask === 'attackers'
          ? t('vision.player.texts.askMany')
          : t('vision.player.texts.askClick')
  return { kicker: t(`vision.player.kickers.${key}`), text }
})

function stopTimers() {
  clearInterval(timer)
  clearTimeout(pause)
}

/** The position hidden: the pause, then the moves, then the question. */
function hide() {
  if (phase.value !== 'show') return
  stopTimers()
  phase.value = 'hidden'
  pause = setTimeout(
    () => (sans.value.length ? announce() : (phase.value = 'ask')),
    (props.item.data.hiddenSeconds ?? 2) * 1000
  )
}

function announce() {
  phase.value = 'announce'
  announcedCount.value = 0
  timer = setInterval(() => {
    if (announcedCount.value < sans.value.length) {
      announcedCount.value++
      return
    }
    clearInterval(timer)
    phase.value = 'ask'
  }, ANNOUNCE_MS)
}

/** @param {Record<string, any>} report */
function send(report) {
  if (phase.value !== 'ask') return
  phase.value = 'sending'
  emit('resolve', report)
}

/** @param {string} square */
function onSquare(square) {
  if (phase.value !== 'ask') return
  if (props.item.data.ask === 'attackers')
    chosen.value = toggleSquare(chosen.value, square)
  else if (ONE_SQUARE.includes(props.item.data.ask)) send({ squares: [square] })
}

/** @param {string|null} piece a FEN letter (the eraser: nothing) */
function onPiece(piece) {
  if (!piece || !props.item.data.target) return
  send({ placement: [{ square: props.item.data.target, piece }] })
}

/** Lets the player answer again (a refused submission). */
function unlock() {
  if (phase.value === 'sending') phase.value = 'ask'
}

defineExpose({ unlock })

watch(
  () => props.item.id,
  () => {
    stopTimers()
    timeline.reset()
    chosen.value = []
    announcedCount.value = 0
    phase.value = 'show'
    countdown.value = props.item.data.visibleSeconds ?? 10
    timer = setInterval(() => {
      countdown.value--
      if (countdown.value <= 0) hide()
    }, 1000)
  },
  { immediate: true }
)

watch(
  () => props.result,
  value => {
    if (!value || phase.value === 'complete') return
    phase.value = 'complete'
    const kind = value.status === 'right' ? 'win' : 'miss'
    playOutcomeSound(kind === 'miss' ? 'puzzleMissed' : 'puzzleDone')
    timeline.play(kind)
  }
)

onBeforeUnmount(stopTimers)
</script>

<style scoped lang="scss">
.vision-player__board {
  position: relative;
}

.vision-player__veil {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  border-radius: 12px;
  background: color-mix(in srgb, var(--cm-ink) 88%, transparent);
  color: var(--cm-page);
  text-align: center;
}

.vision-player__veil-label {
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.vision-player__veil-moves {
  font-family: var(--cm-heading);
  font-size: 28px;
  font-weight: 800;
}

.vision-player__count {
  align-self: center;
  min-width: 56px;
  font-family: var(--cm-heading);
  font-size: 22px;
  font-weight: 800;
  color: var(--cm-ink);
  text-align: center;
}

.vision-player__line {
  font-weight: 600;
  color: var(--cm-ink);
}

.vision-player__line-label {
  margin-right: 6px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}
</style>
