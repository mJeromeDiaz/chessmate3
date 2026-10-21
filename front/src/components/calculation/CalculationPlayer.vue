<template>
  <PlayLayout
    class="calculation-player"
    data-testid="calculation-player"
    :data-phase="phase"
    :data-played="played.length"
    :data-fen="fen"
    :data-mood="metronome ? mood : null"
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
        text-testid="calculation-status"
      />
    </template>

    <template #chips>
      <span class="play-chip">{{
        $t(`calculation.player.${color === 'black' ? 'black' : 'white'}`)
      }}</span>
      <span v-if="level" class="play-chip" data-testid="calculation-mate">{{
        level
      }}</span>
      <span v-if="item.data.capture" class="play-chip">{{
        $t('calculation.player.capture')
      }}</span>
    </template>

    <template #board>
      <ChessBoard
        v-if="isQuestion"
        :fen="result?.fen ?? EMPTY_BOARD"
        :orientation="color"
        any-position
        :animation-duration="0"
        :show-legal-moves="false"
        :highlights="questionHighlights"
        :square-input="phase === 'question' && item.data.ask === 'where'"
        @square="onSquare"
      />
      <ChessBoard
        v-else
        :fen="fen"
        :orientation="color"
        :movable-color="movableColor"
        :highlights="highlights"
        :arrows="arrows"
        @move="onMove"
      />
    </template>

    <template v-if="isQuestion" #controls>
      <template v-if="item.data.ask === 'what' && phase === 'question'">
        <PiecePalette :model-value="null" @update:model-value="onPiece" />
        <q-btn
          outline
          no-caps
          :label="$t('calculation.question.empty')"
          data-testid="calculation-empty"
          @click="sendAnswer({ placement: [] })"
        />
      </template>
    </template>
    <template v-else #controls>
      <div
        v-if="phase === 'think' || phase === 'answer'"
        class="calculation-player__clock"
        :class="{
          'calculation-player__clock--answer': phase === 'answer',
          'calculation-player__clock--low':
            phase === 'answer' && clockLeftMs <= 700
        }"
        role="progressbar"
        :aria-valuenow="Math.ceil(clockLeftMs / 1000)"
        aria-valuemin="0"
        :aria-valuemax="clockSeconds"
        data-testid="calculation-clock"
      >
        <div
          class="calculation-player__clock-fill"
          :style="{ width: `${(clockLeftMs / (clockSeconds * 1000)) * 100}%` }"
        />
      </div>
      <div class="calculation-player__clock-text">{{ clockText }}</div>
      <q-btn
        v-if="phase === 'think'"
        unelevated
        no-caps
        color="primary"
        class="calculation-player__ready"
        :label="$t('calculation.player.ready')"
        :loading="readying"
        data-testid="calculation-ready"
        @click="ready"
      />
    </template>

    <div v-if="result" data-testid="calculation-result">
      <ResultSheet
        :kind="timeline.kind.value ?? (right ? 'win' : 'miss')"
        :step="timeline.step.value"
        :run="timeline.run.value"
        :title="
          $t(
            `calculation.player.kickers.${right ? 'right' : result.status === 'late' ? 'late' : 'wrong'}`
          )
        "
        :sub="verdict"
        :xp="xp"
        :actions="actions"
        :confetti="right"
      />
    </div>
  </PlayLayout>
</template>

<script setup>
/**
 * A line of the calculation module (docs/CALCULATION.md): the position on a frozen board while the
 * player thinks (up to `thinkSeconds`, closed by « Je réponds »), then the whole line played at
 * once, both sides, each half-move within `plySeconds` of the previous one; it goes to the server
 * at the mate, at the mate's length, or when the clock runs out (what was played, or a pass). The
 * server judges and times it again; a wrong line has its solution replayed on the board.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Chess } from 'chess.js'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import PiecePalette from '@/components/blindfold/PiecePalette.vue'
import PlayLayout from '@/components/chess/PlayLayout.vue'
import ProfBubble from '@/components/feedback/ProfBubble.vue'
import ResultSheet from '@/components/feedback/ResultSheet.vue'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'
import { feedbackProf } from '@/utils/feedback'
import {
  after,
  leftMs,
  levelText,
  lineOver,
  metronomeMood,
  questionText,
  questionVerdict,
  verdictText
} from '@/utils/calculation'
import { moodImage } from '@/utils/mindMapPath'
import {
  playAmbientSound,
  playBossVerdict,
  playOutcomeSound
} from '@/utils/sounds'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').RunItem>} the line; a new one starts over */
  item: { type: Object, required: true },
  /** @type {import('vue').PropType<Record<string, any>|null>} the server's verdict, once sent */
  result: { type: Object, default: null },
  /** The XP of the answer (undefined while the server has not answered). */
  xp: { type: [Number, null], default: undefined },
  /** @type {import('vue').PropType<import('@/components/feedback/ResultSheet.vue').SheetAction[]>} */
  actions: { type: Array, default: () => [] },
  /** The server's clock minus the client's, in ms (the thinking time runs from the server's start). */
  offsetMs: { type: Number, default: 0 },
  /** « Je réponds » sent to the server: resolves once stamped (a refusal still lets the player answer). */
  readyFn: { type: Function, default: async () => {} }
})

