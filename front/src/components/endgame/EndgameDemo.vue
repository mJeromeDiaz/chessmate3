<template>
  <q-card class="endgame-demo" data-testid="endgame-demo">
    <div class="endgame-demo__head">
      <div class="endgame-demo__titles">
        <span class="endgame-demo__kicker">{{
          $t('endgame.demo.kicker')
        }}</span>
        <span class="endgame-demo__title">{{ lessonTitle(lessonKey) }}</span>
      </div>
      <q-btn
        flat
        round
        icon="close"
        :aria-label="$t('endgame.demo.close')"
        data-testid="endgame-demo-close"
        @click="emit('close')"
      />
    </div>

    <p class="endgame-demo__goal">{{ $t(`endgame.demo.goals.${goal}`) }}</p>

    <ChessBoard
      class="endgame-demo__board"
      :fen="demo.fen.value"
      :orientation="orientation"
      :highlights="highlights"
    />

    <div class="endgame-demo__controls">
      <q-btn
        flat
        round
        icon="replay"
        :aria-label="$t('endgame.demo.restart')"
        :disable="demo.cursor.value === 0"
        data-testid="endgame-demo-restart"
        @click="restart"
      />
      <q-btn
        flat
        round
        icon="skip_previous"
        :aria-label="$t('endgame.demo.previous')"
        :disable="demo.cursor.value === 0"
        data-testid="endgame-demo-previous"
        @click="step(-1)"
      />
      <q-btn
        unelevated
        round
        size="lg"
        class="endgame-demo__play"
        :icon="playing ? 'pause' : 'play_arrow'"
        :aria-label="
          playing ? $t('endgame.demo.pause') : $t('endgame.demo.play')
        "
        :disable="demo.atEnd.value && demo.moves.value.length === 0"
        data-testid="endgame-demo-play"
        @click="toggle"
      />
      <q-btn
        flat
        round
        icon="skip_next"
        :aria-label="$t('endgame.demo.next')"
        :disable="demo.cursor.value >= demo.moves.value.length"
        data-testid="endgame-demo-next"
        @click="step(1)"
      />
    </div>

    <div
      class="endgame-demo__status"
      data-testid="endgame-demo-status"
      :data-status="demo.status.value"
    >
      <q-spinner v-if="demo.waiting.value" size="16px" />
      {{ statusText }}
    </div>

    <ol class="endgame-demo__moves" data-testid="endgame-demo-moves">
      <li v-for="entry in entries" :key="entry.ply" class="endgame-demo__move">
        <span v-if="entry.number" class="endgame-demo__number">{{
          entry.number
        }}</span>
        <button
          type="button"
          class="endgame-demo__san"
          :class="{ 'endgame-demo__san--on': entry.ply === demo.cursor.value }"
          @click="goTo(entry.ply)"
        >
          {{ entry.san }}
        </button>
      </li>
    </ol>
  </q-card>
</template>

<script setup>
/**
 * The demonstration of an endgame (docs/ENDGAMES.md, § 9): the best line of both sides from a
 * position, played on a board that cannot be moved, one half-move a second, with pause, previous,
 * next and restart, and the line written in the player's language (a move clicked goes there). The
 * line is worked out while it plays (`useEndgameDemo`); nothing of it counts.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import { useEndgameDemo } from '@/composables/endgame/useEndgameDemo'
import { lessonTitle } from '@/utils/endgame'
import { localSan } from '@/utils/notation'
import { t } from '@/i18n'

const props = defineProps({
  /** The lesson's key (its title). */
  lessonKey: { type: String, required: true },
  /** Where the line starts: the player is the side to move. */
  fen: { type: String, required: true },
  /** @type {import('vue').PropType<import('@/utils/endgame').Goal>} */
  goal: { type: String, required: true },
  /** @type {import('vue').PropType<import('@/utils/endgame').Result>} */
  result: { type: String, required: true },
  holdMoves: { type: Number, default: null }
})

const emit = defineEmits(['close'])

/** One half-move every this long while it plays. */
const STEP_MS = 1000

