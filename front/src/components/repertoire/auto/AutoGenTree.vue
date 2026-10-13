<template>
  <svg
    class="ra-gentree"
    :viewBox="`0 0 ${W} 246`"
    role="img"
    :aria-label="`${lineCount} lignes dans ${trees.length} ouverture${trees.length > 1 ? 's' : ''}`"
  >
    <path
      v-for="(op, i) in ops"
      :key="`po-${op.key}`"
      :d="curve(W / 2, ROOT_Y, op.x, OP_Y)"
      pathLength="1"
      class="ra-gentree__edge"
      :style="edge(phase >= 1, i)"
    />
    <path
      v-for="(leaf, i) in leaves"
      :key="`pl-${leaf.key}`"
      :d="curve(leaf.op.x, OP_Y, leaf.x, LEAF_Y)"
      pathLength="1"
      class="ra-gentree__edge"
      :style="edge(phase >= 2, i)"
    />
    <template v-for="(leaf, i) in leaves" :key="`n-${leaf.key}`">
      <circle
        v-if="phase >= 4 && (leaf.tag === 'risk' || leaf.tag === 'weapon')"
        :cx="leaf.x"
        :cy="LEAF_Y"
        r="7"
        fill="none"
        :stroke="TAGS[leaf.tag].color"
        stroke-width="2.5"
        class="ra-gentree__pulse"
        :style="{ animationDelay: `${i * 0.1}s` }"
      />
      <circle
        :cx="leaf.x"
        :cy="LEAF_Y"
        r="7"
        :fill="phase >= 3 ? TAGS[leaf.tag].color : '#4C475E'"
        stroke="#241D3D"
        stroke-width="2"
        class="ra-gentree__node"
        :style="{
          transform: phase >= 2 ? 'scale(1)' : 'scale(0)',
          transitionDelay: `${0.5 + i * 0.06}s, ${i * 0.08}s`
        }"
      />
    </template>
    <template v-for="(op, i) in ops" :key="`o-${op.key}`">
      <circle
        :cx="op.x"
        :cy="OP_Y"
        r="10"
        fill="#8B6BFF"
        stroke="#241D3D"
        stroke-width="2"
        class="ra-gentree__node"
        :style="{
          transform: phase >= 1 ? 'scale(1)' : 'scale(0)',
          transitionDelay: `${0.4 + i * 0.08}s`
        }"
      />
      <text
        :x="op.x"
        :y="OP_Y + 27"
        text-anchor="middle"
        class="ra-gentree__label"
        :style="{ opacity: phase >= 1 ? 1 : 0 }"
      >
        {{ op.short }}
      </text>
    </template>
    <circle :cx="W / 2" :cy="ROOT_Y" r="12" fill="#fff" />
    <text :x="W / 2 + 20" :y="ROOT_Y + 4" class="ra-gentree__root">
      tes parties
    </text>
  </svg>
</template>

<script setup>
/**
 * The repertoire growing (design "Repertoire auto", generation): the user's games at the top, the
 * kept openings, a few of their lines as leaves, coloured by their tag once known; the lines at risk
 * and the weapons pulse at the end. Phases: 1 openings, 2 lines, 3 colours, 4 pulses.
 *
 * An illustration, not the whole repertoire: 2000 games give dozens of lines, unreadable here. A few
 * openings, a few lines each, one of every tag first (the real lines are on the next screen).
 */
import { computed } from 'vue'
import { TAGS } from '@/utils/repertoireAuto'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/repertoireAuto').Tree[]>} */
  trees: { type: Array, required: true },
  phase: { type: Number, required: true }
})

const W = 338
const ROOT_Y = 22
const OP_Y = 104
const LEAF_Y = 214
/** Openings drawn, and lines drawn per opening. */
const MAX_OPENINGS = 4
const LINES_PER_OPENING = 3
/** One line of each tag first, in this order, so that the colours show. */
const TAG_ORDER = ['weapon', 'risk', 'draws', 'solid']

