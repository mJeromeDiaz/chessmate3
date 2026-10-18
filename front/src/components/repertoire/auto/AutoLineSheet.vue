<template>
  <q-dialog
    :model-value="!!line"
    :position="wide ? 'standard' : 'bottom'"
    :maximized="!wide"
    transition-show="slide-up"
    transition-hide="slide-down"
    @update:model-value="value => !value && emit('close')"
  >
    <div
      v-if="line && tree"
      class="ra ra-sheet"
      :class="{ 'ra-sheet--wide': wide }"
      data-testid="auto-sheet"
    >
      <div class="ra-sheet__grip" />
      <div class="ra-sheet__head">
        <div class="ra-sheet__titles">
          <span class="ra-sheet__sub">{{
            $t('repertoireAuto.sheet.sub', {
              n: line.number,
              family: tree.family,
              side:
                tree.color === 'white'
                  ? $t('repertoireAuto.sheet.white')
                  : $t('repertoireAuto.sheet.black')
            })
          }}</span>
          <span class="ra-sheet__name">{{ line.name }}</span>
        </div>
        <span
          class="ra-pill ra-pill--tag"
          :style="{ background: TAGS[line.tag].color }"
          >{{ TAGS[line.tag].label }}</span
        >
        <button
          type="button"
          class="ra-sheet__close"
          :aria-label="$t('common.actions.close')"
          @click="emit('close')"
          >✕</button
        >
      </div>

      <div class="ra-sheet__body">
        <div class="ra-sheet__play">
          <div class="ra-sheet__board">
            <ChessBoard
              :fen="fen"
              :orientation="tree.color"
              :highlights="highlights"
              :coordinates="false"
            />
          </div>

          <div class="ra-sheet__nav">
            <button
              type="button"
              class="ra-nav"
              :aria-label="$t('repertoireAuto.sheet.first')"
              @click="go(0)"
              >⏮</button
            >
            <button
              type="button"
              class="ra-nav"
              :aria-label="$t('repertoireAuto.sheet.previous')"
              @click="go(ply - 1)"
              >◀</button
            >
            <div class="ra-sheet__current" data-testid="auto-sheet-current">{{
              currentLabel
            }}</div>
            <button
              type="button"
              class="ra-nav ra-nav--night"
              :aria-label="$t('repertoireAuto.sheet.next')"
              @click="go(ply + 1)"
              >▶</button
            >
            <button
              type="button"
              class="ra-nav ra-nav--night"
              :aria-label="$t('repertoireAuto.sheet.last')"
              @click="go(nodes.length)"
              >⏭</button
            >
          </div>

          <div class="ra-sheet__chips">
            <button
              v-for="(node, i) in nodes"
              :key="node.path"
              type="button"
              class="ra-move"
              :class="{
                'ra-move--trunk': i < tree.trunk,
                'ra-move--current': i + 1 === ply
              }"
              @click="go(i + 1)"
            >
              {{ moveLabel(node.depth, node.san) }}
              <span
                v-if="dots[node.depth]"
                class="ra-move__dot"
                :style="{ background: dots[node.depth] }"
              />
            </button>
          </div>
        </div>

        <div class="ra-sheet__details">
          <div class="ra-sheet__tiles">
            <div class="ra-stat">
              <span class="ra-stat__label">{{
                $t('repertoireAuto.sheet.frequency')
              }}</span>
              <span class="ra-stat__value">{{
                $t('repertoireAuto.sheet.percent', {
                  n: percent(line.games, tree.games)
                })
              }}</span>
              <span class="ra-stat__sub">{{
                $t('repertoireAuto.sheet.frequencySub', {
                  games: line.games,
                  total: tree.games,
                  family: tree.family
                })
              }}</span>
            </div>
            <div class="ra-stat">
              <span class="ra-stat__label">{{
                $t('repertoireAuto.sheet.score')
              }}</span>
              <span class="ra-stat__value">{{
                $t('repertoireAuto.sheet.percent', { n: score(line) })
              }}</span>
              <span class="ra-stat__sub">{{
                $t('repertoireAuto.sheet.scoreSub')
              }}</span>
            </div>
          </div>

          <div class="ra-sheet__section">
            <span class="ra-kicker">{{
              $t('repertoireAuto.sheet.outcomes')
            }}</span>
            <div class="ra-outcomes">
              <div
                v-for="o in OUTCOMES"
                :key="o.key"
                :style="{
                  width: `${percent(line[o.key], line.games)}%`,
                  background: o.color
                }"
              />
            </div>
            <div class="ra-legend">
              <div v-for="o in OUTCOMES" :key="o.key" class="ra-legend__item">
                <span
                  class="ra-legend__swatch"
                  :style="{ background: o.color }"
                />
                <span class="ra-legend__label">{{ o.label }}</span>
                <b>{{
                  $t('repertoireAuto.sheet.percent', {
                    n: percent(line[o.key], line.games)
                  })
                }}</b>
              </div>
            </div>
            <div class="ra-sheet__facts">
              <span v-if="line.analysed > 0">{{
                $t(
                  'repertoireAuto.sheet.errors',
                  { n: line.errored, analysed: line.analysed },
                  line.analysed
                )
              }}</span>
              <span v-else>{{ $t('repertoireAuto.sheet.notAnalysed') }}</span>
              <span data-testid="auto-sheet-eval">{{
                $t('repertoireAuto.sheet.end', { value: evalText(line) })
              }}</span>
              <span data-testid="auto-sheet-stop">{{ stopText(line) }}</span>
            </div>
          </div>

          <div v-if="events.length" class="ra-sheet__section">
            <span class="ra-kicker">{{
              $t('repertoireAuto.sheet.events')
            }}</span>
            <button
              v-for="(ev, i) in events"
              :key="i"
              type="button"
              class="ra-event"
              :class="{ 'ra-event--current': ev.ply === ply }"
              @click="go(ev.ply)"
            >
              <span
                class="ra-pill ra-pill--small ra-pill--tag"
                :style="{ background: EVENT_KINDS[ev.kind].color }"
                >{{ EVENT_KINDS[ev.kind].label }}</span
              >
              <span class="ra-event__text">{{ ev.text }}</span>
            </button>
          </div>

          <div v-if="choices.length" class="ra-sheet__section">
            <span class="ra-kicker">{{
              $t('repertoireAuto.sheet.other')
            }}</span>
            <button
              v-for="c in choices"
              :key="`${c.parent}|${c.uci}`"
              type="button"
              class="ra-choice"
              :disabled="busy"
              data-testid="auto-choice"
              @click="
                emit('choose', {
                  treeKey: tree.key,
                  parent: c.parent,
                  uci: c.uci
                })
              "
            >
              <span class="ra-choice__move">{{ c.label }}</span>
              <span class="ra-choice__why">{{ c.why }}</span>
            </button>
          </div>

          <button
            v-if="line.stop?.kind === 'unexplored'"
            type="button"
            class="ra-further"
            :disabled="busy || exploring"
            data-testid="auto-explore-further"
            @click="emit('explore', { treeKey: tree.key, line })"
            >{{
              exploring
                ? $t('repertoireAuto.explore.exploring')
                : $t('repertoireAuto.explore.further')
            }}</button
          >

          <button
            type="button"
            class="ra-remove"
            :disabled="busy"
            data-testid="auto-remove"
            @click="emit('remove', { treeKey: tree.key, line })"
            >{{ $t('repertoireAuto.sheet.remove') }}</button
          >
        </div>
      </div>
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * The sheet of a line (design "Repertoire auto"): the board to replay it, its moves, how often
 * it was played and how it went, what happened along it (mistakes Lichess found, long thinks),
 * the other moves the user played or Lichess advised (to play instead), and its removal.
 */
