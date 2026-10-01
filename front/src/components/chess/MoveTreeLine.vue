<template>
  <template v-for="(item, i) in line" :key="itemKey(item, i)">
    <template v-if="item.kind === 'move'">
      <span v-if="item.number" class="move-tree__number">{{
        item.number
      }}</span>
      <span
        class="move-tree__move"
        :class="[
          {
            'move-tree__move--current': item.move.id === currentId,
            'move-tree__move--on-path': onPath.has(item.move.id)
          },
          moveClass?.(item.move)
        ]"
        :data-move-id="item.move.id"
        data-testid="tree-move"
        role="button"
        tabindex="-1"
        @click="emit('select', item.move.id)"
        >{{ item.move.san
        }}<span v-if="glyph(item.move)" class="move-tree__glyph">{{
          glyph(item.move)
        }}</span></span
      ><button
        v-if="!item.move.canonical"
        type="button"
        class="move-tree__transposition"
        title="Transposition : aller à la ligne rejointe"
        data-testid="tree-transposition"
        @click="emit('jump', item.move.to)"
      >
        ⤳</button
      ><span v-if="item.move.comment" class="move-tree__comment">{{
        item.move.comment
      }}</span>
    </template>

    <div v-else-if="item.depth === 1" class="move-tree__variations">
      <div
        v-for="(variation, v) in item.lines"
        :key="variationKey(variation, v)"
        class="move-tree__variation"
      >
        <MoveTreeLine
          :line="variation"
          :current-id="currentId"
          :on-path="onPath"
          :expanded="expanded"
          :move-class="moveClass"
          @select="id => emit('select', id)"
          @jump="id => emit('jump', id)"
          @toggle="key => emit('toggle', key)"
        />
      </div>
    </div>

    <template v-else>
      <button
        v-if="item.depth > MAX_OPEN_DEPTH && !isOpen(item)"
        type="button"
        class="move-tree__fold"
        data-testid="tree-unfold"
        @click="emit('toggle', groupKey(item))"
      >
        (+{{ item.lines.length }})
      </button>
      <span
        v-for="(variation, v) in isOpen(item) ? item.lines : []"
        :key="variationKey(variation, v)"
        class="move-tree__inline"
      >
        (<MoveTreeLine
          :line="variation"
          :current-id="currentId"
          :on-path="onPath"
          :expanded="expanded"
          :move-class="moveClass"
          @select="id => emit('select', id)"
          @jump="id => emit('jump', id)"
          @toggle="key => emit('toggle', key)"
        />)
      </span>
    </template>
  </template>
</template>

<script setup>
/**
 * One line of MoveTree (recursive): its moves, then each group of variations, as blocks at the
 * first level, in parentheses below, folded beyond {@link MAX_OPEN_DEPTH}.
 */
import { moveGlyph } from '@/utils/chess/nags'

/** Variation levels shown unfolded; deeper ones open on demand. */
const MAX_OPEN_DEPTH = 2

const props = defineProps({
  /** @type {import('vue').PropType<import('./moveTree').DisplayLine>} */
  line: { type: Array, required: true },
  currentId: { type: String, default: null },
  /** @type {import('vue').PropType<Set<string>>} moves of the current path */
  onPath: { type: Set, required: true },
  /** @type {import('vue').PropType<Set<string>>} unfolded deep variation groups */
  expanded: { type: Set, required: true },
  /** @type {import('vue').PropType<(move: any) => any>} extra classes of a move */
  moveClass: { type: Function, default: null }
})

const emit = defineEmits(['select', 'jump', 'toggle'])

/** @param {any} move */
const glyph = move => moveGlyph(move.nags ?? [])

/** @param {import('./moveTree').VariationsItem} item */
const groupKey = item => item.lines[0]?.[0]?.move?.id ?? ''

/**
 * Open when shallow, unfolded by the user, or holding the current move.
 *
 * @param {import('./moveTree').VariationsItem} item
 */
function isOpen(item) {
  if (item.depth <= MAX_OPEN_DEPTH || props.expanded.has(groupKey(item)))
    return true
  return item.lines.some(line => containsCurrent(line))
}

/** @param {import('./moveTree').DisplayLine} line */
function containsCurrent(line) {
  return line.some(i =>
    i.kind === 'move'
      ? i.move.id === props.currentId
      : i.lines.some(containsCurrent)
  )
}

/**
 * @param {import('./moveTree').MoveItem|import('./moveTree').VariationsItem} item
 * @param {number} i
 */
const itemKey = (item, i) =>
  item.kind === 'move' ? item.move.id : `v-${groupKey(item)}-${i}`

/**
 * @param {import('./moveTree').DisplayLine} variation
 * @param {number} v
 */
const variationKey = (variation, v) =>
  variation[0]?.kind === 'move' ? variation[0].move.id : `l-${v}`
</script>
