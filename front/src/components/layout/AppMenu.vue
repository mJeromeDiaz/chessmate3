<template>
  <q-drawer
    v-model="open"
    side="right"
    overlay
    behavior="mobile"
    :width="340"
    class="app-menu"
    data-testid="nav-menu"
  >
    <nav class="app-menu__inner" :aria-label="$t('layout.menu.label')">
      <q-tab-panels
        v-model="panel"
        animated
        transition-prev="slide-right"
        transition-next="slide-left"
        class="app-menu__panels"
      >
        <q-tab-panel name="main" class="app-menu__panel">
          <div class="app-menu__top">
            <q-btn
              flat
              dense
              icon="close"
              class="app-menu__square"
              :aria-label="$t('layout.menu.close')"
              data-testid="nav-close"
              @click="open = false"
            />
          </div>

          <q-list class="app-menu__list">
            <q-item
              clickable
              :to="DASHBOARD_LINK.to"
              exact
              :active="isNavActive(DASHBOARD_LINK, route.path)"
              active-class="app-menu__item--on"
              class="app-menu__item"
              data-testid="nav-dashboard"
            >
              <q-item-section avatar>
                <span class="app-menu__tile"
                  ><q-icon :name="DASHBOARD_LINK.icon"
                /></span>
              </q-item-section>
              <q-item-section>
                <q-item-label class="app-menu__title">{{
                  DASHBOARD_LINK.label
                }}</q-item-label>
                <q-item-label caption class="app-menu__text">{{
                  $t('layout.menu.dashboardText')
                }}</q-item-label>
              </q-item-section>
            </q-item>

            <q-item
              clickable
              to="/cerveau"
              :active="onPage('/cerveau')"
              active-class="app-menu__item--on"
              class="app-menu__item"
              data-testid="nav-mind-map"
            >
              <q-item-section avatar>
                <span class="app-menu__tile"><q-icon name="lightbulb" /></span>
              </q-item-section>
              <q-item-section>
                <q-item-label class="app-menu__title">{{
                  $t('layout.menu.mindMap.title')
                }}</q-item-label>
                <q-item-label caption class="app-menu__text">{{
                  $t('layout.menu.mindMap.text')
                }}</q-item-label>
              </q-item-section>
            </q-item>

            <q-item
              clickable
              to="/arene"
              :active="onPage('/arene')"
              active-class="app-menu__item--on"
              class="app-menu__item"
              data-testid="nav-arena"
            >
              <q-item-section avatar>
                <span class="app-menu__tile"
                  ><q-icon name="sports_martial_arts"
                /></span>
              </q-item-section>
              <q-item-section>
                <q-item-label class="app-menu__title">{{
                  $t('layout.menu.arena.title')
                }}</q-item-label>
                <q-item-label caption class="app-menu__text">{{
                  $t('layout.menu.arena.text')
                }}</q-item-label>
              </q-item-section>
            </q-item>

            <q-item
              clickable
              to="/session"
              :active="onPage('/session')"
              active-class="app-menu__item--on"
              class="app-menu__item"
              data-testid="nav-create"
            >
              <q-item-section avatar>
                <span class="app-menu__tile"
                  ><q-icon name="playlist_add"
                /></span>
              </q-item-section>
              <q-item-section>
                <q-item-label class="app-menu__title">{{
                  $t('layout.menu.create.title')
                }}</q-item-label>
                <q-item-label caption class="app-menu__text">{{
                  $t('layout.menu.create.text')
                }}</q-item-label>
              </q-item-section>
            </q-item>

            <q-item
              clickable
              :active="freeActive"
              active-class="app-menu__item--on"
              class="app-menu__item"
              data-testid="nav-free"
              @click="panel = 'free'"
            >
              <q-item-section avatar>
                <span class="app-menu__tile"
                  ><q-icon name="fitness_center"
                /></span>
              </q-item-section>
              <q-item-section>
                <q-item-label class="app-menu__title">{{
                  $t('layout.menu.free.title')
                }}</q-item-label>
                <q-item-label caption class="app-menu__text">{{
                  $t('layout.menu.free.text')
                }}</q-item-label>
              </q-item-section>
              <q-item-section side>
                <q-icon name="chevron_right" />
              </q-item-section>
            </q-item>
          </q-list>

          <div class="app-menu__challenges" data-testid="nav-challenges">
            <div class="app-menu__challenges-head">
              <span class="app-menu__tile"><q-icon name="emoji_events" /></span>
              <span class="app-menu__shop-body">
                <span class="app-menu__title">{{
                  $t('layout.menu.challenges.title')
                }}</span>
                <span class="app-menu__text text-caption">{{
                  $t('layout.menu.challenges.text')
                }}</span>
              </span>
            </div>
            <div class="app-menu__challenges-links">
              <router-link
                v-for="item in CHALLENGE_LINKS"
                :key="item.id"
                :to="item.to"
                class="app-menu__challenge"
                :class="{
                  'app-menu__challenge--on': isNavActive(item, route.path)
                }"
                :aria-current="
                  isNavActive(item, route.path) ? 'page' : undefined
                "
                :data-testid="`nav-challenges-${item.id}`"
              >
                <q-icon :name="item.icon" size="18px" />
                {{ item.label }}
              </router-link>
            </div>
          </div>

          <router-link
            to="/boutique"
            class="app-menu__shop"
            :class="{ 'app-menu__shop--on': onPage('/boutique') }"
            data-testid="nav-shop"
          >
            <CrystalGem :size="40" />
            <span class="app-menu__shop-body">
              <span class="app-menu__shop-title">{{
                $t('layout.menu.shop.title')
              }}</span>
              <span class="app-menu__shop-text">{{
                $t('layout.menu.shop.text')
              }}</span>
            </span>
            <span
              v-if="balance !== null"
              class="app-menu__shop-balance"
              data-testid="nav-crowns"
              >{{ crowns(balance) }}</span
            >
          </router-link>

          <button
            type="button"
            class="app-menu__wheel"
            data-testid="nav-wheel"
            @click="wheel"
          >
            <WheelGlyph :size="40" />
            <span class="app-menu__shop-body">
              <span class="app-menu__shop-title">{{
                $t('layout.menu.wheel.title')
              }}</span>
              <span class="app-menu__shop-text">{{
                $t('layout.menu.wheel.text')
              }}</span>
            </span>
            <span
              v-if="shop.wheelsToSpin > 0"
              class="app-menu__wheel-count"
              :aria-label="
                $t(
                  'layout.header.wheels',
                  { n: shop.wheelsToSpin },
                  shop.wheelsToSpin
                )
              "
              data-testid="nav-wheel-count"
              >{{ shop.wheelsToSpin }}</span
            >
          </button>

          <q-list class="app-menu__foot">
            <q-separator class="q-mb-sm" />
            <q-item
              clickable
              to="/profile"
              :active="onPage('/profile')"
              active-class="app-menu__item--on"
              data-testid="nav-profile"
            >
              <q-item-section avatar><UserAvatar /></q-item-section>
              <q-item-section>
                <q-item-label>{{ $t('layout.account.profile') }}</q-item-label>
                <q-item-label caption class="ellipsis">{{ name }}</q-item-label>
              </q-item-section>
            </q-item>
            <q-item
              v-if="auth.isAdmin"
              clickable
              to="/admin"
              :active="onPage('/admin')"
              active-class="app-menu__item--on"
              data-testid="nav-admin"
            >
              <q-item-section avatar>
                <q-icon name="admin_panel_settings" />
              </q-item-section>
              <q-item-section>{{ $t('layout.account.admin') }}</q-item-section>
            </q-item>
            <q-item
              clickable
              :disable="loggingOut"
              data-testid="nav-logout"
              @click="logout"
            >
              <q-item-section avatar><q-icon name="logout" /></q-item-section>
              <q-item-section>{{
                $t('layout.account.signOut')
              }}</q-item-section>
            </q-item>
          </q-list>
        </q-tab-panel>

        <q-tab-panel name="free" class="app-menu__panel">
          <div class="app-menu__top app-menu__top--sub">
            <q-btn
              flat
              dense
              icon="arrow_back"
              class="app-menu__square"
              :aria-label="$t('layout.menu.free.back')"
              data-testid="nav-free-back"
              @click="panel = 'main'"
            />
            <div class="app-menu__sub-title">
              {{ $t('layout.menu.free.title') }}
            </div>
          </div>
          <q-list
            v-for="group in FREE_TRAINING_SECTIONS"
            :key="group.id"
            class="app-menu__list"
          >
            <q-item-label
              header
              class="app-menu__section"
              :data-testid="`nav-free-section-${group.id}`"
              >{{ group.label }}</q-item-label
            >
            <q-item
              v-for="item in group.links"
              :key="item.id"
              clickable
              :to="{ path: item.to, query: item.query }"
              :active="isNavActive(item, route.path)"
              active-class="app-menu__item--on"
              class="app-menu__item"
              :aria-current="isNavActive(item, route.path) ? 'page' : undefined"
              :data-testid="`nav-free-${item.id}`"
            >
              <q-item-section avatar>
                <span class="app-menu__tile"><q-icon :name="item.icon" /></span>
              </q-item-section>
              <q-item-section class="app-menu__title">{{
                item.label
              }}</q-item-section>
            </q-item>
          </q-list>
        </q-tab-panel>
      </q-tab-panels>
    </nav>
  </q-drawer>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UserAvatar from '@/components/layout/UserAvatar.vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import WheelGlyph from '@/components/shop/WheelGlyph.vue'
