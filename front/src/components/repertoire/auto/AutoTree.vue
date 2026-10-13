<template>
  <div class="ra-screen ra-up">
    <div class="ra-cols">
      <div class="ra-cols__side">
        <div class="ra-tree__head">
          <div class="ra-tree__intro">
            <h1 class="ra-title ra-title--small">Ton répertoire</h1>
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
          <span class="ra-pill"
            >{{ lines.length }} ligne{{ lines.length > 1 ? 's' : '' }}</span
          >
          <span
            class="ra-pill ra-pill--tag"
            :style="{ background: TAGS.risk.color }"
            >{{ count('risk') }} à risque</span
          >
          <span
            class="ra-pill ra-pill--tag"
            :style="{ background: TAGS.weapon.color }"
            >{{ count('weapon') }} arme{{
              count('weapon') > 1 ? 's' : ''
            }}</span
          >
        </div>

        <div class="ra-actions ra-actions--split">
          <button
            type="button"
            class="ra-cta ra-cta--card"
            data-testid="auto-back-select"
            @click="emit('select')"
            >↺ Sélection</button
          >
          <button
            type="button"
            class="ra-cta ra-cta--accent ra-cta--wide"
            :disabled="busy || !lines.length"
            data-testid="auto-validate"
            @click="emit('validate')"
            >Valider</button
          >
        </div>

        <div v-if="pending > 0" class="ra-notice" data-testid="auto-pending">
          <span v-if="evaluationFailed"
            >Lichess n’a pas évalué {{ pending }} fin{{
              pending > 1 ? 's' : ''
            }}
            de ligne.</span
          >
          <span v-else
            >Évaluation de {{ pending }} fin{{ pending > 1 ? 's' : '' }} de
            ligne par Lichess…</span
          >
          <button
            v-if="evaluationFailed"
            type="button"
            class="ra-link"
            @click="emit('evaluate')"
            >Réessayer</button
          >
        </div>

        <template v-if="moments.length">
          <div class="ra-kicker">MOMENTS CRITIQUES</div>
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
        <div class="ra-kicker">ARBRE DES VARIANTES</div>
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
            <span class="ra-group__count"
              >{{ tree.lines.length }} ligne{{
                tree.lines.length > 1 ? 's' : ''
              }}</span
            >
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
                  <span class="ra-row__score">{{ score(line) }} %</span>
                  <span class="ra-row__games">{{ line.games }} parties</span>
                </span>
                <span v-else class="ra-lock" aria-label="Verrouillée"
                  ><span class="ra-lock__arc" /><span class="ra-lock__body"
                /></span>
              </button>
            </div>
          </div>
        </div>

        <div v-if="locked.length" class="ra-locked" data-testid="auto-locked">
          <div class="ra-locked__title"
            >+{{ locked.length }} ligne{{ locked.length > 1 ? 's' : '' }} déjà
            analysée{{ locked.length > 1 ? 's' : '' }}</div
          >
          <div class="ra-locked__text"
            >La version gratuite affiche les {{ freeLines }} premières lignes.
            Débloque l’arbre complet, les échiquiers et les moments
            critiques.</div
          >
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
 * A line opens its sheet.
 */
import { computed, reactive } from 'vue'
import { profImage } from '@/utils/prof/images'
import {
  TAGS,
  criticalMoments,
  score,
  sequence,
  treeSay
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
  error: { type: String, default: '' }
})
const emit = defineEmits(['open', 'select', 'validate', 'evaluate'])

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
