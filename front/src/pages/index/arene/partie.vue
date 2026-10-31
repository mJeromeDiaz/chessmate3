<template>
  <q-page
    class="arena-game"
    data-testid="arena-game"
    :data-status="game?.status"
  >
    <p v-if="error" class="arena-game__error" role="alert">
      {{ error }}
      <router-link to="/arene">{{ $t('arena.result.back') }}</router-link>
    </p>
    <div v-else-if="!game" class="arena-game__wait">
      <q-spinner size="2em" />
      <span>{{ $t('arena.game.starting') }}</span>
    </div>

    <GameReview
      v-else-if="reviewing && finished"
      :game="game"
      :boss-name="bossName"
      :player-name="playerName"
      @back="reviewing = false"
    />

    <div v-else class="arena-game__layout">
      <div class="arena-game__table">
        <MaterialLine
          class="arena-game__material"
          :side="material[bossSide]"
          :piece-color="game.color.charAt(0)"
          testid="arena-material-boss"
        />
        <div class="arena-game__board">
          <ChessBoard
            ref="board"
            :fen="shownFen"
            :orientation="game.color"
            :movable-color="
              arena.playerTurn.value && !stepper.browsing.value
                ? game.color
                : null
            "
            :premove-color="premove"
            :highlights="highlights"
            @move="onMove"
          />
        </div>
        <MaterialLine
          class="arena-game__material"
          :side="material[game.color]"
          :piece-color="bossSide.charAt(0)"
          testid="arena-material-player"
        />
      </div>

      <div class="arena-game__side">
        <BossBanner
          class="arena-game__banner"
          :character="game.boss"
          :name="bossName"
          :meta="
            $t('arena.game.bossMeta', {
              elo: game.elo,
              side: $t(`arena.game.side.${bossSide}`)
            })
          "
          :mood="mood"
          :line="line"
          :reaction-key="arena.reaction.value.n"
          :glyph="glyph"
          :colors="colors"
          :clock="formatClock(arena.clocks.value.boss)"
          :running="active && game.turn === 'boss'"
        />

        <p class="arena-game__status" data-testid="arena-status">{{
          status
        }}</p>
        <p
          v-if="arena.notice.value"
          class="arena-game__notice"
          data-testid="arena-notice"
        >
          {{ $t(`arena.game.${arena.notice.value}`) }}
        </p>

        <div
          v-if="stepper.browsing.value"
          class="arena-game__browsing"
          data-testid="arena-browsing"
        >
          <span>{{
            stepper.moved.value
              ? $t('arena.game.browsingMoved', { name: bossName })
              : $t('arena.game.browsing')
          }}</span>
          <button
            type="button"
            class="arena-game__live"
            data-testid="arena-live"
            @click="stepper.live()"
          >
            {{ $t('arena.game.live') }}
          </button>
        </div>

        <div class="arena-game__moves">
          <div class="arena-game__moves-title gt-sm">
            {{ $t('arena.game.moves') }}
          </div>
          <div
            ref="moveStrip"
            class="arena-game__moves-list"
            data-testid="arena-moves"
          >
            <span v-for="pair in pairs" :key="pair.n" class="arena-game__move"
              ><span class="arena-game__move-n">{{ pair.n }}.</span>
              <button
                v-for="ply in pair.plies"
                :key="ply.index"
                type="button"
                class="arena-game__ply"
                :class="{
                  'arena-game__ply--on': stepper.cursor.value === ply.index
                }"
                :data-testid="`arena-ply-${ply.index}`"
                @click="stepper.go(ply.index)"
              >
                {{ ply.san }}
              </button></span
            >
            <span v-if="!pairs.length" class="arena-game__move-empty">{{
              game.color === 'white'
                ? $t('arena.game.noMoves')
                : $t('arena.game.bossFirst')
            }}</span>
          </div>
          <div class="arena-game__steps">
            <button
              v-for="step in STEPS"
              :key="step.id"
              type="button"
              class="arena-game__step"
              :aria-label="$t(`arena.review.steps.${step.id}`)"
              :data-testid="`arena-step-${step.id}`"
              :disabled="
                step.forward
                  ? stepper.cursor.value >= stepper.last.value
                  : stepper.cursor.value <= 0
              "
              @click="stepper.go(step.to())"
            >
              <q-icon :name="step.icon" size="30px" />
            </button>
          </div>
        </div>

        <div class="arena-game__you">
          <span class="arena-game__avatar" aria-hidden="true">{{
            $t('arena.game.you').charAt(0)
          }}</span>
          <div class="arena-game__you-who">
            <div class="arena-game__you-name">{{ $t('arena.game.you') }}</div>
            <div class="arena-game__you-meta">
              {{ $t(`arena.game.side.${game.color}`) }}
            </div>
          </div>
          <div
            class="arena-game__clock"
            :class="{
              'arena-game__clock--on': active && game.turn === 'player'
            }"
            data-testid="arena-player-clock"
          >
            {{ formatClock(arena.clocks.value.player) }}
          </div>
        </div>

        <div v-if="active" class="arena-game__actions">
          <button
            type="button"
            class="arena-game__action"
            data-testid="arena-abort"
            :disabled="acting"
            @click="confirmAct('abort')"
          >
            ↶ {{ $t('arena.game.actions.abort') }}
          </button>
          <button
            type="button"
            class="arena-game__action arena-game__action--restart"
            data-testid="arena-restart"
            :disabled="acting"
            @click="confirmAct('restart')"
          >
            ⟳ {{ $t('arena.game.actions.restart') }}
          </button>
          <button
            type="button"
            class="arena-game__action arena-game__action--resign"
            data-testid="arena-resign"
            :disabled="acting"
            @click="confirmAct('resign')"
          >
            ⚑ {{ $t('arena.game.actions.resign') }}
          </button>
        </div>
      </div>
    </div>

    <GameResult
      v-if="game && finished"
      :open="finished && !reviewing"
      :character="game.boss"
      :status="game.status"
      :end="game.end"
      :line="line"
      :xp="game.xp"
      :crystals="game.crystals"
      :glyph="glyph"
      :colors="colors"
      :busy="acting"
      @rematch="rematch"
      @back="router.push('/arene')"
      @review="reviewing = true"
    />
  </q-page>