/**
 * A few lines of an opening: one of each tag first, then the next ones, in the tree's order.
 *
 * @param {any[]} lines
 * @param {number} n
 * @returns {any[]}
 */
function sampleLines(lines, n) {
  const picked = new Set()
  for (const tag of TAG_ORDER) {
    const line = lines.find(l => l.tag === tag)
    if (line && picked.size < n) picked.add(line)
  }
  for (const line of lines) {
    if (picked.size >= n) break
    picked.add(line)
  }
  return lines.filter(l => picked.has(l))
}

const shown = computed(() => props.trees.slice(0, MAX_OPENINGS))
const lineCount = computed(() =>
  props.trees.reduce((sum, tree) => sum + tree.lines.length, 0)
)

/** The leaves spread over the width, each opening above its leaves. */
const layout = computed(() => {
  const leaves = shown.value.flatMap(tree =>
    sampleLines(tree.lines, LINES_PER_OPENING).map(line => ({
      key: `${tree.key}|${line.path}`,
      tree: tree.key,
      tag: line.tag,
      x: 0,
      op: null
    }))
  )
  const n = leaves.length
  const step = n > 1 ? (W - 36) / (n - 1) : 0
  leaves.forEach((leaf, i) => {
    leaf.x = n > 1 ? 18 + i * step : W / 2
  })
  const ops = shown.value.map((tree, i) => {
    const xs = leaves.filter(l => l.tree === tree.key).map(l => l.x)
    return {
      key: tree.key,
      short: shortName(tree.family),
      x: xs.length
        ? xs.reduce((a, b) => a + b, 0) / xs.length
        : ((i + 1) * W) / (shown.value.length + 1)
    }
  })
  leaves.forEach(leaf => {
    leaf.op = ops.find(o => o.key === leaf.tree)
  })
  return { leaves, ops }
})
const leaves = computed(() => layout.value.leaves)
const ops = computed(() => layout.value.ops)

/** "Sicilian Defense" → "Sicilian": a label that fits under a node. */
function shortName(family) {
  const word = family.replace(
    / (Defense|Game|Opening|Attack|Gambit|System)$/,
    ''
  )
  return word.length > 14 ? `${word.slice(0, 13)}…` : word
}

/** A vertical S-curve between two points. */
function curve(x1, y1, x2, y2) {
  const mid = (y1 + y2) / 2
  return `M${x1},${y1} C${x1},${mid} ${x2},${mid} ${x2},${y2}`
}

/** An edge drawing itself, one after the other. */
function edge(on, i) {
  return {
    strokeDashoffset: on ? 0 : 1,
    transitionDelay: on ? `${i * 0.07}s` : '0s'
  }
}
</script>

<style scoped lang="scss">
.ra-gentree {
  display: block;
  width: 100%;
  height: 100%;
  margin-top: 8px;
}

.ra-gentree__edge {
  fill: none;
  stroke: #4fb2ff;
  stroke-width: 2.5;
  stroke-linecap: round;
  stroke-dasharray: 1;
  transition: stroke-dashoffset 0.8s cubic-bezier(0.5, 0, 0.2, 1);
}

.ra-gentree__node {
  transform-box: fill-box;
  transform-origin: center;
  transition:
    transform 0.45s cubic-bezier(0.34, 2, 0.64, 1),
    fill 0.4s;
}

.ra-gentree__pulse {
  transform-box: fill-box;
  transform-origin: center;
  animation: ra-pulse 1.4s ease-out infinite;
}

.ra-gentree__label,
.ra-gentree__root {
  fill: #fff;
  font-size: 11px;
  font-weight: 700;
  font-family: 'DM Sans Variable', system-ui, sans-serif;
}

.ra-gentree__label {
  paint-order: stroke;
  stroke: #241d3d;
  stroke-width: 5;
  transition: opacity 0.4s 0.6s;
}
</style>
