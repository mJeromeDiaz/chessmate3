<template>
  <PlayLayout
    class="calculation-player"
    data-testid="calculation-player"
    :data-phase="phase"
    :data-played="played.length"
    :data-fen="fen"
    :data-mood="metronome || advocate ? mood : null"
    :data-kind="item.data.kind ?? null"
    :data-line="kind === 'two_replies' ? (firstLine ? 2 : 1) : null"
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
      <!-- No animation: a move played at 3 s per half-move must never wait for one (a click
        during an animation is lost). -->
      <ChessBoard
        v-else
        :fen="fen"
        :orientation="color"
        :movable-color="movableColor"
        :highlights="highlights"
        :arrows="arrows"
        :animation-duration="0"
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
        v-if="phase === 'choose'"
        class="calculation-player__choices"
        data-testid="calculation-choices"
      >
        <q-btn
          v-for="choice in choices ?? []"
          :key="choice.uci"
          outline
          no-caps
          size="lg"
          :label="choice.san"
          :data-uci="choice.uci"
          data-testid="calculation-choice"
          @click="choose(choice.uci)"
        />
        <div v-if="!choices" class="calculation-player__preparing">
          {{ $t('calculation.player.preparing') }}
        </div>
      </div>
      <div
        v-if="phase === 'combo'"
        class="calculation-player__choices"
        data-testid="calculation-combos"
      >
        <q-btn
          v-for="combo in combos ?? []"
          :key="combo.uci"
          outline
          no-caps
          size="lg"
          :label="combo.san"
          :style="{ color: combo.color }"
          :loading="readying"
          :data-uci="combo.uci"
          data-testid="calculation-combo"
          @click="chooseCombo(combo.uci)"
        />
        <div v-if="!combos" class="calculation-player__preparing">
          {{ $t('calculation.player.preparingCombos') }}
        </div>
      </div>
      <div
        v-if="
          phase === 'think' ||
          phase === 'solve' ||
          phase === 'answer' ||
          phase === 'choose' ||
          phase === 'combo'
        "
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
      <div v-if="engineKind" class="calculation-player__engine">
        <EngineStatus :status="engineStatus" />
        <div
          v-if="engineFailed"
          class="calculation-player__engine-down"
          data-testid="calculation-engine-down"
        >
          {{ $t('calculation.player.engineDown') }}
        </div>
      </div>
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
 *
 * Chapter 3 (`engine`, docs/CALCULATION.md, § 2 quater): Stockfish judges in the browser, each
 * position analysed as soon as it is on the board, and the verdict goes with the line. 3.1
 * (`reply`): the player's first move shown played, a reply to choose between the solution's and a
 * decoy the engine draws; 3.2 (`two_replies`): two lines, the second after the first move and the
 * other reply, both played by themselves; the mini-boss (`combo`): a first move to choose among
 * forcing moves (the others refuted by the engine), then the rest of its line; the Avocat du diable
 * (`advocate`): the player's own move, the opponent's best reply to it, then the rest, at once; an
 * idea that falls says whether its refutation was found.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Chess } from 'chess.js'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import EngineStatus from '@/components/chess/EngineStatus.vue'
import PiecePalette from '@/components/blindfold/PiecePalette.vue'
import PlayLayout from '@/components/chess/PlayLayout.vue'
import ProfBubble from '@/components/feedback/ProfBubble.vue'
import ResultSheet from '@/components/feedback/ResultSheet.vue'
import { useLineJudge } from '@/composables/calculation/useLineJudge'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'
import { feedbackProf } from '@/utils/feedback'
import {
  advocateMood,
  advocateNote,
  after,
  leftMs,
  levelText,
  lineOver,
  metronomeMood,
  forcingMoves,
  offSolution,
  pickDecoy,
  pickDecoys,
  questionText,
  questionVerdict,
  verdictText
} from '@/utils/calculation'
import { isNearBest } from '@/utils/engine/uci'
import { moodImage } from '@/utils/mindMapPath'
import { localSan } from '@/utils/notation'
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
  /**
   * The line: `{moves}` (UCI), or `{pass: true}` when nothing was played in time; chapter 3 adds
   * the engine's verdict `engine: {right, mistakeAt?}`, 3.1 sends `choice`, 3.2 `lines`.
   */
  resolve: payload => typeof payload === 'object'
})

