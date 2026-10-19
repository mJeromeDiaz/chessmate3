<template>
  <q-page padding>
    <div class="mind-page">
      <header class="mind-page__head">
        <div class="mind-page__kicker">{{ $t('mindMap.page.kicker') }}</div>
        <h1 class="text-h5 q-my-none">{{ $t('mindMap.page.title') }}</h1>
        <p class="mind-page__hint" data-testid="mind-hint">{{ hint }}</p>
      </header>

      <div class="mind-page__map">
        <div class="mind-page__brain">
          <BrainMap
            :selected="selected"
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
            :selected="selected === c.key"
            :hovered="hovered === c.key"
            :dimmed="!!selected && selected !== c.key"
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
            :selected="selected === c.key"
            :hovered="hovered === c.key"
            :dimmed="!!selected && selected !== c.key"
            @select="select"
            @hover="hovered = $event"
          />
        </div>
      </div>
    </div>

    <q-dialog
      v-model="open"
      position="right"
      full-height
      :maximized="$q.screen.lt.sm"
      @hide="selected = null"
    >
      <div class="mind-page__panel">
        <CapacityPanel
          v-if="current"
          :capacity="current"
          @close="open = false"
        />
      </div>
    </q-dialog>
  </q-page>
</template>

<script setup>
/**
 * The mind map (design "Carte mentale"): the six cognitive capacities a player trains, as zones
 * of a brain and cards around it. Choosing one (its zone or its card) opens it on the side: what
 * it is and the modules that train it. Its chapters come later.
 */
import { computed, ref } from 'vue'
import { useQuasar } from 'quasar'
import BrainMap from '@/components/mindMap/BrainMap.vue'
import CapacityCard from '@/components/mindMap/CapacityCard.vue'
import CapacityPanel from '@/components/mindMap/CapacityPanel.vue'
import { LEFT, RIGHT, capacity, capacityName } from '@/utils/mindMap'
import { t } from '@/i18n'

definePage({ meta: { auth: 'required' } })

const $q = useQuasar()
/** @type {import('vue').Ref<string|null>} the capacity opened */
const selected = ref(null)
/** @type {import('vue').Ref<string|null>} the capacity under the pointer */
const hovered = ref(null)
const open = ref(false)

const byKey = (/** @type {string} */ key) =>
  /** @type {import('@/utils/mindMap').Capacity} */ (capacity(key))
const left = LEFT.map(byKey)
const right = RIGHT.map(byKey)
const current = computed(() =>
  selected.value ? capacity(selected.value) : null
)

const hint = computed(() =>
  selected.value
    ? t('mindMap.page.selected', { name: capacityName(selected.value) })
    : t('mindMap.page.hint')
)

/** @param {string} key */
function select(key) {
  selected.value = key
  open.value = true
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

.mind-page__kicker {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
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

.mind-page__panel {
  width: min(480px, 100vw);
  max-width: 100vw;
  overflow-y: auto;
  background: var(--cm-page);
}
</style>