import { useLogout } from '@/composables/layout/useLogout'
import { useAuthStore } from '@/stores/auth'
import { useShopStore } from '@/stores/shop'
import {
  CHALLENGE_LINKS,
  DASHBOARD_LINK,
  FREE_TRAINING_LINKS,
  FREE_TRAINING_SECTIONS,
  isNavActive
} from '@/utils/layout/navigation'
import { profileName } from '@/utils/profile'
import { crowns } from '@/utils/shop'

/**
 * The navigation menu, opened by the header's menu button on every screen: the dashboard, the
 * mind map, the session builder, the free training modules (a second panel, by phase of the game),
 * the défis card (the endgames' path and Lizy's défis), the shop, then the account. Closes itself once a page is chosen.
 */
const open = defineModel({ type: Boolean, default: false })

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const shop = useShopStore()
const { loggingOut, logout } = useLogout()

/** @type {import('vue').Ref<'main'|'free'>} */
const panel = ref('main')

const balance = computed(() => shop.state?.balance ?? null)
const name = computed(() => profileName(auth.profile ?? {}))
const freeActive = computed(() =>
  FREE_TRAINING_LINKS.some(item => isNavActive(item, route.path))
)

/**
 * @param {string} prefix
 */
function onPage(prefix) {
  return route.path === prefix || route.path.startsWith(`${prefix}/`)
}

