<template>
  <PlayLayout
    class="endgame-player"
    data-testid="endgame-player"
    :data-phase="game.phase.value"
    :data-outcome="game.outcome.value ?? undefined"
  >
    <template v-if="$slots.header" #header>
      <slot name="header" />
    </template>

    <template #prof>
      <div class="endgame-player__chips">
        <span class="play-chip" data-testid="endgame-lesson-title">{{
          lessonTitle(data.lesson)
        }}</span>
        <span class="play-chip" data-testid="endgame-counter">{{
          counterText
        }}</span>
        <span
          v-if="clocked"
          class="play-chip endgame-player__clock"
          :class="{ 'endgame-player__clock--urgent': urgent }"
          role="timer"
          :aria-label="$t('endgame.player.clock')"
          data-testid="ashes-clock"
          :data-left="Math.ceil(leftMs / 1000)"
          ><q-icon name="timer" size="18px" />{{ clockLabel(leftMs) }}</span
        >
      </div>
      <ProfBubble
        :prof="prof"
        :kicker="bubble.kicker"
        :text="bubble.text"
        :kind="timeline.kind.value"
        :step="timeline.step.value"
        text-testid="endgame-bubble"
      />
    </template>

    <template #board>
      <AshesDistractions
        :tier="data.tier ?? null"
        :active="playing"
        :fen="game.fen.value"
        :orientation="shownOrientation"
        @decoy="decoy = $event"
        @flip="flipped = $event"
      >
        <ChessBoard
          ref="board"
          :fen="shownFen"
          :orientation="shownOrientation"
          :movable-color="movable"
          :arrows="arrows"
          :highlights="netHighlights"
          @move="onMove"
        />
      </AshesDistractions>
    </template>

    <template v-if="!result" #controls>
      <q-btn
        v-if="data.net"
        unelevated
        no-caps
        class="play-btn endgame-player__net"
        :class="{ 'endgame-player__net--on': netOn }"
        :label="$t('endgame.net.button')"
        :aria-pressed="netOn"
        data-testid="endgame-net"
        @click="toggleNet"
      />
      <q-btn
        v-if="data.mode === 'learn'"
        unelevated
        no-caps
        class="play-btn play-btn--hint"
        :label="$t('endgame.player.hint')"
        :disable="!game.canHint.value"
        data-testid="endgame-hint"
        @click="game.askHint()"
      />
      <q-btn
        v-if="data.mode === 'learn' && !clocked"
        unelevated
        no-caps
        class="play-btn play-btn--hint"
        :label="$t('endgame.demo.short')"
        :disable="game.phase.value === 'over'"
        data-testid="endgame-demo-open"
        @click="openDemo(true)"
      />
      <q-btn
        unelevated
        no-caps
        class="play-btn play-btn--skip"
        :label="$t('endgame.player.pass')"
        :disable="game.phase.value === 'over'"
        data-testid="endgame-pass"
        @click="confirmPass"
      />
    </template>

    <div class="column q-gutter-sm">
      <template v-if="!result">
        <div class="endgame-player__status" data-testid="endgame-status">{{
          statusText
        }}</div>
        <div
          v-if="netCosts"
          class="text-caption endgame-player__net-note"
          data-testid="endgame-net-note"
          >{{
            netUsed ? $t('endgame.net.notCounted') : $t('endgame.net.offered')
          }}</div
        >
        <q-linear-progress
          v-if="maiaLoading"
          rounded
          size="6px"
          color="orange-8"
          :value="maia.progress.value ?? 0"
          :indeterminate="maia.progress.value === null"
          data-testid="endgame-maia-progress"
        />
        <q-banner
          v-if="noticeText"
          dense
          rounded
          class="endgame-player__notice"
          data-testid="endgame-notice"
          >{{ noticeText }}</q-banner
        >
        <q-banner
          v-if="game.error.value"
          dense
          rounded
          class="bg-negative text-white"
          data-testid="endgame-error"
          >{{ $t('endgame.player.error') }}</q-banner
        >
      </template>

      <div
        v-if="result?.engineJudged"
        class="text-caption cm-muted"
        data-testid="endgame-engine-judged"
        >{{ $t('endgame.feedback.engineJudged') }}</div
      >
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
      :actions="sheetActions"
      :confetti="feedback.kind === 'win'"
    />

    <q-dialog
      v-model="demoOpen"
      :maximized="$q.screen.lt.sm"
      @hide="closeDemo"
    >
      <EndgameDemo
        :lesson-key="data.lesson"
        :fen="data.fen"
        :goal="data.goal"
        :result="data.result"
        :hold-moves="data.holdMoves"
        @close="demoOpen = false"
      />
    </q-dialog>
  </PlayLayout>
