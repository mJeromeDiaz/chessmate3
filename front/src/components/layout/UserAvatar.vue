<template>
  <span
    class="user-avatar"
    :style="{ background: tile.color }"
    aria-hidden="true"
    >{{ tile.glyph }}</span
  >
</template>

<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { avatarTile, profileName } from '@/utils/profile'

/** The signed-in user's avatar tile (piece or initial), as on the profile page, small. */
const auth = useAuthStore()

const tile = computed(() => {
  const profile = auth.profile ?? {}

  return avatarTile(profile, profileName(profile))
})
</script>

<style scoped lang="scss">
.user-avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  color: #1b1530;
  font-size: 18px;
  font-weight: 800;
  line-height: 1;
}
</style>
