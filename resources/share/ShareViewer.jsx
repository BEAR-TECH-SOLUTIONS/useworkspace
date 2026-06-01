import { useEffect } from 'react'
import { Loader2 } from 'lucide-react'
import { useShareLink } from './useShareLink'
import ShareInfoBar from './components/ShareHeader'
import PasswordForm from './components/PasswordForm'
import ShareError from './components/ShareError'
import BoardRenderer from './renderers/BoardRenderer'
import TaskRenderer from './renderers/TaskRenderer'
import CredentialRenderer from './renderers/CredentialRenderer'
import DocRenderer from './renderers/DocRenderer'
import ExpenseRenderer from './renderers/ExpenseRenderer'

/**
 * Backend-hosted share viewer. Same surface as the landing's
 * pages/ShareViewer.jsx but standalone — no dependency on the
 * landing's marketing Nav / AppearanceProvider so it can ship in a
 * self-hosted Docker image with zero marketing-site coupling.
 */
const RENDERERS = {
  board: BoardRenderer,
  task: TaskRenderer,
  credential: CredentialRenderer,
  doc: DocRenderer,
  expense: ExpenseRenderer,
}

export default function ShareViewer() {
  useEffect(() => {
    const robots = addMeta('robots', 'noindex,nofollow')
    const referrer = addMeta('referrer', 'no-referrer')
    document.title = 'Shared content — usework.space'
    return () => { robots.remove(); referrer.remove() }
  }, [])

  const {
    state, meta, snapshot, errorCode, passwordError, submitPassword, resourceType, shareInfo,
  } = useShareLink()

  const Renderer = resourceType ? RENDERERS[resourceType] : null

  return (
    <div className="min-h-screen bg-background text-foreground text-[15px] leading-relaxed">
      <ShareNav />

      <div className="max-w-6xl mx-auto px-5 sm:px-8 pt-28 pb-12">
        {meta && (
          <div className="mb-8">
            <ShareInfoBar shareInfo={shareInfo} />
          </div>
        )}

        {state === 'loading' && (
          <div className="flex flex-col items-center justify-center py-36">
            <Loader2 className="w-10 h-10 text-primary animate-spin mb-5" />
            <p className="text-base text-muted">Loading shared content…</p>
          </div>
        )}

        {state === 'password' && (
          <div className="py-20">
            <PasswordForm
              shareInfo={shareInfo}
              onSubmit={submitPassword}
              error={passwordError}
              loading={false}
            />
          </div>
        )}

        {state === 'unlocking' && (
          <div className="flex flex-col items-center justify-center py-36">
            <Loader2 className="w-10 h-10 text-primary animate-spin mb-5" />
            <p className="text-base text-muted">Decrypting…</p>
          </div>
        )}

        {state === 'ready' && snapshot && (
          <div>
            {Renderer ? (
              <Renderer data={snapshot} viewsRemaining={shareInfo?.views_remaining} />
            ) : (
              <div className="rounded-2xl card p-8 text-center text-base text-muted">
                Unsupported resource type: {resourceType}
              </div>
            )}
          </div>
        )}

        {state === 'error' && (
          <div className="py-20">
            <ShareError code={errorCode} />
          </div>
        )}

        <footer className="mt-20 pt-8 border-t border-border text-center">
          <div className="font-display text-base text-muted tracking-[-0.03em]">
            usework<span className="text-gradient">.space</span>
          </div>
          <p className="mt-1.5 text-[12px] text-muted2">
            End-to-end encrypted workspace
          </p>
        </footer>
      </div>
    </div>
  )
}

/**
 * Minimal marketing-free nav. The landing's Nav pulls in download
 * CTAs + a pricing link — neither belongs on a self-hosted share
 * page, so we ship a stripped-down brand header here.
 */
function ShareNav() {
  return (
    <header className="fixed top-0 inset-x-0 z-30 backdrop-blur bg-background/70 border-b border-border/60">
      <div className="max-w-6xl mx-auto px-5 sm:px-8 h-16 flex items-center">
        <div className="font-display text-lg tracking-[-0.03em]">
          team<span className="text-gradient">core</span>
        </div>
      </div>
    </header>
  )
}

function addMeta(name, content) {
  const el = document.createElement('meta')
  el.setAttribute('name', name)
  el.setAttribute('content', content)
  document.head.appendChild(el)
  return el
}
