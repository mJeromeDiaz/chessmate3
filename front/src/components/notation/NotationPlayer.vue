<template>
  <PlayLayout
    class="notation-player"
    data-testid="notation-player"
    :data-kind="kind"
    :data-state="state"
    :data-played="played.length"
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
        text-testid="notation-status"
      />
    </template>

    <template #chips>
      <span
        v-if="kind === 'play'"
        class="play-chip notation-player__announced"
        data-testid="notation-announced"
        >{{ announcedSan }}</span
      >
      <span class="play-chip">{{
        $t('notation.player.' + (color === 'black' ? 'black' : 'white'))
      }}</span>
    </template>

    <template #board>
      <ChessBoard
        :fen="correction && correctionView ? correctionView.fen : fen"
        :orientation="orientation"
        :movable-color="movableColor"
        :highlights="
          correction && correctionView ? correctionView.highlights : highlights
        "
        :arrows="correction && correctionView ? correctionView.arrows : arrows"
        @move="onMove"
      />
    </template>

    <template #controls>
      <div
        v-if="isSequence"
        class="notation-player__line"
        data-testid="notation-sequence"
      >
        <span
          v-for="(m, i) in sequenceLine"
          :key="i"
          class="notation-player__move"
          :class="{
            'notation-player__move--done': m.done,
            'notation-player__move--now': m.now,
            'notation-player__move--wrong': m.wrong
          }"
          >{{ m.text }}</span
        >
      </div>

      <div
        v-if="kind === 'line' || isGame"
        class="notation-player__shown"
        data-testid="notation-shown"
      >
        <span class="notation-player__shown-label">{{
          item.data.label || $t('notation.player.lineStart')
        }}</span>
        <span v-if="isGame">{{ chunkRange }}</span>
        <span v-else>{{ shownLine }}</span>
      </div>

      <q-btn
        v-if="kind === 'game_write' && !watching && !answered"
        flat
        no-caps
        icon="replay"
        :label="$t('notation.player.replay')"
        data-testid="notation-replay"
        @click="showMoves"
      />

      <NotationCorrection
        v-if="correction"
        :correction="correction"
        :expected="result?.expected ?? []"
        @board="view => (correctionView = view)"
      />

      <form
        v-else-if="isWritten && !watching"
        class="notation-player__write"
        data-testid="notation-write"
        @submit.prevent="send"
      >
        <q-input
          ref="input"
          v-model="written"
          outlined
          dense
          autocomplete="off"
          autocapitalize="off"
          spellcheck="false"
          :disable="answered"
          :placeholder="placeholder"
          data-testid="notation-input"
        />
        <div class="notation-player__keys">
          <button
            v-for="key in keys"
            :key="key"
            type="button"
            class="notation-player__key"
            :disabled="answered"
            :data-testid="`notation-key-${key}`"
            @click="type(key)"
            >{{ key }}</button
          >
          <button
            type="button"
            class="notation-player__key"
            :disabled="answered || !written"
            :aria-label="$t('notation.player.erase')"
            @click="erase"
            >⌫</button
          >
        </div>
        <q-btn
          type="submit"
          unelevated
          no-caps
          color="primary"
          :label="$t('notation.player.send')"
          :disable="answered || !written.trim()"
          data-testid="notation-send"
        />
      </form>
    </template>

    <div v-if="result" data-testid="notation-result">
      <ResultSheet
        :kind="timeline.kind.value ?? (right ? 'win' : 'miss')"
        :step="timeline.step.value"
        :run="timeline.run.value"
        :title="copy.title"
        :sub="copy.sub"
        :xp="xp"
        :actions="actions"
        :confetti="right"
      />
    </div>
  </PlayLayout>
</template>

