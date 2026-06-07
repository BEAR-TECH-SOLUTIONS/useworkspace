import { useCallback, useEffect, useRef, useState } from 'react'
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

  // Track window.location.hash in state so the rendered <a href>
  // carries the fragment. The fragment holds the raw decryption
  // token — without it the desktop app receives a "tokenHash but
  // no key" link and renders the same broken state the web viewer
  // would in `missing_key`. Hash changes on this page are
  // exceedingly rare (the share viewer is single-page-load), but
  // listening to `hashchange` keeps the href honest if the user
  // ever pastes a fresh token mid-session.
  const [fragment, setFragment] = useState(() =>
    typeof window !== 'undefined' ? window.location.hash : ''
  )
  useEffect(() => {
    const onHashChange = () => setFragment(window.location.hash || '')
    window.addEventListener('hashchange', onHashChange)
    return () => window.removeEventListener('hashchange', onHashChange)
  }, [])

  const onClick = useCallback((e) => {
    e.preventDefault()
    if (!tokenHash) return

    // Read at click time so the very latest fragment wins even if
    // a hashchange landed between render and click. The href is
    // also kept in sync via state above so OS-level handoffs that
    // bypass the click handler (right-click copy, middle-click,
    // Safari handing the href straight to the protocol handler)
    // still carry the token.
    const liveHash = window.location.hash || ''
    const deepLink = `usework://s/${encodeURIComponent(tokenHash)}${liveHash}`

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

  // href MUST include the live fragment. Without it, every code
  // path that bypasses onClick (right-click copy, middle-click,
  // Safari's straight-to-protocol-handler dispatch on custom
  // schemes) emits a usework:// link with no token, and the
  // desktop app renders the same `missing_key` error the web
  // viewer would. Building the href from state keeps it honest
  // across hashchange events while still being a real anchor
  // for assistive tech / keyboard activation.
  const href = tokenHash
    ? `usework://s/${encodeURIComponent(tokenHash)}${fragment}`
    : '#'

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
