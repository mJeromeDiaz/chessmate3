<template>
  <div class="game-review" data-testid="arena-review">
    <div class="game-review__board">
      <MaterialLine
        class="game-review__material"
        :side="material[bossSide]"
        :piece-color="game.color.charAt(0)"
        testid="arena-review-material-boss"
      />
      <ChessBoard
        :fen="shown.fen"
        :orientation="game.color"
        :highlights="highlights"
        :arrows="arrows"
        :glyphs="glyphs"
      />
      <MaterialLine
        class="game-review__material"
        :side="material[game.color]"
        :piece-color="bossSide.charAt(0)"
        testid="arena-review-material-player"
      />
      <div class="game-review__steps">
        <button
          v-for="step in STEPS"
          :key="step.id"
          type="button"
          class="game-review__step"
          :aria-label="$t(`arena.review.steps.${step.id}`)"
          :data-testid="`arena-review-${step.id}`"
          :disabled="step.forward ? cursor >= last : cursor <= 0"
          @click="go(step.to())"
        >
          <q-icon :name="step.icon" size="32px" />
        </button>
      </div>
    </div>

    <div class="game-review__side">
      <div class="game-review__head">
        <button
          type="button"
          class="game-review__back"
          data-testid="arena-review-back"
          @click="emit('back')"
        >
          ← {{ $t('arena.review.back') }}
        </button>
        <h2 class="game-review__title">{{ $t('arena.review.title') }}</h2>
      </div>

      <div
        v-if="review.state.value === 'running'"
        class="game-review__progress"
        data-testid="arena-review-progress"
      >
        <span>{{
          $t('arena.review.running', {
            percent: Math.round(review.progress.value * 100)
          })
        }}</span>
        <q-linear-progress
          :value="review.progress.value"
          rounded
          size="8px"
          color="primary"
        />
      </div>
      <p
        v-else-if="review.state.value === 'failed'"
        class="game-review__failed"
        role="alert"
      >
        {{ $t('arena.review.failed') }}
        <button type="button" class="game-review__link" @click="startReview">
          {{ $t('arena.review.retry') }}
        </button>
      </p>

      <svg
        v-if="review.curve.value.length > 1"
        class="game-review__curve"
        :viewBox="`0 0 ${CURVE_W} ${CURVE_H}`"
        preserveAspectRatio="none"
        role="img"
        :aria-label="$t('arena.review.curve')"
        data-testid="arena-review-curve"
      >
        <rect
          :width="CURVE_W"
          :height="CURVE_H"
          class="game-review__curve-ground"
        />
        <polygon :points="area" class="game-review__curve-area" />
        <line
          :x1="cursorX"
          :x2="cursorX"
          y1="0"
          :y2="CURVE_H"
          class="game-review__curve-cursor"
        />
      </svg>

      <div class="game-review__summary">
        <div
          v-for="side in sides"
          :key="side.id"
          class="game-review__who"
          :data-testid="`arena-review-summary-${side.who}`"
        >
          <div class="game-review__who-name">{{ side.name }}</div>
          <div class="game-review__counts">
            <span
              v-for="kind in KINDS"
              :key="kind"
              class="game-review__count"
              :class="`game-review__count--${kind}`"
              >{{ VERDICT_GLYPHS[kind] }} {{ side.counts[kind] }}</span
            >
          </div>
        </div>
      </div>
      <p
        v-if="current"
        class="game-review__verdict"
        data-testid="arena-review-verdict"
      >
        <b>{{ moveLabel(current) }}</b> ·
        {{ $t(`arena.review.verdicts.${current.verdict}`) }}
        <template v-if="bestSan">
          · {{ $t('arena.review.best', { move: bestSan }) }}</template
        >
      </p>

      <div class="game-review__moves" data-testid="arena-review-moves">
        <button
          v-for="move in moveRows"
          :key="move.ply"
          type="button"
          class="game-review__move"
          :class="[
            `game-review__move--${move.verdict}`,
            { 'game-review__move--on': cursor === move.ply + 1 }
          ]"
          @click="go(move.ply + 1)"
        >
          <span v-if="move.side === 'white'" class="game-review__move-n"
            >{{ move.ply / 2 + 1 }}.</span
          >{{ move.san
          }}<span class="game-review__glyph">{{
            VERDICT_GLYPHS[move.verdict]
          }}</span>
        </button>
      </div>

      <div class="game-review__pgn">
        <button
          type="button"
          class="game-review__pgn-btn"
          data-testid="arena-review-download"
          @click="download"
        >
          <q-icon name="download" size="18px" />
          {{ $t('arena.review.download') }}
        </button>
        <button
          type="button"
          class="game-review__pgn-btn"
          data-testid="arena-review-copy"
          @click="copy"
        >
          <q-icon name="content_copy" size="18px" />
          {{ $t('arena.review.copy') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * A game of the arena reviewed once over (docs/ARENA.md, § 6 quinquies): the board stepped move
 * by move (the buttons, the arrow keys), Stockfish's curve, each move's verdict (?! ? ??) and the
 * best move where it went wrong, the mistakes and the material of both sides; the game in PGN,
 * downloaded or copied.
 */
import { useQuasar } from 'quasar'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import MaterialLine from '@/components/chess/MaterialLine.vue'
import { useArenaReview } from '@/composables/arena/useArenaReview'
import { t } from '@/i18n'
import { downloadText } from '@/utils/download'
import { VERDICT_GLYPHS, pgnFileName, pgnOf } from '@/utils/arenaReview'
import { materialOf } from '@/utils/chess/material'
import { Chess } from 'chess.js'

const CURVE_W = 300
const CURVE_H = 80
const KINDS = /** @type {const} */ (['inaccuracy', 'mistake', 'blunder'])

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/arenaBoss').ArenaGame>} */
  game: { type: Object, required: true },
  bossName: { type: String, required: true },
  playerName: { type: String, required: true }
})

