<template>
  <div class="tour-hello">
    <div class="tour-hello__trio">
      <div
        v-for="(prof, i) in trio"
        :key="prof.name"
        class="tour-hello__card tour-in"
        :style="{ background: prof.bg, animationDelay: `${0.15 + i * 0.08}s` }"
      >
        <img v-if="prof.image" :src="prof.image" :alt="prof.name" />
        <span v-else class="tour-hello__shadow" aria-hidden="true">♘︎</span>
        <span
          class="tour-hello__name"
          :class="{ 'tour-hello__name--night': !prof.image }"
          >{{ prof.name }}</span
        >
      </div>
    </div>
    <p class="tour-hello__note tour-in" style="animation-delay: 0.5s">
      {{ $t('onboarding.hello.note') }}
    </p>
  </div>
</template>

<script setup>
/**
 * The tour's first screen: the professors, and an empty seat in the shadow (Ray, later).
 */
import { welcomeTrio } from '@/utils/onboarding'

const trio = welcomeTrio()
</script>

<style scoped lang="scss">
.tour-hello {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.tour-hello__trio {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
}

.tour-hello__card {
  position: relative;
  height: 96px;
  border-radius: 18px;
  overflow: hidden;

  img {
    position: absolute;
    left: 50%;
    bottom: -6px;
    height: 92px;
    transform: translateX(-50%);
  }

  @media (min-width: $breakpoint-md-min) {
    height: 130px;

    img {
      height: 124px;
    }
  }
}

.tour-hello__shadow {
  position: absolute;
  left: 50%;
  bottom: -4px;
  transform: translateX(-50%);
  font-size: 76px;
  line-height: 1;
  color: #000;
  opacity: 0.75;
}

.tour-hello__name {
  position: absolute;
  left: 8px;
  top: 7px;
  font-size: 11px;
  font-weight: 800;
  color: #1b1530;

  &--night {
    color: #c6f432;
  }
}

.tour-hello__note {
  margin: 0;
  padding: 0 4px;
  font-size: 13.5px;
  line-height: 1.45;
  color: var(--cm-ink-soft);
}
</style>