</template>

<script setup>
/**
 * A game against a boss of the arena (docs/ARENA.md, § 3, design « Défier les boss »): the boss
 * talking and its clock, the board, the moves, the player's clock, then cancel, restart or
 * resign at any time; the result at the end. The game in progress is resumed; without one, the
 * query's settings (`?boss=…&color=…&cadence=…`, from the boss's sheet) start a new one. The
 * moves can be stepped through while the game goes on (the board stays on the move looked at,
 * no move played until back), each side's material shown as on Lichess.
 */
import { useQuasar } from 'quasar'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BossBanner from '@/components/arena/BossBanner.vue'
import GameResult from '@/components/arena/GameResult.vue'
import GameReview from '@/components/arena/GameReview.vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import MaterialLine from '@/components/chess/MaterialLine.vue'
import { useArenaGame } from '@/composables/arena/useArenaGame'
import { useMoveStepper } from '@/composables/arena/useMoveStepper'
import { usePageHeader } from '@/composables/layout/usePageHeader'
import { t } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { useShopStore } from '@/stores/shop'
import { apiErrorMessage } from '@/utils/apiError'
import { bossText, cardPicture, paletteOf } from '@/utils/arena'
import { REACTION_MOODS } from '@/utils/arenaBoss'
import { materialOf } from '@/utils/chess/material'

definePage({
  meta: {
    auth: 'required',
    header: { title: 'arena.page.title' }
  }
})

const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const shop = useShopStore()
const auth = useAuthStore()
const arena = useArenaGame()

const error = ref('')
const acting = ref(false)
/** The game over, reviewed in place of the board. */
const reviewing = ref(false)
/** @type {import('vue').Ref<any>} */
const board = ref(null)
/** @type {import('vue').Ref<HTMLElement|null>} */
const moveStrip = ref(null)

