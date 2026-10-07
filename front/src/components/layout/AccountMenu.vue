<template>
  <q-btn
    flat
    round
    dense
    class="account-menu"
    :aria-label="`Compte : ${name}`"
    data-testid="account-menu"
  >
    <UserAvatar />
    <q-menu anchor="bottom right" self="top right">
      <q-list style="min-width: 210px">
        <q-item-label header class="account-menu__name">{{
          name
        }}</q-item-label>
        <q-item v-close-popup clickable to="/profile" data-testid="nav-profile">
          <q-item-section avatar><q-icon name="person" /></q-item-section>
          <q-item-section>Profil</q-item-section>
        </q-item>
        <q-item
          v-if="auth.isAdmin"
          v-close-popup
          clickable
          to="/admin"
          data-testid="nav-admin"
        >
          <q-item-section avatar>
            <q-icon name="admin_panel_settings" />
          </q-item-section>
          <q-item-section>Admin</q-item-section>
        </q-item>
        <q-item clickable data-testid="account-theme">
          <q-item-section avatar
            ><q-icon :name="current.icon"
          /></q-item-section>
          <q-item-section>Thème : {{ current.label }}</q-item-section>
          <q-item-section side><q-icon name="chevron_right" /></q-item-section>
          <q-menu anchor="top start" self="top end">
            <q-list dense style="min-width: 170px">
              <q-item
                v-for="option in THEME_OPTIONS"
                :key="option.value"
                v-close-popup="2"
                clickable
                :active="theme.theme === option.value"
                :data-testid="`theme-${option.value}`"
                @click="theme.choose(option.value)"
              >
                <q-item-section avatar
                  ><q-icon :name="option.icon"
                /></q-item-section>
                <q-item-section>{{ option.label }}</q-item-section>
              </q-item>
            </q-list>
          </q-menu>
        </q-item>
        <q-separator />
        <q-item
          v-close-popup
          clickable
          :disable="loggingOut"
          data-testid="nav-logout"
          @click="logout"
        >
          <q-item-section avatar><q-icon name="logout" /></q-item-section>
          <q-item-section>Déconnexion</q-item-section>
        </q-item>
      </q-list>
    </q-menu>
  </q-btn>
</template>

<script setup>
import { computed } from 'vue'
import UserAvatar from '@/components/layout/UserAvatar.vue'
import { useLogout } from '@/composables/layout/useLogout'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { profileName } from '@/utils/profile'
import { THEME_OPTIONS } from '@/utils/theme'

/** The wide header's account menu: profile, administration, theme, sign-out. */
const auth = useAuthStore()
const theme = useThemeStore()
const { loggingOut, logout } = useLogout()

const name = computed(() => profileName(auth.profile ?? {}))
const current = computed(
  () =>
    THEME_OPTIONS.find(option => option.value === theme.theme) ??
    THEME_OPTIONS[0]
)
</script>

<style scoped lang="scss">
.account-menu__name {
  font-weight: 700;
  color: var(--cm-ink);
}
</style>
