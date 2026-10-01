<template>
  <div ref="root" class="move-tree" data-testid="move-tree">
    <div v-if="lines.length === 0" class="move-tree__empty text-grey">
      <slot name="empty">Aucun coup.</slot>
    </div>
    <MoveTreeLine
      v-else
      :line="lines"
      :current-id="currentId"
      :on-path="onPath"
      :expanded="expanded"
      :move-class="moveClass"
      @select="select"
      @jump="jump"
      @toggle="toggle"
    />
  </div>
</template>

<script setup>
/**
 * Generic move tree (repertoire editor today; any graph of positions tomorrow): the main line
 * and its variations written like Lichess, the current move highlighted, a click on a move goes
 * back to it, transpositions marked "⤳" with a link to the line they join.
 *
 * The parent owns the path (`v-model:path`: move ids from the root). With `keyboard`, the arrows
 * navigate (← → back and forward, ↑ ↓ previous and next variation, Home and End: start and end
 * of the line), except while the user types in a field. See moveTree.js for the model.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import MoveTreeLine from './MoveTreeLine.vue'
import {
  back,
  canonicalPath,
  displayLines,
  forward,
  indexGraph,
  lineEnd,
  pathToMove,
  sibling
} from './moveTree'

const props = defineProps({
  /** @type {import('vue').PropType<import('./moveTree').TreeGraph>} */
  graph: { type: Object, required: true },
  /** @type {import('vue').PropType<import('./moveTree').TreeIndex|null>} computed if omitted */
  index: { type: Object, default: null },
  /** @type {import('vue').PropType<string[]>} */
  path: { type: Array, required: true },
  /** Handle the navigation keys on the whole page. */
  keyboard: { type: Boolean, default: true },
  /** @type {import('vue').PropType<(move: any) => any>} extra classes of a move */
  moveClass: { type: Function, default: null }
})

const emit = defineEmits({
  /** The new path (move ids from the root). */
  'update:path': value => Array.isArray(value)
})

const root = ref(null)
/** Deep variation groups the user unfolded (key: first move of the group). */
const expanded = ref(new Set())

const treeIndex = computed(() => props.index ?? indexGraph(props.graph))
const lines = computed(() => displayLines(treeIndex.value, props.graph))
const currentId = computed(() => props.path.at(-1) ?? null)
const onPath = computed(() => new Set(props.path))

/** @param {string} moveId */
function select(moveId) {
  emit('update:path', pathToMove(treeIndex.value, props.graph, moveId))
}

/** @param {string} positionId the position a transposition joins */
function jump(positionId) {
  emit('update:path', canonicalPath(treeIndex.value, props.graph, positionId))
}

/** @param {string} key */
function toggle(key) {
  const next = new Set(expanded.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  expanded.value = next
}

/**
 * @param {'back'|'forward'|'up'|'down'|'start'|'end'} action
 */
function navigate(action) {
  const { graph, path } = props
  const index = treeIndex.value
  const next = {
    back: () => back(path),
    forward: () => forward(index, graph, path),
    up: () => sibling(index, graph, path, -1),
    down: () => sibling(index, graph, path, 1),
    start: () => [],
    end: () => lineEnd(index, graph, path)
  }[action]()
  if (next !== path) emit('update:path', next)
}

const KEYS = {
  ArrowLeft: 'back',
  ArrowRight: 'forward',
  ArrowUp: 'up',
  ArrowDown: 'down',
  Home: 'start',
  End: 'end'
}

/** @param {KeyboardEvent} event */
function onKeydown(event) {
  const action = KEYS[event.key]
  if (
    !props.keyboard ||
    !action ||
    event.altKey ||
    event.ctrlKey ||
    event.metaKey
  )
    return
  const target = /** @type {HTMLElement|null} */ (event.target)
  if (
    target?.closest?.(
      'input, textarea, select, [contenteditable="true"], .q-dialog, .q-menu'
    )
  )
    return
  event.preventDefault()
  navigate(action)
}

// Read at each key: the parent may hand the keys over for a while.
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

// Keep the current move in sight in a long tree.
watch(currentId, async id => {
  await nextTick()
  if (!id) return
  root.value
    ?.querySelector(`[data-move-id="${CSS.escape(id)}"]`)
    ?.scrollIntoView?.({ block: 'nearest' })
})

defineExpose({ navigate })
</script>

<style lang="scss">
.move-tree {
  line-height: 1.9;
  font-size: 0.95rem;
  overflow-y: auto;
}
.move-tree__number {
  color: #757575;
  margin-right: 0.15em;
}
.move-tree__move {
  display: inline-block;
  padding: 0 0.3em;
  margin-right: 0.15em;
  border-radius: 4px;
  cursor: pointer;
  font-weight: 500;
  &:hover {
    background: rgba(25, 118, 210, 0.12);
  }
}
.move-tree__move--on-path {
  background: rgba(25, 118, 210, 0.06);
}
.move-tree__move--current {
  background: #1976d2;
  color: #fff;
  &:hover {
    background: #1565c0;
  }
}
.move-tree__glyph {
  margin-left: 0.1em;
}
.move-tree__comment {
  color: #616161;
  font-style: italic;
  margin: 0 0.4em 0 0.1em;
  white-space: pre-wrap;
}
.move-tree__transposition,
.move-tree__fold {
  border: none;
  background: none;
  cursor: pointer;
  color: #1976d2;
  padding: 0 0.25em;
  font: inherit;
}
.move-tree__variations {
  margin: 0.2em 0 0.3em 0.3em;
  padding-left: 0.6em;
  border-left: 2px solid #e0e0e0;
  font-size: 0.92em;
}
.move-tree__variation {
  margin: 0.1em 0;
}
.move-tree__inline {
  color: #616161;
}
</style>
