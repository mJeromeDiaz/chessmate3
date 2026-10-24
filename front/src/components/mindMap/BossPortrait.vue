<template>
  <div
    v-if="picture"
    class="boss-portrait boss-portrait--picture"
    :class="{
      'boss-portrait--round': round,
      'boss-portrait--standing': standing
    }"
    :style="{
      aspectRatio: round ? '1 / 1' : standing ? '545 / 830' : '5 / 4'
    }"
    role="img"
    :aria-label="label"
    :data-mood="standing ? 'standing' : currentMood"
  >
    <img
      :src="picture"
      alt=""
      class="boss-portrait__picture"
      draggable="false"
    />
  </div>
  <div
    v-else-if="glyph"
    class="boss-portrait boss-portrait--glyph"
    :class="{ 'boss-portrait--round': round }"
    :style="{ aspectRatio: round ? '1 / 1' : '5 / 4' }"
    role="img"
    :aria-label="label"
  >
    <span class="boss-portrait__glyph" aria-hidden="true">{{ glyph }}</span>
  </div>
  <div
    v-else
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
 * A boss: in one of its expressions when it has them (src/assets/mindMap/boss/<character>/, the
 * Métronome, the Gargouille), else as a piece (the endgames path's bosses, `BOSS_GLYPHS`), else in
 * one pose cut out of its character sheet (as cyclope.png): until each boss has its own picture,
 * the cyclops stands in for the others.
 */
import { computed } from 'vue'
import {
  BOSS_GLYPHS,
  bossImage,
  moodImage,
  POSE_MOODS,
  poseCrop,
  standingImage
} from '@/utils/mindMapPath'

const props = defineProps({
  /** The character (jongleur, cyclope…), null for a nameless boss. */
  character: { type: String, default: null },
  /** @type {import('vue').PropType<'idle'|'cheer'|'angry'|'search'|'beaten'>} */
  pose: { type: String, default: 'idle' },
  /** Its name, for screen readers. */
  label: { type: String, default: '' },
  /** In a round frame (the nodes of the path). */
  round: { type: Boolean, default: false },
  /**
   * Its expression in a fight, for a boss drawn by expressions (else its pose's).
   *
   * @type {import('vue').PropType<import('@/utils/mindMapPath').Mood|null>}
   */
  mood: { type: String, default: null },
  /** Standing, whole (its card), when it has such a picture. */
  full: { type: Boolean, default: false }
})

const currentMood = computed(
  () => props.mood ?? POSE_MOODS[props.pose] ?? 'neutral'
)
const standingSrc = computed(() =>
  props.full ? standingImage(props.character) : ''
)
const standing = computed(() => standingSrc.value !== '')
const picture = computed(
  () =>
    standingSrc.value ||
    moodImage(
      props.character,
      /** @type {import('@/utils/mindMapPath').Mood} */ (currentMood.value)
    )
)

const glyph = computed(() =>
  props.character ? (BOSS_GLYPHS[props.character] ?? '') : ''
)
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

// A piece in a halo of the endgames' lime (design « Parcours echiqueens »).
.boss-portrait--glyph {
  display: grid;
  place-items: center;
  container-type: inline-size;
  border-radius: 24px;
  background: radial-gradient(
    circle at 50% 35%,
    var(--cm-lime-soft),
    var(--cm-inset) 75%
  );
  box-shadow: inset 0 0 0 2px var(--cm-lime);

  &.boss-portrait--round {
    border-radius: 50%;
  }
}

.boss-portrait__glyph {
  font-size: 55cqi;
  line-height: 1;
  color: var(--cm-lime-ink);
  user-select: none;
}

.boss-portrait__picture {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
  object-position: center bottom;
  user-select: none;
  pointer-events: none;
}

.boss-portrait--round .boss-portrait__picture {
  object-fit: cover;
  object-position: center top;
}

.boss-portrait__sheet {
  position: absolute;
  max-width: none;
  height: auto;
  user-select: none;
  pointer-events: none;
}
</style>
