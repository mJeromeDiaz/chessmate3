<template>
  <q-page padding>
    <div class="mind-page">
      <header class="mind-page__head">
        <h1 class="text-h5 q-my-none">{{ $t('mindMap.page.title') }}</h1>
        <p class="mind-page__lead" data-testid="mind-lead">
          {{ $t('mindMap.page.lead') }}
        </p>
        <p class="mind-page__hint" data-testid="mind-hint">{{
          $t('mindMap.page.hint')
        }}</p>
      </header>

      <div class="mind-page__map">
        <div class="mind-page__brain">
          <BrainMap
            :hovered="hovered"
            @select="select"
            @hover="hovered = $event"
          />
        </div>
        <div class="mind-page__side mind-page__side--left">
          <CapacityCard
            v-for="c in left"
            :key="c.key"
            :capacity="c"
            side="left"
            :hovered="hovered === c.key"
            :soon="!hasPath(c.key)"
            @select="select"
            @hover="hovered = $event"
          />
        </div>
        <div class="mind-page__side mind-page__side--right">
          <CapacityCard
            v-for="c in right"
            :key="c.key"
            :capacity="c"
            side="right"
            :hovered="hovered === c.key"
            :soon="!hasPath(c.key)"
            @select="select"
            @hover="hovered = $event"
          />
        </div>
      </div>
    </div>
  </q-page>
</template>

<script setup>
/**
 * The mind map (design "Carte mentale"): the six cognitive capacities a player trains, as zones
 * of a brain and cards around it. Choosing one (its zone or its card) opens its path; a capacity
 * without path yet says « Bientôt ».
 */
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import BrainMap from '@/components/mindMap/BrainMap.vue'
import CapacityCard from '@/components/mindMap/CapacityCard.vue'
import { LEFT, RIGHT, capacity } from '@/utils/mindMap'
import { hasPath } from '@/utils/mindMapPath'

definePage({
  meta: {
    auth: 'required',
    header: { title: 'mindMap.page.title', subtitle: 'mindMap.page.kicker' }
  }
})

const router = useRouter()
/** @type {import('vue').Ref<string|null>} the capacity under the pointer */
const hovered = ref(null)

const byKey = (/** @type {string} */ key) =>
  /** @type {import('@/utils/mindMap').Capacity} */ (capacity(key))
const left = LEFT.map(byKey)
const right = RIGHT.map(byKey)

/** @param {string} key */
function select(key) {
  if (hasPath(key)) router.push(`/cerveau/${key}`)
}
</script>

<style scoped lang="scss">
.mind-page {
  max-width: 1240px;
  margin: 0 auto;
}

.mind-page__head {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-bottom: 16px;
}

.mind-page__lead {
  max-width: 72ch;
  margin: 8px 0 0;
  font-size: 15px;
  line-height: 1.5;
}

.mind-page__hint {
  margin: 8px 0 0;
  color: var(--cm-muted);
  font-size: 15px;
  font-weight: 600;
}

// Phone: the brain, then the cards. Wide screen: cards | brain | cards, as in the design.
.mind-page__map {
  display: grid;
  gap: 18px;
  grid-template-areas: 'brain' 'left' 'right';

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: minmax(0, 1fr) minmax(280px, 1.45fr) minmax(0, 1fr);
    grid-template-areas: 'left brain right';
    gap: 44px;
    align-items: center;
  }
}

.mind-page__brain {
  grid-area: brain;
  padding: 16px 0;
}

.mind-page__side {
  display: flex;
  flex-direction: column;
  gap: 18px;

  &--left {
    grid-area: left;
  }

  &--right {
    grid-area: right;
  }
}
</style>