/**
 * What the server allows past the line's 3 s per half-move (1 s of rounding, 2 s of network),
 * less a margin: the engine's verdict is waited for until then.
 */
const JUDGE_SLACK_MS = 2500
/** A refuted combination: its refutation shown this long before the solution is replayed. */
const REFUTATION_MS = 1800
/** The mini-boss's combinations, told apart by colour (arrow and button alike). */
const COMBO_COLORS = [
  { type: 'choiceA', color: '#2f80ed' },
  { type: 'choiceB', color: '#f5a524' },
  { type: 'choiceC', color: '#8b6bff' }
]
/** Between two moves of the solution replayed. */
const REPLAY_MS = 900
const EMPTY_BOARD = '8/8/8/8/8/8/8/8 w - - 0 1'

const timeline = useFeedbackTimeline()

/** A question on the position the line before reached (docs/CALCULATION.md, § 2 bis). */
const isQuestion = computed(() => props.item.data.kind === 'question')
/** The mini-boss of chapter 2 (docs/CALCULATION.md, § 2 ter): solved first, then replayed. */
const isSolve = computed(() => !!props.item.data.solve && !isQuestion.value)
/** Between the player's move and the opponent's answer while solving, and before the replay. */
const SOLVE_REPLY_MS = 450
const SOLVED_MS = 900
/** @type {ReturnType<typeof setTimeout>|undefined} */
let solveTimer
/** @type {import('vue').Ref<'think'|'solve'|'choose'|'combo'|'answer'|'question'|'judging'|'sending'|'complete'>} */
const phase = ref('think')
const fen = ref('')
/** @type {import('vue').Ref<string[]>} */
const played = ref([])
const readying = ref(false)
const now = ref(Date.now())
/** When the thinking started (the server's start, on the client's clock). */
const thinkStart = ref(0)
/** When « Je réponds » was clicked (the answer's clock started). */
const answerStart = ref(0)
/** When the last half-move was played, or « Je réponds » clicked. */
const plyStart = ref(0)
/** The solution's move being replayed, highlighted. */
const replayed = ref(/** @type {string|null} */ (null))
/** @type {ReturnType<typeof setInterval>|undefined} */
let clock
/** @type {ReturnType<typeof setTimeout>|undefined} */
let replayTimer

/** Chapter 3: judged by Stockfish in the browser. */
const judge = useLineJudge()
const { status: engineStatus, failed: engineFailed } = judge
const kind = computed(() => props.item.data.kind ?? 'line')
const engineKind = computed(() => !!props.item.data.engine)
const solution = computed(
  () => /** @type {string[]} */ (props.item.data.solution ?? [])
)
const toleranceCp = computed(() => props.item.data.toleranceCp ?? 50)
/** 3.1: the two replies offered (shuffled), null while the decoy is drawn. */
const choices = ref(/** @type {{uci: string, san: string}[]|null} */ (null))
/** 3.1: the engine's lines on the opponent's replies, null without them. */
let replyLines = /** @type {import('@/utils/engine/uci').EngineLine[]|null} */ (
  null
)
/** The mini-boss: the combinations offered (shuffled, coloured), null while the decoys are drawn. */
const combos = ref(
  /** @type {{uci: string, san: string, type: string, color: string}[]|null} */ (
    null
  )
)
/** The mini-boss: the engine's lines on the forcing moves, null without them. */
let comboLines = /** @type {import('@/utils/engine/uci').EngineLine[]|null} */ (
  null
)
/** A refuted combination: the engine's answer to it, shown before the solution. */
const refutation = ref(/** @type {string|null} */ (null))
/** 3.2: the first line, once played; the second is on the board. */
const firstLine = ref(/** @type {string[]|null} */ (null))

const color = computed(() =>
  props.item.data.color === 'black' ? 'black' : 'white'
)
const right = computed(() => props.result?.status === 'right')
/** « Mat en 3 », « Gain en 3 coups »… nothing for the Métronome. */
const level = computed(() => levelText(props.item.data))
const thinkSeconds = computed(() => props.item.data.thinkSeconds ?? 120)
const plySeconds = computed(() => props.item.data.plySeconds ?? 3)
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
  return phase.value === 'think' ||
    phase.value === 'solve' ||
    phase.value === 'choose' ||
    phase.value === 'combo'
    ? t('calculation.player.thinkLeft', { s })
    : ''
})

