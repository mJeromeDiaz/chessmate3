<template>
  <section class="cm-card modules" data-testid="module-progress">
    <div class="modules__head">
      <h2 class="cm-card__title">Progression par module</h2>
      <span class="modules__note">Niveaux <ShowcaseTag /></span>
    </div>
    <div class="modules__grid">
      <component
        :is="row.to ? 'router-link' : 'div'"
        v-for="row in rows"
        :key="row.module.id"
        :to="row.to ?? undefined"
        class="modules__row"
        :class="{ 'modules__row--soon': !row.to }"
        :data-testid="`module-row-${row.module.id}`"
      >
        <ProfAvatar
          :image="row.module.image"
          :bg="row.module.bg"
          :deep="row.module.deep"
          :ink="row.module.ink"
          :glyph="row.module.glyph"
          radius="13px"
          class="modules__avatar"
        />
        <div class="modules__body">
          <div class="modules__line">
            <span class="modules__title">{{ row.module.title }}</span>
            <span
              class="modules__level"
              :style="{ color: row.module.accentInk }"
              >Niv. {{ row.level }}</span
            >
          </div>
          <div
            class="modules__bar"
            :style="{ background: row.module.soft }"
            :title="row.ratioLabel || undefined"
          >
            <div
              :style="{
                width: `${Math.round(row.ratio * 100)}%`,
                background: row.module.deep
              }"
            />
          </div>
          <div class="modules__stat" data-testid="module-stat">{{
            row.stat
          }}</div>
        </div>
      </component>
    </div>
  </section>
</template>

<script setup>
import ProfAvatar from '@/components/session/ProfAvatar.vue'
import ShowcaseTag from '@/components/dashboard/ShowcaseTag.vue'

defineProps({
  /** @type {import('vue').PropType<import('@/utils/dashboard/modules').ModuleRow[]>} */
  rows: { type: Array, required: true }
})
</script>

<style scoped lang="scss">
.modules {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.modules__head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
}

.modules__note {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 700;
  color: var(--cm-muted);
}

.modules__grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 10px;

  @media (min-width: $breakpoint-sm-min) {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.modules__row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 18px;
  background: var(--cm-page);
  color: inherit;
  text-decoration: none;

  &:hover:not(.modules__row--soon) {
    color: inherit;
    background: var(--cm-subtle);
  }

  &--soon {
    opacity: 0.55;
  }
}

.modules__avatar {
  width: 46px;
  height: 46px;
  flex: none;
}

.modules__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.modules__line {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}

.modules__title {
  font-weight: 700;
  font-size: 14px;
}

.modules__level {
  font-size: 12px;
  font-weight: 700;
}

.modules__bar {
  height: 7px;
  border-radius: 7px;
  overflow: hidden;

  > div {
    height: 100%;
    border-radius: 7px;
  }
}

.modules__stat {
  font-size: 12px;
  color: var(--cm-muted);
}
</style>
