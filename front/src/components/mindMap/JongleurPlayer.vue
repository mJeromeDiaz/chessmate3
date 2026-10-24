<template>
  <PlayLayout
    class="jongleur-player"
    data-testid="jongleur-player"
    :data-question="question"
    :data-state="state"
    :data-distraction="distraction ?? 'none'"
  >
    <template v-if="$slots.header" #header>
      <slot name="header" />
    </template>

    <template #prof>
      <div class="jongleur-player__boss">
        <BossPortrait
          class="jongleur-player__portrait"
          :class="{
            'jongleur-player__portrait--juggling': state === 'answering'
          }"
          character="jongleur"
          :pose="pose"
          :label="$t('mindMapPath.characters.jongleur.name')"
        />
        <div class="jongleur-player__talk">
          <div
            class="jongleur-player__lives"
            data-testid="jongleur-lives"
            :data-lives="lives"
          >
            <q-icon
              v-for="i in BOSS_LIVES"
              :key="i"
              :name="i <= lives ? 'favorite' : 'heart_broken'"
              :class="{ 'jongleur-player__life--lost': i > lives }"
            />
          </div>
          <div class="jongleur-player__bubble" data-testid="jongleur-bubble">{{
            bubble
          }}</div>
        </div>
      </div>
      <div
        class="jongleur-player__clock"
        :class="{ 'jongleur-player__clock--low': leftMs <= 2000 && !answered }"
        role="progressbar"
        :aria-valuenow="Math.ceil(leftMs / 1000)"
        aria-valuemin="0"
        :aria-valuemax="seconds"
        data-testid="jongleur-clock"
      >
        <div
          class="jongleur-player__clock-fill"
          :style="{ width: `${(leftMs / (seconds * 1000)) * 100}%` }"
        />
      </div>
      <!-- The order under the clock: beside the board on a wide screen, above it on a phone. -->
      <div
        :key="item.id"
        class="jongleur-player__order"
        :class="[
          `jongleur-player__order--${question}`,
          { 'jongleur-player__order--new': changed }
        ]"
        data-testid="jongleur-order"
        :data-question="question"
      >
        <q-icon
          :name="ORDER_ICONS[question]"
          class="jongleur-player__order-icon"
        />
        <div class="jongleur-player__order-text">
          <span class="jongleur-player__order-title"
            >{{ $t(`mindMapPath.jongleur.order.${question}.title`) }}
            <b
              v-if="question === 'play'"
              class="jongleur-player__order-what"
              data-testid="jongleur-announced"
              >{{ announced }}</b
            ><b
              v-else-if="question === 'find'"
              class="jongleur-player__order-what"
              >{{ item.data.square }}</b
            ></span
          >
          <span class="jongleur-player__order-hint">{{
            $t(`mindMapPath.jongleur.order.${question}.hint`, {
              square: item.data.square ?? ''
            })
          }}</span>
        </div>
        <span v-if="changed" class="jongleur-player__order-new">{{
          $t('mindMapPath.jongleur.order.new')
        }}</span>
      </div>
    </template>

    <template #board>
      <div
        class="jongleur-player__stage"
        :class="distraction ? `jongleur-player__stage--${distraction}` : ''"
      >
        <div
          v-if="isSquare && question !== 'name'"
          class="jongleur-player__target"
          data-testid="jongleur-target"
          >{{ item.data.square }}</div
        >
        <div
          v-else-if="question === 'name'"
          class="jongleur-player__target jongleur-player__target--typed"
          data-testid="jongleur-typed"
          @click="field?.focus()"
          >{{ typed || '·' }}</div
        >

        <div v-if="question === 'color'" class="jongleur-player__colors">
          <button
            type="button"
            class="jongleur-player__color jongleur-player__color--light"
            :class="colorClass('light')"
            :disabled="answered"
            data-testid="jongleur-light"
            @click="answerSquare('light')"
            >{{ $t('coordinates.player.light') }}</button
          >
          <button
            type="button"
            class="jongleur-player__color jongleur-player__color--dark"
            :class="colorClass('dark')"
            :disabled="answered"
            data-testid="jongleur-dark"
            @click="answerSquare('dark')"
            >{{ $t('coordinates.player.dark') }}</button
          >
        </div>

        <ChessBoard
          v-else
          :fen="fen"
          :orientation="orientation"
          :coordinates="!isSquare"
          :animation-duration="isSquare ? 0 : undefined"
          :movable-color="movableColor"
          :square-input="question === 'find' && !answered"
          :highlights="highlights"
          :arrows="arrows"
          @square="answerSquare"
          @move="onMove"
        />

        <div
          v-if="distraction === 'drift'"
          class="jongleur-player__balls"
          aria-hidden="true"
        >
          <span
            v-for="(glyph, i) in BALLS"
            :key="i"
            class="jongleur-player__ball"
            :style="{ animationDelay: `${i * 0.7}s` }"
            >{{ glyph }}</span
          >
        </div>
      </div>
    </template>

    <template #controls>
      <div
        v-if="question === 'name'"
        class="jongleur-player__keypad"
        data-testid="jongleur-keypad"
      >
        <input
          v-if="touch"
          ref="field"
          class="jongleur-player__field"
          type="text"
          inputmode="text"
          autocomplete="off"
          autocapitalize="none"
          autocorrect="off"
          spellcheck="false"
          maxlength="2"
          :disabled="answered"
          :aria-label="$t('coordinates.player.name')"
          data-testid="jongleur-input"
          @beforeinput="onBeforeInput"
          @input="onField"
        />
        <div class="jongleur-player__hint">{{
          $t(
            touch
              ? 'coordinates.player.keysNameTouch'
              : 'coordinates.player.keysName'
          )
        }}</div>
      </div>

      <form
        v-if="question === 'write'"
        class="jongleur-player__write"
        data-testid="jongleur-write"
        @submit.prevent="send"
      >
        <q-input
          v-model="written"
          outlined
          dense
          autofocus
          autocomplete="off"
          autocapitalize="off"
          spellcheck="false"
          :disable="answered"
          :placeholder="$t('mindMapPath.jongleur.writePlaceholder')"
          data-testid="jongleur-input"
        />
        <div class="jongleur-player__keys">
          <button
            v-for="key in pieceKeys"
            :key="key"
            type="button"
            class="jongleur-player__key jongleur-player__key--small"
            :disabled="answered"
            @click="written += key"
            >{{ key }}</button
          >
        </div>
        <q-btn
          type="submit"
          unelevated
          no-caps
          color="primary"
          :label="$t('notation.player.send')"
          :disable="answered || !written.trim()"
          data-testid="jongleur-send"
        />
      </form>
    </template>

    <div
      v-if="result"
      class="jongleur-player__verdict"
      :class="`jongleur-player__verdict--${result.status}`"
      data-testid="jongleur-result"
      :data-status="result.status"
      >{{ verdict }}</div
    >
  </PlayLayout>