</template>

<script setup>
/**
 * A position of the endgames module in a timed run (docs/ENDGAMES.md, § 9): the game against
 * Maia (or the perfect defence) from the start, Lizy's bubble holding the goal and, in learn mode,
 * the lesson's explanation; each move judged as it comes (`useEndgameGame`). The game over, it is sent
 * (`resolve`); the server's verdict comes back as `result`, with the result sheet and, when the
 * position was missed, the right move drawn where it should have been played. Against the Roi des
 * cendres (docs/ENDGAMES.md, § 10): the position's clock, held by the server (its `deadline`), and
 * the distractions of its tier around the board; the clock out, the game stops and is sent once the
 * server finds it late (`timeout`).
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import PlayLayout from '@/components/chess/PlayLayout.vue'
import AshesDistractions from '@/components/endgame/AshesDistractions.vue'
import EndgameDemo from '@/components/endgame/EndgameDemo.vue'
import ProfBubble from '@/components/feedback/ProfBubble.vue'
import ResultSheet from '@/components/feedback/ResultSheet.vue'
import { useEndgameGame } from '@/composables/endgame/useEndgameGame'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'
import { useMaia } from '@/composables/maia/useMaia'
import {
  endgameFeedback,
  gameFrom,
  goalText,
  lessonText,
  lessonTitle,
  netSquares
} from '@/utils/endgame'
import {
  clockLabel,
  remainingMs,
  sendDelayMs,
  TICKING_MS,
  URGENT_MS
} from '@/utils/ashes'
import { playTick } from '@/utils/ashesSounds'
import { feedbackProf } from '@/utils/feedback'
import { playOutcomeSound } from '@/utils/sounds'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').RunItem>} the endgame_position item; a new one starts a new game */
  item: { type: Object, required: true },
  /** @type {import('vue').PropType<Record<string, any>|null>} the server's verdict of this position */
  result: { type: Object, default: null },
  /** The submission's `xp` (undefined until it answered). */
  xp: { type: [Number, null], default: undefined },
  /** @type {import('vue').PropType<import('@/components/feedback/ResultSheet.vue').SheetAction[]>} */
  actions: { type: Array, default: () => [] },
  /** Server clock minus client clock (useTimeboxedRun), in ms: the Roi des cendres' clock. */
  offsetMs: { type: Number, default: 0 }
})

const emit = defineEmits({
  /** The game over: `{moves, pass?, engine?, net?}`, the run's submission. */
  resolve: report => Array.isArray(report?.moves)
})

const $q = useQuasar()
/** @type {import('vue').Ref<{setPosition: (fen: string, animate?: boolean) => Promise<void>}|null>} */
const board = ref(null)
const maia = useMaia()
const game = useEndgameGame({
  maia,
  onFinish: report => emit('resolve', withNet(report))
})
const timeline = useFeedbackTimeline()

/** @type {import('vue').ComputedRef<import('@/composables/endgame/useEndgameGame').EndgameItem>} */
const data = computed(() => props.item.data)
/** The player plays from the bottom: the side to move in the position served. */
const orientation = computed(() =>
  data.value.fen.split(' ')[1] === 'b' ? 'black' : 'white'
)
/** The Roi des cendres turns the board over for a while. */
const flipped = ref(false)
const shownOrientation = computed(() => {
  if (!flipped.value) return orientation.value
  return orientation.value === 'white' ? 'black' : 'white'
})
/** @type {import('vue').Ref<{from: string, to: string}|null>} a false arrow of the Roi */
const decoy = ref(null)
/** The position being played (its distractions run meanwhile). */
const playing = computed(
  () =>
    !props.result &&
    game.phase.value !== 'over' &&
    game.phase.value !== 'idle'
)

/** A position against the Roi des cendres' clock. */
const clocked = computed(
  () => !!data.value.clockSeconds && !!data.value.deadline
)
const now = ref(Date.now())
/** @type {ReturnType<typeof setInterval>|undefined} */
let clockTicker
/** @type {ReturnType<typeof setTimeout>|undefined} */
let sendTimer
/** Frozen once the game is over (its verdict on the way or back). */
const leftMs = computed(() =>
  clocked.value
    ? remainingMs(
        /** @type {string} */ (data.value.deadline),
        props.offsetMs,
        now.value
      )
    : 0
)
const urgent = computed(() => clocked.value && leftMs.value < URGENT_MS)

function stopClock() {
  clearInterval(clockTicker)
  clockTicker = undefined
}

