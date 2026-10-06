<template>
  <q-drawer
    v-model="open"
    side="left"
    overlay
    behavior="mobile"
    :width="272"
    class="nav-drawer"
    data-testid="nav-drawer"
  >
    <div class="nav-drawer__who">
      <UserAvatar />
      <div class="nav-drawer__name">{{ name }}</div>
      <RatingBadge v-if="open" class="nav-drawer__rating" />
    </div>

    <q-list>
      <q-item
        v-for="link in NAV_LINKS"
        :key="link.to"
        clickable
        :to="link.to"
        :active="isNavActive(link, route.path)"
        active-class="nav-drawer__item--on"
        :aria-current="isNavActive(link, route.path) ? 'page' : undefined"
      >
        <q-item-section avatar><q-icon :name="link.icon" /></q-item-section>
        <q-item-section>{{ link.label }}</q-item-section>
      </q-item>

      <q-separator class="q-my-sm" />

      <q-item clickable to="/profile" active-class="nav-drawer__item--on">
        <q-item-section avatar><q-icon name="person" /></q-item-section>
        <q-item-section>Profil</q-item-section>
      </q-item>
      <q-item
        v-if="auth.isAdmin"
        clickable
        to="/admin"
        active-class="nav-drawer__item--on"
        data-testid="drawer-admin"
      >
        <q-item-section avatar>
          <q-icon name="admin_panel_settings" />
        </q-item-section>
        <q-item-section>Admin</q-item-section>
      </q-item>
      <q-expansion-item
        :icon="current.icon"
        :label="`Thème : ${current.label}`"
        data-testid="drawer-theme"
      >
        <q-item
          v-for="option in THEME_OPTIONS"
          :key="option.value"
          clickable
          dense
          :inset-level="1"
          :active="theme.theme === option.value"
          active-class="nav-drawer__item--on"
          :data-testid="`drawer-theme-${option.value}`"
          @click="theme.choose(option.value)"
        >
          <q-item-section avatar><q-icon :name="option.icon" /></q-item-section>
          <q-item-section>{{ option.label }}</q-item-section>
        </q-item>
      </q-expansion-item>
      <q-item
        clickable
        :disable="loggingOut"
        data-testid="drawer-logout"
        @click="logout"
      >
        <q-item-section avatar><q-icon name="logout" /></q-item-section>
        <q-item-section>Déconnexion</q-item-section>
      </q-item>
    </q-list>
  </q-drawer>
</template>

<script setup>
import { computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import RatingBadge from '@/components/puzzle/RatingBadge.vue'
import UserAvatar from '@/components/layout/UserAvatar.vue'
import { useLogout } from '@/composables/layout/useLogout'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { NAV_LINKS, isNavActive } from '@/utils/layout/navigation'
import { profileName } from '@/utils/profile'
import { THEME_OPTIONS } from '@/utils/theme'

/**
 * The narrow screens' navigation (below 1024 px): every page plus the account, in a drawer
 * opened by the header's burger button. Closes itself once a page is chosen.
 */
const open = defineModel({ type: Boolean, default: false })

const auth = useAuthStore()
const theme = useThemeStore()
const route = useRoute()
const { loggingOut, logout } = useLogout()

const name = computed(() => profileName(auth.profile ?? {}))
const current = computed(
  () => THEME_OPTIONS.find(option => option.value === theme.theme) ?? THEME_OPTIONS[0]
)

watch(
  () => route.fullPath,
  () => {
    open.value = false
  }
)
</script>

<style scoped lang="scss">
.nav-drawer__who {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px;
  border-bottom: 1px solid var(--cm-line);
}

.nav-drawer__name {
  flex: 1;
  min-width: 0;
  font-weight: 700;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.nav-drawer__rating {
  margin: 0;
}

.nav-drawer__item--on {
  color: var(--cm-brand-deep);
  background: var(--cm-brand-soft);
  font-weight: 700;
}
</style>
