/**
 * Share-link API client. No auth headers — both endpoints are public.
 * Never caches responses (server sets Cache-Control: no-store, private).
 */

// Runtime-injected by the Blade shell (resources/views/share.blade.php).
// Empty string → same-origin (self-hosted / local dev). On the cloud's
// share.usework.space the shell injects the cloud API host so the share
// domain itself never has to know about anything else.
const BASE = (typeof window !== 'undefined' && window.__TC_SHARE_API_BASE__) || ''

export class ShareApiError extends Error {
  constructor(message, status) {
    super(message)
    this.name = 'ShareApiError'
    this.status = status
  }
}

export async function fetchShareMeta(tokenHash) {
  let res
  try {
    res = await fetch(`${BASE}/api/v1/share-links/${tokenHash}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })
  } catch {
    throw new ShareApiError('network', 0)
  }
  if (!res.ok) throw new ShareApiError(statusToCode(res.status), res.status)
  return res.json()
}

export async function unlockShare(tokenHash, body) {
  let res
  try {
    res = await fetch(`${BASE}/api/v1/share-links/${tokenHash}/unlock`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
      cache: 'no-store',
    })
  } catch {
    throw new ShareApiError('network', 0)
  }
  if (!res.ok) throw new ShareApiError(statusToCode(res.status), res.status)
  return res.json()
}

function statusToCode(status) {
  if (status === 401) return 'wrong_password'
  if (status === 404) return 'not_found'
  if (status === 410) return 'expired'
  if (status === 429) return 'rate_limited'
  return 'unknown'
}