import { computed, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import {
  OUTCOMES,
  TAGS,
  evalText,
  lineEvents,
  moveLabel,
  percent,
  score,
  stopText
} from '@/utils/repertoireAuto'
import { t } from '@/i18n'

/**
 * A kind of event along a line, its label read when shown.
 *
 * @param {string} kind
 * @param {string} color
 */
function eventKind(kind, color) {
  return {
    get label() {
      return t(`repertoireAuto.sheet.kinds.${kind}`)
    },
    color
  }
}

const INITIAL_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'

const EVENT_KINDS = {
  error: eventKind('error', TAGS.risk.color),
  key: eventKind('key', '#CBBEFF'),
  good: eventKind('good', TAGS.weapon.color),
  alt: eventKind('alt', TAGS.solid.color)
}

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/repertoireAuto').Tree|null>} */
  tree: { type: Object, default: null },
  /** @type {import('vue').PropType<import('@/utils/repertoireAuto').Line|null>} */
  line: { type: Object, default: null },
  busy: { type: Boolean, default: false },
  /** "Explore further" is running on this line. */
  exploring: { type: Boolean, default: false }
})
const emit = defineEmits(['close', 'choose', 'remove', 'explore'])

const $q = useQuasar()
/** A computer screen: a centred window, the board beside the details; a phone: full screen. */
const wide = computed(() => $q.screen.width >= 1024)

