<template>
  <PlayLayout
    class="blind-player"
    data-testid="blindfold-player"
    :data-phase="game.phase.value"
    :data-moves="game.history.value.length"
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
        :hint="game.peeking.value"
        text-testid="blindfold-status"
      />
    </template>

    <template #chips>
      <span
        v-for="chip in infoChips"
        :key="chip"
        class="play-chip"
        data-testid="blindfold-info"
        >{{ chip }}</span
      >
    </template>

    <template #board>
      <div class="blind-player__board">
        <ChessBoard
          :fen="game.boardFen.value"
          :orientation="game.orientation.value"
          :highlights="game.highlights.value"
          :square-input="game.phase.value === 'play'"
          @square="game.clickSquare"
        />
        <div
          v-if="game.phase.value === 'hidden'"
          class="blind-player__veil"
          data-testid="blindfold-veil"
        >
          <div class="blind-player__veil-label">Position cachée</div>
          <div class="blind-player__veil-count">{{ game.countdown.value }}</div>
        </div>
      </div>
    </template>

    <template v-if="game.phase.value !== 'complete'" #controls>
      <template v-if="game.phase.value === 'show'">
        <div class="blind-player__count" data-testid="blindfold-countdown"
          >{{ game.countdown.value }} s</div
        >
        <q-btn
          unelevated
          no-caps
          class="play-btn play-btn--skip"
          label="J’ai mémorisé"
          data-testid="blindfold-memorized"
          @click="game.memorized()"
        />
      </template>
      <q-btn
        unelevated
        no-caps
        class="play-btn play-btn--solution"
        label="Solution"
        :disable="!canGiveUp"
        data-testid="blindfold-give-up"
        @click="game.giveUp()"
      />
    </template>

    <div
      v-if="
        game.message.value ||
        game.promotion.value ||
        line ||
        game.phase.value === 'complete'
      "
      class="column q-gutter-sm"
    >
      <div
        v-if="game.message.value"
        class="blind-player__message"
        :class="`blind-player__message--${game.message.value.tone}`"
        data-testid="blindfold-message"
        >{{ game.message.value.text }}</div
      >

      <div
        v-if="game.promotion.value"
        class="row items-center q-gutter-sm"
        data-testid="blindfold-promotion"
      >
        <span>Promotion en</span>
        <q-btn
          v-for="p in PROMOTIONS"
          :key="p.piece"
          outline
          no-caps
          dense
          :label="p.label"
          :data-testid="`blindfold-promote-${p.piece}`"
          @click="game.promote(p.piece)"
        />
        <q-btn
          flat
          dense
          no-caps
          label="Annuler"
          @click="game.cancelPromotion()"
        />
      </div>

      <div
        v-if="line"
        class="blind-player__line"
        data-testid="blindfold-line"
        >{{ line }}</div
      >

      <div
        v-if="game.phase.value === 'complete'"
        data-testid="blindfold-result"
      >
        <ResultSheet
          v-if="timeline.kind.value"
          :kind="timeline.kind.value"
          :step="timeline.step.value"
          :run="timeline.run.value"
          :title="copy.title"
          :sub="copy.sub"
          :xp="xp"
          :actions="actions"
        >
          <slot name="result" :status="game.status.value" />
        </ResultSheet>
      </div>
    </div>
  </PlayLayout>
</template>

<script setup>
/**
 * A blindfold puzzle being played (docs/BLINDFOLD.md): the position shown for the chosen
 * time (or until "J'ai mémorisé"), hidden a few seconds, then played from memory on an empty
 * board with its coordinates, by clicking the start square then the arrival square. The
 * opponent's replies come as text and a flash of their squares; the moves played are listed.
 * The logic lives in {@see useBlindfoldPuzzle}; the page owns the API calls (through `resolve`),
 * gives the XP the server announced and the result sheet's buttons, and fills the `header` and
 * `result` slots. Noctis, the module's professor, talks and reacts (design "Animation Puzzle").
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import PlayLayout from '@/components/chess/PlayLayout.vue'
import ProfBubble from '@/components/feedback/ProfBubble.vue'
import ResultSheet from '@/components/feedback/ResultSheet.vue'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'
import { useBlindfoldPuzzle } from '@/composables/blindfold/useBlindfoldPuzzle'
import { useBoardPreferences } from '@/composables/chess/useBoardPreferences'
import { LEVELS } from '@/utils/blindfold'
import { numberedLine } from '@/utils/chess/lichess'
import {
  blindfoldCopy,
  blindfoldKind,
  feedbackProf,
  keyMove
} from '@/utils/feedback'
import { moveKind, playMoveSound } from '@/utils/chess/moveSounds'
import { playOutcomeSound } from '@/utils/sounds'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').RunItem>} the blindfold_puzzle item; a new one starts a new puzzle */
  item: { type: Object, required: true },
  /** The submission's `xp`: undefined until it answered. */
  xp: { type: [Number, null], default: undefined },
  /** @type {import('vue').PropType<import('@/components/feedback/ResultSheet.vue').SheetAction[]>} the result sheet's buttons */
  actions: { type: Array, default: () => [] }
})

const emit = defineEmits({
  /** The outcome is known: submit `report`. */
  resolve: (status, report) =>
    typeof status === 'string' && Array.isArray(report?.moves),
  /** The final position is on the board. */
  complete: null
})