</template>

<script setup>
/**
 * A question of the Jongleur (docs/MIND_MAP.md, § 8), its order on a large banner above the
 * board (its colour and icon by kind, the square or move asked; it pops in at each question, and
 * shakes with « Nouveau ! » when the kind changes): find a square (a click on the empty board),
 * name the square lit (a file then a rank typed: the keyboard, or the device's own on a touch
 * screen; no keys on screen, they would read as a hint), tell a square's colour (« Claire »
 * / « Foncée », or their initials, C / F or L / D), play the move announced in a puzzle's
 * position, or write the move just shown played (piece letters of the UI's language). Each has
 * its seconds: the bar runs from the server's start, and an empty answer goes when it is empty.
 * The Jongleur juggles while the player thinks, laughs at a miss and sulks at a hit; one question
 * in three or so brings a distraction (the board wobbles, pieces fly over it), none when the
 * system asks for less motion.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { Chess } from 'chess.js'
import BossPortrait from '@/components/mindMap/BossPortrait.vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import PlayLayout from '@/components/chess/PlayLayout.vue'
import { colorKey } from '@/utils/coordinates'
import { BOSS_LIVES } from '@/utils/mindMapPath'
import { distractionOf, jongleurVerdict } from '@/utils/jongleur'
import { englishSan, localSan, pieceLetters } from '@/utils/notation'
import { playBossVerdict } from '@/utils/sounds'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').RunItem>} the question (jongleur) */
  item: { type: Object, required: true },
  /** @type {import('vue').PropType<Record<string, any>|null>} the server's verdict on it */
  result: { type: Object, default: null },
  /** Server clock minus client clock (useTimeboxedRun), in ms. */
  offsetMs: { type: Number, default: 0 }
})

const emit = defineEmits({ resolve: null })