const game = computed(() => arena.game.value)
const active = computed(() => arena.active.value)
const finished = computed(
  () => !!game.value && ['won', 'lost', 'drawn'].includes(game.value.status)
)
const bossName = computed(() =>
  game.value ? bossText(game.value.boss, 'name') : ''
)
const playerName = computed(
  () => /** @type {any} */ (auth.profile)?.displayName || t('arena.game.you')
)
const bossSide = computed(() =>
  game.value?.color === 'white' ? 'black' : 'white'
)
const colors = computed(() => paletteOf(game.value?.boss ?? ''))
const glyph = computed(() => cardPicture(game.value?.boss ?? '').glyph || '♚')
const stepper = useMoveStepper(
  computed(() => game.value?.moves ?? []),
  computed(() => game.value?.id)
)
/** The live position, or the one looked at while stepping back. */
const shownFen = computed(() =>
  stepper.browsing.value ? stepper.shown.value.fen : arena.position.value.fen
)
const material = computed(() => materialOf(shownFen.value))
/** The moves by move number, each half-move with its position's index (n: after the n-th move). */
const pairs = computed(() => {
  /** @type {{n: number, plies: {index: number, san: string}[]}[]} */
  const list = []
  arena.position.value.sans.forEach((san, i) => {
    if (i % 2 === 0) list.push({ n: i / 2 + 1, plies: [] })
    list[list.length - 1].plies.push({ index: i + 1, san })
  })
  return list
})
const STEPS = [
  { id: 'first', icon: 'first_page', forward: false, to: () => 0 },
  {
    id: 'previous',
    icon: 'chevron_left',
    forward: false,
    to: () => stepper.cursor.value - 1
  },
  {
    id: 'next',
    icon: 'chevron_right',
    forward: true,
    to: () => stepper.cursor.value + 1
  },
  {
    id: 'last',
    icon: 'last_page',
    forward: true,
    to: () => stepper.last.value
  }
]
const premove = computed(() =>
  active.value && game.value?.turn === 'boss' && !stepper.browsing.value
    ? game.value.color
    : null
)
const highlights = computed(() => {
  const last = arena.position.value.lastMove
  const uci = stepper.browsing.value
    ? stepper.shown.value.uci
    : last && last.from + last.to
  return uci
    ? [
        { square: uci.slice(0, 2), type: /** @type {const} */ ('lastMove') },
        { square: uci.slice(2, 4), type: /** @type {const} */ ('lastMove') }
      ]
    : []
})
const mood = computed(() =>
  arena.thinkingFace.value ? 'focus' : REACTION_MOODS[arena.reaction.value.key]
)
/** The boss's words: its taunt first, its own lines at the end, the arena's reactions between. */
const line = computed(() => {
  const g = game.value
  if (!g) return ''
  const { key, n } = arena.reaction.value
  if (key === 'start') return bossText(g.boss, 'taunt')
  if (key === 'won') return bossText(g.boss, 'defeat')
  if (key === 'lost') return bossText(g.boss, 'win')
  return t(`arena.reactions.${key}.${n % 3}`)
})
const status = computed(() => {
  const g = game.value
  if (!g || !active.value) return ''
  if (g.turn === 'boss') {
    const progress = arena.maiaProgress.value
    if (arena.maiaStatus.value === 'loading')
      return t('arena.game.loadingMaia', {
        name: bossName.value,
        percent: Math.round((progress ?? 0) * 100)
      })
    return t('arena.game.bossTurn', { name: bossName.value })
  }
  return g.clocksRunning ? t('arena.game.yourTurn') : t('arena.game.freeMove')
})

usePageHeader(() => ({
  title: game.value ? t('arena.game.title', { name: bossName.value }) : null,
  subtitle: game.value
    ? t('arena.game.kicker', { cadence: game.value.cadence })
    : null
}))

watch(
  () => pairs.value.length,
  async () => {
    await nextTick()
    const strip = moveStrip.value
    if (strip) {
      strip.scrollLeft = strip.scrollWidth
      strip.scrollTop = strip.scrollHeight
    }
  }
)

// A first win's crystals: the menus get the new balance.
watch(
  () => game.value?.crystals,
  crystals => {
    if (crystals && shop.state) shop.load().catch(() => {})
  }
)

