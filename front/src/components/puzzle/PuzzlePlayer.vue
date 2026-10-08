<template>
  <PlayLayout class="puzzle-player" :stacked="stacked">
    <template v-if="$slots.header" #header>
      <slot name="header" />
    </template>

    <template v-if="puzzle" #prof>
      <ProfBubble
        v-if="feedback"
        large
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
    </template>

    <template v-if="puzzle && $slots.chips" #chips>
      <slot name="chips" />
    </template>

    <template #board>
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
    </template>

    <template v-if="puzzle && game.phase.value !== 'complete'" #controls>
      <q-btn
        unelevated
        no-caps
        class="play-btn play-btn--hint"
        :class="{ 'play-btn--hint-used': game.hintLevel.value > 0 }"
        label="Aide"
        :disable="game.phase.value !== 'playing' || game.hintShown.value >= 2"
        data-testid="puzzle-hint"
        @click="game.hint()"
      />
      <q-btn
        unelevated
        no-caps
        class="play-btn play-btn--solution"
        label="Solution"
        :disable="game.phase.value === 'idle' || game.solutionShown.value"
        data-testid="puzzle-solution"
        @click="game.showSolution()"
      />
      <q-btn
        v-if="skippable"
        unelevated
        no-caps
        class="play-btn play-btn--skip"
        label="Passer"
        :disable="game.phase.value !== 'playing'"
        data-testid="puzzle-skip"
        @click="confirmSkip"
      />
      <q-btn
        v-if="replaceable"
        unelevated
        class="play-btn play-btn--icon"
        icon="swap_horiz"
        aria-label="Remplacer ce puzzle"
        :disable="game.phase.value === 'idle'"
        data-testid="puzzle-replace"
        @click="confirmReplace"
      >
        <q-tooltip>Remplacer ce puzzle</q-tooltip>
      </q-btn>
      <q-btn
        v-if="withSettings"
        unelevated
        class="play-btn play-btn--icon"
        icon="tune"
        aria-label="Réglages des puzzles"
        data-testid="puzzle-settings"
        @click="emit('settings')"
      >
        <q-badge v-if="settingsActive" floating rounded color="primary" />
      </q-btn>
    </template>

    <div
      v-if="puzzle && game.phase.value === 'complete'"
      data-testid="puzzle-result"
    >
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

    <template v-if="$slots.footer" #footer>
      <slot name="footer" />
    </template>
  </PlayLayout>
</template>

<script setup>
/**
 * One puzzle being played (design "Animation Puzzle", PlayLayout): the module's professor talking
 * (instruction, hint, then their reaction), the board, the Aide / Solution buttons (Passer, the
 * settings and replace icons when the page asks), and the result sheet at the end. Shared by the
 * rated puzzles, Woodpecker, the timed runs and the replay at a run's end; the page owns the API
 * calls (through `resolve`), gives the XP and rating change the server announced and the sheet's
 * buttons, and fills the `header`, `chips`, `result` and `footer` slots.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import PlayLayout from '@/components/chess/PlayLayout.vue'
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
  feedback: { type: Boolean, default: true },
  /** One column whatever the screen (a narrow side column). */
  stacked: { type: Boolean, default: false },
  /** Free play: "Passer" gives up (a failure, after confirmation) and asks for the next puzzle. */
  skippable: { type: Boolean, default: false },
  /** Woodpecker: swap the puzzle for another of the set's profile (after confirmation). */
  replaceable: { type: Boolean, default: false },
  /** Free play: the settings icon (themes, difficulty). */
  withSettings: { type: Boolean, default: false },
  /** A dot on the settings icon: filters other than the defaults. */
  settingsActive: { type: Boolean, default: false }
})

const emit = defineEmits({
  /** The outcome is known (first mistake, hint, solution, or clean end): submit `report`. */
  resolve: (outcome, report) =>
    typeof outcome === 'string' && Array.isArray(report?.moves),
  /** The final position is on the board. */
  complete: null,
  /** "Passer" confirmed: the failure is reported through `resolve`, show the next puzzle. */
  skip: null,
  /** The settings icon. */
  settings: null,
  /** "Remplacer" confirmed: the page swaps the puzzle and loads the new one. */
  replace: null
})

const $q = useQuasar()

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

/** "Passer" counts as a failure: say so before giving up. */
function confirmSkip() {
  $q.dialog({
    title: 'Passer ce puzzle ?',
    message: 'Passer compte comme un échec.',
    cancel: { label: 'Annuler', flat: true, noCaps: true },
    ok: { label: 'Passer', unelevated: true, noCaps: true },
    persistent: false
  }).onOk(() => {
    if (game.phase.value !== 'playing') return
    game.skip()
    emit('skip')
  })
}

/** The puzzle leaves the set for good: say so before. */
function confirmReplace() {
  $q.dialog({
    title: 'Remplacer ce puzzle ?',
    message:
      'Il quitte le set pour de bon : un autre puzzle du même niveau prend sa place.',
    cancel: { label: 'Annuler', flat: true, noCaps: true },
    ok: { label: 'Remplacer', unelevated: true, noCaps: true }
  }).onOk(() => emit('replace'))
}

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