watch(
  [clocked, playing],
  ([on, live]) => {
    stopClock()
    if (!on || !live) return
    now.value = Date.now()
    clockTicker = setInterval(() => (now.value = Date.now()), 250)
  },
  { immediate: true }
)

/** The last seconds tick; at zero the game stops, sent once late for the server. */
watch(
  () => /** @type {const} */ ([Math.ceil(leftMs.value / 1000), playing.value]),
  ([seconds, live], previous) => {
    if (!clocked.value || !live) return
    if (seconds > 0) {
      const fresh = seconds !== previous?.[0]
      if (fresh && seconds * 1000 <= TICKING_MS) playTick()
      return
    }
    const report = game.timeUp()
    stopClock()
    if (!report) return
    const deadline = /** @type {string} */ (data.value.deadline)
    clearTimeout(sendTimer)
    sendTimer = setTimeout(
      () => emit('resolve', withNet(report)),
      sendDelayMs(deadline, props.offsetMs, Date.now())
    )
  }
)

onBeforeUnmount(() => {
  stopClock()
  clearTimeout(sendTimer)
})

/**
 * The net (docs/ENDGAMES.md, § 8): the squares the player's pieces deny to the enemy king, in
 * pink. Shown from the start (`shown`), or offered (`offered`): switched on, it goes with the
 * submission, and in the path the pass does not count. None against a boss.
 */
const netOn = ref(false)
/** Switched on where it was only offered, during this position. */
const netUsed = ref(false)
/** Offered in a path's run (a count of positions): switching it on costs the pass. */
const netCosts = computed(
  () => data.value.net === 'offered' && data.value.count != null
)

function toggleNet() {
  netOn.value = !netOn.value
  if (netOn.value && data.value.net === 'offered') netUsed.value = true
}

/**
 * The submission, with the net when it was switched on where only offered.
 *
 * @param {Record<string, any>} report
 */
function withNet(report) {
  return netUsed.value ? { ...report, net: true } : report
}

/** Drawn while the position is played (not on its correction). */
const netHighlights = computed(() =>
  netOn.value && !props.result && !game.mistake.value
    ? netSquares(game.fen.value, orientation.value).map(square => ({
        square,
        type: 'net'
      }))
    : []
)

/** Starts the position: its game, Maia downloading meanwhile. */
function start() {
  clearTimeout(sendTimer)
  netOn.value = data.value.net === 'shown'
  netUsed.value = false
  flipped.value = false
  decoy.value = null
  game.load(data.value)
  timeline.reset()
  now.value = Date.now()
  if (!data.value.opponent.perfect) maia.preload().catch(() => {})
}

watch(() => props.item.id, start, { immediate: true })

/** The submission failed: the position starts again (the server serves it again from the start). */
function unlock() {
  start()
}
defineExpose({ unlock })

watch(
  () => props.result,
  value => {
    if (!value) return
    const kind =
      value.status === 'won' || value.status === 'held' ? 'win' : 'miss'
    playOutcomeSound(kind === 'win' ? 'puzzleDone' : 'puzzleMissed')
    timeline.play(kind)
  }
)

const movable = computed(() =>
  game.phase.value === 'player' && !game.busy.value
    ? orientation.value
    : null
)

/** The position missed: back to where the right move was to be played. */
const mistakeFen = computed(() => {
  const mistake = game.mistake.value
  if (!mistake) return null
  try {
    return gameFrom(
      data.value.fen,
      game.moves.value.slice(0, mistake.ply)
    ).fen()
  } catch {
    return null
  }
})
const shownFen = computed(() => mistakeFen.value ?? game.fen.value)

/**
 * @param {string} uci
 * @param {'hint'|'solution'|'error'} type
 */
const arrowOf = (uci, type) => ({
  from: uci.slice(0, 2),
  to: uci.slice(2, 4),
  type
})

const arrows = computed(() => {
  const mistake = game.mistake.value
  if (mistake && mistakeFen.value) {
    const list = []
    const played = game.moves.value[mistake.ply]
    if (played && game.outcome.value === 'spoiled')
      list.push(arrowOf(played, 'error'))
    const best = props.result?.bestMove ?? mistake.bestMove
    if (best) list.push(arrowOf(best, 'solution'))
    return list
  }
  if (game.hint.value) return [arrowOf(game.hint.value, 'hint')]
  // The Roi des cendres' false arrow, drawn as a hint would be.
  return decoy.value ? [{ ...decoy.value, type: 'hint' }] : []
})