const emit = defineEmits(['back'])

const $q = useQuasar()
const review = useArenaReview()
/** The position shown: 0 the start, n after the n-th move. */
const cursor = ref(0)

const last = computed(() => Math.max(0, review.positions.value.length - 1))
const shown = computed(
  () =>
    review.positions.value[cursor.value] ?? {
      fen: new Chess().fen(),
      uci: null
    }
)
/** The move that reached the position shown, once judged. */
const current = computed(
  () => review.moves.value.find(move => move.ply === cursor.value - 1) ?? null
)
const moveRows = computed(() =>
  review.positions.value.slice(1).map((position, i) => {
    const judged = review.moves.value[i]
    return {
      ply: i,
      san: position.san ?? '',
      side: i % 2 === 0 ? 'white' : 'black',
      verdict: judged?.verdict ?? 'good'
    }
  })
)
const STEPS = [
  { id: 'first', icon: 'first_page', forward: false, to: () => 0 },
  {
    id: 'previous',
    icon: 'chevron_left',
    forward: false,
    to: () => cursor.value - 1
  },
  {
    id: 'next',
    icon: 'chevron_right',
    forward: true,
    to: () => cursor.value + 1
  },
  { id: 'last', icon: 'last_page', forward: true, to: () => last.value }
]
const highlights = computed(() => {
  const uci = shown.value.uci
  return uci
    ? [
        { square: uci.slice(0, 2), type: /** @type {const} */ ('lastMove') },
        { square: uci.slice(2, 4), type: /** @type {const} */ ('lastMove') }
      ]
    : []
})
const wrong = computed(
  () =>
    !!current.value &&
    ['inaccuracy', 'mistake', 'blunder'].includes(current.value.verdict)
)
const arrows = computed(() => {
  const best = current.value?.bestMove
  return wrong.value && best
    ? [
        {
          from: best.slice(0, 2),
          to: best.slice(2, 4),
          type: /** @type {const} */ ('solution')
        }
      ]
    : []
})
const glyphs = computed(() => {
  const move = current.value
  if (!move || !wrong.value) return []
  return [
    {
      square: move.uci.slice(2, 4),
      text: VERDICT_GLYPHS[move.verdict],
      tone: /** @type {const} */ ('blunder')
    }
  ]
})
/** The best move where it went wrong, in SAN. */
const bestSan = computed(() => {
  const move = current.value
  if (!move?.bestMove || !wrong.value) return ''
  const before = review.positions.value[move.ply]
  try {
    const chess = new Chess(before.fen)
    return chess.move({
      from: move.bestMove.slice(0, 2),
      to: move.bestMove.slice(2, 4),
      promotion: move.bestMove[4]
    }).san
  } catch {
    return ''
  }
})
/** Each side's material in the position shown, the boss's above the board, the player's below. */
const material = computed(() => materialOf(shown.value.fen))
const bossSide = computed(() =>
  props.game.color === 'white' ? 'black' : 'white'
)
const sides = computed(() => {
  const player = props.game.color
  const boss = player === 'white' ? 'black' : 'white'
  return [
    {
      id: player,
      who: 'player',
      name: props.playerName,
      counts: review.mistakes(player)
    },
    {
      id: boss,
      who: 'boss',
      name: props.bossName,
      counts: review.mistakes(boss)
    }
  ]
})
const area = computed(() => {
  const values = review.curve.value
  const total = Math.max(1, review.positions.value.length - 1)
  const y = (/** @type {number} */ v) =>
    CURVE_H / 2 - (v / 1000) * (CURVE_H / 2)
  const points = values.map((v, i) => `${(i / total) * CURVE_W},${y(v)}`)
  const end = ((values.length - 1) / total) * CURVE_W
  return [`0,${CURVE_H / 2}`, ...points, `${end},${CURVE_H / 2}`].join(' ')
})
const cursorX = computed(
  () =>
    (cursor.value / Math.max(1, review.positions.value.length - 1)) * CURVE_W
)