/**
 * The gift wheel (docs/SHOP.md, § 4 ter): spun at once when there is one, else the store's
 * wheel section (a ticket to buy).
 */
function wheel() {
  if (shop.wheelsToSpin > 0) {
    open.value = false
    shop.openWheel()
  } else router.push({ path: '/boutique', query: { section: 'wheel' } })
}

watch(open, opened => {
  if (!opened) return
  panel.value = 'main'
  shop.loadOnce().catch(() => {})
})

watch(
  () => route.fullPath,
  () => {
    open.value = false
  }
)
</script>

<style scoped lang="scss">
.app-menu__inner {
  height: 100%;
}

.app-menu__panels {
  height: 100%;
  background: var(--cm-surface);
}

.app-menu__panel {
  display: flex;
  flex-direction: column;
  min-height: 100%;
  padding: 12px 12px 16px;
}

.app-menu__top {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  margin-bottom: 8px;

  &--sub {
    justify-content: flex-start;
  }
}

.app-menu__sub-title {
  font-size: 17px;
  font-weight: 800;
  color: var(--cm-ink);
}

.app-menu__square {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
}

.app-menu__item {
  border-radius: 14px;
  padding: 10px 8px;
  margin-bottom: 2px;
}

.app-menu__item--on {
  color: var(--cm-brand-deep);
  background: var(--cm-brand-soft);
}

.app-menu__tile {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: var(--cm-subtle);
  color: var(--cm-brand-deep);
  font-size: 20px;
}

.app-menu__title {
  font-weight: 800;
}

.app-menu__text {
  color: var(--cm-muted);
  line-height: 1.35;
}

.app-menu__section {
  padding: 12px 8px 4px;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.app-menu__challenges {
  padding: 10px 8px;
}

// Laid out like the list's items: the tile, then the title and its caption.
.app-menu__challenges-head {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 8px;
}

// Under the text, aligned with it (the tile's 40px and the gap).
.app-menu__challenges-links {
  display: flex;
  gap: 8px;
  padding-left: 56px;
}

.app-menu__challenge {
  display: inline-flex;
  flex: 1;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 34px;
  border-radius: 10px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font-size: 13px;
  font-weight: 700;
  text-decoration: none;

  &--on {
    background: var(--cm-brand-soft);
    color: var(--cm-brand-deep);
  }
}

.app-menu__shop {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 12px 0;
  padding: 14px;
  border-radius: 18px;
  background: linear-gradient(135deg, var(--cm-hero), var(--cm-hero-deep));
  color: #fff;
  text-decoration: none;
  box-shadow: 0 4px 0 rgba(27, 21, 48, 0.25);

  &--on {
    outline: 3px solid var(--cm-lime);
  }
}

.app-menu__shop-body {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.app-menu__shop-title {
  font-size: 16px;
  font-weight: 800;
}

.app-menu__shop-text {
  font-size: 12px;
  opacity: 0.85;
  line-height: 1.3;
}

.app-menu__shop-balance {
  padding: 4px 10px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.2);
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.app-menu__wheel {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  margin: 0 0 12px;
  padding: 14px;
  border: 0;
  border-radius: 18px;
  background: var(--cm-lime-soft);
  color: var(--cm-ink);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.app-menu__wheel-count {
  min-width: 28px;
  height: 28px;
  padding: 0 8px;
  border-radius: 14px;
  background: #ff3d5a;
  color: #fff;
  font-weight: 800;
  line-height: 28px;
  text-align: center;
  font-variant-numeric: tabular-nums;
  animation: app-menu-wheel-pulse 1.4s ease-in-out infinite;
}

@keyframes app-menu-wheel-pulse {
  0%,
  100% {
    transform: scale(1);
    opacity: 1;
  }
  50% {
    transform: scale(1.15);
    opacity: 0.55;
  }
}

@media (prefers-reduced-motion: reduce) {
  .app-menu__wheel-count {
    animation: none;
  }
}

.app-menu__foot {
  margin-top: auto;
}
</style>
