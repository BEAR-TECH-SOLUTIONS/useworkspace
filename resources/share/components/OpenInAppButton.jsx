import { useCallback, useRef } from 'react'
import { ExternalLink } from 'lucide-react'
import { useParams } from 'react-router-dom'

/**
 * "Open in app" affordance for the share page. Clicking attempts
 * the desktop app via the usework:// protocol handler; if the OS
 * doesn't have it registered (app not installed) the page falls
 * back to the web viewer after 1.5s.
 *
 * The raw decryption token lives in window.location.hash and is
 * deliberately never serialized server-side — both the deep-link
 * and the fallback URL are assembled at click time from the live
 * fragment, so the token follows the user to whichever destination
 * the OS hands them off to. The server only emits the web origin
 * to fall back to (window.__TC_SHARE_WEB_FALLBACK_BASE__).
 *
 * Behaviour notes:
 *  - The button is rendered for every visit; we do not sniff the
 *    user-agent to guess whether the app is installed. There is no
 *    reliable cross-platform way to do that, and the visibility
 *    check below already covers every install state.
 *  - The fallback timer is gated on `document.hidden` so a browser
 *    that fires queued timers when the user returns from the app
 *    doesn't redirect a tab that already handed off.
 *  - We don't open a new tab — the user is already in their
 *    browser, and opening a second tab on top of the protocol
 *    launch confuses the OS handoff on some platforms (notably
 *    Safari).
 */
export default function OpenInAppButton() {
  const { tokenHash } = useParams()
  const firedAtRef = useRef(0)

  const onClick = useCallback((e) => {
    e.preventDefault()
    if (!tokenHash) return

    const hash = window.location.hash || ''
    const deepLink = `usework://s/${encodeURIComponent(tokenHash)}${hash}`

    firedAtRef.current = Date.now()
    window.location.href = deepLink

    const fallbackBase = (window.__TC_SHARE_WEB_FALLBACK_BASE__ || '').replace(/\/+$/, '')
    if (!fallbackBase) return

    setTimeout(() => {
      // If the OS launched the desktop app the tab is now in the
      // background; bail rather than yanking the user back to the
      // browser. Also bail if more than 5s elapsed (a sleeping tab
      // resuming an old timer) to avoid surprise redirects.
      if (document.hidden) return
      if (Date.now() - firedAtRef.current > 5000) return
      window.location.href = `${fallbackBase}/s/${encodeURIComponent(tokenHash)}${window.location.hash}`
    }, 1500)
  }, [tokenHash])

  // Build a non-empty href so the anchor is a real link for
  // assistive tech / keyboard users + so right-click → copy link
  // gives the deep link. The actual navigation goes through the
  // click handler above (which calls preventDefault).
  const href = tokenHash ? `usework://s/${encodeURIComponent(tokenHash)}` : '#'

  return (
    <a
      href={href}
      onClick={onClick}
      className="inline-flex items-center gap-2 rounded-full bg-primary/15 text-primary-glow hover:bg-primary/25 transition-colors px-4 py-2 text-[13px] font-medium ring-1 ring-primary/20"
    >
      <span>Open in usework.space app</span>
      <ExternalLink className="w-3.5 h-3.5" aria-hidden="true" />
    </a>
  )
}
