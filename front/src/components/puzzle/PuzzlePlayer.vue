<template>
  <div class="puzzle-player">
    <div class="puzzle-player__board">
      <ChessBoard
        v-if="game.puzzle.value"
        ref="board"
        :fen="game.fen.value"
        :orientation="game.orientation.value"
        :movable-color="game.movableColor.value"
        :highlights="game.highlights.value"
        :arrows="game.arrows.value"
        @move="onMove"
      />
      <div v-else-if="loading" class="flex flex-center q-pa-xl">
        <q-spinner size="3em" />
      </div>
    </div>

    <div class="puzzle-player__panel column q-gutter-md">
      <slot name="header" />

      <template v-if="puzzle">
        <ProfBubble
          v-if="feedback"
          :prof="prof"
          :kicker="bubble.kicker"
          :text="bubble.text"
          :kind="timeline.kind.value"
          :step="timeline.step.value"
          :hint="game.hintShown.value > 0 && game.phase.value === 'playing'"
          text-testid="puzzle-status"
        />
        <div v-else class="text-subtitle1" data-testid="puzzle-status">{{
          statusText
        }}</div>
        <slot name="info" />

        <div v-if="game.phase.value !== 'complete'" class="row q-gutter-sm">
          <q-btn
            outline
            no-caps
            icon="lightbulb"
            :label="game.hintShown.value === 0 ? 'Indice' : 'Indice suivant'"
            :disable="
              game.phase.value !== 'playing' || game.hintShown.value >= 2
            "
            data-testid="puzzle-hint"
            @click="game.hint()"
          />
          <q-btn
            outline
            no-caps
            icon="visibility"
            label="Voir la solution"
            :disable="game.phase.value === 'idle' || game.solutionShown.value"
            data-testid="puzzle-solution"
            @click="game.showSolution()"
          />
        </div>

        <div v-else data-testid="puzzle-result">
          <ResultSheet
            v-if="feedback && timeline.kind.value"
            :kind="timeline.kind.value"
            :step="timeline.step.value"
            :run="timeline.run.value"
            :title="copy.title"
            :sub="copy.sub"
            :xp="xp"
            :actions="actions"
          >
            <slot
              name="result"
              :kind="timeline.kind.value"
              :failed="game.failed.value"
            />
          </ResultSheet>
          <slot
            v-else-if="!feedback"
            name="result"
            :kind="timeline.kind.value"
            :failed="game.failed.value"
          />
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
/**
 * One puzzle being played: the board, the module's professor talking (instruction, hint, then
 * their reaction), hint and solution buttons, and the result sheet at the end (design "Animation
 * Puzzle"). Shared by the rated puzzles, Woodpecker and the timed runs; the page owns the API
 * calls (through `resolve`), gives the XP and rating change the server announced and the sheet's
 * buttons, and fills the `header`, `info` and `result` slots.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import ProfBubble from '@/components/feedback/ProfBubble.vue'
import ResultSheet from '@/components/feedback/ResultSheet.vue'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'
import { usePuzzle } from '@/composables/puzzle/usePuzzle'
import { usePuzzleStore } from '@/stores/puzzle'
import {
  feedbackProf,
  keyMove,
  motifTheme,
  puzzleCopy,
  puzzleKind
} from '@/utils/feedback'
import { playOutcomeSound } from '@/utils/sounds'

const props = defineProps({
  /** @type {import('vue').PropType<{fen: string, moves: string[], playerColor: 'white'|'black', themes?: string[]}|null>} A new object starts a new game. */
  puzzle: { type: Object, default: null },
  /** After a wrong move: keep searching, or play the solution at once (one try). */
  afterMistake: { type: String, default: 'continue' },
  loading: { type: Boolean, default: false },
  /** The API's module: its professor talks (puzzles, woodpecker). */
  module: { type: String, default: 'puzzles' },
  /** The submission's `xp`: undefined until it answered. */
  xp: { type: [Number, null], default: undefined },
  /** Rated puzzles: the rating change the server computed. */
  ratingDelta: { type: [Number, null], default: null },
  /** @type {import('vue').PropType<import('@/components/feedback/ResultSheet.vue').SheetAction[]>} the result sheet's buttons */
  actions: { type: Array, default: () => [] },
  /** The professor and the result sheet; false: a plain status line and the `result` slot (a replay nothing records). */
  feedback: { type: Boolean, default: true }
})

