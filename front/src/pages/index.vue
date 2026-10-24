<template>
  <q-layout view="hHh lpr fFf">
    <q-header v-if="showHeader" class="app-header">
      <AppHeader v-if="auth.isAuthenticated" @menu="drawer = !drawer" />
      <q-toolbar v-else class="app-header__bar">
        <q-toolbar-title class="app-header__title">
          <router-link to="/" class="cm-brand-name"
            >Don't Stay <span>Rooky</span></router-link
          >
        </q-toolbar-title>
        <q-btn flat no-caps to="/login" :label="$t('layout.account.signIn')" />
        <q-btn
          flat
          no-caps
          to="/register"
          :label="$t('layout.account.signUp')"
          class="gt-xs"
        />
        <ThemeMenu />
      </q-toolbar>
    </q-header>

    <AppMenu v-if="auth.isAuthenticated" v-model="drawer" />
    <StreakCelebration v-if="auth.isAuthenticated" />

    <q-page-container>
      <router-view />
    </q-page-container>
    <!-- After the page: above its own chests (a mind map step's), after the celebration. -->
    <DailyChest v-if="auth.isAuthenticated" />
    <!-- An admin's gifts, once nothing else is on screen (docs/SHOP.md, § 4 quater). -->
    <GiftNotice v-if="auth.isAuthenticated" />
  </q-layout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import StreakCelebration from '@/components/gamification/StreakCelebration.vue'
import AppHeader from '@/components/layout/AppHeader.vue'
import AppMenu from '@/components/layout/AppMenu.vue'
import DailyChest from '@/components/shop/DailyChest.vue'
import GiftNotice from '@/components/shop/GiftNotice.vue'
import ThemeMenu from '@/components/theme/ThemeMenu.vue'

const auth = useAuthStore()
const route = useRoute()
/** The navigation menu, opened by the header's menu button. */
const drawer = ref(false)

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
</style>
