<template>
  <PlaceholderPage title="Confidentialité" />
</template>

<script setup>
import PlaceholderPage from '@/components/landing/PlaceholderPage.vue'

definePage({ meta: { auth: 'public' } })
</script>
