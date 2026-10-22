<template>
  <section class="path-hero" data-testid="path-hero">
    <div class="path-hero__intro">
      <span class="path-hero__kicker" data-testid="path-hero-progress">{{
        $t('mindMapPath.page.capacity', {
          n: info.num,
          done: progress.done,
          total: progress.total
        })
      }}</span>
      <h1 class="path-hero__title">{{ capacityName(info.key) }}</h1>
      <p class="path-hero__desc">{{ capacityDesc(info.key) }}</p>
      <div class="path-hero__bar">
        <span class="path-hero__track"
          ><span class="path-hero__fill" :style="{ width: `${percent}%` }"
        /></span>
        <span class="path-hero__percent">{{ percent }} %</span>
      </div>
    </div>
  </section>
</template>

<script setup>
/**
 * The head of a capacity's path (design « Parcours zone »), in the capacity's colour: its number
 * and steps passed, its name and what it is, the whole path's progress.
 */
import { computed } from 'vue'
import { capacityDesc, capacityName } from '@/utils/mindMap'
import { pathProgress } from '@/utils/mindMapPath'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/mindMap').Capacity>} */
  info: { type: Object, required: true },
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').MindPath>} */
  path: { type: Object, required: true }
})

const progress = computed(() => pathProgress(props.path))
const percent = computed(() =>
  progress.value.total
    ? Math.round((100 * progress.value.done) / progress.value.total)
    : 0
)
</script>

<style scoped lang="scss">
.path-hero {
  display: grid;
  gap: 24px;
  padding: 24px 22px;
  border-radius: 28px;
  background: var(--capacity, var(--cm-brand));
  color: var(--capacity-ink, #fff);

  @media (min-width: $breakpoint-md-min) {
    padding: 32px 28px 36px;
  }
}

.path-hero__intro {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.path-hero__kicker {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.path-hero__title {
  margin: 0;
  font-family: var(--cm-heading);
  font-size: 32px;
  font-weight: 800;
  line-height: 1.02;
  text-wrap: balance;

  @media (min-width: $breakpoint-md-min) {
    font-size: 40px;
  }
}

.path-hero__desc {
  max-width: 560px;
  margin: 0;
  font-size: 15px;
  line-height: 1.5;
  text-wrap: pretty;
}

.path-hero__bar {
  display: flex;
  align-items: center;
  gap: 12px;
  max-width: 420px;
  margin-top: 4px;
  font-size: 13px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

.path-hero__track {
  flex: 1;
  display: flex;
  height: 10px;
  overflow: hidden;
  border-radius: 5px;
  background: rgba(27, 21, 48, 0.18);
}

.path-hero__fill {
  border-radius: 5px;
  background: var(--capacity-ink, #fff);
}
</style>
