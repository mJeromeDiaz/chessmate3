<template>
  <q-layout view="hHh lpr fFf">
    <q-header v-if="showHeader" class="app-header">
      <q-toolbar class="app-header__bar">
        <q-btn
          v-if="auth.isAuthenticated"
          flat
          round
          dense
          icon="menu"
          class="lt-md"
          aria-label="Menu"
          data-testid="nav-burger"
          @click="drawer = !drawer"
        />
        <q-toolbar-title class="app-header__title">
          <router-link to="/" class="cm-brand-name"
            >Don't Stay <span>Rooky</span></router-link
          >
        </q-toolbar-title>

        <template v-if="auth.isAuthenticated">
          <nav class="app-header__nav gt-sm" aria-label="Navigation principale">
            <q-btn
              v-for="link in headerLinks"
              :key="link.to"
              flat
              no-caps
              :to="link.to"
              :label="link.label"
              class="app-header__link"
              :class="{ 'app-header__link--on': isNavActive(link, route.path) }"
              :aria-current="isNavActive(link, route.path) ? 'page' : undefined"
            />
          </nav>
          <StreakChip class="app-header__streak" />
          <RatingBadge class="gt-xs" />
          <AccountMenu class="gt-sm" />
        </template>
        <template v-else>
          <q-btn flat no-caps to="/login" label="Connexion" />
          <q-btn
            flat
            no-caps
            to="/register"
            label="Inscription"
            class="gt-xs"
          />
          <ThemeMenu />
        </template>
      </q-toolbar>
    </q-header>

    <NavDrawer v-if="auth.isAuthenticated" v-model="drawer" />
    <StreakCelebration v-if="auth.isAuthenticated" />

    <q-page-container>
      <router-view />
    </q-page-container>
  </q-layout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import StreakCelebration from '@/components/gamification/StreakCelebration.vue'
import StreakChip from '@/components/gamification/StreakChip.vue'
import AccountMenu from '@/components/layout/AccountMenu.vue'
import NavDrawer from '@/components/layout/NavDrawer.vue'
import RatingBadge from '@/components/puzzle/RatingBadge.vue'
import ThemeMenu from '@/components/theme/ThemeMenu.vue'
import { NAV_LINKS, isNavActive } from '@/utils/layout/navigation'

const auth = useAuthStore()
const route = useRoute()
/** The narrow screens' drawer (below 1024 px; the wide header shows the links). */
const drawer = ref(false)

const headerLinks = NAV_LINKS.filter(link => link.header)

/** The landing page (visitors' home) brings its own header. */
const showHeader = computed(() => auth.isAuthenticated || !route.meta.landing)
</script>

<style scoped lang="scss">
.app-header {
  background: var(--cm-surface);
  color: var(--cm-ink);
  border-bottom: 1px solid var(--cm-line);
}

.app-header__bar {
  gap: 4px;
}

.app-header__title {
  min-width: 0;
  padding-left: 4px;
}

.cm-brand-name {
  font-size: 19px;
  white-space: nowrap;
}

.app-header__streak {
  margin-right: 4px;
}

.app-header__nav {
  display: flex;
  gap: 2px;
  margin-right: 8px;
}

.app-header__link {
  color: var(--cm-ink-soft);
  font-weight: 600;

  &--on {
    color: var(--cm-brand-deep);
    box-shadow: inset 0 -3px 0 var(--cm-brand);
  }
}
</style>
