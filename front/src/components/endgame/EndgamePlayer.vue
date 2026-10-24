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
      <ChessBoard
        ref="board"
        :fen="shownFen"
        :orientation="orientation"
        :movable-color="movable"
        :arrows="arrows"
        @move="onMove"
      />
    </template>

    <template v-if="!result" #controls>
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
      :actions="actions"
      :confetti="feedback.kind === 'win'"
    />
  </PlayLayout>
</template>

<script setup>
/**
 * A position of the endgames module in a timed run (docs/ENDGAMES.md, § 9): the game against
 * Maia (or the perfect defence) from the start, Lizy's bubble holding the goal and, in learn mode,
 * the lesson's explanation; each move judged as it comes (`useEndgameGame`). The game over, it is sent
 * (`resolve`); the server's verdict comes back as `result`, with the result sheet and, when the
 * position was missed, the right move drawn where it should have been played.
 */
import { computed, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import PlayLayout from '@/components/chess/PlayLayout.vue'
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
  lessonTitle
} from '@/utils/endgame'
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
  actions: { type: Array, default: () => [] }
})

const emit = defineEmits({
  /** The game over: `{moves, pass?, engine?}`, the run's submission. */
  resolve: report => Array.isArray(report?.moves)
})

const $q = useQuasar()
/** @type {import('vue').Ref<{setPosition: (fen: string, animate?: boolean) => Promise<void>}|null>} */
const board = ref(null)
const maia = useMaia()
const game = useEndgameGame({
  maia,
  onFinish: report => emit('resolve', report)
})
const timeline = useFeedbackTimeline()

/** @type {import('vue').ComputedRef<import('@/composables/endgame/useEndgameGame').EndgameItem>} */
const data = computed(() => props.item.data)
/** The player plays from the bottom: the side to move in the position served. */
const orientation = computed(() =>
  data.value.fen.split(' ')[1] === 'b' ? 'black' : 'white'
)

/** Starts the position: its game, Maia downloading meanwhile. */
function start() {
  game.load(data.value)
  timeline.reset()
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
  return game.hint.value ? [arrowOf(game.hint.value, 'hint')] : []
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

.endgame-player__notice {
  background: #fff5d1;
  color: #1b1530;
}
</style>
