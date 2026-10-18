<template>
  <div class="ra-screen ra-up">
    <div class="ra-cols">
      <div class="ra-cols__side">
        <div class="ra-tree__head">
          <div class="ra-tree__intro">
            <h1 class="ra-title ra-title--small">{{
              $t('repertoireAuto.treeScreen.title')
            }}</h1>
            <div class="ra-text ra-text--small" data-testid="auto-tree-say">{{
              treeSay(lines, games)
            }}</div>
          </div>
          <img
            :src="profImage('albert-v2-wow')"
            alt=""
            class="ra-tree__albert"
          />
        </div>

        <div class="ra-tree__chips">
          <span class="ra-pill">{{
            $t('repertoireAuto.treeScreen.lines', lines.length)
          }}</span>
          <span
            class="ra-pill ra-pill--tag"
            :style="{ background: TAGS.risk.color }"
            >{{
              $t('repertoireAuto.treeScreen.risk', { n: count('risk') })
            }}</span
          >
          <span
            class="ra-pill ra-pill--tag"
            :style="{ background: TAGS.weapon.color }"
            >{{
              $t('repertoireAuto.treeScreen.weapons', count('weapon'))
            }}</span
          >
        </div>

        <div class="ra-actions ra-actions--split">
          <button
            type="button"
            class="ra-cta ra-cta--card"
            data-testid="auto-back-select"
            @click="emit('select')"
            >{{ $t('repertoireAuto.treeScreen.select') }}</button
          >
          <button
            type="button"
            class="ra-cta ra-cta--accent ra-cta--wide"
            :disabled="busy || !lines.length || unconfirmed.length > 0"
            data-testid="auto-validate"
            @click="emit('validate')"
            >{{ $t('repertoireAuto.treeScreen.validate') }}</button
          >
        </div>

        <div
          v-if="unconfirmed.length"
          class="ra-notice"
          data-testid="auto-unconfirmed"
        >
          <span>{{
            $t('repertoireAuto.short.toConfirm', unconfirmed.length)
          }}</span>
          <button
            type="button"
            class="ra-link"
            :disabled="busy"
            data-testid="auto-short-keep-all"
            @click="emit('keepAll')"
            >{{ $t('repertoireAuto.short.keepAll') }}</button
          >
        </div>

        <div
          v-if="exploring || exploreFailed || exploreSay"
          class="ra-notice"
          data-testid="auto-exploration"
        >
          <span v-if="exploreFailed">{{
            $t('repertoireAuto.explore.failed')
          }}</span>
          <span v-else-if="exploring && !exploration?.capped">{{
            $t('repertoireAuto.explore.exploring')
          }}</span>
          <span v-else>{{ exploreSay }}</span>
          <button
            v-if="exploreFailed || exploration?.capped"
            type="button"
            class="ra-link"
            :disabled="exploring"
            data-testid="auto-explore-resume"
            @click="emit(exploration?.capped ? 'resume' : 'explore')"
            >{{ $t('repertoireAuto.explore.resume') }}</button
          >
        </div>

        <div v-if="pending > 0" class="ra-notice" data-testid="auto-pending">
          <span v-if="evaluationFailed">{{
            $t('repertoireAuto.treeScreen.notEvaluated', pending)
          }}</span>
          <span v-else>{{
            $t('repertoireAuto.treeScreen.evaluating', pending)
          }}</span>
          <button
            v-if="evaluationFailed"
            type="button"
            class="ra-link"
            @click="emit('evaluate')"
            >{{ $t('common.actions.retry') }}</button
          >
        </div>

        <template v-if="pending.length">
          <div class="ra-kicker" data-testid="auto-pending-count">{{
            $t('repertoireAuto.pending.title') +
            ' · ' +
            $t('repertoireAuto.pending.count', { n: pending.length })
          }}</div>
          <div class="ra-text ra-text--small">{{
            $t('repertoireAuto.pending.lead')
          }}</div>
          <ul class="ra-pending" data-testid="auto-pending-list">
            <li
              v-for="p in pending"
              :key="`${p.key}:${p.fen}`"
              class="ra-pending__item"
              data-testid="auto-pending-item"
            >
              <span class="ra-pending__path">{{ sanPath(p.sans) }}</span>
              <span class="ra-pending__meta"
                >{{ pendingReasonText(p.reason)
                }}<template v-if="p.share !== null">
                  ·
                  {{
                    $t('repertoireAuto.pending.share', {
                      share: formatShare(p.share)
                    })
                  }}</template
                ></span
              >
            </li>
          </ul>
        </template>

        <template v-if="moments.length">
          <div class="ra-kicker">{{
            $t('repertoireAuto.treeScreen.moments')
          }}</div>
          <div class="ra-moments">
            <button
              v-for="m in moments"
              :key="m.kind"
              type="button"
              class="ra-moment"
              :data-testid="`auto-moment-${m.kind}`"
              @click="emit('open', m.line)"
            >
              <span
                class="ra-pill ra-pill--small ra-pill--tag"
                :style="{ background: MOMENT_COLORS[m.kind] }"
                >{{ m.title }}</span
              >
              <span class="ra-moment__name">{{ m.line.name }}</span>
              <span class="ra-moment__text">{{ m.text }}</span>
            </button>
          </div>
        </template>
      </div>

      <div class="ra-cols__main">
        <div class="ra-kicker">{{
          $t('repertoireAuto.treeScreen.variations')
        }}</div>
        <div v-for="tree in trees" :key="tree.key" class="ra-group">
          <button
            type="button"
            class="ra-group__head"
            :aria-expanded="!collapsed[tree.key]"
            data-testid="auto-group"
            @click="collapsed[tree.key] = !collapsed[tree.key]"
          >
            <span
              class="ra-group__side"
              :class="`ra-group__side--${tree.color}`"
              >{{ tree.color === 'white' ? '♔︎' : '♚︎' }}</span
            >
            <span class="ra-group__text">
              <span class="ra-group__name">{{ tree.family }}</span>
              <span class="ra-group__base">{{ trunk(tree) }}</span>
            </span>
            <span class="ra-group__count">{{
              $t('repertoireAuto.treeScreen.lines', tree.lines.length)
            }}</span>
            <span
              class="ra-group__caret"
              :style="{
                transform: collapsed[tree.key] ? 'rotate(-90deg)' : 'none'
              }"
              >▾</span
            >
          </button>
          <div v-if="!collapsed[tree.key]" class="ra-group__rows">
            <div
              v-for="(line, i) in tree.lines"
              :key="line.path"
              class="ra-row"
              :class="{ 'ra-row--locked': line.locked }"
            >
              <div class="ra-row__trunk">
                <div
                  class="ra-row__trunk-v"
                  :style="{
                    bottom:
                      i === tree.lines.length - 1 ? 'calc(50% - 3px)' : '0'
                  }"
                />
                <div class="ra-row__trunk-h" />
              </div>
              <button
                type="button"
                class="ra-row__btn"
                :disabled="line.locked"
                data-testid="auto-line"
                @click="emit('open', line)"
              >
                <span
                  class="ra-row__num"
                  :style="{
                    background: line.locked
                      ? 'var(--ra-line)'
                      : TAGS[line.tag].color
                  }"
                  >{{ line.number }}</span
                >
                <span class="ra-row__text">
                  <span class="ra-row__name">{{ line.name }}</span>
                  <span class="ra-row__moves" data-testid="auto-line-moves"
                    ><span class="ra-row__shared">{{ trunk(tree) }}</span>
                    <b class="ra-row__own">{{ lineMoves(tree, line) }}</b></span
                  >
                </span>
                <span v-if="!line.locked" class="ra-row__figures">
                  <span class="ra-row__score">{{
                    $t('repertoireAuto.treeScreen.percent', { n: score(line) })
                  }}</span>
                  <span class="ra-row__games">{{
                    $t('repertoireAuto.treeScreen.games', { n: line.games })
                  }}</span>
                </span>
                <span
                  v-else
                  class="ra-lock"
                  :aria-label="$t('repertoireAuto.treeScreen.locked')"
                  ><span class="ra-lock__arc" /><span class="ra-lock__body"
                /></span>
              </button>
              <div
                v-if="line.short && !line.locked"
                class="ra-row__short"
                data-testid="auto-short"
              >
                <span class="ra-pill ra-pill--small">{{
                  $t('repertoireAuto.short.badge')
                }}</span>
                <span class="ra-row__stop" data-testid="auto-short-stop">{{
                  stopText(line)
                }}</span>
                <span class="ra-row__choices">
                  <button
                    type="button"
                    class="ra-link"
                    :class="{ 'ra-link--on': line.confirmed }"
                    :disabled="busy"
                    data-testid="auto-short-keep"
                    @click="emit('confirm', tree.key, line, 'keep')"
                    >{{
                      line.confirmed
                        ? '✓ ' + $t('repertoireAuto.short.kept')
                        : $t('repertoireAuto.short.keep')
                    }}</button
                  >
                  <button
                    type="button"
                    class="ra-link"
                    :disabled="busy"
                    data-testid="auto-short-suspend"
                    @click="emit('confirm', tree.key, line, 'suspend')"
                    >{{ $t('repertoireAuto.short.suspend') }}</button
                  >
                </span>
              </div>
            </div>
          </div>
        </div>

        <div v-if="locked.length" class="ra-locked" data-testid="auto-locked">
          <div class="ra-locked__title">{{
            $t('repertoireAuto.treeScreen.lockedTitle', locked.length)
          }}</div>
          <div class="ra-locked__text">{{
            $t('repertoireAuto.treeScreen.lockedText', { n: freeLines })
          }}</div>
        </div>

        <div v-if="error" class="ra-error" data-testid="auto-error">{{
          error
        }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Fifth step: the repertoire built, per opening (collapsible): its lines numbered and coloured by
 * tag, the critical moments, the lines beyond the free ones locked (when there is such a limit).
 * A short line shows where it stops and asks to be kept or set aside (validating waits for it);
 * the pending positions are listed (answered later, in the editor); the exploration of the
 * opponents' replies says where it stands (resumed after its cap). A line opens its sheet.
 */
import { computed, reactive } from 'vue'
import { intlLocale } from '@/i18n'
import { profImage } from '@/utils/prof/images'
import {
  TAGS,
  criticalMoments,
  explorationSay,
  pendingItems,
  pendingReasonText,
  sanPath,
  score,
  sequence,
  stopText,
  treeSay,
  unconfirmedLines
} from '@/utils/repertoireAuto'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/repertoireAuto').Tree[]>} */
  trees: { type: Array, required: true },
  /** Games read. */
  games: { type: Number, required: true },
  /** Line ends Lichess was not asked about yet. */
  pending: { type: Number, default: 0 },
  evaluationFailed: { type: Boolean, default: false },
  /** Lines shown in full without a subscription (null: all). */
  freeLines: { type: Number, default: null },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' },
  /** @type {import('vue').PropType<{calls: number, budget: number, capped: boolean, frontier: number, explored: number}|null>} */
  exploration: { type: Object, default: null },
  exploring: { type: Boolean, default: false },
  exploreFailed: { type: Boolean, default: false }
})
const emit = defineEmits([
  'open',
  'select',
  'validate',
  'evaluate',
  'confirm',
  'keepAll',
  'resume',
  'explore'
])

