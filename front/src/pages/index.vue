<template>
  <q-layout view="hHh lpr fFf">
    <q-header elevated>
      <q-toolbar>
        <q-toolbar-title>
          <router-link to="/" class="text-white" style="text-decoration: none"
            >ChessMate</router-link
          >
        </q-toolbar-title>

        <template v-if="auth.isAuthenticated">
          <RatingBadge class="gt-xs" />
          <q-btn flat no-caps to="/puzzle" label="Puzzles" />
          <q-btn flat no-caps to="/woodpecker" label="Woodpecker" />
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
      </q-toolbar>
    </q-header>

    <q-page-container>
      <router-view />
    </q-page-container>
  </q-layout>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import RatingBadge from '@/components/puzzle/RatingBadge.vue'

const auth = useAuthStore()
const router = useRouter()
const loggingOut = ref(false)

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
