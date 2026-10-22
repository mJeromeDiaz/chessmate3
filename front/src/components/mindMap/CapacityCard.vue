<template>
  <button
    type="button"
    class="capacity-card"
    :class="[
      `capacity-card--${side}`,
      { 'capacity-card--hover': hovered, 'capacity-card--soon': soon }
    ]"
    :style="{ '--capacity': capacity.color, '--capacity-ink': capacity.ink }"
    :disabled="soon"
    :data-testid="`capacity-card-${capacity.key}`"
    @click.stop="emit('select', capacity.key)"
    @mouseenter="emit('hover', capacity.key)"
    @mouseleave="emit('hover', null)"
  >
    <span class="capacity-card__link" aria-hidden="true" />
    <span class="capacity-card__head">
      <span class="capacity-card__num">{{ capacity.num }}</span>
      <span class="capacity-card__name">{{ capacityName(capacity.key) }}</span>
      <span
        v-if="soon"
        class="capacity-card__soon"
        :data-testid="`capacity-soon-${capacity.key}`"
        >{{ $t('mindMap.page.soon') }}</span
      >
    </span>
    <span class="capacity-card__short">{{ capacityShort(capacity.key) }}</span>
  </button>
</template>

<script setup>
/**
 * A capacity's summary beside the brain (design "Carte mentale"): its number, its name and what
 * it is about. A line points to the brain on wide screens. Clicking it opens its path; without a
 * path yet, it is disabled and says « Bientôt ».
 */
import { capacityName, capacityShort } from '@/utils/mindMap'

defineProps({
  /** @type {import('vue').PropType<import('@/utils/mindMap').Capacity>} */
  capacity: { type: Object, required: true },
  /** The side of the brain it stands on: its line points the other way. */
  side: { type: String, default: 'left' },
  hovered: { type: Boolean, default: false },
  /** No path yet. */
  soon: { type: Boolean, default: false }
})

const emit = defineEmits({
  select: key => typeof key === 'string',
  hover: key => key === null || typeof key === 'string'
})
</script>

<style scoped lang="scss">
.capacity-card {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 10px;
  width: 100%;
  padding: 16px 18px;
  border: none;
  border-radius: 20px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  box-shadow:
    0 0 0 2px var(--cm-line),
    var(--cm-card-shadow);
  text-align: left;
  font: inherit;
  cursor: pointer;
  transition:
    opacity 0.3s,
    box-shadow 0.2s;

  &--hover {
    box-shadow:
      0 0 0 2px var(--cm-muted),
      var(--cm-card-shadow);
  }

  &--soon {
    cursor: default;
    opacity: 0.6;
  }
}

// The line to the brain, only beside it (wide screens).
.capacity-card__link {
  display: none;
  position: absolute;
  top: 50%;
  width: 40px;
  height: 3px;
  margin-top: -1px;
  border-radius: 2px;
  background: var(--capacity);
  opacity: 0.55;

  &::after {
    content: '';
    position: absolute;
    top: -4px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--capacity);
  }

  @media (min-width: $breakpoint-md-min) {
    display: block;
  }
}

.capacity-card--left .capacity-card__link {
  right: -44px;

  &::after {
    right: -6px;
  }
}

.capacity-card--right .capacity-card__link {
  left: -44px;

  &::after {
    left: -6px;
  }
}

.capacity-card__head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.capacity-card__num {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: var(--capacity);
  color: var(--capacity-ink);
  font-weight: 800;
  font-size: 14px;
}

.capacity-card__soon {
  margin-left: auto;
  flex: none;
  padding: 3px 10px;
  border-radius: 999px;
  background: var(--cm-subtle);
  color: var(--cm-muted);
  font-size: 11.5px;
  font-weight: 800;
}

.capacity-card__name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 17px;
  line-height: 1.15;
  text-wrap: balance;
}

.capacity-card__short {
  font-size: 13.5px;
  line-height: 1.4;
  color: var(--cm-muted);
  text-wrap: pretty;
}
</style>