watch(
  () => props.game.id,
  () => startReview(),
  { immediate: true }
)

function startReview() {
  review.start(props.game.moves)
  cursor.value = last.value
}

/** @param {number} to */
function go(to) {
  cursor.value = Math.max(0, Math.min(last.value, to))
}

/** @param {{ply: number, san: string}} move */
function moveLabel(move) {
  const n = Math.floor(move.ply / 2) + 1
  return move.ply % 2 === 0 ? `${n}. ${move.san}` : `${n}… ${move.san}`
}

function pgn() {
  return pgnOf({
    game: props.game,
    sans: review.positions.value.slice(1).map(position => position.san ?? ''),
    player: props.playerName,
    boss: props.bossName,
    site: window.location.origin,
    termination: props.game.end ? t(`arena.end.${props.game.end}`) : undefined
  })
}

function download() {
  downloadText(pgn(), pgnFileName(props.game))
}

async function copy() {
  try {
    await navigator.clipboard.writeText(pgn())
    $q.notify({ type: 'positive', message: t('arena.review.copied') })
  } catch {
    $q.notify({ type: 'negative', message: t('arena.review.copyFailed') })
  }
}

/** @param {KeyboardEvent} event */
function onKey(event) {
  const target = /** @type {HTMLElement|null} */ (event.target)
  if (target?.closest('input, textarea, [contenteditable]')) return
  if (event.key === 'ArrowLeft') go(cursor.value - 1)
  else if (event.key === 'ArrowRight') go(cursor.value + 1)
  else if (event.key === 'Home') go(0)
  else if (event.key === 'End') go(last.value)
  else return
  event.preventDefault()
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  review.stop()
})
</script>

