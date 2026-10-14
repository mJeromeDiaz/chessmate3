<template>
  <span
    class="crown-coin"
    :style="{ width: `${size}px`, height: `${size}px` }"
    aria-hidden="true"
  >
    <span
      class="crown-coin__face"
      :class="{ 'crown-coin__face--spin': spin }"
      :style="{
        boxShadow: `inset 0 ${-size * 0.1}px 0 #C78F00, 0 ${size * 0.06}px 0 rgba(27,21,48,.25)`,
        animationDelay: `${delay}s`
      }"
    >
      <span
        class="crown-coin__rim"
        :style="{
          inset: `${size * 0.12}px`,
          borderWidth: `${Math.max(1, size * 0.05)}px`
        }"
      />
      <span
        class="crown-coin__king"
        :style="{
          fontSize: `${size * 0.56}px`,
          marginTop: `${-size * 0.04}px`
        }"
        >♔︎</span
      >
    </span>
  </span>
</template>

<script setup>
/**
 * A crown (design "Boutique"): a gold coin with the king, turning on itself when `spin`.
 */
defineProps({
  size: { type: Number, default: 28 },
  spin: { type: Boolean, default: false },
  /** Seconds before it starts turning. */
  delay: { type: Number, default: 0 }
})
</script>

<style scoped lang="scss">
.crown-coin {
  flex: none;
  display: inline-flex;
  perspective: 200px;
}

.crown-coin__face {
  position: relative;
  width: 100%;
  height: 100%;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: radial-gradient(
    circle at 34% 28%,
    #fff6c2 0,
    #ffd43b 42%,
    #e7a900 100%
  );

  &--spin {
    animation: crown-spin 3.2s cubic-bezier(0.6, 0, 0.4, 1) infinite;
  }
}

.crown-coin__rim {
  position: absolute;
  border-radius: 50%;
  border-style: solid;
  border-color: rgba(138, 106, 0, 0.45);
}

.crown-coin__king {
  position: relative;
  line-height: 1;
  color: #8a6a00;
}

@keyframes crown-spin {
  to {
    transform: rotateY(360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .crown-coin__face--spin {
    animation: none;
  }
}
</style>
