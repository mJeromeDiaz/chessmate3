<template>
  <PlaceholderPage :title="$t('landing.footer.terms')" />
</template>

<script setup>
import PlaceholderPage from '@/components/landing/PlaceholderPage.vue'

definePage({ meta: { auth: 'public' } })
</script>