const FILES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']
const RANKS = ['1', '2', '3', '4', '5', '6', '7', '8']
const EMPTY_BOARD = '8/8/8/8/8/8/8/8 w - - 0 1'
/** Before the move to write is played on the board. */
const SHOW_DELAY_MS = 300
/** What flies over the board (drift). */
const BALLS = ['♞', '♝', '♜']
/** Each kind of question: its icon on the order's banner. */
const ORDER_ICONS = /** @type {Record<string, string>} */ ({
  find: 'ads_click',
  name: 'keyboard',
  color: 'contrast',
  play: 'touch_app',
  write: 'edit_note'
})

const $q = useQuasar()
/** A touch screen: the device's keyboard, through a field, names the square. */
const touch = computed(() => $q.platform.has.touch)
/** @type {import('vue').Ref<HTMLInputElement|null>} */
const field = ref(null)

/** Sent: waiting for the verdict, or judged. */
const answered = ref(false)
/** The name being typed: '', a file, then sent with the rank. */
const typed = ref('')
const written = ref('')
/** The answer given (a square or a colour; a move played), for the board's colours. */
const given = ref(/** @type {string|null} */ (null))
const fen = ref(EMPTY_BOARD)
/** The client's time now, refreshed while the question runs. */
const now = ref(Date.now())
/** When the question reached the client (its own clock). */
const receivedAt = ref(Date.now())
/** @type {ReturnType<typeof setInterval>|undefined} */
let ticker
/** @type {ReturnType<typeof setTimeout>|undefined} */
let showTimer

const question = computed(() => props.item.data.question)
/** The kind of question changed from the previous one: the banner says it louder. */
const changed = ref(false)
/** @type {string|null} */
let previousQuestion = null
const isSquare = computed(() =>
  ['find', 'name', 'color'].includes(question.value)
)
const seconds = computed(() => props.item.data.seconds ?? 7)
const distraction = computed(() =>
  distractionOf(props.item.id, props.item.data.distraction)
)
const lives = computed(() => props.result?.lives ?? props.item.data.lives)
const state = computed(() =>
  props.result ? 'judged' : answered.value ? 'sent' : 'answering'
)
const pose = computed(() => {
  if (!props.result) return 'idle'
  return props.result.status === 'right' ? 'angry' : 'cheer'
})
const orientation = computed(() =>
  isSquare.value || props.item.data.color !== 'black' ? 'white' : 'black'
)
const movableColor = computed(() =>
  question.value === 'play' && !answered.value ? orientation.value : null
)
const announced = computed(() => localSan(props.item.data.move?.san ?? ''))
const pieceKeys = computed(() => [...pieceLetters(), 'x', '+', '=', 'O-O'])

/**
 * The question's deadline on the client's clock. The server stores its start to the second
 * (truncated): the real start is within the second after, never after the question came.
 */
const deadline = computed(() => {
  const serverStart = Date.parse(props.item.data.startedAt) - props.offsetMs
  return Math.min(receivedAt.value, serverStart + 1000) + seconds.value * 1000
})
const leftMs = computed(() =>
  answered.value && !props.result ? 0 : Math.max(0, deadline.value - now.value)
)

const bubble = computed(() => {
  const r = props.result
  if (!r) return t('mindMapPath.jongleur.taunt')
  return t(`mindMapPath.jongleur.react.${r.status}`)
})

const verdict = computed(() =>
  props.result
    ? jongleurVerdict(question.value, props.item.data.square, props.result)
    : ''
)

/** The square lit (name), then the answer's colours: green, or red and the right one in green. */
const highlights = computed(() => {
  const r = props.result
  const square = props.item.data.square
  if (question.value === 'name')
    return [
      {
        square,
        type: !r ? 'hint' : r.status === 'right' ? 'success' : 'error'
      }
    ]
  if (question.value === 'find') {
    if (!r) return []
    if (r.status === 'right') return [{ square, type: 'success' }]
    return [
      ...(given.value ? [{ square: given.value, type: 'error' }] : []),
      { square, type: 'success' }
    ]
  }
  const move = props.item.data.move?.uci
  if (question.value === 'write' && move && !r)
    return [
      { square: move.slice(0, 2), type: 'lastMove' },
      { square: move.slice(2, 4), type: 'lastMove' }
    ]
  if (question.value === 'play' && r && r.status !== 'right' && given.value)
    return [
      { square: given.value.slice(0, 2), type: 'error' },
      { square: given.value.slice(2, 4), type: 'error' }
    ]
  return []
})

