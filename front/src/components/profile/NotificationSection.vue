<template>
  <div class="profile-block">
    <div class="profile-row__title">Notifications du navigateur</div>
    <div class="profile-row__sub">
      Pour recevoir les rappels de tes sessions et de ta série sur cet appareil,
      même quand Don't Stay Rooky est fermé. À activer sur chaque appareil.
    </div>
    <PushToggle />
  </div>
</template>

<script setup>
/** Profile: Web Push on this device (session and streak reminders). */
import PushToggle from '@/components/notification/PushToggle.vue'
</script>
