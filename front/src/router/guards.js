/**
 * Route access levels, set per page with `definePage({ meta: { auth: ... } })`:
 * - 'public' (default): anyone;
 * - 'guest': signed-out users only (login, register...): a signed-in user goes to the dashboard;
 * - 'required': signed-in users only: others go to the login page, which brings them back after;
 * - 'mfa': only while a password login waits for its emailed code;
 * - 'admin': signed-in admins only (`profile.isAdmin`): others go to the dashboard. The API checks
 *   the role itself: this only keeps the pages out of sight.
 *
 * A frozen account (deletion scheduled) is kept on the deletion page, whatever the level.
 *
 * A guest account (docs/GUEST.md) is signed in, but a 'required' page opens to it only with
 * `guestOpen: true` in its meta; any other goes to {@link ACCOUNT_PAGE}. The 'guest' level means
 * signed out: a guest account may still reach it (signing up or in from there).
 *
 * @typedef {'public'|'guest'|'required'|'mfa'|'admin'} AuthLevel
 */

/**
 * Keeps a post-login redirect inside the SPA: only a path of this app ("/x"), never an absolute
 * or protocol-relative URL ("https://evil", "//evil", "/\\evil") — an open redirect otherwise.
 *
 * @param {unknown} value
 * @param {string} [fallback]
 * @returns {string}
 */
export function safeRedirect(value, fallback = '/') {
  if (
    typeof value !== 'string' ||
    !value.startsWith('/') ||
    value.startsWith('//') ||
    value.includes('\\')
  ) {
    return fallback
  }

  return value
}

/** Where a frozen account (deletion scheduled) stays: cancel, export or sign out. */
export const FROZEN_PAGE = '/account-deletion'

/** Where a guest asking for an account's page goes: what an account brings, and the sign-up. */
export const ACCOUNT_PAGE = '/creer-un-compte'

/**
 * Creates the global navigation guard.
 *
 * @param {() => ReturnType<typeof import('@/stores/auth').useAuthStore>} getAuth store accessor
 *   (resolved lazily, once Pinia is installed)
 * @returns {import('vue-router').NavigationGuard}
 */
export function createAuthGuard(getAuth) {
  return async to => {
    const auth = getAuth()
    // First navigation of the page load: restore the session from the refresh cookie first.
    await auth.init()

    /** @type {AuthLevel} */
    const level = to.meta?.auth ?? 'public'

    if (auth.isAuthenticated && auth.isFrozen && to.path !== FROZEN_PAGE) {
      return { path: FROZEN_PAGE }
    }

    if ((level === 'required' || level === 'admin') && !auth.isAuthenticated) {
      return { path: '/login', query: { redirect: to.fullPath } }
    }

    if (level === 'admin' && !auth.isAdmin) {
      return { path: '/' }
    }

    if (level === 'required' && auth.isGuest && !to.meta?.guestOpen) {
      return { path: ACCOUNT_PAGE }
    }

    if (level === 'guest' && auth.isAuthenticated && !auth.isGuest) {
      return { path: '/' }
    }

    if (level === 'mfa' && !auth.mfa) {
      return { path: auth.isAuthenticated ? '/' : '/login' }
    }

    return true
  }
}