const ply = ref(0)

/** @type {import('vue').ComputedRef<import('@/utils/repertoireAuto').TreeNode[]>} */
const nodes = computed(() => {
  if (!props.tree || !props.line) return []
  const byPath = new Map(props.tree.nodes.map(n => [n.path, n]))
  return props.line.nodes.map(p => byPath.get(p)).filter(Boolean)
})

watch(
  () => props.line?.path,
  () => {
    ply.value = nodes.value.length
  },
  { immediate: true }
)

/** @param {number} target */
function go(target) {
  ply.value = Math.max(0, Math.min(nodes.value.length, target))
}

const current = computed(() =>
  ply.value > 0 ? nodes.value[ply.value - 1] : null
)
const fen = computed(() =>
  current.value ? `${current.value.fen} 0 1` : INITIAL_FEN
)
const highlights = computed(() =>
  current.value
    ? [
        { square: current.value.uci.slice(0, 2), type: 'lastMove' },
        { square: current.value.uci.slice(2, 4), type: 'lastMove' }
      ]
    : []
)
const currentLabel = computed(() =>
  current.value
    ? moveLabel(current.value.depth, current.value.san, true)
    : t('repertoireAuto.sheet.start')
)

const events = computed(() => lineEvents(nodes.value))

/** A coloured dot on the moves something happened on (the most serious event wins). */
const dots = computed(() => {
  const order = ['alt', 'good', 'key', 'error']
  /** @type {Record<number, string>} */
  const byPly = {}
  const rank = {}
  for (const ev of events.value) {
    if ((rank[ev.ply] ?? -1) < order.indexOf(ev.kind)) {
      rank[ev.ply] = order.indexOf(ev.kind)
      byPly[ev.ply] = EVENT_KINDS[ev.kind].color
    }
  }
  return byPly
})

/** The moves the user may play instead, along the line: theirs, and Lichess' advice. */
const choices = computed(() =>
  nodes.value
    .filter(n => n.user)
    .flatMap(n => [
      ...n.alternatives.map(a => ({
        parent: n.parent,
        uci: a.uci,
        label: t('repertoireAuto.sheet.insteadOf', {
          move: moveLabel(n.depth, a.san, true),
          played: moveLabel(n.depth, n.san, true)
        }),
        why: t('repertoireAuto.sheet.played', { n: a.games, score: score(a) })
      })),
      ...(n.suggestion
        ? [
            {
              parent: n.parent,
              uci: n.suggestion.uci,
              label: t('repertoireAuto.sheet.insteadOf', {
                move: moveLabel(n.depth, n.suggestion.san, true),
                played: moveLabel(n.depth, n.san, true)
              }),
              why: t('repertoireAuto.sheet.advised')
            }
          ]
        : [])
    ])
)
</script>

<style scoped lang="scss">
.ra-sheet {
  width: 100%;
  height: 100%;
  background: var(--ra-card);
  color: var(--ra-ink);
  display: flex;
  flex-direction: column;

  &--wide {
    width: min(1080px, 94vw);
    max-width: none !important;
    height: min(760px, 92vh);
    border-radius: 28px;
  }
}

.ra-sheet__grip {
  width: 40px;
  height: 5px;
  border-radius: 5px;
  background: var(--ra-line);
  align-self: center;
  margin: 10px 0 4px;
}

.ra-sheet__head {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 4px 18px 8px;
}

.ra-sheet__titles {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.ra-sheet__sub {
  font-size: 12px;
  font-weight: 700;
  color: var(--ra-muted);
}

.ra-sheet__name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
  line-height: 1.05;
}

.ra-sheet__close {
  width: 36px;
  height: 36px;
  flex: none;
  border-radius: 12px;
  border: none;
  background: var(--ra-subtle);
  color: var(--ra-ink);
  font-size: 16px;
  font-weight: 800;
  cursor: pointer;
}

