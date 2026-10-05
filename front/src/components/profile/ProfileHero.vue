<template>
  <div class="profile-hero" data-testid="profile-hero">
    <div class="profile-hero__shade" />
    <div class="profile-hero__who">
      <div
        class="profile-hero__avatar"
        :style="{ background: tile.color }"
        aria-hidden="true"
        data-testid="profile-avatar"
        >{{ tile.glyph }}</div
      >
      <div class="profile-hero__names">
        <div class="profile-hero__name" data-testid="profile-name">{{
          name
        }}</div>
        <div class="profile-hero__since">
          <span v-if="profile.handle" data-testid="profile-handle"
            >@{{ profile.handle }} · </span
          >{{ since }}
        </div>
      </div>
      <q-btn
        unelevated
        no-caps
        class="profile-hero__edit gt-sm"
        label="✎ Modifier"
        data-testid="profile-edit-open"
        @click="editing = true"
      />
    </div>
    <template v-if="bar">
      <div class="profile-hero__level">
        <span class="profile-hero__title" data-testid="profile-level"
          >Niveau {{ bar.level }}</span
        >
        <span class="profile-hero__rank">{{ bar.rank }}</span>
        <span class="profile-hero__xp"
          >{{ bar.xpLabel }}<span class="gt-sm"> · {{ bar.next }}</span></span
        >
      </div>
      <div class="profile-hero__bar">
        <div :style="{ width: `${bar.percent}%` }" />
      </div>
    </template>
    <q-btn
      unelevated
      no-caps
      class="profile-hero__edit profile-hero__edit--wide lt-md"
      label="✎ Modifier le profil"
      data-testid="profile-edit-open-mobile"
      @click="editing = true"
    />
    <ProfileEditDialog
      v-model="editing"
      :profile="profile"
      :fallback-name="fallbackName"
    />
  </div>
</template>

<script setup>
/**
 * Profile: the design's banner and its "Modifier" button: avatar, name, handle, member date,
 * level and XP (docs/GAMIFICATION.md).
 */
import { computed, onMounted, ref } from 'vue'
import ProfileEditDialog from '@/components/profile/ProfileEditDialog.vue'
import { useGamificationStore } from '@/stores/gamification'
import { levelBar } from '@/utils/gamification'
import { avatarTile, formatMonth, profileName } from '@/utils/profile'

const props = defineProps({
  /** The /api/profile payload. */
  profile: { type: Object, required: true }
})

const gamification = useGamificationStore()
const bar = computed(() =>
  gamification.summary ? levelBar(gamification.summary) : null
)
onMounted(() => gamification.load(['summary']))

const editing = ref(false)
const name = computed(() => profileName(props.profile))
/** The name shown without a display name (the edit field's placeholder). */
const fallbackName = computed(() =>
  profileName({ ...props.profile, displayName: null })
)
const tile = computed(() => avatarTile(props.profile, name.value))
const since = computed(
  () =>
    `${props.profile.handle ? 'membre' : 'Membre'} depuis ${formatMonth(props.profile.createdAt)}`
)
</script>

<style scoped lang="scss">
.profile-hero__edit.q-btn {
  flex: none;
  min-height: 40px;
  padding: 0 16px;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.2);
  color: #fff;
  font-size: 14px;

  &:hover {
    background: rgba(255, 255, 255, 0.3);
  }

  &--wide {
    min-height: 42px;
  }
}

.profile-hero {
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 18px;
  border-radius: 24px;
  background: var(--cm-hero);
  color: #fff;

  > * {
    position: relative;
  }

  @media (min-width: $breakpoint-md-min) {
    gap: 14px;
    padding: 22px;
    border-radius: 26px;
  }
}

.profile-hero__shade {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  width: 60%;
  background: var(--cm-hero-deep);
  opacity: 0.6;
  mask-image: linear-gradient(to right, transparent, #000);
}

.profile-hero__who {
  display: flex;
  align-items: center;
  gap: 14px;

  @media (min-width: $breakpoint-md-min) {
    gap: 16px;
  }
}

.profile-hero__avatar {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 64px;
  height: 64px;
  border-radius: 20px;
  color: #1b1530;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 34px;
  box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.35);

  @media (min-width: $breakpoint-md-min) {
    width: 80px;
    height: 80px;
    border-radius: 24px;
    font-size: 44px;
  }
}

.profile-hero__names {
  flex: 1;
  min-width: 0;
}

.profile-hero__name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
  line-height: 1.1;

  @media (min-width: $breakpoint-md-min) {
    font-size: 28px;
  }
}

.profile-hero__since {
  margin-top: 2px;
  font-size: 13px;

  @media (min-width: $breakpoint-md-min) {
    font-size: 14px;
  }
}

.profile-hero__level {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.profile-hero__title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 17px;

  @media (min-width: $breakpoint-md-min) {
    font-size: 19px;
  }
}

.profile-hero__rank {
  padding: 3px 9px;
  border-radius: 999px;
  background: var(--cm-lime);
  color: #1b1530;
  font-weight: 700;
  font-size: 12px;
}

.profile-hero__xp {
  margin-left: auto;
  font-size: 12.5px;

  @media (min-width: $breakpoint-md-min) {
    font-size: 13px;
  }
}

.profile-hero__bar {
  height: 10px;
  overflow: hidden;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.25);

  > div {
    height: 100%;
    border-radius: 10px;
    background: var(--cm-lime);
  }

  @media (min-width: $breakpoint-md-min) {
    height: 12px;
  }
}
</style>
