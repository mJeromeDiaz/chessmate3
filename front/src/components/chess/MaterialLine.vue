<template>
  <div
    class="material-line"
    :class="`material-line--${pieceColor}`"
    :data-testid="testid"
    :data-score="side.score"
    :aria-label="side.score ? `+${side.score}` : undefined"
  >
    <span v-if="groups.length" class="material-line__chip">
      <span
        v-for="group in groups"
        :key="group.type"
        class="material-line__group"
      >
        <svg
          v-for="i in group.count"
          :key="i"
          class="material-line__piece"
          viewBox="0 0 40 40"
          aria-hidden="true"
        >
          <use :href="`${sprite}#${pieceColor}${group.type}`" />
        </svg>
      </span>
    </span>
    <span v-if="side.score" class="material-line__score"
      >+{{ side.score }}</span
    >
  </div>
</template>

<script setup>
/**
 * One side's material, as on Lichess (docs/ARENA.md, § 3): the enemy pieces it is up, drawn with
 * the board's pieces on a chip of the opposite tone (black under white pieces, white under black
 * ones, readable in both themes), then its lead (« +1 ») when it leads. Empty when the material is
 * even.
 */
import { computed } from 'vue'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/chess/material').SideMaterial>} */
  side: { type: Object, required: true },
  /** The colour of the pieces it took, the other side's: 'w' or 'b' (the sprite's prefix). */
  pieceColor: { type: String, required: true },
  testid: { type: String, default: undefined }
})

const sprite = `${import.meta.env.BASE_URL || '/'}chessboard/pieces/standard.svg`

/** The pieces grouped by type, pawns first: a group's pieces overlap. */
const groups = computed(() => {
  /** @type {{type: string, count: number}[]} */
  const list = []
  for (const type of props.side.pieces) {
    const last = list[list.length - 1]
    if (last?.type === type) last.count++
    else list.push({ type, count: 1 })
  }
  return list
})
</script>

<style scoped lang="scss">
.material-line {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  min-height: 26px;
}

.material-line__chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 1px 8px;
  border-radius: 999px;
}

.material-line--w .material-line__chip {
  background: #1b1530;
}

.material-line--b .material-line__chip {
  background: #fff;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.12);
}

.material-line__group {
  display: inline-flex;
}

.material-line__piece {
  width: 24px;
  height: 24px;

  & + & {
    margin-left: -12px;
  }
}

.material-line__score {
  font-size: 15px;
  font-weight: 800;
  opacity: 0.8;
  font-variant-numeric: tabular-nums;
}
</style>