const MOMENT_COLORS = {
  risk: TAGS.risk.color,
  weapon: TAGS.weapon.color,
  played: TAGS.solid.color
}

/** @type {Record<string, boolean>} */
const collapsed = reactive({})

const lines = computed(() => props.trees.flatMap(t => t.lines))
const locked = computed(() => lines.value.filter(l => l.locked))
const moments = computed(() => criticalMoments(lines.value))
const pending = computed(() => pendingItems(props.trees))
const unconfirmed = computed(() => unconfirmedLines(props.trees))
const exploreSay = computed(() => explorationSay(props.exploration))

/** @param {number} share a percentage */
const formatShare = share =>
  share.toLocaleString(intlLocale(), { maximumFractionDigits: 1 })

/** @param {string} tag */
const count = tag => lines.value.filter(l => l.tag === tag).length

/**
 * @param {import('@/utils/repertoireAuto').Tree} tree
 * @returns {Map<string, import('@/utils/repertoireAuto').TreeNode>}
 */
function nodesOf(tree) {
  return new Map(tree.nodes.map(n => [n.path, n]))
}

/** What every line of the opening shares: "1.e4 e5 2.Nf3 Nc6 3.Bc4". */
function trunk(tree) {
  const nodes = tree.lines[0]?.nodes ?? []
  const byPath = nodesOf(tree)
  return sequence(
    nodes
      .slice(0, tree.trunk)
      .map(p => byPath.get(p))
      .filter(Boolean)
  )
}

