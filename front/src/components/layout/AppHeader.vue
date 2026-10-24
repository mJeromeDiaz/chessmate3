<template>
  <q-toolbar class="app-bar">
    <q-btn
      v-if="parent"
      flat
      dense
      icon="arrow_back"
      :to="parent"
      class="app-bar__square"
      :aria-label="$t('layout.header.back')"
      data-testid="header-back"
    />
    <div class="app-bar__titles">
      <template v-if="title">
        <div
          v-if="subtitle"
          class="app-bar__subtitle"
          data-testid="header-subtitle"
        >
          {{ subtitle }}
        </div>
        <div class="app-bar__title" data-testid="header-title">{{ title }}</div>
      </template>
      <router-link v-else to="/" class="cm-brand-name app-bar__brand"
        >Don't Stay <span>Rooky</span></router-link
      >
    </div>

    <StreakChip />
    <WheelChip />
    <CrystalChip />
    <q-btn
      flat
      dense
      icon="menu"
      class="app-bar__square"
      :aria-label="$t('layout.nav.menu')"
      data-testid="nav-burger"
      @click="emit('menu')"
    />
  </q-toolbar>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import StreakChip from '@/components/gamification/StreakChip.vue'
import CrystalChip from '@/components/layout/CrystalChip.vue'
import WheelChip from '@/components/layout/WheelChip.vue'
import { t } from '@/i18n'
import { useLayoutStore } from '@/stores/layout'
import { parentPath } from '@/utils/layout/pageHeader'

/**
 * The signed-in header: back arrow to the parent page, the page's subtitle and title (the brand
 * when the page has none), the streak, the gift wheels to spin, the crystals and the menu button.
 */
const emit = defineEmits(['menu'])

const route = useRoute()
const router = useRouter()
const layout = useLayoutStore()

/** @type {import('vue').ComputedRef<import('@/utils/layout/pageHeader').PageHeaderMeta>} */
const meta = computed(() => route.meta.header ?? {})

const title = computed(
  () => layout.title ?? (meta.value.title ? t(meta.value.title) : null)
)
const subtitle = computed(
  () => layout.subtitle ?? (meta.value.subtitle ? t(meta.value.subtitle) : null)
)

/**
 * A real page, not the catch-all 404.
 *
 * @param {string} path
 */
function exists(path) {
  const matched = router.resolve(path).matched
  return (
    matched.length > 0 && !matched.some(record => record.path.includes('(.*)'))
  )
}

const parent = computed(() => parentPath(route.path, exists, meta.value.parent))
</script>

<style scoped lang="scss">
.app-bar {
  gap: 8px;
  min-height: 60px;
  padding: 8px 16px;
}

.app-bar__square {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  flex-shrink: 0;
}

.app-bar__titles {
  flex: 1;
  min-width: 0;
  margin-left: 4px;
}

.app-bar__subtitle {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
  line-height: 1.2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.app-bar__title {
  margin: 0;
  font-size: 19px;
  font-weight: 800;
  line-height: 1.25;
  letter-spacing: 0;
  color: var(--cm-ink);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.app-bar__brand {
  font-size: 19px;
  white-space: nowrap;
}
</style>
