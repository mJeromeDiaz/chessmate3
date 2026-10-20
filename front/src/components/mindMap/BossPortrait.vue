<template>
  <div
    class="boss-portrait"
    :class="{ 'boss-portrait--round': round }"
    :style="{ aspectRatio: crop.aspectRatio }"
    role="img"
    :aria-label="label"
  >
    <img
      :src="src"
      alt=""
      class="boss-portrait__sheet"
      :style="{ width: crop.width, left: crop.left, top: crop.top }"
      draggable="false"
    />
  </div>
</template>

<script setup>
/**
 * A boss in one pose, cut out of its character sheet (src/assets/mindMap/boss, as cyclope.png).
 * Until each boss has its own picture, the cyclops stands in for all of them.
 */
import { computed } from 'vue'
import { bossImage, poseCrop } from '@/utils/mindMapPath'

const props = defineProps({
  /** The character (jongleur, cyclope…), null for a nameless boss. */
  character: { type: String, default: null },
  /** @type {import('vue').PropType<'idle'|'cheer'|'angry'|'search'|'beaten'>} */
  pose: { type: String, default: 'idle' },
  /** Its name, for screen readers. */
  label: { type: String, default: '' },
  /** In a round frame (the nodes of the path). */
  round: { type: Boolean, default: false }
})

const src = computed(() => bossImage(props.character))
const crop = computed(() => poseCrop(/** @type {'idle'} */ (props.pose)))
</script>

<style scoped lang="scss">
.boss-portrait {
  position: relative;
  overflow: hidden;
  width: 100%;

  &--round {
    border-radius: 50%;
    background: #fff;
  }
}

.boss-portrait__sheet {
  position: absolute;
  max-width: none;
  height: auto;
  user-select: none;
  pointer-events: none;
}
</style>