/** A move missed (played or written): the right one as an arrow. */
const arrows = computed(() => {
  const r = props.result
  const move = props.item.data.move?.uci
  if (!r || r.status === 'right' || isSquare.value || !move) return []
  return [{ from: move.slice(0, 2), to: move.slice(2, 4), type: 'solution' }]
})

/** @param {'light'|'dark'} color */
function colorClass(color) {
  const r = props.result
  if (!r) return ''
  if (r.expected === color) return 'jongleur-player__color--right'
  return given.value === color ? 'jongleur-player__color--wrong' : ''
}

/** @param {Record<string, any>} report */
function answer(report) {
  if (answered.value) return
  answered.value = true
  emit('resolve', report)
}

/** @param {string} value a square, or light / dark */
function answerSquare(value) {
  if (answered.value) return
  given.value = value
  answer({ answers: [{ index: 0, square: value, ms: elapsed() }] })
}

/** @param {{uci: string}} move */
function onMove(move) {
  if (answered.value) return
  given.value = move.uci
  fen.value = after(fen.value, move.uci)
  answer({ moves: [move.uci] })
}

function send() {
  const text = written.value.trim()
  if (answered.value || !text) return
  answer({ written: [englishSan(text)] })
}

/** @param {string} key a file, then a rank sends the square */
function type(key) {
  if (answered.value) return
  if (FILES.includes(key)) {
    if (typed.value.length === 0) typed.value = key
    return
  }
  if (RANKS.includes(key) && typed.value.length === 1) {
    const square = `${typed.value}${key}`
    typed.value = ''
    answerSquare(square)
  }
}

/**
 * The device's keyboard typed (touch screens): each letter or digit to the name, the field empty.
 *
 * @param {Event} event
 */
function onField(event) {
  const input = /** @type {HTMLInputElement} */ (event.target)
  for (const char of input.value.toLowerCase()) type(char)
  input.value = ''
}

/** @param {InputEvent} event deleting on the device's keyboard */
function onBeforeInput(event) {
  if (event.inputType?.startsWith('delete')) {
    event.preventDefault()
    typed.value = ''
  }
}

/** The keyboard: a file and a rank (name), the colour's initial or the arrows (colour). */
function onKey(/** @type {KeyboardEvent} */ event) {
  if (event.ctrlKey || event.metaKey || event.altKey || answered.value) return
  const target = /** @type {HTMLElement|null} */ (event.target)
  if (target?.closest('input, textarea, [contenteditable="true"]')) return
  const key = event.key.toLowerCase()
  if (question.value === 'name') {
    if (key === 'backspace') typed.value = ''
    else if (FILES.includes(key) || RANKS.includes(key)) type(key)
    else return
  } else if (question.value === 'color') {
    const color = colorKey(key)
    if (color === null) return
    answerSquare(color)
  } else return
  event.preventDefault()
}

function elapsed() {
  return Math.max(0, Date.now() - receivedAt.value)
}

/**
 * @param {string} position
 * @param {string} uci
 */
function after(position, uci) {
  const chess = new Chess(position)
  chess.move({ from: uci.slice(0, 2), to: uci.slice(2, 4), promotion: uci[4] })
  return chess.fen()
}

/** Lets the player answer again (a refused submission). */
function unlock() {
  answered.value = false
}

defineExpose({ unlock })

watch(
  () => props.item.id,
  () => {
    changed.value =
      previousQuestion !== null && previousQuestion !== question.value
    previousQuestion = question.value
    receivedAt.value = Date.now()
    now.value = receivedAt.value
    answered.value = false
    typed.value = ''
    written.value = ''
    given.value = null
    clearTimeout(showTimer)
    const data = props.item.data
    fen.value = data.fen ?? EMPTY_BOARD
    // Naming a square on a touch screen: its keyboard at once.
    if (data.question === 'name') nextTick(() => field.value?.focus())
    // The move to write is played before the player's eyes.
    if (data.question === 'write' && data.fen && data.move?.uci) {
      const start = data.fen
      showTimer = setTimeout(() => {
        if (props.item.data.fen === start)
          fen.value = after(start, data.move.uci)
      }, SHOW_DELAY_MS)
    }
  },
  { immediate: true }
)

