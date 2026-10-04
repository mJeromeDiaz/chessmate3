<template>
  <div
    class="prof-avatar"
    :style="{ background: bg, borderRadius: radius }"
    aria-hidden="true"
  >
    <img v-if="image" :src="image" alt="" class="prof-avatar__img" />
    <template v-else>
      <div class="prof-avatar__body" :style="{ background: deep }">
        <span class="prof-avatar__eye prof-avatar__eye--left" />
        <span class="prof-avatar__eye prof-avatar__eye--right" />
        <span class="prof-avatar__mouth" />
      </div>
      <div class="prof-avatar__glyph" :style="{ color: ink }">{{ glyph }}</div>
    </template>
  </div>
</template>

<script setup>
/**
 * A module's professor: their bust when there is one, otherwise a little piece character drawn in
 * CSS, wearing the module's chess piece (design "ProfAvatar").
 */
defineProps({
  /** Bust URL; '' draws the fallback character. */
  image: { type: String, default: '' },
  bg: { type: String, default: 'transparent' },
  deep: { type: String, default: '#E03C86' },
  ink: { type: String, default: '#1B1530' },
  glyph: { type: String, default: '♞︎' },
  radius: { type: String, default: '0' }
})
</script>

<style scoped lang="scss">
.prof-avatar {
  position: relative;
  overflow: hidden;
  container-type: size;
}

.prof-avatar__img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
  object-position: center bottom;
}

.prof-avatar__body {
  position: absolute;
  left: 50%;
  bottom: -12%;
  width: 70%;
  height: 60%;
  transform: translateX(-50%);
  border-radius: 50% 50% 32% 32%;
}

.prof-avatar__eye {
  position: absolute;
  top: 22%;
  width: 27%;
  aspect-ratio: 1;
  background: #fff;
  border-radius: 50%;

  &::after {
    content: '';
    position: absolute;
    right: 10%;
    bottom: 10%;
    width: 52%;
    height: 52%;
    background: #1b1530;
    border-radius: 50%;
  }

  &--left {
    left: 15%;
  }

  &--right {
    right: 15%;
  }
}

.prof-avatar__mouth {
  position: absolute;
  top: 58%;
  left: 38%;
  width: 24%;
  height: 12%;
  border-bottom: 2px solid #1b1530;
  border-radius: 0 0 50% 50%;
}

.prof-avatar__glyph {
  position: absolute;
  left: 50%;
  top: 2%;
  transform: translateX(-50%) rotate(-12deg);
  font-size: 42cqw;
  line-height: 1;
}
</style>
