/**
 * Share-link client-side crypto.
 *
 * Parameters MUST match the desktop sharer's EXACTLY:
 *   argon2id: m=65536 (64 MB), t=3, p=1, hashLength=32
 *   HKDF info: "share-link-auth-v1" / "share-link-enc-v1"
 *
 * Security invariants:
 *   - enc_key is non-extractable
 *   - plaintext password + master_key are never stored in state
 *   - no data written to localStorage / sessionStorage / IndexedDB
 */

import { argon2id } from 'hash-wasm'

/* ---- base64 helpers (handle both standard + url-safe) ---- */

export function fromBase64(s) {
  return Uint8Array.from(
    atob(s.replace(/-/g, '+').replace(/_/g, '/')),
    (c) => c.charCodeAt(0),
  )
}

export function toBase64(bytes) {
  return btoa(String.fromCharCode(...bytes))
}

/* ---- key derivation ---- */

export async function deriveShareKeys(password, keySaltB64) {
  const salt = fromBase64(keySaltB64)

  // Step 1: argon2id → 32-byte master key
  const masterKey = await argon2id({
    password,
    salt,
    iterations: 3,
    memorySize: 65536, // 64 MB
    parallelism: 1,
    hashLength: 32,
    outputType: 'binary',
  })

  // Step 2: import master key into Web Crypto for HKDF
  const baseKey = await crypto.subtle.importKey(
    'raw',
    masterKey,
    'HKDF',
    false,
    ['deriveBits', 'deriveKey'],
  )

  // Step 3: derive auth proof (sent to server to prove password knowledge)
  const authProofBits = await crypto.subtle.deriveBits(
    {
      name: 'HKDF',
      hash: 'SHA-256',
      salt: new Uint8Array(0),
      info: new TextEncoder().encode('share-link-auth-v1'),
    },
    baseKey,
    256,
  )

  // Step 4: derive enc_key (used locally to decrypt the blob — never sent)
  const encKey = await crypto.subtle.deriveKey(
    {
      name: 'HKDF',
      hash: 'SHA-256',
      salt: new Uint8Array(0),
      info: new TextEncoder().encode('share-link-enc-v1'),
    },
    baseKey,
    { name: 'AES-GCM', length: 256 },
    false, // non-extractable — critical
    ['decrypt'],
  )

  return {
    authProofB64: toBase64(new Uint8Array(authProofBits)),
    encKey,
  }
}

/* ---- blob decryption ---- */

export async function decryptBlob(encryptedBlobB64, blobIvB64, encKey) {
  const plaintext = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: fromBase64(blobIvB64) },
    encKey,
    fromBase64(encryptedBlobB64),
  )
  return JSON.parse(new TextDecoder().decode(plaintext))
}