/** Both sides in turn while answering; the player's while solving (the opponent answers); nothing before nor after. */
const movableColor = computed(() => {
  if (phase.value === 'solve') {
    const turn = new Chess(fen.value).turn() === 'w' ? 'white' : 'black'
    return turn === color.value && !solveTimer ? turn : null
  }
  if (phase.value !== 'answer') return null
  return new Chess(fen.value).turn() === 'w' ? 'white' : 'black'
})

/** The Métronome's fight (a streak to reach): its face speaks in place of the professor. */
const metronome = computed(() => !!props.item.data.streakTarget)
/** When « Je réponds » was clicked: how much of the thinking time was used. */
const thoughtRatio = ref(/** @type {number|null} */ (null))
/** The Avocat du diable's fight (chapter 3's boss): his face speaks in place of the professor. */
const advocate = computed(() => kind.value === 'advocate')
/** The Avocat du diable: whether the reply played refuted the idea that fell (null: unknown). */
const refuted = ref(/** @type {boolean|null} */ (null))
const thinkLeftRatio = computed(() =>
  phase.value === 'think' ? clockLeftMs.value / (thinkSeconds.value * 1000) : 1
)
const mood = computed(() =>
  advocate.value
    ? advocateMood({
        phase: phase.value,
        result: props.result,
        thinkLeftRatio: thinkLeftRatio.value
      })
    : metronomeMood({
        phase: phase.value,
        streak: props.item.data.streak ?? 0,
        result: props.result,
        thinkLeftRatio: thinkLeftRatio.value,
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
    : advocate.value
      ? {
          name: t('mindMapPath.characters.avocat.name'),
          image: moodImage('avocat', mood.value),
          glyph: '⚖︎',
          bg: '#F3ECFB',
          deep: '#8B6BFF',
          ink: '#1B1530',
          accentInk: '#5B3FC4',
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
        : advocate.value
          ? t(`calculation.advocate.${status}`)
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
  if (phase.value === 'solve')
    return {
      kicker: t('calculation.player.kickers.solve'),
      text: t('calculation.player.solveText')
    }
  if (isSolve.value && phase.value === 'answer')
    return {
      kicker: t('calculation.player.kickers.replay'),
      text: t('calculation.player.replayText')
    }
  if (phase.value === 'judging')
    return {
      kicker: t('calculation.player.kickers.judging'),
      text: t('calculation.player.judgingText')
    }
  if (phase.value === 'choose' || phase.value === 'combo')
    return {
      kicker: t('calculation.player.kickers.choose'),
      text: t(`calculation.player.kinds.${kind.value}`)
    }
  if (engineKind.value && phase.value === 'think')
    return {
      kicker: t('calculation.player.kickers.think'),
      text: t(`calculation.player.kinds.${kind.value}`)
    }
  if (kind.value === 'two_replies' && phase.value === 'answer')
    return {
      kicker: t('calculation.player.kickers.answer'),
      text: firstLine.value
        ? t('calculation.player.secondLineText')
        : t('calculation.player.firstLineText')
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
  if (isQuestion.value) return questionVerdict(props.item.data, props.result)
  const note =
    advocate.value && !engineFailed.value
      ? advocateNote(props.result, refuted.value)
      : ''
  return [note, verdictText(props.result, props.item.data.fen)]
    .filter(Boolean)
    .join(' · ')
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
  const first = solution.value[0]
  if (phase.value === 'choose' && first)
    return [
      { square: first.slice(0, 2), type: 'lastMove' },
      { square: first.slice(2, 4), type: 'lastMove' }
    ]
  if (replayed.value)
    return [
      { square: replayed.value.slice(0, 2), type: 'lastMove' },
      { square: replayed.value.slice(2, 4), type: 'lastMove' }
    ]
  return []
})
/** A refused combination: one half-move, other than the solution's first. */
const refused = computed(
  () =>
    kind.value === 'combo' &&
    props.result?.status === 'wrong' &&
    props.result?.mistakeAt === 0 &&
    props.result?.answer?.length === 1
)

/**
 * The combinations offered; the right move where the line went off, before the replay (a refused
 * combination: the engine's answer to it).
 */
const arrows = computed(() => {
  if (phase.value === 'combo')
    return (combos.value ?? []).map(c => ({
      from: c.uci.slice(0, 2),
      to: c.uci.slice(2, 4),
      type: c.type
    }))
  const r = props.result
  if (refused.value && !replayed.value)
    return refutation.value
      ? [
          {
            from: refutation.value.slice(0, 2),
            to: refutation.value.slice(2, 4),
            type: 'error'
          }
        ]
      : []
  if (!r || r.status === 'right' || replayed.value) return []
  // 3.2: a mistake in the second line has no move of the solution to show.
  if (r.second && (r.mistakeAt ?? 0) >= (r.answer?.length ?? 0)) return []
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
  if ((phase.value !== 'think' && phase.value !== 'solve') || readying.value)
    return
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
  if (phase.value !== 'think' && phase.value !== 'solve') return
  phase.value = 'answer'
  plyStart.value = Date.now()
  answerStart.value = plyStart.value
}

/**
 * Solving the mini-boss's puzzle: the solution's move (or any mate), the opponent's answer, until
 * its end; then back to the start, the replay's clock started. Missed: a failure (the solution
 * is then replayed).
 *
 * @param {string} uci
 */
function solveMove(uci) {
  const solution = props.item.data.solution ?? []
  const next = [...played.value, uci]
  const mates = new Chess(after(props.item.data.fen, next)).isCheckmate()
  if (uci !== solution[played.value.length] && !mates) {
    phase.value = 'sending'
    emit('resolve', { moves: [], solutionShown: true })
    return
  }
  played.value = next
  fen.value = after(props.item.data.fen, next)
  if (mates || next.length >= solution.length) {
    solveTimer = setTimeout(() => {
      solveTimer = undefined
      played.value = []
      fen.value = props.item.data.fen
      ready()
    }, SOLVED_MS)
    return
  }
  solveTimer = setTimeout(() => {
    solveTimer = undefined
    played.value = [...played.value, solution[played.value.length]]
    fen.value = after(props.item.data.fen, played.value)
  }, SOLVE_REPLY_MS)
}

/** @param {{uci: string}} move */
function onMove(move) {
  if (phase.value === 'solve') {
    solveMove(move.uci)
    return
  }
  if (phase.value !== 'answer') return
  played.value = [...played.value, move.uci]
  fen.value = after(props.item.data.fen, played.value)
  plyStart.value = Date.now()
  if (engineKind.value) judge.follow(props.item.data.fen, played.value)
  if (!over(played.value)) return
  if (kind.value === 'two_replies' && !firstLine.value) secondLine()
  else send()
}

/** Whether a line is over: at the solution's length in chapter 3, else at the mate's. */
function over(/** @type {string[]} */ line) {
  if (engineKind.value && solution.value.length)
    return (
      lineOver(props.item.data.fen, line, null) ||
      line.length >= solution.value.length
    )
  return lineOver(props.item.data.fen, line, props.item.data.moves ?? null)
}

/**
 * 3.2: the first line played, the second starts from the same first move and the other reply,
 * both played by themselves (just the first move when there is no other reply: a line cut short).
 */
function secondLine() {
  const first = played.value
  firstLine.value = first
  const start = first.slice(0, 1)
  const other = first.length
    ? new Chess(after(props.item.data.fen, start))
        .moves({ verbose: true })
        .map(m => m.lan)
        .find(uci => uci !== first[1])
    : undefined
  played.value = other ? [...start, other] : start
  fen.value = after(props.item.data.fen, played.value)
  plyStart.value = Date.now()
  judge.follow(props.item.data.fen, played.value)
  if (other && over(played.value)) send()
}

function send() {
  if (phase.value !== 'answer') return
  if (engineKind.value) {
    sendJudged()
    return
  }
  phase.value = 'sending'
  emit(
    'resolve',
    played.value.length ? { moves: played.value } : { pass: true }
  )
}

/**
 * A line's verdict: right on the solution with no analysis; cut short, wrong where it stops;
 * otherwise Stockfish's. The engine down, only the solution is right.
 *
 * @param {string[]} line
 * @returns {Promise<{right: boolean, mistakeAt?: number}>}
 */
async function lineVerdict(line) {
  const off = offSolution(line, solution.value)
  if (off === null) return { right: true }
  if (!over(line)) return { right: false, mistakeAt: line.length }
  // The server times the line up to the submission: an engine too slow counts as down.
  const verdict = await Promise.race([
    judge.verdict(props.item.data.fen, line, toleranceCp.value),
    new Promise(resolve => setTimeout(() => resolve(null), judgeWaitMs()))
  ])
  if (!verdict) return { right: false, mistakeAt: off }
  return verdict.mistakeAt === null
    ? { right: verdict.right }
    : { right: verdict.right, mistakeAt: verdict.mistakeAt }
}

/**
 * How long the engine's verdict may still be waited for: the server times the line from « Je
 * réponds » up to the submission (3 s per half-move of the solution, for each line of 3.2).
 */
function judgeWaitMs() {
  const lines = kind.value === 'two_replies' ? 2 : 1
  const deadline =
    answerStart.value +
    plySeconds.value * 1000 * solution.value.length * lines +
    JUDGE_SLACK_MS
  return Math.max(1000, deadline - Date.now())
}

/** Chapter 3: the line(s) sent with the engine's verdict, once the last position is analysed. */
async function sendJudged() {
  const item = props.item.id
  const line = played.value
  const first = firstLine.value
  if (!first && line.length === 0) {
    phase.value = 'sending'
    emit('resolve', { pass: true })
    return
  }
  phase.value = 'judging'
  if (kind.value === 'two_replies') {
    const lines = first ? [first, line] : [line, []]
    const [a, b] = await Promise.all(lines.map(l => lineVerdict(l)))
    if (props.item.id !== item || phase.value !== 'judging') return
    const mistakeAt = !a.right
      ? a.mistakeAt
      : !b.right
        ? lines[0].length + (b.mistakeAt ?? 0)
        : undefined
    phase.value = 'sending'
    emit('resolve', {
      lines,
      engine:
        mistakeAt === undefined
          ? { right: a.right && b.right }
          : { right: false, mistakeAt }
    })
    return
  }
  const engine = await lineVerdict(line)
  if (props.item.id !== item || phase.value !== 'judging') return
  // The Avocat du diable: an idea that falls, was its refutation found? (for the verdict only)
  if (advocate.value && engine.mistakeAt === 0)
    judge.holdsAt(props.item.data.fen, line, 1, toleranceCp.value).then(v => {
      if (props.item.id === item) refuted.value = v
    })
  phase.value = 'sending'
  emit('resolve', { moves: line, engine })
}

/**
 * 3.1: the reply chosen; right when it is the solution's, or as good for the engine.
 *
 * @param {string} uci
 */
function choose(uci) {
  if (phase.value !== 'choose' || !choices.value) return
  const right =
    uci === solution.value[1] ||
    (!!replyLines && isNearBest(replyLines, uci, toleranceCp.value))
  phase.value = 'sending'
  emit('resolve', { choice: uci, engine: { right } })
}

/**
 * The mini-boss: a combination chosen. Refuted, a failure at once, the engine's answer to it shown;
 * holding (the solution's, or as good for the engine), « Je réponds »: it is played, the player
 * plays the rest of the line.
 *
 * @param {string} uci
 */
async function chooseCombo(uci) {
  if (phase.value !== 'combo' || !combos.value || readying.value) return
  const holds =
    uci === solution.value[0] ||
    (!!comboLines && isNearBest(comboLines, uci, toleranceCp.value))
  if (!holds) {
    const item = props.item.id
    const position = after(props.item.data.fen, [uci])
    fen.value = position
    judge.bestMove(position).then(move => {
      if (props.item.id === item) refutation.value = move
    })
    phase.value = 'sending'
    emit('resolve', { moves: [uci], engine: { right: false, mistakeAt: 0 } })
    return
  }
  readying.value = true
  try {
    await props.readyFn()
  } finally {
    readying.value = false
  }
  if (phase.value !== 'combo') return
  phase.value = 'answer'
  played.value = [uci]
  fen.value = after(props.item.data.fen, played.value)
  plyStart.value = Date.now()
  answerStart.value = plyStart.value
  judge.follow(props.item.data.fen, played.value)
  if (over(played.value)) send()
}

/** The mini-boss: the decoys drawn by the engine, the combinations offered in a random order. */
async function offerCombos() {
  const item = props.item.id
  const first = solution.value[0]
  if (!first) return
  const forcing = forcingMoves(props.item.data.fen)
  const lines = await judge.linesOn(props.item.data.fen, forcing)
  if (props.item.id !== item) return
  comboLines = lines
  const decoys = pickDecoys(
    forcing,
    first,
    lines,
    props.item.data.decoyGapCp ?? 150,
    (props.item.data.combos ?? COMBO_COLORS.length) - 1
  )
  const offered = [first, ...decoys]
  for (let i = offered.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[offered[i], offered[j]] = [offered[j], offered[i]]
  }
  combos.value = offered.map((uci, i) => ({
    uci,
    san: localSan(
      new Chess(props.item.data.fen).move({
        from: uci.slice(0, 2),
        to: uci.slice(2, 4),
        promotion: uci[4]
      }).san
    ),
    ...COMBO_COLORS[i % COMBO_COLORS.length]
  }))
}

/** 3.1: the decoy drawn by the engine, then the two replies offered in a random order. */
async function offerReplies() {
  const item = props.item.id
  const [first, reply] = solution.value
  if (!first || !reply) return
  const position = after(props.item.data.fen, [first])
  const lines = await judge.replies(position)
  if (props.item.id !== item) return
  replyLines = lines
  const decoy = pickDecoy(
    position,
    reply,
    lines,
    props.item.data.decoyGapCp ?? 150
  )
  const offered = [reply, ...(decoy ? [decoy] : [])].map(uci => ({
    uci,
    san: localSan(
      new Chess(position).move({
        from: uci.slice(0, 2),
        to: uci.slice(2, 4),
        promotion: uci[4]
      }).san
    )
  }))
  if (Math.random() < 0.5) offered.reverse()
  choices.value = offered
}

function tick() {
  now.value = Date.now()
  if (phase.value === 'solve' && clockLeftMs.value <= 0 && !solveTimer) {
    // Not solved in time: a failure.
    phase.value = 'sending'
    emit('resolve', { moves: [], solutionShown: true })
  } else if (
    (phase.value === 'choose' || phase.value === 'combo') &&
    clockLeftMs.value <= 0
  ) {
    phase.value = 'sending'
    emit('resolve', { pass: true })
  } else if (phase.value === 'think' && clockLeftMs.value <= 0) ready()
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
    phase.value = isQuestion.value
      ? 'question'
      : kind.value === 'reply'
        ? 'choose'
        : kind.value === 'combo' && played.value.length === 0
          ? 'combo'
          : 'answer'
  if (phase.value === 'combo') fen.value = props.item.data.fen
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
    clearTimeout(solveTimer)
    solveTimer = undefined
    judge.reset()
    choices.value = null
    replyLines = null
    combos.value = null
    comboLines = null
    refutation.value = null
    refuted.value = null
    firstLine.value = null
    phase.value = isQuestion.value
      ? 'question'
      : props.item.data.solve
        ? 'solve'
        : kind.value === 'reply'
          ? 'choose'
          : kind.value === 'combo'
            ? 'combo'
            : 'think'
    const start = Date.parse(props.item.data.startedAt) - props.offsetMs
    thinkStart.value = Number.isFinite(start)
      ? Math.min(start, Date.now())
      : Date.now()
    now.value = Date.now()
    // Back on the page after « Je réponds »: the answer's clock already runs.
    if (!isQuestion.value && props.item.data.readyAt) answer()
    if (kind.value === 'reply') {
      fen.value = after(props.item.data.fen, solution.value.slice(0, 1))
      offerReplies()
    } else if (kind.value === 'combo') offerCombos()
    else if (engineKind.value) judge.follow(props.item.data.fen, [])
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
    if (!win && !isQuestion.value) {
      // A refused combination: the engine's answer to it first.
      if (refused.value) replayTimer = setTimeout(replaySolution, REFUTATION_MS)
      else replaySolution()
    }
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
  clearTimeout(solveTimer)
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

.calculation-player__choices {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;

  .q-btn {
    min-width: 140px;
  }
}

.calculation-player__preparing,
.calculation-player__engine-down {
  font-size: 13px;
  opacity: 0.75;
}

.calculation-player__engine {
  margin-top: 12px;
}

.calculation-player__ready {
  margin-top: 12px;

  @media (min-width: $breakpoint-md-min) {
    min-width: 360px;
  }
}
</style>