const emit = defineEmits({
  /** The outcome is known (first mistake, hint, solution, or clean end): submit `report`. */
  resolve: (outcome, report) =>
    typeof outcome === 'string' && Array.isArray(report?.moves),
  /** The final position is on the board. */
  complete: null
})

const board = ref(null)
const timeline = useFeedbackTimeline()
const puzzles = usePuzzleStore()
/** When the player could first move, and how long they took to the end. */
let startedAt = 0
const durationMs = ref(/** @type {number|null} */ (null))

const game = usePuzzle({
  afterMistake: props.afterMistake,
  onResolve: (outcome, report) => emit('resolve', outcome, report),
  onComplete: () => {
    durationMs.value = startedAt ? Date.now() - startedAt : null
    const kind = puzzleKind({
      mistaken: game.mistaken.value,
      solutionShown: game.solutionShown.value,
      hinted: game.hintLevel.value > 0
    })
    playOutcomeSound(kind === 'miss' ? 'puzzleMissed' : 'puzzleDone')
    timeline.play(kind)
    emit('complete')
  }
})

const statusText = computed(() => {
  const side = game.orientation.value === 'white' ? 'les Blancs' : 'les Noirs'
  switch (game.phase.value) {
    case 'intro':
      return game.solutionShown.value ? 'Solution…' : 'Au tour de l’adversaire…'
    case 'playing':
      return game.mistaken.value
        ? 'Ce n’est pas le bon coup. Cherche encore !'
        : `Trouve le meilleur coup pour ${side}.`
    case 'complete':
      return game.failed.value ? 'Puzzle terminé.' : 'Bravo !'
    default:
      return ''
  }
})

/** The motif's French label, once the themes are known (none for an unknown theme). */
const motif = computed(() => {
  const key = motifTheme(props.puzzle?.themes)
  return key ? (puzzles.themes.find(t => t.key === key)?.labelFr ?? null) : null
})

/** The verdict's words, once the animation reached the title. */
const copy = computed(() =>
  puzzleCopy(timeline.kind.value ?? 'win', {
    move: props.puzzle ? keyMove(props.puzzle) : '',
    motif: motif.value,
    durationMs: durationMs.value,
    ratingDelta: props.ratingDelta
  })
)

const reacting = computed(
  () => timeline.kind.value !== null && timeline.step.value >= 2
)

const prof = computed(() =>
  feedbackProf(props.module, reacting.value ? timeline.kind.value : null)
)

const bubble = computed(() => {
  if (reacting.value)
    return { kicker: copy.value.kicker, text: copy.value.bubble }
  if (game.phase.value === 'playing' && game.hintShown.value > 0) {
    return {
      kicker: 'INDICE',
      text:
        game.hintShown.value === 1
          ? 'Indice : la pièce à jouer est en surbrillance.'
          : 'Indice : suis la flèche.'
    }
  }
  return { kicker: 'À TOI', text: statusText.value }
})

watch(
  () => game.phase.value,
  phase => {
    if (phase === 'playing' && !startedAt) startedAt = Date.now()
  }
)

/** @param {{uci: string}} move */
async function onMove(move) {
  const verdict = game.play(move.uci)
  if (verdict === 'correct') return
  if (verdict === 'wrong') await board.value?.shake()
  // Back to the position before the move (the board already shows it played).
  await board.value?.setPosition(game.fen.value, true)
}

watch(
  () => props.puzzle,
  puzzle => {
    game.dispose()
    timeline.reset()
    startedAt = 0
    durationMs.value = null
    if (puzzle) game.load(puzzle)
  },
  { immediate: true }
)

onMounted(() => puzzles.fetchThemes().catch(() => null))

onBeforeUnmount(() => game.dispose())
</script>

<style scoped>
.puzzle-player {
  display: grid;
  gap: 24px;
  grid-template-columns: minmax(0, 560px) minmax(260px, 1fr);
  align-items: start;
  max-width: 1000px;
  margin: 0 auto;
}
@media (max-width: 800px) {
  .puzzle-player {
    grid-template-columns: 1fr;
  }
}
</style>