const emit = defineEmits({
  /** The line: `{moves}` (UCI), or `{pass: true}` when nothing was played in time. */
  resolve: payload => typeof payload === 'object'
})

/** Between two moves of the solution replayed. */
const REPLAY_MS = 900
const EMPTY_BOARD = '8/8/8/8/8/8/8/8 w - - 0 1'

const timeline = useFeedbackTimeline()

/** A question on the position the line before reached (docs/CALCULATION.md, § 2 bis). */
const isQuestion = computed(() => props.item.data.kind === 'question')
/** @type {import('vue').Ref<'think'|'answer'|'question'|'sending'|'complete'>} */
const phase = ref('think')
const fen = ref('')
/** @type {import('vue').Ref<string[]>} */
const played = ref([])
const readying = ref(false)
const now = ref(Date.now())
/** When the thinking started (the server's start, on the client's clock). */
const thinkStart = ref(0)
/** When the last half-move was played, or « Je réponds » clicked. */
const plyStart = ref(0)
/** The solution's move being replayed, highlighted. */
const replayed = ref(/** @type {string|null} */ (null))
/** @type {ReturnType<typeof setInterval>|undefined} */
let clock
/** @type {ReturnType<typeof setTimeout>|undefined} */
let replayTimer

const color = computed(() =>
  props.item.data.color === 'black' ? 'black' : 'white'
)
const right = computed(() => props.result?.status === 'right')
/** « Mat en 3 », « Gain en 3 coups »… nothing for the Métronome. */
const level = computed(() => levelText(props.item.data))
const thinkSeconds = computed(() => props.item.data.thinkSeconds ?? 120)
const plySeconds = computed(() => props.item.data.plySeconds ?? 2)
const clockSeconds = computed(() =>
  phase.value === 'answer' ? plySeconds.value : thinkSeconds.value
)
const clockLeftMs = computed(() =>
  phase.value === 'answer'
    ? leftMs(plyStart.value, plySeconds.value, now.value)
    : leftMs(thinkStart.value, thinkSeconds.value, now.value)
)
const clockText = computed(() => {
  const s = Math.ceil(clockLeftMs.value / 1000)
  if (phase.value === 'answer')
    return t('calculation.player.plyLeft', { n: played.value.length + 1, s })
  return phase.value === 'think' ? t('calculation.player.thinkLeft', { s }) : ''
})

/** Both sides in turn while answering; nothing before nor after. */
const movableColor = computed(() => {
  if (phase.value !== 'answer') return null
  return new Chess(fen.value).turn() === 'w' ? 'white' : 'black'
})

/** The Métronome's fight (a streak to reach): its face speaks in place of the professor. */
const metronome = computed(() => !!props.item.data.streakTarget)
/** When « Je réponds » was clicked: how much of the thinking time was used. */
const thoughtRatio = ref(/** @type {number|null} */ (null))
const mood = computed(() =>
  metronomeMood({
    phase: phase.value,
    streak: props.item.data.streak ?? 0,
    result: props.result,
    thinkLeftRatio:
      phase.value === 'think'
        ? clockLeftMs.value / (thinkSeconds.value * 1000)
        : 1,
    thoughtRatio: thoughtRatio.value
  })
)

