import { useState } from 'react'
import { Lock, Eye, EyeOff, ArrowRight, Loader2, AlertCircle, Shield } from 'lucide-react'

const ERROR_MESSAGES = {
  wrong_password: 'Wrong password. Try again.',
  rate_limited: 'Too many failed attempts. The link may be temporarily disabled.',
}

export default function PasswordForm({ shareInfo, onSubmit, error, loading }) {
  const [password, setPassword] = useState('')
  const [showPw, setShowPw] = useState(false)

  const handleSubmit = (e) => {
    e.preventDefault()
    if (!password.trim() || loading) return
    onSubmit(password)
    // Don't clear password — user may need to retry on wrong_password
  }

  return (
    <div className="w-full max-w-lg mx-auto text-center">
      <div className="w-20 h-20 rounded-3xl bg-primary/15 ring-1 ring-primary/30 grid place-items-center mx-auto mb-8">
        <Lock className="w-9 h-9 text-primary-glow" />
      </div>

      <h1 className="font-display text-4xl font-medium text-foreground tracking-[-0.03em]">
        This link is password-protected
      </h1>
      <p className="mt-4 text-base text-muted leading-relaxed">
        {shareInfo?.name
          ? `Enter the password to view "${shareInfo.name}".`
          : 'Enter the password shared with you to continue.'}
      </p>

      {shareInfo?.views_remaining === 1 && (
        <div className="mt-6 flex items-start gap-2.5 rounded-xl ring-1 ring-amber/40 bg-amber/10 text-amber px-4 py-3 text-[13px] text-left">
          <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
          <span>Opening this will use the last available view. Make sure you can save anything important.</span>
        </div>
      )}

      <form onSubmit={handleSubmit} className="mt-8 space-y-4">
        <div
          className={`flex items-center gap-2.5 rounded-xl bg-background/60 ring-1 transition px-4 h-14 ${
            error ? 'ring-rose/50' : 'ring-border focus-within:ring-primary/50'
          }`}
        >
          <Lock className={`w-5 h-5 shrink-0 ${error ? 'text-rose' : 'text-muted'}`} />
          <input
            type={showPw ? 'text' : 'password'}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="Password"
            autoFocus
            disabled={loading}
            className="flex-1 min-w-0 bg-transparent outline-none text-[17px] text-foreground placeholder:text-muted2"
          />
          <button
            type="button"
            onClick={() => setShowPw((s) => !s)}
            className="w-9 h-9 grid place-items-center rounded-lg text-muted hover:text-foreground hover:bg-surface/60"
          >
            {showPw ? <EyeOff className="w-5 h-5" /> : <Eye className="w-5 h-5" />}
          </button>
        </div>

        {error && (
          <div className="flex items-start gap-2.5 rounded-xl ring-1 ring-rose/40 bg-rose/10 text-rose px-4 py-3 text-[13px] text-left">
            <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
            <span>{ERROR_MESSAGES[error] || 'Something went wrong. Try again.'}</span>
          </div>
        )}

        <button
          type="submit"
          disabled={loading || !password.trim()}
          className="w-full h-13 py-3.5 inline-flex items-center justify-center gap-2 rounded-xl bg-primary text-white font-semibold text-base transition hover:bg-primary-glow disabled:opacity-50 disabled:cursor-wait"
        >
          {loading ? (
            <Loader2 className="w-5 h-5 animate-spin" />
          ) : (
            <>
              Unlock
              <ArrowRight className="w-5 h-5" />
            </>
          )}
        </button>
      </form>

      <div className="mt-8 flex items-center justify-center gap-2 text-[13px] text-muted2">
        <Shield className="w-3.5 h-3.5" />
        <span>Decrypted in your browser. Never sent to our servers.</span>
      </div>
    </div>
  )
}