<script setup>
/**
 * An item of the notation module (docs/NOTATION.md), by its exercise: a move announced, to play on
 * the board; a sequence of moves announced, to play for both sides (each move judged at once, the
 * first wrong one ends it and its right move is shown); a puzzle to solve in one's head, its whole
 * line written in the field or with the keys of the pieces, never played; a line of the player's
 * repertoire played up to its last half-moves, to write the same way; a legendary game, 6
 * half-moves at a time: read, then played (as a sequence), or shown played, then written. Everything the player
 * reads and types is in the interface's language (utils/notation.js); the server judges again.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Chess } from 'chess.js'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import NotationCorrection from '@/components/notation/NotationCorrection.vue'
import PlayLayout from '@/components/chess/PlayLayout.vue'
import ProfBubble from '@/components/feedback/ProfBubble.vue'
import ResultSheet from '@/components/feedback/ResultSheet.vue'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'
import { feedbackProf } from '@/utils/feedback'
import {
  GAMES,
  SEQUENCES,
  WRITTEN,
  correctionRows,
  englishSan,
  localSan,
  numberedLine,
  pieceLetters,
  writtenMistake
} from '@/utils/notation'
import { playOutcomeSound } from '@/utils/sounds'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').RunItem>} the notation item; a new one starts a new exercise */
  item: { type: Object, required: true },
  /** @type {import('vue').PropType<Record<string, any>|null>} the server's verdict on this item, once sent */
  result: { type: Object, default: null },
  /** The XP of the answer (undefined while the server has not answered). */
  xp: { type: [Number, null], default: undefined },
  /** @type {import('vue').PropType<import('@/components/feedback/ResultSheet.vue').SheetAction[]>} the result sheet's buttons */
  actions: { type: Array, default: () => [] }
})

const emit = defineEmits({
  /** The answer: `{moves}` (UCI) or `{written}` (SAN, English letters). */
  resolve: payload => typeof payload === 'object'
})

/** Where a repertoire line starts. */
const INITIAL_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'

const timeline = useFeedbackTimeline()

/** The position on the board. */
const fen = ref('')
/** @type {import('vue').Ref<string[]>} the moves played (UCI), the wrong one included */
const played = ref([])
const written = ref('')
/** Sent: waiting for the verdict, or judged. */
const answered = ref(false)
/** The position before the last move played: back to it when that move was wrong (the arrow then shows the right one). */
const before = ref('')
/** @type {import('vue').Ref<any>} */
const input = ref(null)

const kind = computed(() => props.item.data.kind)
/** Answered in writing (a puzzle's line, a repertoire line's end), not on the board. */
const isWritten = computed(() => WRITTEN.includes(kind.value))
/** Played as a sequence of moves read (a puzzle's solution, a legendary game's chunk). */
const isSequence = computed(() => SEQUENCES.includes(kind.value))
const isGame = computed(() => GAMES.includes(kind.value))
/** A legendary game's chunk being shown played (game_write), before writing it. */
const watching = ref(false)
/** The move just shown played. */
const lastShown = ref(/** @type {string|null} */ (null))
/** Between two moves shown played. */
const WATCH_MS = 900
/** @type {ReturnType<typeof setTimeout>|undefined} */
let watchTimer
/** A legendary game's chunk: « Coups 7 à 12 » (full moves). */
const chunkRange = computed(() => {
  const from = (props.item.data.shown ?? []).length
  const to = from + (props.item.data.plies ?? 0)
  return t('notation.player.gameFrom', {
    from: Math.floor(from / 2) + 1,
    to: Math.floor((to - 1) / 2) + 1
  })
})
/** A repertoire line or a legendary game: the side chosen at the bottom; otherwise the side to move. */
const orientation = computed(() =>
  kind.value === 'line' || isGame.value
    ? props.item.data.orientation === 'black'
      ? 'black'
      : 'white'
    : color.value
)
/** A repertoire line's moves played so far, numbered from the start. */
const shownLine = computed(() =>
  numberedLine(
    (props.item.data.shown ?? []).map(
      (/** @type {{san: string}} */ m) => m.san
    ),
    INITIAL_FEN
  )
)
const color = computed(() =>
  props.item.data.color === 'black' ? 'black' : 'white'
)
/** @type {import('vue').ComputedRef<import('@/utils/notation').SanMove[]>} */
const expectedMoves = computed(() => props.item.data.moves ?? [])
const announcedSan = computed(() =>
  props.item.data.announced ? localSan(props.item.data.announced.san) : ''
)
const right = computed(() => props.result?.status === 'right')
const state = computed(() =>
  props.result ? props.result.status : answered.value ? 'sending' : 'answering'
)

/** The side to move on the board, null once answered or for a line to write. */
const movableColor = computed(() => {
  if (answered.value || isWritten.value) return null
  return new Chess(fen.value).turn() === 'w' ? 'white' : 'black'
})

/** The sequence, numbered: the moves played, the one to play, the wrong one. */
const sequenceLine = computed(() => {
  const moves = expectedMoves.value
  const line = numberedLine(
    moves.map(m => m.san),
    props.item.data.fen
  ).split(' ')
  // Each move follows its number (and "n…" before a first black move): map tokens to moves.
  let index = -1
  return line.map(text => {
    const isNumber = /^\d+[.…]$/.test(text)
    if (!isNumber) index++
    const mistake = props.result?.mistakeAt ?? null
    return {
      text,
      done: !isNumber && index < played.value.length && index !== mistake,
      now: !isNumber && !answered.value && index === played.value.length,
      wrong: !isNumber && mistake !== null && index === mistake
    }
  })
})