const PROMOTIONS = [
  { piece: 'q', label: 'Dame' },
  { piece: 'r', label: 'Tour' },
  { piece: 'b', label: 'Fou' },
  { piece: 'n', label: 'Cavalier' }
]

const { soundOn } = useBoardPreferences()
const timeline = useFeedbackTimeline()
/** From the position shown to the end: memorizing counts. */
let loadedAt = 0
const durationMs = ref(/** @type {number|null} */ (null))
const game = useBlindfoldPuzzle({
  onResolve: (status, report) => emit('resolve', status, report),
  onComplete: status => {
    durationMs.value = loadedAt ? Date.now() - loadedAt : null
    const kind = blindfoldKind(status)
    playOutcomeSound(kind === 'miss' ? 'puzzleMissed' : 'puzzleDone')
    timeline.play(kind)
    emit('complete')
  }
})

const copy = computed(() =>
  blindfoldCopy(timeline.kind.value ?? 'win', {
    move: game.puzzle.value ? keyMove(game.puzzle.value) : '',
    durationMs: durationMs.value
  })
)

const reacting = computed(
  () => timeline.kind.value !== null && timeline.step.value >= 2
)

const prof = computed(() =>
  feedbackProf('blindfold', reacting.value ? timeline.kind.value : null)
)

/** What the professor says: the phase's instruction, then the reaction to the verdict. */
const bubble = computed(() => {
  if (reacting.value)
    return { kicker: copy.value.kicker, text: copy.value.bubble }
  const kicker =
    game.phase.value === 'show'
      ? game.peeking.value
        ? 'COUP D’ŒIL'
        : 'MÉMORISE'
      : game.phase.value === 'hidden'
        ? 'VISUALISE'
        : 'À TOI'
  return { kicker, text: statusText.value }
})

const side = computed(() =>
  game.orientation.value === 'white' ? 'les Blancs' : 'les Noirs'
)

const statusText = computed(() => {
  switch (game.phase.value) {
    case 'intro':
      return 'Au tour de l’adversaire…'
    case 'show':
      return game.peeking.value
        ? 'Coup d’œil : la position actuelle. Mémorise-la !'
        : `Mémorise la position : ${side.value} jouent.`
    case 'hidden':
      return 'Visualise la position…'
    case 'play':
      return game.selected.value
        ? `Départ ${game.selected.value} : clique la case d’arrivée.`
        : 'À toi : clique la case de départ, puis la case d’arrivée.'
    case 'reply':
      return 'L’adversaire répond…'
    case 'solution':
      return 'Solution…'
    case 'complete':
      return game.status.value === 'solved'
        ? 'Résolu de mémoire, bravo !'
        : game.status.value === 'helped'
          ? 'Résolu avec un coup d’œil.'
          : 'Puzzle raté : voici la solution.'
    default:
      return ''
  }
})

/** The moves played since the position shown first, numbered: "23…Qxe4 24.Rd8+". */
const line = computed(() => {
  const data = game.puzzle.value
  if (!data || !game.history.value.length) return ''
  const [, turn, , , , number] = data.fen.split(' ')
  const depth = (Number(number) - 1) * 2 + (turn === 'b' ? 1 : 0)
  return numberedLine(game.history.value, {
    turn: turn === 'b' ? 'b' : 'w',
    depth
  })
})

/** The level, the time to memorize and the peeks left, above the board. */
const infoChips = computed(() => {
  const d = props.item.data
  const peeks = game.peeksLeft.value
  return [
    LEVELS[d.level] ?? d.level,
    `${d.visibleSeconds} s pour mémoriser`,
    peeks > 0
      ? `${peeks} coup${peeks > 1 ? 's' : ''} d’œil`
      : 'plus de coup d’œil'
  ]
})

const canGiveUp = computed(() =>
  ['show', 'hidden', 'play', 'reply'].includes(game.phase.value)
)

// The board stays empty while the player plays: the moves are heard, not seen.
watch(
  () => game.fen.value,
  (after, before) => {
    if (game.visible.value || !soundOn.value || !before) return
    const kind = moveKind(before, after)
    if (kind) playMoveSound(kind)
  }
)

watch(
  () => props.item.id,
  () => {
    timeline.reset()
    loadedAt = Date.now()
    durationMs.value = null
    const d = props.item.data
    game.load(d.puzzle, {
      visibleSeconds: d.visibleSeconds,
      hiddenSeconds: d.hiddenSeconds,
      peeks: d.peeks
    })
  },
  { immediate: true }
)

onBeforeUnmount(() => game.dispose())
</script>

<style scoped lang="scss">
.blind-player__board {
  position: relative;
  user-select: none;
}

// The pause, position hidden: the board is covered.
.blind-player__veil {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: var(--cm-ink);
  color: var(--cm-page);
  border-radius: 4px;
  opacity: 0.92;
}

.blind-player__veil-label {
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.blind-player__veil-count {
  font-family: var(--cm-heading);
  font-size: 72px;
  font-weight: 800;
  line-height: 1.1;
}

.blind-player__count {
  flex: none;
  align-self: center;
  font-family: var(--cm-heading);
  font-size: 32px;
  font-weight: 800;
  min-width: 2.2em;
  text-align: center;
}

.blind-player__message {
  font-weight: 600;

  &--success {
    color: var(--cm-success);
  }

  &--error {
    color: var(--cm-danger);
  }

  &--info {
    color: var(--cm-ink);
  }
}

.blind-player__line {
  font-family: monospace;
  color: var(--cm-muted);
}
</style>
