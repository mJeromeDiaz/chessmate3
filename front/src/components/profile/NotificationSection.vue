<template>
  <div class="profile-block">
    <div class="profile-row__title">{{ $t('profile.push.title') }}</div>
    <div class="profile-row__sub">{{ $t('profile.push.hint') }}</div>
    <PushToggle />
  </div>
</template>

<script setup>
/** Profile: Web Push on this device (session and streak reminders). */
import PushToggle from '@/components/notification/PushToggle.vue'
</script>
