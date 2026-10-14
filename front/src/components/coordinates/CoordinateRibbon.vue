<template>
  <div class="coord-ribbon" data-testid="coordinate-ribbon">
    <div class="coord-ribbon__cells">
      <button
        v-for="cell in cells"
        :key="cell.number"
        type="button"
        class="coord-ribbon__cell"
        :class="{
          'coord-ribbon__cell--fail': !cell.correct && !cell.pardoned,
          'coord-ribbon__cell--pardoned': cell.pardoned,
          'coord-ribbon__cell--picked': picked?.number === cell.number
        }"
        :title="cell.title"
        :aria-label="cell.title"
        :data-status="cell.correct ? 'ok' : cell.pardoned ? 'pardoned' : 'fail'"
        data-testid="coordinate-cell"
        @click="picked = picked?.number === cell.number ? null : cell"
      />
    </div>
    <div
      v-if="picked"
      class="coord-ribbon__picked"
      data-testid="coordinate-picked"
      >{{ picked.title }}</div
    >
    <div class="coord-ribbon__totals" data-testid="coordinate-totals">
      <span
        ><b>{{ totals.count }}</b>
        {{ $t('coordinates.player.answers', totals.count) }}</span
      >
      <span class="coord-ribbon__ok"
        ><i />{{ $t('coordinates.ribbon.correct', totals.correct) }}</span
      >
      <span class="coord-ribbon__fail"
        ><i />{{ $t('coordinates.ribbon.wrong', totals.wrong) }}</span
      >
      <span v-if="totals.rate !== null">{{
        $t('coordinates.player.percent', { n: Math.floor(totals.rate * 100) })
      }}</span>
      <span v-if="totals.averageMs !== null">{{
        $t('coordinates.ribbon.perSquare', {
          time: formatAnswerTime(totals.averageMs)
        })
      }}</span>
    </div>
  </div>
</template>

<script setup>
/**
 * The history of a coordinates series (docs/COORDINATES.md), GitHub-like: one cell per answer in
 * the order played, green or red; a cell's tooltip (or a tap) tells the square asked, the one
 * clicked and the time it took; the answer the store's pardon took away is ice blue, out of the
 * totals. Totals underneath.
 */
import { computed, ref } from 'vue'
import {
  formatAnswerTime,
  ribbonCells,
  ribbonTotals
} from '@/utils/coordinates'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/coordinates').CoordinateItem[]>} the run review's items */
  items: { type: Array, required: true }
})

const cells = computed(() => ribbonCells(props.items))
const totals = computed(() => ribbonTotals(props.items))
/** @type {import('vue').Ref<import('@/utils/coordinates').RibbonCell|null>} */
const picked = ref(null)
</script>

<style scoped lang="scss">
.coord-ribbon__cells {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
}

.coord-ribbon__cell {
  width: 13px;
  height: 13px;
  padding: 0;
  border: 0;
  border-radius: 3px;
  background: #a6d61e;
  cursor: pointer;

  &--fail {
    background: #ff6fae;
  }

  &--pardoned {
    background: #4fb2ff;
  }

  &--picked {
    outline: 2px solid var(--cm-ink);
    outline-offset: 1px;
  }
}

.coord-ribbon__picked {
  margin-top: 8px;
  font-weight: 600;
}

.coord-ribbon__totals {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 14px;
  margin-top: 10px;
  font-size: 13px;
  color: var(--cm-muted);

  b {
    color: var(--cm-ink);
  }

  i {
    display: inline-block;
    width: 10px;
    height: 10px;
    margin-right: 5px;
    border-radius: 2px;
    vertical-align: -1px;
  }
}

.coord-ribbon__ok i {
  background: #a6d61e;
}

.coord-ribbon__fail i {
  background: #ff6fae;
}
</style>