const prof = computed(() =>
  metronome.value
    ? {
        name: t('mindMapPath.characters.metronome.name'),
        image: moodImage('metronome', mood.value),
        glyph: '♞︎',
        bg: '#FBF1DC',
        deep: '#F5C04A',
        ink: '#1B1530',
        accentInk: '#B07A12',
        title: ''
      }
    : feedbackProf(
        'puzzles',
        timeline.kind.value !== null && timeline.step.value >= 2
          ? timeline.kind.value
          : null
      )
)

const bubble = computed(() => {
  if (props.result) {
    const status = right.value
      ? 'right'
      : props.result.status === 'late'
        ? 'late'
        : 'wrong'
    return {
      kicker: t(`calculation.player.kickers.${status}`),
      text: metronome.value
        ? t(`calculation.metronome.${status}`)
        : t(`calculation.player.${status}Text`)
    }
  }
  if (metronome.value)
    return {
      kicker: t(
        `calculation.player.kickers.${phase.value === 'think' ? 'think' : 'answer'}`
      ),
      text: t(`calculation.metronome.moods.${mood.value}`)
    }
  if (isQuestion.value)
    return {
      kicker: t('calculation.player.kickers.question'),
      text: questionText(props.item.data)
    }
  if (phase.value === 'think')
    return {
      kicker: t('calculation.player.kickers.think'),
      text: props.item.data.moves
        ? t('calculation.player.thinkText')
        : t('calculation.player.thinkHidden')
    }
  return {
    kicker: t('calculation.player.kickers.answer'),
    text: t('calculation.player.answerText')
  }
})

const verdict = computed(() => {
  if (!props.result) return ''
  return isQuestion.value
    ? questionVerdict(props.item.data, props.result)
    : verdictText(props.result, props.item.data.fen)
})

/** The square asked lit; once judged, the right squares in green, a wrong click in red. */
const questionHighlights = computed(() => {
  const r = props.result
  if (!r) {
    return props.item.data.ask === 'what' && props.item.data.target
      ? [{ square: props.item.data.target, type: 'hint' }]
      : []
  }
  const squares =
    props.item.data.ask === 'where'
      ? (r.expected ?? []).map((/** @type {string} */ square) => ({
          square,
          type: 'success'
        }))
      : [
          {
            square: props.item.data.target,
            type: right.value ? 'success' : 'error'
          }
        ]
  const wrong =
    props.item.data.ask === 'where' && !right.value && r.answer?.[0]
      ? [{ square: r.answer[0], type: 'error' }]
      : []
  return [...squares, ...wrong]
})

/** @param {string} square */
function onSquare(square) {
  if (phase.value === 'question') sendAnswer({ squares: [square] })
}

/** @param {string|null} piece a FEN letter */
function onPiece(piece) {
  if (!piece || phase.value !== 'question') return
  sendAnswer({ placement: [{ square: props.item.data.target, piece }] })
}

/** @param {Record<string, any>} report */
function sendAnswer(report) {
  if (phase.value !== 'question') return
  phase.value = 'sending'
  emit('resolve', report)
}

/** The first wrong half-move in red, then the solution's moves replayed. */
const highlights = computed(() => {
  if (replayed.value)
    return [
      { square: replayed.value.slice(0, 2), type: 'lastMove' },
      { square: replayed.value.slice(2, 4), type: 'lastMove' }
    ]
  return []
})
/** The right move where the line went off, before the replay. */
const arrows = computed(() => {
  const r = props.result
  if (!r || r.status === 'right' || replayed.value) return []
  const move = r.expected?.[r.mistakeAt ?? 0]
  return move
    ? [
        {
          from: move.uci.slice(0, 2),
          to: move.uci.slice(2, 4),
          type: 'solution'
        }
      ]
    : []
})

async function ready() {
  if (phase.value !== 'think' || readying.value) return
  readying.value = true
  thoughtRatio.value = Math.min(
    1,
    (Date.now() - thinkStart.value) / (thinkSeconds.value * 1000)
  )
  try {
    await props.readyFn()
  } finally {
    readying.value = false
    answer()
  }
}

/** The answer's clock starts: the first half-move within plySeconds. */
function answer() {
  if (phase.value !== 'think') return
  phase.value = 'answer'
  plyStart.value = Date.now()
}

/** @param {{uci: string}} move */
function onMove(move) {
  if (phase.value !== 'answer') return
  played.value = [...played.value, move.uci]
  fen.value = after(props.item.data.fen, played.value)
  plyStart.value = Date.now()
  if (
    lineOver(props.item.data.fen, played.value, props.item.data.moves ?? null)
  )
    send()
}