const demo = useEndgameDemo()
const playing = ref(true)
/** @type {ReturnType<typeof setInterval>|undefined} */
let ticker

const orientation = computed(() =>
  props.fen.split(' ')[1] === 'b' ? 'black' : 'white'
)
const highlights = computed(() => {
  const uci = demo.lastMove.value
  if (!uci) return []
  return [
    { square: uci.slice(0, 2), type: 'lastMove' },
    { square: uci.slice(2, 4), type: 'lastMove' }
  ]
})

/** The line as it reads: « 1. Tc8 Rd6 2. … », each move after its number when White's. */
const entries = computed(() => {
  const [, turn = 'w', , , , fullmove = '1'] = props.fen.split(' ')
  let number = Number(fullmove) || 1
  let white = turn === 'w'
  return demo.sans.value.map((san, i) => {
    const entry = {
      ply: i + 1,
      number: white ? `${number}.` : i === 0 ? `${number}…` : '',
      san: localSan(san)
    }
    if (!white) number++
    white = !white
    return entry
  })
})

const statusText = computed(() => {
  const status = demo.status.value
  if (status === 'unavailable' || status === 'error')
    return t('endgame.demo.unavailable')
  if (demo.waiting.value) return t('endgame.demo.loading')
  if (demo.atEnd.value) return t('endgame.demo.end')
  return t('endgame.demo.progress', {
    n: demo.cursor.value,
    total: demo.moves.value.length
  })
})

watch(
  () => [props.fen, props.goal, props.result, props.holdMoves],
  () => {
    demo.load({
      fen: props.fen,
      goal: props.goal,
      result: props.result,
      holdMoves: props.holdMoves
    })
    playing.value = true
  },
  { immediate: true }
)

/** While it plays: a half-move each second once it is known; stopped at the end. */
watch(
  playing,
  on => {
    clearInterval(ticker)
    if (!on) return
    ticker = setInterval(() => {
      if (demo.atEnd.value) playing.value = false
      else demo.next()
    }, STEP_MS)
  },
  { immediate: true }
)

function toggle() {
  if (!playing.value && demo.atEnd.value) demo.restart()
  playing.value = !playing.value
}

/** @param {number} delta */
function step(delta) {
  playing.value = false
  if (delta > 0) demo.next()
  else demo.previous()
}

/** @param {number} ply */
function goTo(ply) {
  playing.value = false
  demo.goTo(ply)
}

function restart() {
  demo.restart()
  playing.value = true
}

onBeforeUnmount(() => clearInterval(ticker))
</script>

<style scoped lang="scss">
.endgame-demo {
  display: flex;
  flex-direction: column;
  gap: 12px;
  width: min(560px, 100vw);
  max-width: 100%;
  padding: 16px 18px 20px;
  border-radius: 24px;
}

.endgame-demo__head {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.endgame-demo__titles {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.endgame-demo__kicker {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--cm-lime-ink);
}

.endgame-demo__title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
  line-height: 1.15;
}

.endgame-demo__goal {
  margin: 0;
  font-size: 14px;
  color: var(--cm-muted);
}

.endgame-demo__board {
  --board-max: min(92vw, 60vh, 520px);
}

.endgame-demo__controls {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.endgame-demo__play {
  background: var(--cm-lime);
  color: #1b1530;
}

.endgame-demo__status {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 20px;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-muted);
}

.endgame-demo__moves {
  display: flex;
  flex-wrap: wrap;
  gap: 2px 4px;
  margin: 0;
  padding: 0;
  list-style: none;
  max-height: 120px;
  overflow-y: auto;
}

.endgame-demo__move {
  display: inline-flex;
  align-items: center;
  gap: 2px;
}

.endgame-demo__number {
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-muted);
}

.endgame-demo__san {
  padding: 2px 6px;
  border: none;
  border-radius: 6px;
  background: none;
  color: inherit;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;

  &:hover {
    background: var(--cm-inset);
  }

  &--on {
    background: var(--cm-lime);
    color: #1b1530;
  }
}
</style>