.ra-sheet__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 4px 18px 28px;
  display: flex;
  flex-direction: column;
  gap: 14px;

  .ra-sheet--wide & {
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);
    gap: 28px;
    overflow: hidden;
    padding: 4px 28px 24px;
  }
}

.ra-sheet__play,
.ra-sheet__details {
  display: flex;
  flex-direction: column;
  gap: 14px;

  .ra-sheet--wide & {
    min-height: 0;
    overflow-y: auto;
  }
}

.ra-sheet__board {
  width: 100%;
  max-width: 480px;
  align-self: center;
  // The whole board stays in view: the width a phone's screen leaves, or the window's height.
  --board-max: min(100%, 92vw, 480px);

  .ra-sheet--wide & {
    --board-max: min(100%, calc(92vh - 260px), 480px);
  }
}

.ra-sheet__nav {
  display: flex;
  align-items: center;
  gap: 6px;
}

.ra-nav {
  width: 44px;
  height: 44px;
  flex: none;
  border-radius: 14px;
  border: none;
  background: var(--ra-subtle);
  color: var(--ra-ink);
  font-size: 15px;
  font-weight: 800;
  cursor: pointer;

  &--night {
    background: var(--ra-night);
    color: #fff;
  }
}

.ra-sheet__current {
  flex: 1;
  text-align: center;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 17px;
}

.ra-sheet__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.ra-move {
  position: relative;
  height: 30px;
  padding: 0 8px;
  border-radius: 9px;
  border: none;
  background: var(--ra-subtle);
  color: var(--ra-ink);
  font-weight: 700;
  font-size: 13px;
  cursor: pointer;

  &--trunk {
    color: var(--ra-muted);
  }

  &--current {
    background: var(--ra-night);
    color: #fff;
  }
}

.ra-move__dot {
  position: absolute;
  right: -2px;
  top: -2px;
  width: 9px;
  height: 9px;
  border-radius: 50%;
  border: 2px solid var(--ra-card);
}

.ra-sheet__tiles {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.ra-stat {
  background: var(--ra-subtle);
  border-radius: 18px;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.ra-stat__label {
  font-size: 12px;
  font-weight: 700;
  color: var(--ra-muted);
}

.ra-stat__value {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 28px;
  line-height: 1;
}

.ra-stat__sub {
  font-size: 12px;
  color: var(--ra-ink-soft);
}

.ra-sheet__section {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ra-outcomes {
  display: flex;
  height: 14px;
  border-radius: 8px;
  overflow: hidden;
  gap: 2px;
}

.ra-legend {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 4px 12px;
}

.ra-legend__item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}

.ra-legend__swatch {
  width: 10px;
  height: 10px;
  border-radius: 3px;
  flex: none;
}

.ra-legend__label {
  flex: 1;
}

.ra-sheet__facts {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 13px;
  color: var(--ra-ink-soft);
}

.ra-event {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px;
  border-radius: 16px;
  border: 2px solid var(--ra-line);
  background: var(--ra-card);
  text-align: left;
  cursor: pointer;
  color: var(--ra-ink);

  &--current {
    border-color: var(--ra-ink);
  }
}

.ra-event__text {
  flex: 1;
  font-size: 13px;
  line-height: 1.4;
  color: var(--ra-ink-soft);
}

.ra-choice {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 12px;
  border-radius: 16px;
  border: 2px dashed var(--ra-faint);
  background: var(--ra-card);
  color: var(--ra-ink);
  text-align: left;
  cursor: pointer;
}

.ra-choice__move {
  font-weight: 800;
  font-size: 14px;
}

.ra-choice__why {
  font-size: 12px;
  color: var(--ra-muted);
}

.ra-further {
  height: 50px;
  border-radius: 16px;
  border: 2px solid var(--ra-accent);
  background: var(--ra-card);
  color: inherit;
  font-weight: 800;
  font-size: 14px;
  cursor: pointer;
  flex: none;
}

.ra-remove {
  height: 50px;
  border-radius: 16px;
  border: 2px solid #ff6fae;
  background: var(--ra-card);
  color: #c02670;
  font-weight: 800;
  font-size: 14px;
  cursor: pointer;
  flex: none;
}
</style>