<style scoped lang="scss">
.game-review {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

// Edge to edge on a phone, as during the game (the board's own cap is 92vw).
.game-review__board {
  --board-max: 100%;
  width: 100%;
  max-width: 760px;
  margin: 0 auto;
}

.game-review__material {
  padding: 4px 16px;
  color: var(--cm-muted);
}

.game-review__steps {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
  padding: 10px 16px 0;
}

.game-review__step {
  height: 46px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  border-radius: 14px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  cursor: pointer;

  &:disabled {
    opacity: 0.4;
    cursor: default;
  }
}

.game-review__side {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 0 16px 24px;
}

.game-review__head {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.game-review__back,
.game-review__link {
  border: none;
  background: none;
  padding: 0;
  color: var(--cm-brand);
  font: inherit;
  font-weight: 800;
  cursor: pointer;
}

.game-review__title {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 20px;
}

.game-review__progress {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-muted);
}

.game-review__failed {
  margin: 0;
  color: var(--cm-danger);
  font-weight: 700;
}

.game-review__curve {
  width: 100%;
  height: 80px;
  border-radius: 12px;
  overflow: hidden;
  display: block;
}

.game-review__curve-ground {
  fill: #3a3550;
}

.game-review__curve-area {
  fill: #f4f2fa;
}

.game-review__curve-cursor {
  stroke: var(--cm-lime);
  stroke-width: 2;
  vector-effect: non-scaling-stroke;
}

.game-review__summary {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.game-review__who {
  background: var(--cm-surface);
  border-radius: 16px;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.game-review__who-name {
  font-weight: 800;
  font-size: 14px;
}

.game-review__counts {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.game-review__count {
  font-size: 12.5px;
  font-weight: 800;
  padding: 3px 8px;
  border-radius: 999px;
  background: var(--cm-inset);
}

.game-review__count--inaccuracy {
  color: var(--cm-orange-ink);
}
.game-review__count--mistake,
.game-review__count--blunder {
  color: var(--cm-danger);
}

.game-review__verdict {
  margin: 0;
  font-size: 14px;
  color: var(--cm-ink-soft);
}

.game-review__moves {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  max-height: 220px;
  overflow-y: auto;
  align-content: flex-start;
}

.game-review__move {
  border: none;
  border-radius: 8px;
  padding: 4px 8px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  font: inherit;
  font-size: 13px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
}

.game-review__move--on {
  background: var(--cm-brand-soft);
  outline: 2px solid var(--cm-brand);
}

.game-review__move-n {
  color: var(--cm-muted);
  margin-right: 3px;
}

.game-review__glyph {
  margin-left: 1px;
}

.game-review__move--inaccuracy .game-review__glyph {
  color: var(--cm-orange-ink);
}
.game-review__move--mistake .game-review__glyph,
.game-review__move--blunder .game-review__glyph {
  color: var(--cm-danger);
}

.game-review__pgn {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.game-review__pgn-btn {
  height: 46px;
  padding: 0 16px;
  border: none;
  border-radius: 14px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  font: inherit;
  font-weight: 800;
  font-size: 14px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
}

@media (min-width: 1024px) {
  .game-review {
    flex-direction: row;
    align-items: flex-start;
    gap: 28px;
    padding: 14px 28px;
    max-width: 1600px;
    margin: 0 auto;
  }

  .game-review__board {
    flex: none;
    // The material lines and the steps around the board: a little less than the window's height;
    // the board fills it (no 560 px cap).
    --board-max: 100%;
    width: min(calc(100vh - 210px), calc(100vw - 404px));
    max-width: none;
    margin: 0;
  }

  .game-review__steps {
    padding: 12px 0 0;
  }

  .game-review__material {
    padding: 2px 4px;
  }

  .game-review__side {
    flex: 1 1 400px;
    min-width: 320px;
    max-width: 460px;
    padding: 0;
  }

  .game-review__moves {
    max-height: 300px;
  }
}
</style>
