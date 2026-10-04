<template>
  <button
    type="button"
    class="module-card"
    :class="{ 'module-card--soon': !module.available }"
    :style="{ background: module.bg, color: module.ink }"
    :disabled="!module.available"
    :data-testid="`module-card-${module.id}`"
    @click="emit('select', module.id)"
  >
    <span class="module-card__shade" :style="{ background: module.deep }" />
    <span class="module-card__glow" />
    <span class="module-card__head">
      <span class="module-card__title cm-heading">{{ module.title }}</span>
      <span v-if="!module.available" class="module-card__soon">Bientôt</span>
    </span>
    <span class="module-card__desc">{{ module.desc }}</span>
    <ProfAvatar
      class="module-card__avatar"
      :image="module.image"
      :deep="module.deep"
      :ink="module.ink"
      :glyph="module.glyph"
    />
  </button>
</template>

<script setup>
import ProfAvatar from '@/components/session/ProfAvatar.vue'

/** A catalogue module, in its colours with its professor; modules not built yet are greyed. */
defineProps({
  /** @type {import('vue').PropType<import('@/utils/session/catalog').Module>} */
  module: { type: Object, required: true }
})

const emit = defineEmits(['select'])
</script>

<style scoped lang="scss">
.module-card {
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 3px;
  width: 100%;
  min-height: 84px;
  padding: 14px 96px 14px 18px;
  border: none;
  border-radius: 22px;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: transform 0.12s ease;

  &:hover:not(:disabled) {
    transform: translateY(-1px);
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }

  &--soon {
    cursor: default;
    filter: saturate(0.35);
    opacity: 0.7;
  }
}

.module-card__shade {
  position: absolute;
  inset: 0 0 0 auto;
  width: 55%;
  opacity: 0.55;
  mask-image: linear-gradient(to right, transparent, #000);
  pointer-events: none;
}

.module-card__glow {
  position: absolute;
  right: -20px;
  top: 50%;
  width: 140px;
  height: 140px;
  transform: translateY(-50%);
  background: radial-gradient(
    circle,
    rgba(255, 255, 255, 0.28),
    transparent 65%
  );
  pointer-events: none;
}

.module-card__head {
  position: relative;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px 8px;
}

.module-card__title {
  font-size: 18px;
  line-height: 1.15;
}

.module-card__desc {
  position: relative;
  font-size: 13px;
  line-height: 1.4;
  text-wrap: pretty;
}

.module-card__soon {
  flex: none;
  padding: 2px 9px;
  border-radius: 999px;
  background: #fff;
  color: #1b1530;
  font-size: 11px;
  font-weight: 700;
}

.module-card__avatar {
  position: absolute;
  right: 6px;
  bottom: -10px;
  width: 86px;
  height: 86px;
}
</style>
