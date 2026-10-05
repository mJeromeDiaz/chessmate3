<template>
  <q-layout view="hHh lpr fFf">
    <q-header v-if="showHeader" class="app-header">
      <q-toolbar>
        <q-toolbar-title>
          <router-link to="/" class="cm-brand-name"
            >Chess<span>Mate</span></router-link
          >
        </q-toolbar-title>

        <template v-if="auth.isAuthenticated">
          <RatingBadge class="gt-xs" />
          <q-btn flat no-caps to="/session" label="Sessions" />
          <q-btn flat no-caps to="/puzzle" label="Puzzles" />
          <q-btn flat no-caps to="/woodpecker" label="Woodpecker" />
          <q-btn flat no-caps to="/repertoire" label="Répertoires" />
          <q-btn
            v-if="auth.isAdmin"
            flat
            no-caps
            to="/admin"
            label="Admin"
            data-testid="nav-admin"
          />
          <q-btn flat no-caps to="/profile" label="Profil" />
          <q-btn
            flat
            no-caps
            label="Déconnexion"
            :loading="loggingOut"
            @click="logout"
          />
        </template>
        <template v-else>
          <q-btn flat no-caps to="/login" label="Connexion" />
          <q-btn flat no-caps to="/register" label="Inscription" />
        </template>
        <ThemeMenu />
      </q-toolbar>
    </q-header>

    <q-page-container>
      <router-view />
    </q-page-container>
  </q-layout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import RatingBadge from '@/components/puzzle/RatingBadge.vue'
import ThemeMenu from '@/components/theme/ThemeMenu.vue'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const loggingOut = ref(false)

/** The landing page (visitors' home) brings its own header. */
const showHeader = computed(() => auth.isAuthenticated || !route.meta.landing)

async function logout() {
  loggingOut.value = true
  try {
    await auth.logout()
  } catch {
    // Signed out locally anyway (see the store).
  } finally {
    loggingOut.value = false
    router.push('/login')
  }
}
</script>

<style scoped lang="scss">
.app-header {
  background: var(--cm-surface);
  color: var(--cm-ink);
  border-bottom: 1px solid var(--cm-line);
}

.cm-brand-name {
  font-size: 19px;
}
</style>