// Time out: an empty answer (the server judges it late or wrong).
// Its verdict heard, as every boss's.
watch(
  () => props.result,
  value => {
    if (value) playBossVerdict(value.status === 'right')
  }
)

watch(leftMs, left => {
  if (left <= 0 && !answered.value && !props.result) answer({})
})

onMounted(() => {
  window.addEventListener('keydown', onKey)
  ticker = setInterval(() => (now.value = Date.now()), 100)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  clearInterval(ticker)
  clearTimeout(showTimer)
})
</script>

<style scoped lang="scss">
// On a phone the order sits above the board: a smaller board, all on the screen.
@media (max-width: 1023px) {
  .jongleur-player.play-layout {
    --board-max: min(100%, max(240px, 100svh - 450px));
  }
}

// The order: a banner as wide as the board, one colour per kind of question.
.jongleur-player__order {
  --order: #4fc3f7;
  margin-top: 12px;
  --order-ink: #1b1530;
  position: relative;
  display: flex;
  align-items: center;
  gap: 14px;
  width: 100%;
  padding: 12px 16px;
  border-radius: 18px;
  background: var(--order);
  color: var(--order-ink);
  box-shadow: 0 5px 0 rgba(27, 21, 48, 0.25);
  animation: jongleur-order-in 0.35s cubic-bezier(0.34, 1.8, 0.64, 1) both;

  &--name {
    --order: #c6f432;
  }

  &--color {
    --order: #ffd43b;
  }

  &--play {
    --order: #ff6fae;
  }

  &--write {
    --order: #8b6bff;
    --order-ink: #fff;
  }

  &--new {
    animation:
      jongleur-order-in 0.35s cubic-bezier(0.34, 1.8, 0.64, 1) both,
      jongleur-order-shake 0.45s 0.35s both;
  }
}

@keyframes jongleur-order-in {
  from {
    transform: scale(0.85);
    opacity: 0;
  }
}