onMounted(async () => {
  window.addEventListener('keydown', onKey)
  arena.preload().catch(() => {})
  try {
    if (await arena.resume()) return
    const { boss, color, cadence } = route.query
    if (typeof boss !== 'string' || !boss) {
      router.replace('/arene')
      return
    }
    await arena.begin({
      boss,
      color: typeof color === 'string' ? color : 'white',
      cadence: typeof cadence === 'string' ? cadence : '5+3'
    })
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  arena.leave()
})

/**
 * The arrows step through the moves, Home goes to the start, End back to the game.
 *
 * @param {KeyboardEvent} event
 */
function onKey(event) {
  if (!game.value || reviewing.value) return
  const target = /** @type {HTMLElement|null} */ (event.target)
  if (target?.closest('input, textarea, [contenteditable]')) return
  if (event.key === 'ArrowLeft') stepper.go(stepper.cursor.value - 1)
  else if (event.key === 'ArrowRight') stepper.go(stepper.cursor.value + 1)
  else if (event.key === 'Home') stepper.go(0)
  else if (event.key === 'End') stepper.live()
  else return
  event.preventDefault()
}

/** @param {number} ms */
function formatClock(ms) {
  const seconds = Math.ceil(ms / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

/** @param {{uci: string}} move */
async function onMove(move) {
  const played = await arena.play(move.uci)
  if (!played) board.value?.setPosition(arena.position.value.fen)
}

/** @param {'abort'|'restart'|'resign'} action */
function confirmAct(action) {
  $q.dialog({
    title: t(`arena.game.confirm.${action}Title`),
    message: t(`arena.game.confirm.${action}Message`),
    cancel: { flat: true, noCaps: true, label: t('arena.game.confirm.back') },
    ok: {
      noCaps: true,
      color: action === 'resign' ? 'negative' : 'primary',
      label: t(`arena.game.actions.${action}`)
    }
  }).onOk(async () => {
    acting.value = true
    try {
      await arena.act(action)
      if (action === 'abort') router.push('/arene')
    } catch (e) {
      $q.notify({ type: 'negative', message: apiErrorMessage(e) })
    } finally {
      acting.value = false
    }
  })
}

async function rematch() {
  acting.value = true
  reviewing.value = false
  try {
    await arena.rematch()
  } catch (e) {
    $q.notify({ type: 'negative', message: apiErrorMessage(e) })
  } finally {
    acting.value = false
  }
}
</script>

<style scoped lang="scss">
.arena-game {
  background: var(--cm-page);
}

.arena-game__error,
.arena-game__wait {
  margin: 24px 16px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  font-weight: 700;
  color: var(--cm-muted);
}

.arena-game__error {
  color: var(--cm-danger);
}

.arena-game__layout {
  display: flex;
  flex-direction: column;
}

.arena-game__table {
  width: 100%;
  max-width: 760px;
  margin: 0 auto;
}

// Edge to edge on a phone: the board's own cap (92vw) left a margin around it.
.arena-game__board {
  --board-max: 100%;
  width: 100%;
  max-width: 760px;
  margin: 0 auto;
  aspect-ratio: 1;
}

.arena-game__side {
  display: flex;
  flex-direction: column;
}

// On a phone: the boss above the board, the rest below. The column is held to the screen's
// width: an implicit (auto) column grew with the move list, one unbroken line, and the browser
// then zoomed the whole page out (board with a margin, wide step buttons).
@media (max-width: 1023px) {
  .arena-game__layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    grid-template-areas: 'banner' 'board' 'rest';
  }
  .arena-game__side {
    display: contents;
  }
  .arena-game__banner {
    grid-area: banner;
  }
  .arena-game__table {
    grid-area: board;
  }
  .arena-game__side > :not(.arena-game__banner) {
    grid-column: 1;
  }
}

.arena-game__status,
.arena-game__notice {
  margin: 8px 16px 0;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-muted);
  text-align: center;
}

.arena-game__notice {
  color: var(--cm-orange-ink);
}

.arena-game__moves {
  margin-top: 8px;
  background: var(--cm-surface);
  border-bottom: 1px solid var(--cm-line);
}

.arena-game__moves-title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 16px;
}

.arena-game__moves-list {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding: 10px 16px;
}

.arena-game__move {
  flex: none;
  font-size: 13px;
  font-weight: 700;
  padding: 4px 8px;
  border-radius: 8px;
  background: var(--cm-inset);
  font-variant-numeric: tabular-nums;
}

.arena-game__ply {
  border: none;
  background: none;
  padding: 0 2px;
  border-radius: 6px;
  font: inherit;
  color: inherit;
  cursor: pointer;
}

.arena-game__ply--on {
  background: var(--cm-brand-soft);
}

.arena-game__steps {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 6px;
  padding: 0 16px 10px;
}

