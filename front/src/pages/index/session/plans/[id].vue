<template>
  <SessionBuilder :key="planId" :plan-id="planId" />
</template>

<script setup>
/** A saved training session, edited in the builder. */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import SessionBuilder from '@/components/session/SessionBuilder.vue'

definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'layout.header.titles.plan',
      subtitle: 'layout.header.kickers.create'
    }
  }
})

const route = useRoute()
const planId = computed(() => String(route.params.id))
</script>