/** The wrong move in red, the right one as an arrow, once judged; the move shown played. */
const highlights = computed(() => {
  if (watching.value && lastShown.value)
    return [
      { square: lastShown.value.slice(0, 2), type: 'lastMove' },
      { square: lastShown.value.slice(2, 4), type: 'lastMove' }
    ]
  const r = props.result
  const wrong = r && r.mistakeAt !== null ? played.value[r.mistakeAt] : null
  if (!wrong || isWritten.value) return []
  return [
    { square: wrong.slice(0, 2), type: 'error' },
    { square: wrong.slice(2, 4), type: 'error' }
  ]
})
const arrows = computed(() => {
  const r = props.result
  if (!r || r.status === 'right' || isWritten.value) return []
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

const keys = computed(() => [
  ...pieceLetters(),
  'x',
  '+',
  '#',
  '=',
  'O-O',
  'O-O-O'
])
const placeholder = computed(() =>
  t('notation.player.placeholder', { example: localSan('Nf3 Nc6') })
)

const prof = computed(() =>
  feedbackProf(
    'notation',
    timeline.kind.value !== null && timeline.step.value >= 2
      ? timeline.kind.value
      : null
  )
)

/** What the professor says: the instruction, then the verdict. */
const bubble = computed(() => {
  if (props.result)
    return {
      kicker: t(`notation.player.kickers.${right.value ? 'right' : 'wrong'}`),
      text: right.value
        ? t('notation.player.rightText')
        : t('notation.player.wrongText')
    }
  const side = t(`notation.player.${color.value}`)
  return {
    kicker: t(`notation.player.kickers.${kind.value}`),
    text:
      kind.value === 'play'
        ? t('notation.player.playText', { san: announcedSan.value })
        : kind.value === 'sequence'
          ? t('notation.player.sequenceText')
          : isGame.value
            ? t(
                kind.value === 'game_play'
                  ? 'notation.player.gamePlayText'
                  : watching.value
                    ? 'notation.player.gameWatchText'
                    : 'notation.player.gameWriteText',
                {
                  label: props.item.data.label ?? '',
                  n: props.item.data.plies ?? 0
                }
              )
            : kind.value === 'line'
              ? t(
                  'notation.player.lineText',
                  {
                    line: props.item.data.label || shownLine.value,
                    n: props.item.data.plies ?? 0
                  },
                  props.item.data.plies ?? 0
                )
              : t(
                  'notation.player.writeText',
                  { side, n: props.item.data.plies ?? 0 },
                  props.item.data.plies ?? 0
                )
  }
})

/**
 * A wrong written line beside the right one, the board stepping through the solution
 * (NotationCorrection); null otherwise.
 */
const correction = computed(() => {
  const r = props.result
  if (!r || r.status === 'right' || !isWritten.value) return null
  return correctionRows(
    props.item.data.fen,
    r.expected ?? [],
    r.answer ?? [],
    r.mistakeAt ?? null,
    r.reason ?? null
  )
})
/** @type {import('vue').Ref<ReturnType<typeof import('@/utils/notation').correctionBoard>|null>} what the correction shows on the board */
const correctionView = ref(null)

/** The sheet: the verdict, and the right answer when wrong (a written line's in its correction). */
const copy = computed(() => {
  const r = props.result
  if (!r) return { title: '', sub: '' }
  const start = props.item.data.fen
  const line = numberedLine(
    (r.expected ?? []).map((/** @type {{san: string}} */ m) => m.san),
    start
  )
  if (r.status === 'right')
    return { title: t('notation.player.kickers.right'), sub: line }
  if (kind.value === 'play')
    return {
      title: t('notation.player.kickers.wrong'),
      sub: t('notation.player.wasPlay', {
        san: localSan(r.expected?.[0]?.san ?? '')
      })
    }
  if (isSequence.value)
    return {
      title: t('notation.player.kickers.wrong'),
      sub: t('notation.player.mistakeAt', {
        n: (r.mistakeAt ?? 0) + 1,
        san: localSan(r.expected?.[r.mistakeAt ?? 0]?.san ?? '')
      })
    }
  return {
    title: t('notation.player.kickers.wrong'),
    sub: writtenMistake(r.reason, r.mistakeAt)
  }
})

/** @param {{uci: string}} move */
function onMove(move) {
  if (answered.value) return
  before.value = fen.value
  if (kind.value === 'play') {
    played.value = [move.uci]
    fen.value = after(fen.value, move.uci)
    answer({ moves: [move.uci] })
    return
  }
  const expected = expectedMoves.value[played.value.length]
  played.value = [...played.value, move.uci]
  fen.value = after(fen.value, move.uci)
  // A wrong move ends the sequence (its verdict puts the board back before it).
  if (
    move.uci !== expected?.uci ||
    played.value.length === expectedMoves.value.length
  )
    answer({ moves: played.value })
}

function send() {
  if (answered.value || !written.value.trim()) return
  answer({ written: [englishSan(written.value.trim())] })
}

/** @param {Record<string, any>} report */
function answer(report) {
  answered.value = true
  emit('resolve', report)
}

/** @param {string} key a piece letter, a mark, a castling */
function type(key) {
  const text = written.value
  const glue = /^[A-Z]|^O-O/.test(key) && text && !/\s$/.test(text) ? ' ' : ''
  written.value = `${text}${glue}${key}`
  input.value?.focus?.()
}

function erase() {
  written.value = written.value.replace(/\s*\S$/, '')
}

/**
 * @param {string} position
 * @param {string} uci
 */
function after(position, uci) {
  const chess = new Chess(position)
  chess.move({
    from: uci.slice(0, 2),
    to: uci.slice(2, 4),
    promotion: uci[4]
  })
  return chess.fen()
}

/** A legendary game's chunk played on the board, a move at a time, then back to its start to write it. */
function showMoves() {
  clearTimeout(watchTimer)
  watching.value = true
  fen.value = props.item.data.fen
  lastShown.value = null
  const moves = expectedMoves.value
  let i = 0
  const step = () => {
    if (i < moves.length) {
      fen.value = after(fen.value, moves[i].uci)
      lastShown.value = moves[i].uci
      i++
      watchTimer = setTimeout(step, WATCH_MS)
      return
    }
    watchTimer = setTimeout(() => {
      fen.value = props.item.data.fen
      lastShown.value = null
      watching.value = false
    }, WATCH_MS)
  }
  watchTimer = setTimeout(step, WATCH_MS)
}

/** Lets the player answer again (a refused submission). */
function unlock() {
  answered.value = false
}

watch(
  () => props.item.id,
  () => {
    timeline.reset()
    fen.value = props.item.data.fen
    played.value = []
    correctionView.value = null
    before.value = ''
    written.value = ''
    answered.value = false
    clearTimeout(watchTimer)
    watching.value = false
    lastShown.value = null
    if (props.item.data.kind === 'game_write') showMoves()
  },
  { immediate: true }
)

watch(
  () => props.result,
  value => {
    if (!value) return
    const win = value.status === 'right'
    // Wrong on the board: back to the position before the move, the right one shown as an arrow.
    if (!win && !isWritten.value && before.value) fen.value = before.value
    playOutcomeSound(win ? 'puzzleDone' : 'puzzleMissed')
    timeline.play(win ? 'win' : 'miss')
  }
)

onBeforeUnmount(() => {
  timeline.reset()
  clearTimeout(watchTimer)
})

defineExpose({ unlock })
</script>

<style scoped lang="scss">
.notation-player__announced {
  font-family: var(--cm-heading);
  font-size: 22px;
  font-weight: 800;
}

.notation-player__line {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  font-family: var(--cm-heading);
  font-size: 18px;
  font-weight: 700;
  color: var(--cm-muted);
}

.notation-player__move {
  padding: 2px 6px;
  border-radius: 8px;

  &--done {
    color: var(--cm-success);
  }

  &--now {
    background: var(--cm-brand-soft);
    color: var(--cm-brand-deep);
  }

  &--wrong {
    background: var(--cm-danger-soft);
    color: var(--cm-danger);
  }
}

.notation-player__shown {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-family: var(--cm-heading);
  font-size: 17px;
  font-weight: 700;
  color: var(--cm-ink);
}

.notation-player__shown-label {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.notation-player__write {
  display: flex;
  flex-direction: column;
  gap: 10px;
  width: 100%;
}

.notation-player__keys {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.notation-player__key {
  min-width: 44px;
  height: 44px;
  padding: 0 10px;
  border: none;
  border-radius: 12px;
  background: var(--cm-surface);
  box-shadow:
    inset 0 0 0 2px var(--cm-line),
    0 3px 0 var(--cm-dash);
  color: var(--cm-ink);
  font-family: var(--cm-heading);
  font-size: 18px;
  font-weight: 800;
  cursor: pointer;

  &:disabled {
    opacity: 0.45;
    cursor: default;
  }
}
</style>