function send() {
  if (phase.value !== 'answer') return
  phase.value = 'sending'
  emit(
    'resolve',
    played.value.length ? { moves: played.value } : { pass: true }
  )
}

function tick() {
  now.value = Date.now()
  if (phase.value === 'think' && clockLeftMs.value <= 0) ready()
  else if (phase.value === 'answer' && clockLeftMs.value <= 0) send()
}

/** The solution played from the start, a move at a time. */
function replaySolution() {
  clearTimeout(replayTimer)
  const moves = (props.result?.expected ?? []).map(
    (/** @type {{uci: string}} */ m) => m.uci
  )
  fen.value = props.item.data.fen
  let i = 0
  const step = () => {
    if (i >= moves.length) return
    i++
    fen.value = after(props.item.data.fen, moves.slice(0, i))
    replayed.value = moves[i - 1]
    replayTimer = setTimeout(step, REPLAY_MS)
  }
  replayTimer = setTimeout(step, REPLAY_MS)
}

/** Lets the player answer again (a refused submission). */
function unlock() {
  if (phase.value === 'sending')
    phase.value = isQuestion.value ? 'question' : 'answer'
  plyStart.value = Date.now()
}

watch(
  () => props.item.id,
  () => {
    timeline.reset()
    clearTimeout(replayTimer)
    fen.value = props.item.data.fen
    played.value = []
    replayed.value = null
    readying.value = false
    thoughtRatio.value = null
    phase.value = isQuestion.value ? 'question' : 'think'
    const start = Date.parse(props.item.data.startedAt) - props.offsetMs
    thinkStart.value = Number.isFinite(start)
      ? Math.min(start, Date.now())
      : Date.now()
    now.value = Date.now()
    // Back on the page after « Je réponds »: the answer's clock already runs.
    if (!isQuestion.value && props.item.data.readyAt) answer()
  },
  { immediate: true }
)

watch(
  () => props.result,
  value => {
    if (!value) return
    phase.value = 'complete'
    const win = value.status === 'right'
    // A mind map boss (its lives): the bosses' verdicts.
    if (typeof props.item.data.lives === 'number') playBossVerdict(win)
    else playOutcomeSound(win ? 'puzzleDone' : 'puzzleMissed')
    timeline.play(win ? 'win' : 'miss')
    if (!win && !isQuestion.value) replaySolution()
  }
)

clock = setInterval(tick, 100)

/** The Métronome's theme under its fight, and its ticking while a line is thought over. */
const TICK_FAST_MS = 5_000
let stopTheme = () => {}
let stopTick = () => {}
/** The ticking's speed now: 0 silent, 1 as recorded, faster over the last seconds. */
const tickRate = computed(() => {
  if (!metronome.value || phase.value !== 'think') return 0
  return clockLeftMs.value <= TICK_FAST_MS ? 1.5 : 1
})
watch(tickRate, rate => {
  stopTick()
  stopTick = rate
    ? playAmbientSound('metronomeTick', { loop: true, volume: 0.6, rate })
    : () => {}
})

onMounted(() => {
  if (metronome.value)
    stopTheme = playAmbientSound('metronomeTheme', { loop: true, volume: 0.3 })
  if (tickRate.value)
    stopTick = playAmbientSound('metronomeTick', { loop: true, volume: 0.6 })
})

onBeforeUnmount(() => {
  timeline.reset()
  clearInterval(clock)
  clearTimeout(replayTimer)
  stopTheme()
  stopTick()
})

defineExpose({ unlock })
</script>

<style scoped lang="scss">
.calculation-player__clock {
  height: 10px;
  border-radius: 999px;
  background: var(--cm-track, rgba(0, 0, 0, 0.08));
  overflow: hidden;
}

.calculation-player__clock-fill {
  height: 100%;
  border-radius: inherit;
  background: #8b6bff;
  transition: width 0.1s linear;
}

.calculation-player__clock--answer .calculation-player__clock-fill {
  background: #f5a524;
}

.calculation-player__clock--low .calculation-player__clock-fill {
  background: #e5484d;
}

.calculation-player__clock-text {
  margin-top: 6px;
  font-size: 13px;
  opacity: 0.75;
}

.calculation-player__ready {
  margin-top: 12px;

  @media (min-width: $breakpoint-md-min) {
    min-width: 360px;
  }
}
</style>
