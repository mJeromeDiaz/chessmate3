<template>
  <q-page padding class="column items-center">
    <div class="text-h4 q-mb-md">ChessMate</div>
    <p>Entraînez-vous aux échecs, un peu chaque jour.</p>

    <div v-if="auth.isAuthenticated" class="q-gutter-sm">
      <q-btn
        color="primary"
        no-caps
        to="/puzzle"
        label="Résoudre des puzzles"
      />
      <q-btn outline color="primary" no-caps to="/profile" label="Mon profil" />
    </div>
    <div v-else class="q-gutter-sm">
      <q-btn color="primary" no-caps to="/login" label="Se connecter" />
      <q-btn
        outline
        color="primary"
        no-caps
        to="/register"
        label="Créer un compte"
      />
    </div>
  </q-page>
</template>

<script setup>
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>
