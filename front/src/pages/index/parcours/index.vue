<template>
  <q-page padding class="row justify-center">
    <q-spinner size="2em" />
  </q-page>
</template>

<script setup>
/**
 * The former parcours page (docs/PARCOURS.md): the défis replaced them (docs/DEFIS.md), an old
 * link lands on their page.
 */
import { useRouter } from 'vue-router'

definePage({ meta: { auth: 'required' } })

useRouter().replace('/defis')
</script>
