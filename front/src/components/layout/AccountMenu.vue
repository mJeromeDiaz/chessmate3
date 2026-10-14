<template>
  <q-btn
    flat
    round
    dense
    class="account-menu"
    :aria-label="$t('layout.account.label', { name })"
    data-testid="account-menu"
  >
    <UserAvatar />
    <q-menu
      anchor="bottom right"
      self="top right"
      @show="shop.loadOnce().catch(() => {})"
    >
      <q-list style="min-width: 210px">
        <q-item-label header class="account-menu__name">{{
          name
        }}</q-item-label>
        <q-item v-close-popup clickable to="/profile" data-testid="nav-profile">
          <q-item-section avatar><q-icon name="person" /></q-item-section>
          <q-item-section>{{ $t('layout.account.profile') }}</q-item-section>
        </q-item>
        <q-item v-close-popup clickable :to="TOUR_PATH" data-testid="nav-tour">
          <q-item-section avatar><q-icon name="tour" /></q-item-section>
          <q-item-section>{{ $t('layout.account.tour') }}</q-item-section>
        </q-item>
        <q-item v-close-popup clickable to="/boutique" data-testid="nav-shop">
          <q-item-section avatar><CrownCoin :size="22" /></q-item-section>
          <q-item-section>{{ $t('layout.account.shop') }}</q-item-section>
          <q-item-section
            v-if="shop.state"
            side
            class="account-menu__crowns"
            data-testid="nav-crowns"
            >{{ crowns(shop.state.balance) }}</q-item-section
          >
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
          <q-item-section>{{ $t('layout.account.admin') }}</q-item-section>
        </q-item>
        <q-item clickable data-testid="account-theme">
          <q-item-section avatar
            ><q-icon :name="current.icon"
          /></q-item-section>
          <q-item-section>{{
            $t('layout.theme.current', { name: current.label })
          }}</q-item-section>
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
          <q-item-section>{{ $t('layout.account.signOut') }}</q-item-section>
        </q-item>
      </q-list>
    </q-menu>
  </q-btn>
</template>

<script setup>
import { computed } from 'vue'
import UserAvatar from '@/components/layout/UserAvatar.vue'
import CrownCoin from '@/components/shop/CrownCoin.vue'
import { useLogout } from '@/composables/layout/useLogout'
import { useAuthStore } from '@/stores/auth'
import { useShopStore } from '@/stores/shop'
import { useThemeStore } from '@/stores/theme'
import { TOUR_PATH } from '@/utils/auth/authFlow'
import { profileName } from '@/utils/profile'
import { crowns } from '@/utils/shop'
import { THEME_OPTIONS } from '@/utils/theme'

/** The wide header's account menu: profile, administration, theme, sign-out. */
const auth = useAuthStore()
const theme = useThemeStore()
const { loggingOut, logout } = useLogout()
const shop = useShopStore()

const name = computed(() => profileName(auth.profile ?? {}))
const current = computed(
  () =>
    THEME_OPTIONS.find(option => option.value === theme.theme) ??
    THEME_OPTIONS[0]
)
</script>

<style scoped lang="scss">
.account-menu__crowns {
  font-weight: 800;
  color: #8a6a00;
}

.body--dark .account-menu__crowns {
  color: #ffd43b;
}

.account-menu__name {
  font-weight: 700;
  color: var(--cm-ink);
}
</style>
