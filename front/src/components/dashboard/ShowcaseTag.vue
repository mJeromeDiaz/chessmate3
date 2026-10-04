<template>
  <span class="showcase-tag" data-testid="showcase-tag"
    >Aperçu
    <q-tooltip
      >Valeurs d’exemple : la gamification arrive dans une prochaine
      version.</q-tooltip
    ></span
  >
</template>

<script setup>
/** Marks a block of the design's gamification shown with example values (utils/dashboard/showcase). */
</script>

<style scoped lang="scss">
.showcase-tag {
  display: inline-flex;
  align-items: center;
  height: 20px;
  padding: 0 8px;
  border-radius: 999px;
  border: 1px dashed currentColor;
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  opacity: 0.8;
  cursor: help;
}
</style>