/** The line's own moves, after the trunk. */
function lineMoves(tree, line) {
  const byPath = nodesOf(tree)
  return sequence(
    line.nodes
      .slice(tree.trunk)
      .map(p => byPath.get(p))
      .filter(Boolean)
  )
}
</script>

<style scoped lang="scss">
.ra-row__short {
  // Under the line, past its trunk.
  flex-basis: 100%;
  margin-left: 20px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 10px;
  padding: 2px 0 8px 8px;
  font-size: 12px;
}

.ra-row__stop {
  flex: 1 1 200px;
  opacity: 0.8;
}

.ra-row__choices {
  display: flex;
  gap: 12px;
}

.ra-link--on {
  font-weight: 700;
}

.ra-pending {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.ra-pending__item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 8px 10px;
  border-radius: 10px;
  background: var(--ra-card, rgba(127, 127, 127, 0.08));
}

.ra-pending__path {
  font-weight: 700;
}

.ra-pending__meta {
  font-size: 12px;
  opacity: 0.75;
}

.ra-tree__head {
  display: flex;
  align-items: flex-end;
  gap: 8px;
}

.ra-tree__intro {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.ra-tree__albert {
  width: 78px;
  height: 88px;
  object-fit: contain;
  object-position: center bottom;
  flex: none;

  @media (min-width: 1024px) {
    width: 150px;
    height: 170px;
  }
}

.ra-tree__chips {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.ra-moments {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  margin: 0 -16px;
  padding: 0 16px 4px;
  flex: none;

  // Beside the tree on a computer: one under the other, full width.
  @media (min-width: 1024px) {
    flex-direction: column;
    overflow: visible;
    margin: 0;
    padding: 0;

    .ra-moment {
      width: auto;
    }
  }
}

.ra-moment {
  flex: none;
  width: 200px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 13px;
  border-radius: 18px;
  border: none;
  background: var(--ra-card);
  text-align: left;
  cursor: pointer;
  color: var(--ra-ink);

  .ra-pill {
    align-self: flex-start;
  }
}

.ra-moment__name {
  font-weight: 800;
  font-size: 14px;
}

.ra-moment__text {
  font-size: 12px;
  line-height: 1.4;
  color: var(--ra-ink-soft);
}

.ra-group {
  display: flex;
  flex-direction: column;
}

.ra-group__head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px;
  border-radius: 18px;
  border: none;
  background: var(--ra-night);
  color: #fff;
  text-align: left;
  cursor: pointer;
}

.ra-group__side {
  width: 32px;
  height: 32px;
  flex: none;
  border-radius: 11px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
  font-size: 20px;

  &--white {
    background: #fff;
    color: var(--ra-night);
  }

  &--black {
    background: #4c475e;
    color: #fff;
  }
}

.ra-group__text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.ra-group__name {
  font-weight: 800;
  font-size: 15px;
}

.ra-group__base {
  font-size: 12px;
  color: #cfc9e2;
}

.ra-group__count {
  font-size: 12px;
  font-weight: 700;
  color: var(--ra-accent);
  white-space: nowrap;
}

.ra-group__caret {
  font-size: 14px;
  transition: transform 0.3s;
}

.ra-group__rows {
  display: flex;
  flex-direction: column;
  padding-left: 22px;
}

.ra-row {
  display: flex;
  flex-wrap: wrap;
  align-items: stretch;

  &--locked {
    opacity: 0.4;
  }
}

.ra-row__trunk {
  width: 20px;
  flex: none;
  position: relative;
}

.ra-row__trunk-v {
  position: absolute;
  left: 0;
  top: 0;
  border-left: 2px solid var(--ra-faint);
}

.ra-row__trunk-h {
  position: absolute;
  left: 0;
  top: calc(50% + 3px);
  width: 14px;
  border-top: 2px solid var(--ra-faint);
}

.ra-row__btn {
  flex: 1;
  min-width: 0;
  margin-top: 6px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 16px;
  border: none;
  background: var(--ra-card);
  text-align: left;
  cursor: pointer;
  color: var(--ra-ink);

  &:disabled {
    cursor: not-allowed;
  }
}

.ra-row__num {
  width: 30px;
  height: 30px;
  flex: none;
  border-radius: 50%;
  color: var(--ra-night);
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 13px;
}

.ra-row__text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.ra-row__name {
  font-weight: 800;
  font-size: 14px;
}

// The whole line: what the opening's lines share (faded), then this line's own part.
.ra-row__shared {
  color: var(--ra-faint);
}

.ra-row__own {
  color: var(--ra-ink);
  font-weight: 700;
}

.ra-row__moves {
  font-size: 12px;
  line-height: 1.35;
  color: var(--ra-muted);
}

.ra-row__figures {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
  flex: none;
}

.ra-row__score {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 15px;
}

.ra-row__games {
  font-size: 11px;
  color: var(--ra-muted);
}

.ra-lock {
  flex: none;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.ra-lock__arc {
  width: 10px;
  height: 8px;
  border: 2.5px solid var(--ra-ink);
  border-bottom: none;
  border-radius: 7px 7px 0 0;
}

.ra-lock__body {
  width: 17px;
  height: 12px;
  border-radius: 3px;
  background: var(--ra-ink);
}

.ra-locked {
  background: var(--ra-night);
  color: #fff;
  border-radius: 22px;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 4px;
}

.ra-locked__title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 20px;
  line-height: 1.1;
}

.ra-locked__text {
  font-size: 13px;
  line-height: 1.45;
  color: #cfc9e2;
}
</style>
