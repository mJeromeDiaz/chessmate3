<template>
  <q-dialog :model-value="open" position="top" @hide="onHide">
    <!-- A div: Quasar only lets a dialog's direct div children take the clicks. -->
    <div
      v-if="current"
      class="announcement cm-card"
      role="document"
      data-testid="announcement-notice"
    >
      <div class="announcement__kicker">{{
        $t('announcement.notice.kicker')
      }}</div>
      <h2 class="announcement__title" data-testid="announcement-title">{{
        current.title
      }}</h2>
      <!-- Sanitized by the server when saved (docs/ANNOUNCEMENTS.md, § 2). -->
      <div
        class="announcement__body"
        data-testid="announcement-body"
        v-html="current.body"
      />
      <div class="announcement__actions">
        <q-btn
          unelevated
          no-caps
          color="primary"
          :label="$t('announcement.notice.ok')"
          data-testid="announcement-ok"
          @click="close"
        />
      </div>
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * The team's announcements (docs/ANNOUNCEMENTS.md, § 3), in the layout: a modal at the top of the
 * screen, one at a time, the oldest first, closed for good. Asked when the app loads, when the tab
 * comes back and every 5 minutes; never shown during a run, nor over the streak's celebration,
 * the wheels, the chests and an admin's gifts.
 */
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAnnouncementStore } from '@/stores/announcement'
import { useGamificationStore } from '@/stores/gamification'
import { useShopStore } from '@/stores/shop'
import { POLL_INTERVAL, announcementAllowed } from '@/utils/announcement'

const announcements = useAnnouncementStore()
const shop = useShopStore()
const gamification = useGamificationStore()
const route = useRoute()

const current = computed(() => announcements.items[0] ?? null)
/** Something else on screen first: a run, the celebration, a wheel, a chest or a gift. */
const busy = computed(
  () =>
    !announcementAllowed(route.path) ||
    !!gamification.celebration ||
    shop.dailyChests.length > 0 ||
    !!shop.handWheel ||
    shop.ticketWheel ||
    (shop.state?.gifts?.length ?? 0) > 0
)
const open = computed(() => !busy.value && current.value !== null)

function close() {
  if (current.value) announcements.markRead(current.value.id)
}

/** Closed by the player (outside, Esc), not hidden by something else coming first. */
function onHide() {
  if (!busy.value) close()
}

/** @type {ReturnType<typeof setInterval>|undefined} */
let poll
function onVisibility() {
  if (document.visibilityState === 'visible') announcements.load()
}

onMounted(() => {
  announcements.load()
  poll = setInterval(() => announcements.load(), POLL_INTERVAL)
  document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
  clearInterval(poll)
  document.removeEventListener('visibilitychange', onVisibility)
})
</script>

<style scoped lang="scss">
.announcement {
  width: min(560px, calc(100vw - 32px));
  max-height: calc(100vh - 48px);
  margin-top: 16px;
  padding: 22px 22px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  overflow-y: auto;
}

.announcement__kicker {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--cm-ink-soft);
}

.announcement__title {
  margin: 0;
  font-size: 22px;
  line-height: 1.25;
  font-weight: 800;
}

.announcement__actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 4px;
}
</style>

<!-- Not scoped: the body is HTML from the admin page, styled the same in its preview. -->
<style lang="scss">
.announcement__body {
  font-size: 15px;
  line-height: 1.5;
  overflow-wrap: anywhere;

  > :first-child {
    margin-top: 0;
  }

  > :last-child {
    margin-bottom: 0;
  }

  p,
  ul,
  ol,
  blockquote {
    margin: 0 0 10px;
  }

  h2,
  h3,
  h4 {
    margin: 14px 0 6px;
    font-size: 17px;
    line-height: 1.3;
  }

  blockquote {
    padding: 8px 12px;
    border-left: 3px solid var(--cm-brand);
    border-radius: 0 10px 10px 0;
    background: var(--cm-inset);
  }

  a {
    color: var(--cm-brand);
  }
}
</style>
