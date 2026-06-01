import { useCallback, useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { fetchShareMeta, unlockShare } from './api'
import { deriveShareKeys, decryptBlob } from './crypto'

/**
 * State machine for the share-link viewer.
 *
 * States:
 *   loading     — fetching metadata
 *   password    — waiting for user to enter password
 *   unlocking   — submitting unlock request (+ optional crypto derivation)
 *   ready       — snapshot available for rendering
 *   error       — terminal error state
 */
export function useShareLink() {
  const { tokenHash } = useParams()
  const [state, setState] = useState('loading') // loading | password | unlocking | ready | error
  const [meta, setMeta] = useState(null)
  const [snapshot, setSnapshot] = useState(null)
  const [errorCode, setErrorCode] = useState(null) // not_found | expired | rate_limited | network | missing_key | wrong_password | decrypt_failed
  const [passwordError, setPasswordError] = useState(null)

  // Read raw token from fragment — never log or store it
  const rawTokenRef = useRef(null)
  useEffect(() => {
    const hash = window.location.hash.slice(1)
    rawTokenRef.current = hash || null
  }, [])

  // Fetch metadata on mount
  useEffect(() => {
    if (!tokenHash) { setError('not_found'); return }

    const rawToken = window.location.hash.slice(1)
    rawTokenRef.current = rawToken || null

    if (!rawToken) { setError('missing_key'); return }

    let cancelled = false
    setState('loading')

    fetchShareMeta(tokenHash)
      .then((data) => {
        if (cancelled) return
        setMeta(data)

        if (data.auth_scheme === 'open' && data.snapshot_payload) {
          setSnapshot(data.snapshot_payload)
          setState('ready')
        } else {
          setState('password')
        }
      })
      .catch((err) => {
        if (cancelled) return
        setError(err.message || 'unknown')
      })

    return () => { cancelled = true }
  }, [tokenHash])

  const setError = useCallback((code) => {
    setErrorCode(code)
    setState('error')
  }, [])

  // Submit password (handles both "password" and "auth_proof" schemes)
  const submitPassword = useCallback(async (password) => {
    if (!meta || !tokenHash) return
    const rawToken = rawTokenRef.current
    if (!rawToken) { setError('missing_key'); return }

    setPasswordError(null)
    setState('unlocking')

    try {
      let body
      let encKey = null

      if (meta.auth_scheme === 'auth_proof' && meta.key_salt) {
        // Derive auth proof + encryption key client-side
        const keys = await deriveShareKeys(password, meta.key_salt)
        body = { token: rawToken, auth_proof: keys.authProofB64 }
        encKey = keys.encKey
      } else {
        // Plain password scheme
        body = { token: rawToken, password }
      }

      const result = await unlockShare(tokenHash, body)
      const payload = result.snapshot_payload

      // If auth_proof scheme with encrypted credential blob, decrypt client-side
      if (encKey && payload?.encrypted_blob && payload?.blob_iv) {
        try {
          const decrypted = await decryptBlob(payload.encrypted_blob, payload.blob_iv, encKey)
          // Merge decrypted fields into the snapshot
          setSnapshot({ ...payload, _decrypted: decrypted })
        } catch {
          // AES-GCM auth-tag fail — show as "wrong password" to avoid leaking info
          setPasswordError('wrong_password')
          setState('password')
          return
        }
      } else {
        setSnapshot(payload)
      }

      setState('ready')
    } catch (err) {
      const code = err.message || 'unknown'
      if (code === 'wrong_password') {
        setPasswordError(code)
        setState('password')
      } else if (code === 'rate_limited') {
        setPasswordError(code)
        setState('password')
      } else {
        setError(code)
      }
    }
  }, [meta, tokenHash, setError])

  return {
    state,
    meta,
    snapshot,
    errorCode,
    passwordError,
    submitPassword,
    resourceType: meta?.share_link?.resource_type,
    shareInfo: meta?.share_link,
  }
}
