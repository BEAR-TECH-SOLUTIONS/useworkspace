import { AlertTriangle, Link2Off, ShieldX, WifiOff, Hash } from 'lucide-react'

const ERRORS = {
  not_found: {
    icon: Link2Off,
    title: "This link doesn't exist or has been revoked.",
    body: 'Double-check the URL. If someone sent you this link, ask them to confirm it.',
  },
  expired: {
    icon: Link2Off,
    title: 'This link is no longer available.',
    body: 'It may have expired or reached its view limit. Ask the person who shared it to send a new one.',
  },
  rate_limited: {
    icon: ShieldX,
    title: 'Too many failed attempts.',
    body: 'The link may be temporarily disabled. Wait a few minutes and try again.',
  },
  network: {
    icon: WifiOff,
    title: "Couldn't reach usework.space.",
    body: 'Check your internet connection and try again.',
  },
  missing_key: {
    icon: Hash,
    title: 'This link is missing its access key.',
    body: "Open the URL exactly as it was sent to you, including the part after the # symbol. If you copied it, make sure you got the full thing.",
  },
  unknown: {
    icon: AlertTriangle,
    title: 'Something went wrong.',
    body: 'Try opening the link again. If the problem continues, ask the person who shared it with you.',
  },
}

export default function ShareError({ code }) {
  const err = ERRORS[code] || ERRORS.unknown
  const Icon = err.icon

  return (
    <div className="w-full max-w-lg mx-auto text-center">
      <div className="w-20 h-20 rounded-3xl bg-rose/15 ring-1 ring-rose/30 grid place-items-center mx-auto mb-8">
        <Icon className="w-9 h-9 text-rose" />
      </div>
      <h1 className="font-display text-4xl font-medium text-foreground tracking-[-0.03em]">
        {err.title}
      </h1>
      <p className="mt-4 text-base text-muted leading-relaxed max-w-md mx-auto">
        {err.body}
      </p>
      {code === 'network' && (
        <button
          onClick={() => window.location.reload()}
          className="mt-8 h-12 px-6 rounded-xl bg-surface ring-1 ring-border text-base font-medium text-foreground hover:bg-surface2 transition"
        >
          Try again
        </button>
      )}
    </div>
  )
}