/** @param {{uci: string}} move */
async function onMove(move) {
  if (game.play(move.uci)) return
  // Refused (not the player's turn): back to the position on screen.
  await board.value?.setPosition(game.fen.value, true)
}

function confirmPass() {
  $q.dialog({
    title: t('endgame.player.passTitle'),
    message: t('endgame.player.passText'),
    cancel: { label: t('common.actions.cancel'), flat: true, noCaps: true },
    ok: { label: t('endgame.player.pass'), unelevated: true, noCaps: true },
    persistent: false
  }).onOk(() => game.pass())
}

const counterText = computed(() => {
  const c = game.counter.value
  if (!c) return ''
  return t(`endgame.player.${c.kind === 'hold' ? 'hold' : 'budget'}`, {
    done: c.done,
    total: c.total
  })
})

/** Maia still downloading while the game needs it. */
const maiaLoading = computed(
  () => !data.value.opponent.perfect && maia.status.value === 'loading'
)

const statusText = computed(() => {
  if (game.outcome.value === 'timeout') return t('endgame.player.timeUp')
  if (game.busy.value) return t('endgame.player.checking')
  if (game.phase.value === 'opponent') {
    if (maiaLoading.value) {
      const p = maia.progress.value
      return p === null
        ? t('endgame.player.maiaLoadingUnknown')
        : t('endgame.player.maiaLoading', { pct: Math.round(p * 100) })
    }
    return t('endgame.player.opponentThinking')
  }
  return game.hint.value
    ? t('endgame.player.hintShown')
    : t('endgame.player.yourTurn')
})

const noticeText = computed(() =>
  game.notice.value ? t(`endgame.player.notices.${game.notice.value}`) : ''
)

const feedback = computed(() =>
  props.result
    ? endgameFeedback(
        /** @type {any} */ (props.result),
        data.value.fen,
        game.mistake.value?.bestMove ?? null
      )
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

/** The demonstration of the position served (learn mode, or after a miss; never the Roi's). */
const demoOpen = ref(false)
/** Opened during the game: the position starts again once it is closed. */
let demoRestarts = false

/** @param {boolean} restarts */
function openDemo(restarts) {
  demoRestarts = restarts
  demoOpen.value = true
}

function closeDemo() {
  if (demoRestarts && !props.result && game.phase.value !== 'over') start()
  demoRestarts = false
}

/** The result sheet's actions: « Voir la démonstration » first after a miss. */
const sheetActions = computed(() => {
  const missed =
    !!props.result &&
    props.result.status !== 'won' &&
    props.result.status !== 'held'
  if (!missed || clocked.value) return props.actions
  return [
    {
      label: t('endgame.demo.watch'),
      testid: 'endgame-demo-watch',
      onClick: () => openDemo(false)
    },
    ...props.actions
  ]
})

const reacting = computed(
  () => timeline.kind.value !== null && timeline.step.value >= 2
)
const prof = computed(() =>
  feedbackProf('endgames', reacting.value ? feedback.value.kind : null)
)
const bubble = computed(() => {
  if (props.result)
    return { kicker: feedback.value.kicker, text: feedback.value.bubble }
  if (data.value.mode === 'learn')
    return {
      kicker: t('endgame.player.lessonKicker'),
      text: `${lessonText(data.value.lesson)}\n${goalText(data.value)}`
    }
  return { kicker: t('endgame.player.goalKicker'), text: goalText(data.value) }
})
</script>

<style scoped lang="scss">
.endgame-player__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 8px;
}

.endgame-player__status {
  font-weight: 700;
  font-size: 14px;
}

// Above PlayLayout's chip rules (a class more).
.play-chip.endgame-player__clock {
  gap: 4px;
  font-size: 16px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;

  &--urgent {
    background: var(--cm-danger-soft);
    color: var(--cm-danger);
    animation: endgame-clock-pulse 1s ease-in-out infinite;
  }
}

@keyframes endgame-clock-pulse {
  50% {
    transform: scale(1.06);
  }
}

@media (prefers-reduced-motion: reduce) {
  .play-chip.endgame-player__clock--urgent {
    animation: none;
  }
}

// Above PlayLayout's button rules (a class more): a pale pink, a little deeper and outlined while
// the net is drawn.
.play-btn.endgame-player__net {
  background: #fff0f6;
}

.play-btn.endgame-player__net--on {
  background: #ffdcea;
  box-shadow: inset 0 0 0 2px #f7a8c8;
}

.endgame-player__net-note {
  color: var(--cm-muted);
}

.endgame-player__notice {
  background: #fff5d1;
  color: #1b1530;
}
</style>
