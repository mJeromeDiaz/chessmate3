<template>
  <span
    class="wheel-glyph"
    :style="{ width: `${size}px`, height: `${size}px` }"
    aria-hidden="true"
  >
    <span class="wheel-glyph__hub" />
  </span>
</template>

<script setup>
/**
 * A small gift wheel (docs/SHOP.md, § 4 ter): its eight segments around a hub, for the header's
 * wheel and the menu. Decorative.
 */
defineProps({
  size: { type: Number, default: 22 }
})
</script>

<style scoped lang="scss">
.wheel-glyph {
  position: relative;
  display: inline-block;
  flex: none;
  border-radius: 50%;
  border: 2px solid #1b1530;
  background: conic-gradient(
    #c6f432 0 45deg,
    #5a3be0 45deg 90deg,
    #ff6fae 90deg 135deg,
    #5a3be0 135deg 180deg,
    #ffd43b 180deg 225deg,
    #5a3be0 225deg 270deg,
    #ff6fae 270deg 315deg,
    #5a3be0 315deg 360deg
  );
}

.wheel-glyph__hub {
  position: absolute;
  inset: 32%;
  border-radius: 50%;
  background: #fff;
  border: 2px solid #1b1530;
}
</style>
