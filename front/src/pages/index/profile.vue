<template>
  <q-page class="profile" data-testid="profile-page">
    <div v-if="profile" class="profile__inner">
      <h1 class="profile__title">Profil</h1>

      <q-banner v-if="linkedNotice" class="profile-success q-mb-md" rounded>{{
        linkedNotice
      }}</q-banner>

      <div class="profile__grid">
        <div class="profile__col">
          <ProfileHero :profile="profile" class="profile__hero" />
          <LinkedAccounts :profile="profile" class="profile__connections" />
          <ActiveSessions class="profile__sessions" />
          <SecurityCard :profile="profile" class="profile__security" />
        </div>
        <div class="profile__col">
          <PreferencesCard :profile="profile" class="profile__preferences" />
          <section
            class="cm-card profile-card profile__notifications"
            data-testid="profile-notifications"
          >
            <h2 class="cm-card__title">Notifications et calendrier</h2>
            <NotificationSection />
            <StreakReminderSection />
            <CalendarSection />
          </section>
          <DataCard class="profile__data" />
          <ProfileFooter class="profile__footer" />
        </div>
      </div>
    </div>
  </q-page>
</template>

<script setup>
/**
 * Profile (design "Profil"): one column on a phone, two from md. Level and XP are real
 * values.
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { providerLabel } from '@/utils/format'
import ActiveSessions from '@/components/profile/ActiveSessions.vue'
import CalendarSection from '@/components/profile/CalendarSection.vue'
import DataCard from '@/components/profile/DataCard.vue'
import LinkedAccounts from '@/components/profile/LinkedAccounts.vue'
import NotificationSection from '@/components/profile/NotificationSection.vue'
import PreferencesCard from '@/components/profile/PreferencesCard.vue'
import ProfileFooter from '@/components/profile/ProfileFooter.vue'
import ProfileHero from '@/components/profile/ProfileHero.vue'
import SecurityCard from '@/components/profile/SecurityCard.vue'
import StreakReminderSection from '@/components/profile/StreakReminderSection.vue'

definePage({ meta: { auth: 'required' } })

const auth = useAuthStore()
const route = useRoute()

const profile = computed(() => auth.profile)
const linkedNotice = computed(() =>
  typeof route.query.linked === 'string'
    ? `Compte ${providerLabel(route.query.linked)} lié.`
    : ''
)
</script>

<style scoped lang="scss">
.profile__inner {
  max-width: 1180px;
  margin: 0 auto;
  padding: 12px 16px 48px;

  @media (min-width: $breakpoint-md-min) {
    padding: 24px 28px 48px;
  }
}

.profile__title {
  margin: 0 4px 14px;
  font-size: 26px;
  line-height: 1.1;

  @media (min-width: $breakpoint-md-min) {
    margin-bottom: 18px;
    font-size: 28px;
  }
}

// Mobile: one column in the design's order; from md: two equal columns.
.profile__grid {
  display: flex;
  flex-direction: column;
  gap: 14px;

  @media (min-width: $breakpoint-md-min) {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 18px;
    align-items: start;
  }
}

.profile__col {
  display: contents;

  @media (min-width: $breakpoint-md-min) {
    display: flex;
    flex-direction: column;
    gap: 18px;
    min-width: 0;
  }
}

.profile__hero {
  order: 1;
}
.profile__connections {
  order: 2;
}
.profile__sessions {
  order: 3;
}
.profile__preferences {
  order: 4;
}
.profile__notifications {
  order: 5;
}
.profile__security {
  order: 6;
}
.profile__data {
  order: 7;
}
.profile__footer {
  order: 8;
}
</style>

<style lang="scss">
// Rows shared by the profile's cards (design "Profil"): title and caption on the left, action on
// the right, separated by a thin line.
.profile-card {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.profile-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  border-top: 1px solid var(--cm-line);

  .cm-card__title + &,
  &--flat {
    border-top: none;
  }

  @media (min-width: $breakpoint-md-min) {
    gap: 14px;
    padding: 12px 0;
  }
}

.profile-row__text {
  flex: 1;
  min-width: 0;
}

.profile-row__title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 700;
  font-size: 14px;

  &--danger {
    color: var(--cm-danger);
  }

  @media (min-width: $breakpoint-md-min) {
    font-size: 14.5px;
  }
}

.profile-row__sub {
  font-size: 12px;
  color: var(--cm-muted);

  @media (min-width: $breakpoint-md-min) {
    font-size: 12.5px;
  }
}

// A section inside a card: title, caption, then its own controls.
.profile-block {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 0 4px;
  border-top: 1px solid var(--cm-line);

  .cm-card__title + & {
    border-top: none;
    padding-top: 8px;
  }

  > p {
    margin: 0;
  }
}

.profile-btn.q-btn {
  flex: none;
  min-height: 34px;
  padding: 0 12px;
  border-radius: 11px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font-size: 12.5px;

  &.profile-btn--strong {
    background: var(--cm-ink);
    color: var(--cm-surface);
  }

  &.profile-btn--danger {
    background: var(--cm-danger-soft);
    color: var(--cm-danger);
  }
}

.profile-segmented {
  border-radius: 14px;
  background: var(--cm-subtle);
  padding: 4px;

  .q-btn {
    border-radius: 11px !important;
  }
}

.profile-note {
  margin-top: 4px;
  padding: 8px 10px;
  border-radius: 10px;
  background: var(--cm-subtle);
  font-size: 12px;
  color: var(--cm-muted);
}

.profile-soon {
  padding: 1px 8px;
  border-radius: 999px;
  border: 1px dashed currentColor;
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.profile-error {
  background: var(--cm-danger-soft);
  color: var(--cm-danger);
}

.profile-success {
  background: var(--cm-lime-soft);
  color: var(--cm-lime-ink);
}
</style>
