<template>
  <q-card flat bordered>
    <q-card-section>
      <div class="text-subtitle1">Notifications du navigateur</div>
      <p class="text-caption text-grey q-mb-sm">
        Pour recevoir les rappels de tes sessions sur cet appareil, même quand
        ChessMate est fermé. À activer sur chaque appareil.
      </p>
      <PushToggle />
    </q-card-section>
  </q-card>
</template>

<script setup>
/** Profile: Web Push on this device (session reminders). */
import PushToggle from '@/components/notification/PushToggle.vue'
</script>