.arena-game__step {
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  border-radius: 10px;
  background: var(--cm-inset);
  color: var(--cm-ink);
  cursor: pointer;

  &:disabled {
    opacity: 0.4;
    cursor: default;
  }
}

.arena-game__browsing {
  margin: 8px 16px 0;
  padding: 8px 12px;
  border-radius: 14px;
  background: var(--cm-orange-soft);
  color: var(--cm-orange-ink);
  font-size: 13px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.arena-game__live {
  flex: none;
  height: 32px;
  padding: 0 12px;
  border: none;
  border-radius: 10px;
  background: var(--cm-brand);
  color: #fff;
  font: inherit;
  font-weight: 800;
  cursor: pointer;
}

.arena-game__material {
  padding: 4px 16px;
  color: var(--cm-muted);
}

.arena-game__move-n {
  color: var(--cm-muted);
  margin-right: 2px;
}

.arena-game__move-empty {
  font-size: 13px;
  color: var(--cm-muted);
}

.arena-game__you {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
}

.arena-game__avatar {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: var(--cm-brand);
  color: #fff;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 17px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.arena-game__you-who {
  flex: 1;
  min-width: 0;
}

.arena-game__you-name {
  font-weight: 800;
  font-size: 15px;
}

.arena-game__you-meta {
  font-size: 11.5px;
  color: var(--cm-muted);
  font-weight: 700;
}

.arena-game__clock {
  height: 34px;
  padding: 0 12px;
  border-radius: 10px;
  background: var(--cm-inset);
  color: var(--cm-muted);
  font-weight: 800;
  font-size: 16px;
  display: flex;
  align-items: center;
  font-variant-numeric: tabular-nums;
}

.arena-game__clock--on {
  background: #1b1530;
  color: var(--cm-lime);
}

:global(.body--dark) .arena-game__clock--on {
  background: var(--cm-lime);
  color: #1b1530;
}

.arena-game__actions {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  padding: 10px 16px 24px;
  background: var(--cm-surface);
  border-top: 1px solid var(--cm-line);
}

.arena-game__action {
  height: 50px;
  border: none;
  border-radius: 16px;
  background: var(--cm-inset);
  color: var(--cm-ink);
  font: inherit;
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;

  &:disabled {
    opacity: 0.6;
    cursor: default;
  }
}

.arena-game__action--restart {
  background: var(--cm-brand-soft);
}

.arena-game__action--resign {
  background: var(--cm-danger-soft);
}

@media (min-width: 1024px) {
  .arena-game__layout {
    flex-direction: row;
    gap: 28px;
    padding: 14px 28px;
    max-width: 1600px;
    margin: 0 auto;
    align-items: flex-start;
  }

  .arena-game__table {
    flex: none;
    width: auto;
    max-width: none;
    margin: 0;
  }

  .arena-game__material {
    padding: 2px 4px;
  }

  .arena-game__board {
    flex: none;
    // As tall as the window under the header and the two material lines, the side column kept
    // at 320 px at least; the board fills it (no 560 px cap).
    --board-max: 100%;
    width: min(calc(100vh - 150px), calc(100vw - 404px));
    max-width: none;
    margin: 0;
    border-radius: 22px;
    overflow: hidden;
  }

  .arena-game__side {
    flex: 1 1 400px;
    min-width: 320px;
    max-width: 460px;
    gap: 14px;
  }

  .arena-game__status,
  .arena-game__notice {
    margin: 0;
    text-align: left;
  }

  .arena-game__moves {
    margin: 0;
    border: none;
    border-radius: 22px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .arena-game__steps {
    padding: 0;
  }

  .arena-game__browsing {
    margin: 0;
  }

  .arena-game__moves-list {
    flex-wrap: wrap;
    align-content: flex-start;
    max-height: 260px;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 0;
  }

  .arena-game__you {
    background: var(--cm-surface);
    border-radius: 22px;
    padding: 14px 16px;
  }

  .arena-game__clock {
    height: 44px;
    padding: 0 16px;
    font-size: 22px;
  }

  .arena-game__actions {
    background: none;
    border: none;
    padding: 0;
  }

  .arena-game__action {
    background: var(--cm-surface);
  }
  .arena-game__action--restart {
    background: var(--cm-brand-soft);
  }
  .arena-game__action--resign {
    background: var(--cm-danger-soft);
  }
}
</style>
