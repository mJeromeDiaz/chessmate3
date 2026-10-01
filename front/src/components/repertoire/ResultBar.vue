<template>
  <div
    v-if="shares"
    class="result-bar"
    :title="`Blancs ${shares.white} % · nulle ${shares.draws} % · Noirs ${shares.black} %`"
  >
    <span
      v-for="part in parts"
      :key="part.key"
      class="result-bar__part"
      :class="`result-bar__part--${part.key}`"
      :style="{ width: `${part.value}%` }"
      >{{ part.value >= MIN_LABEL ? `${part.value} %` : '' }}</span
    >
  </div>
</template>

<script setup>
/** White wins / draws / Black wins of a set of games, as a bar. */
import { computed } from 'vue'
import { resultShares } from '@/utils/chess/lichess'

/** Narrower parts carry no label. */
const MIN_LABEL = 14

const props = defineProps({
  /** @type {import('vue').PropType<{white: number, draws: number, black: number}>} */
  counts: { type: Object, required: true }
})

const shares = computed(() => resultShares(props.counts))
const parts = computed(() =>
  shares.value
    ? ['white', 'draws', 'black'].map(key => ({
        key,
        value: shares.value[key]
      }))
    : []
)
</script>

<style scoped>
.result-bar {
  display: flex;
  height: 18px;
  border-radius: 3px;
  overflow: hidden;
  border: 1px solid #bdbdbd;
  font-size: 11px;
  line-height: 16px;
}
.result-bar__part {
  text-align: center;
  white-space: nowrap;
  overflow: hidden;
}
.result-bar__part--white {
  background: #fafafa;
  color: #212121;
}
.result-bar__part--draws {
  background: #9e9e9e;
  color: #fff;
}
.result-bar__part--black {
  background: #303030;
  color: #fff;
}
</style>
