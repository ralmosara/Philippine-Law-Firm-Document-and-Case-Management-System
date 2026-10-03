/**
 * Delete the screens the service worker saved for offline use. Called on
 * sign-out and sign-in, so one person's saved copies never show to another
 * on a shared phone or computer.
 */
export async function clearSavedScreens(): Promise<void> {
  try {
    if (typeof caches === 'undefined') return
    const keys = await caches.keys()
    await Promise.all(keys.filter((k) => k.startsWith('lexph-api-')).map((k) => caches.delete(k)))
  } catch {
    // Storage unavailable: nothing was saved.
  }
}
