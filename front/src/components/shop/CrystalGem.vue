<template>
  <span
    class="crystal-gem"
    :style="{
      width: `${size}px`,
      height: `${size}px`,
      filter: `drop-shadow(0 ${size * 0.06}px 0 rgba(27,21,48,.3))`
    }"
    aria-hidden="true"
  >
    <span
      class="crystal-gem__body"
      :class="{ 'crystal-gem__body--spin': spin }"
      :style="{ animationDelay: `${delay}s` }"
    >
      <span class="crystal-gem__layer crystal-gem__layer--edge" />
      <span
        class="crystal-gem__layer crystal-gem__layer--face"
        :style="{ inset: `${Math.max(1, size * 0.05)}px` }"
      />
      <span class="crystal-gem__layer crystal-gem__layer--shade" />
      <span class="crystal-gem__layer crystal-gem__layer--table" />
      <span class="crystal-gem__layer crystal-gem__layer--glint" />
    </span>
  </span>
</template>

<script setup>
/**
 * A crystal (design "Boutique cristaux"), the currency's look (stored and served as `crowns`): a
 * violet hexagonal gem with its facets, turning on itself when `spin`.
 */
defineProps({
  size: { type: Number, default: 28 },
  spin: { type: Boolean, default: false },
  /** Seconds before it starts turning. */
  delay: { type: Number, default: 0 }
})
</script>

<style scoped lang="scss">
$hex: polygon(50% 0, 92% 24%, 92% 76%, 50% 100%, 8% 76%, 8% 24%);

.crystal-gem {
  flex: none;
  display: inline-flex;
  perspective: 200px;
}

.crystal-gem__body {
  position: relative;
  width: 100%;
  height: 100%;

  &--spin {
    animation: crystal-spin 3.2s cubic-bezier(0.6, 0, 0.4, 1) infinite;
  }
}

.crystal-gem__layer {
  position: absolute;
  inset: 0;

  &--edge {
    clip-path: $hex;
    background: #2e1c80;
  }

  &--face {
    clip-path: $hex;
    background: linear-gradient(180deg, #ede5ff 0, #a98bff 45%, #6a4cff 100%);
  }

  &--shade {
    clip-path: polygon(50% 0, 92% 24%, 92% 76%, 50% 100%);
    background: rgba(46, 28, 128, 0.28);
  }

  &--table {
    clip-path: polygon(50% 20%, 72% 33%, 72% 67%, 50% 80%, 28% 67%, 28% 33%);
    background: rgba(255, 255, 255, 0.32);
  }

  &--glint {
    clip-path: polygon(50% 0, 50% 20%, 28% 33%, 8% 24%);
    background: rgba(255, 255, 255, 0.45);
  }
}

@keyframes crystal-spin {
  to {
    transform: rotateY(360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .crystal-gem__body--spin {
    animation: none;
  }
}
</style>