@keyframes jongleur-order-shake {
  20% {
    transform: rotate(-2deg);
  }
  40% {
    transform: rotate(2deg);
  }
  60% {
    transform: rotate(-1deg);
  }
  80% {
    transform: rotate(1deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .jongleur-player__order,
  .jongleur-player__order--new {
    animation: none;
  }
}

.jongleur-player__order-icon {
  flex: none;
  font-size: 34px;
}

.jongleur-player__order-text {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.jongleur-player__order-title {
  font-family: var(--cm-heading);
  font-size: 22px;
  font-weight: 800;
  line-height: 1.1;
  text-transform: uppercase;

  @media (min-width: $breakpoint-md-min) {
    font-size: 26px;
  }
}

.jongleur-player__order-what {
  margin-left: 4px;
  padding: 0 8px;
  border-radius: 8px;
  background: rgba(27, 21, 48, 0.88);
  color: #fff;
  text-transform: none;
  white-space: nowrap;
}

.jongleur-player__order-hint {
  margin-top: 2px;
  font-size: 14px;
  font-weight: 700;
  opacity: 0.85;
}

.jongleur-player__order-new {
  position: absolute;
  top: -10px;
  right: 12px;
  padding: 3px 10px;
  border-radius: 999px;
  background: #1b1530;
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.jongleur-player__boss {
  display: flex;
  align-items: center;
  gap: 12px;
}

.jongleur-player__portrait {
  flex: none;
  width: 96px;

  &--juggling {
    animation: jongleur-bob 1.2s ease-in-out infinite;
  }
}

.jongleur-player__talk {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
}

.jongleur-player__lives {
  display: flex;
  gap: 4px;
  font-size: 22px;
  color: #e5484d;
}

.jongleur-player__life--lost {
  color: var(--cm-faint);
}

.jongleur-player__bubble {
  padding: 8px 12px;
  border-radius: 14px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font-weight: 600;
}

.jongleur-player__clock {
  height: 10px;
  margin-top: 10px;
  border-radius: 999px;
  background: var(--cm-subtle);
  overflow: hidden;

  &--low .jongleur-player__clock-fill {
    background: #e5484d;
  }
}

.jongleur-player__clock-fill {
  height: 100%;
  border-radius: inherit;
  background: var(--cm-brand);
  transition: width 0.1s linear;
}

.jongleur-player__stage {
  position: relative;

  &--shake {
    animation: jongleur-wobble 0.9s ease-in-out 0.8s 3;
  }
}

.jongleur-player__target {
  margin-bottom: 8px;
  text-align: center;
  font-family: var(--cm-heading);
  font-size: 48px;
  font-weight: 800;
  line-height: 1.1;
  color: var(--cm-ink);
}

.jongleur-player__colors {
  display: flex;
  justify-content: center;
  gap: 14px;
  padding: 12px 0;
}

.jongleur-player__color {
  width: min(42vw, 200px);
  height: 140px;
  border: none;
  border-radius: 20px;
  font-family: var(--cm-heading);
  font-size: 24px;
  font-weight: 800;
  cursor: pointer;

  &--light {
    background: #f0d9b5;
    color: #5b3d1f;
    box-shadow: 0 6px 0 #c9ac80;
  }

  &--dark {
    background: #b58863;
    color: #fff;
    box-shadow: 0 6px 0 #8a6342;
  }

  &--right {
    outline: 4px solid var(--cm-success);
  }

  &--wrong {
    outline: 4px solid var(--cm-danger);
  }

  &:disabled {
    cursor: default;
  }
}

.jongleur-player__balls {
  position: absolute;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
}

.jongleur-player__ball {
  position: absolute;
  top: 40%;
  left: -15%;
  font-size: 44px;
  opacity: 0.75;
  animation: jongleur-drift 2.8s linear infinite;
}

.jongleur-player__keypad,
.jongleur-player__write {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  width: 100%;
}

.jongleur-player__write .q-input {
  width: 100%;
  max-width: 320px;
}

.jongleur-player__keys {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 6px;
}

.jongleur-player__field {
  width: 120px;
  height: 52px;
  border: none;
  border-radius: 14px;
  background: var(--cm-surface);
  box-shadow: inset 0 0 0 2px var(--cm-line);
  color: var(--cm-ink);
  font-family: var(--cm-heading);
  font-size: 24px;
  font-weight: 800;
  text-align: center;

  &:focus {
    outline: none;
    box-shadow: inset 0 0 0 2px var(--cm-brand);
  }
}

.jongleur-player__hint {
  font-size: 12.5px;
  color: var(--cm-muted);
  text-align: center;
}

.jongleur-player__key {
  width: 44px;
  height: 48px;
  border: none;
  border-radius: 12px;
  background: var(--cm-surface);
  box-shadow:
    inset 0 0 0 2px var(--cm-line),
    0 3px 0 var(--cm-dash);
  color: var(--cm-ink);
  font-family: var(--cm-heading);
  font-size: 20px;
  font-weight: 800;
  cursor: pointer;

  &--small {
    width: auto;
    min-width: 40px;
    height: 40px;
    padding: 0 8px;
    font-size: 16px;
  }

  &--on {
    background: var(--cm-brand);
    color: #fff;
    box-shadow: 0 3px 0 var(--cm-brand-deep);
  }

  &:disabled {
    opacity: 0.45;
    cursor: default;
  }
}

.jongleur-player__verdict {
  padding: 10px 14px;
  border-radius: 14px;
  text-align: center;
  font-size: 18px;
  font-weight: 800;

  &--right {
    background: color-mix(in srgb, var(--cm-success) 16%, transparent);
    color: var(--cm-success);
  }

  &--wrong,
  &--late {
    background: color-mix(in srgb, var(--cm-danger) 14%, transparent);
    color: var(--cm-danger);
  }
}

@keyframes jongleur-bob {
  0%,
  100% {
    transform: translateY(0) rotate(-2deg);
  }
  50% {
    transform: translateY(-6px) rotate(2deg);
  }
}

@keyframes jongleur-wobble {
  0%,
  100% {
    transform: rotate(0);
  }
  25% {
    transform: rotate(-1.5deg) translateX(-4px);
  }
  75% {
    transform: rotate(1.5deg) translateX(4px);
  }
}

@keyframes jongleur-drift {
  0% {
    transform: translate(0, 0) rotate(0);
  }
  50% {
    transform: translate(65vw, -120px) rotate(200deg);
  }
  100% {
    transform: translate(130vw, 0) rotate(400deg);
  }
}

// Less motion asked: no juggling, no distraction.
@media (prefers-reduced-motion: reduce) {
  .jongleur-player__portrait--juggling,
  .jongleur-player__stage--shake {
    animation: none;
  }

  .jongleur-player__balls {
    display: none;
  }
}
</style>
