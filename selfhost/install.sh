#!/usr/bin/env sh
# shellcheck shell=sh
#
# usework.space self-hosted installer. Pipe-friendly:
#
#   curl https://raw.githubusercontent.com/BEAR-TECH-SOLUTIONS/useworkspace/main/selfhost/install.sh | sh
#
# Or with flags (CI / unattended):
#
#   curl ... | sh -s -- --domain=workspace.acme.com --license=eyJ... --skip-eula
#
# Refuses to continue without:
#  - Docker + Compose v2 on PATH
#  - explicit EULA acceptance
#  - a license token (no demo / unlicensed mode)

set -eu

# ─── Helpers ────────────────────────────────────────────────────────
#
# Function definitions only — nothing here executes when sh first
# parses the file. The `curl ... | sh` invocation depends on this:
# sh reads the script from the pipe line-by-line, and if execution
# starts BEFORE the whole script is read, any later `exec < /dev/tty`
# breaks the pipe and sh loses the rest of the file (`curl: (23)
# Failure writing output to destination`). Wrapping everything in
# main() down below means sh finishes reading the entire pipe (just
# function defs) before any line actually runs.

log()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*"; }
die()  { printf '\033[1;31mERROR\033[0m: %s\n' "$*" >&2; exit 1; }

# Audit M15: previously domain/license were dropped straight into a
# `sed s|^KEY=.*$|KEY=$VAR|` substitution. Any sed-replacement
# metachar in the input (`|`, `&`, `\\`, newline) would mangle the
# .env file or inject extra keys. set_env_var validates the inputs
# against a strict regex first (see validate_* below), then writes
# the file by stream-rewriting line-by-line in awk — no `sed s|`
# form at all.
set_env_var() {
    local file="$1"
    local key="$2"
    local value="$3"

    # AWK = full-line replace; portable across BSD/GNU.
    awk -v k="${key}" -v v="${value}" '
        BEGIN { written = 0 }
        $0 ~ "^"k"=" { print k"="v; written = 1; next }
        { print }
        END { if (!written) print k"="v }
    ' "${file}" > "${file}.tmp" && mv "${file}.tmp" "${file}"
}

# Strict-regex inputs. Bail loud if the operator passes anything
# weird — better to refuse install than corrupt the .env or smuggle
# a token. POSIX form: `[[ ... =~ ... ]]` is bash-only and the
# documented install path is `curl ... | sh`, which on Debian/Ubuntu
# is dash. `printf | grep -Eq` is portable across every sh.
validate_domain() {
    printf '%s' "$1" | grep -Eq '^[A-Za-z0-9.-]{1,253}$' \
        || die "Invalid domain: must be DNS-shaped."
}
validate_license() {
    printf '%s' "$1" | grep -Eq '^[A-Za-z0-9_.+/=-]{20,4096}$' \
        || die "Invalid license token shape."
}
# Self-serve claim code minted by the cloud backend when a self-hosted
# Paddle subscription is purchased; the operator pastes it into install
# and we POST it to /api/v1/licenses/claim to receive the signed token.
validate_claim() {
    printf '%s' "$1" | grep -Eq '^ws-[a-z0-9]{4,16}$' \
        || die "Invalid claim code shape (expected ws-xxxxxx)."
}

# ─── main() — everything that executes lives in here ────────────────

main() {
    INSTALL_DIR="${TC_INSTALL_DIR:-/opt/usework}"
    # Public mirror at github.com/BEAR-TECH-SOLUTIONS/useworkspace —
    # populated from the private repo by scripts/mirror-public.sh on
    # every master deploy with cloud-only paths stripped. install.sh
    # itself is intentionally NOT stripped, so this URL also serves
    # this script.
    COMPOSE_RAW="${TC_COMPOSE_RAW:-https://raw.githubusercontent.com/BEAR-TECH-SOLUTIONS/useworkspace/main/selfhost}"
    # Central backend that mints / verifies licenses. Same URL the
    # phone-home loop hits (LICENSE_PHONE_HOME_URL points at
    # ${API_BASE}/licenses/verify).
    API_BASE="${TC_API_BASE:-https://api.usework.space/api/v1}"
    DOMAIN=""
    LICENSE=""
    CLAIM=""
    ADMIN_EMAIL=""
    ADMIN_PASSWORD=""
    ADMIN_NAME="Admin"
    SKIP_EULA="${TC_SKIP_EULA:-0}"

    for arg in "$@"; do
        case "$arg" in
            --domain=*)         DOMAIN="${arg#*=}" ;;
            --license=*)        LICENSE="${arg#*=}" ;;
            --claim=*)          CLAIM="${arg#*=}" ;;
            --api-base=*)       API_BASE="${arg#*=}" ;;
            --install-dir=*)    INSTALL_DIR="${arg#*=}" ;;
            --admin-email=*)    ADMIN_EMAIL="${arg#*=}" ;;
            --admin-password=*) ADMIN_PASSWORD="${arg#*=}" ;;
            --admin-name=*)     ADMIN_NAME="${arg#*=}" ;;
            --skip-eula)        SKIP_EULA=1 ;;
            --help)
                cat <<EOF
Usage: install.sh [flags]
  --domain=FQDN              Public hostname (required)
  --claim=CODE               Self-serve claim code (ws-xxxxxx) from the
                             cloud dashboard. The installer exchanges
                             it for a signed token via the central API.
                             Mutually exclusive with --license.
  --license=TOKEN            Pre-signed license token (admin / sales-led
                             customers received this directly).
                             Mutually exclusive with --claim.
  --admin-email=EMAIL        Initial admin user email (prompted if absent)
  --admin-password=PASS      Initial admin user password (prompted if absent)
  --admin-name=NAME          Display name for the admin user (default: "Admin")
  --install-dir=PATH         Defaults to /opt/usework
  --api-base=URL             Central backend base URL (default:
                             https://api.usework.space/api/v1). Override
                             for staging or on-prem proxy installs.
  --skip-eula                Skip the typed EULA acceptance (CI only)
EOF
                exit 0
                ;;
        esac
    done

    if [ -n "${CLAIM}" ] && [ -n "${LICENSE}" ]; then
        die "Pass --claim or --license, not both."
    fi

    # Rescue stdin for the `curl ... | sh` pipe pattern. By the time
    # main runs, sh has fully parsed the script body (function defs
    # only) so rebinding stdin here doesn't lose any pipe bytes.
    # Without /dev/tty (truly headless CI / unattended provisioner)
    # we leave stdin alone and require every value as a --flag — the
    # `[ -n "${X}" ] || die` checks below catch missing values either
    # way.
    if [ ! -t 0 ] && [ -r /dev/tty ]; then
        exec < /dev/tty
    fi

    # ─── 1. Docker + Compose v2 ─────────────────────────────────────────
    command -v docker >/dev/null 2>&1 || die "Docker is required. See https://docs.docker.com/engine/install/"
    docker compose version >/dev/null 2>&1 || die "Docker Compose v2 is required (\`docker compose\`, not \`docker-compose\`)."

    # ─── 2. Install directory ───────────────────────────────────────────
    log "Installing into ${INSTALL_DIR}"
    mkdir -p "${INSTALL_DIR}"
    cd "${INSTALL_DIR}"

    # ─── 3. Pull compose + Caddyfile + .env.example ─────────────────────
    for f in compose.yml Caddyfile .env.example; do
        if [ ! -f "${f}" ]; then
            log "Fetching ${f}"
            curl -fsSL "${COMPOSE_RAW}/${f}" -o "${f}"
        else
            log "Keeping existing ${f}"
        fi
    done

    # ─── 4. Generate local secrets if missing ───────────────────────────
    if [ ! -f .env ]; then
        log "Generating .env with fresh secrets"
        cp .env.example .env

        APP_KEY="base64:$(openssl rand -base64 32)"
        POSTGRES_PASSWORD="$(openssl rand -hex 24)"
        REVERB_APP_KEY="$(openssl rand -hex 16)"
        REVERB_APP_SECRET="$(openssl rand -hex 32)"
        # UUIDv4 without dashes — instance id has no hierarchy semantics.
        LICENSE_INSTANCE_ID="$(openssl rand -hex 16)"

        set_env_var .env "APP_KEY"             "${APP_KEY}"
        set_env_var .env "POSTGRES_PASSWORD"   "${POSTGRES_PASSWORD}"
        set_env_var .env "DB_PASSWORD"         "${POSTGRES_PASSWORD}"
        set_env_var .env "REVERB_APP_KEY"      "${REVERB_APP_KEY}"
        set_env_var .env "REVERB_APP_SECRET"   "${REVERB_APP_SECRET}"
        set_env_var .env "LICENSE_INSTANCE_ID" "${LICENSE_INSTANCE_ID}"
    else
        log "Keeping existing .env (delete it to regenerate secrets)"
    fi

    # ─── 5. Prompt for domain + license/claim if not provided ───────────
    if [ -z "${DOMAIN}" ]; then
        printf "Fully-qualified domain for this install (e.g. workspace.acme.com): "
        read DOMAIN
    fi
    [ -n "${DOMAIN}" ] || die "Domain is required."
    validate_domain "${DOMAIN}"

    # If LICENSE_TOKEN is already populated from a previous successful
    # run, skip the prompt / re-claim — claim codes are single-use and
    # re-running would fail with `invalid_claim`. Operator can force a
    # re-claim by clearing LICENSE_TOKEN from .env or passing
    # --claim/--license explicitly.
    EXISTING_TOKEN="$(grep -E '^LICENSE_TOKEN=' .env 2>/dev/null | head -n1 | cut -d= -f2-)"
    if [ -z "${CLAIM}" ] && [ -z "${LICENSE}" ] && [ -n "${EXISTING_TOKEN}" ]; then
        log "LICENSE_TOKEN already set in .env — skipping license prompt."
        LICENSE="${EXISTING_TOKEN}"
    fi

    # Prompt once for "either format" and shape-detect: claim codes
    # start with `ws-`, signed tokens are an opaque base64-ish blob.
    if [ -z "${CLAIM}" ] && [ -z "${LICENSE}" ]; then
        printf "Claim code (ws-xxxxxx) OR pre-signed license token: "
        read LICENSE_INPUT
        case "${LICENSE_INPUT}" in
            ws-*) CLAIM="${LICENSE_INPUT}" ;;
            *)    LICENSE="${LICENSE_INPUT}" ;;
        esac
    fi

    # Self-serve path — exchange the claim code for a signed token.
    if [ -n "${CLAIM}" ]; then
        validate_claim "${CLAIM}"

        # The instance_id we POST is what the resulting token will be
        # bound to — pull from .env where Step 4 wrote it (or backfill
        # for older installs that pre-date the column).
        LICENSE_INSTANCE_ID="$(grep -E '^LICENSE_INSTANCE_ID=' .env 2>/dev/null | head -n1 | cut -d= -f2-)"
        if [ -z "${LICENSE_INSTANCE_ID}" ]; then
            LICENSE_INSTANCE_ID="$(openssl rand -hex 16)"
            set_env_var .env "LICENSE_INSTANCE_ID" "${LICENSE_INSTANCE_ID}"
            log "Generated LICENSE_INSTANCE_ID (was missing from existing .env)."
        fi

        log "Claiming license against ${API_BASE}/licenses/claim"
        BODY="$(printf '{"claim_code":"%s","instance_id":"%s"}' \
            "${CLAIM}" "${LICENSE_INSTANCE_ID}")"
        # `--fail` makes curl exit non-zero on 4xx/5xx so a 422 / 429
        # surfaces as a script failure instead of writing junk into
        # .env. The endpoint collapses every reject reason (unknown /
        # expired / already-claimed / revoked) to a generic
        # `invalid_claim` so the message below covers all of them.
        RESPONSE="$(printf '%s' "${BODY}" | curl \
            --silent --show-error --fail --max-time 15 \
            --request POST \
            --header 'Content-Type: application/json' \
            --data @- \
            "${API_BASE}/licenses/claim")" \
            || die "Claim failed. Check that the code is correct and current (codes expire after 24h), and that this server can reach ${API_BASE}."

        # Tiny JSON field extraction without a jq dependency — the
        # response shape is fixed: { "valid": true, "token": "...",
        # "license": {...} } so a portable grep/sed lifts the token
        # cleanly.
        LICENSE="$(printf '%s' "${RESPONSE}" \
            | tr ',' '\n' \
            | grep -E '"token"[[:space:]]*:' \
            | head -n 1 \
            | sed -E 's/.*"token"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/')"

        [ -n "${LICENSE}" ] \
            || die "Claim succeeded but the response had no token. Contact support with this install's instance id: ${LICENSE_INSTANCE_ID}"

        log "Claim exchanged for a signed token."
    fi

    [ -n "${LICENSE}" ] || die "License is required (use --claim=ws-... or --license=...)."
    validate_license "${LICENSE}"

    set_env_var .env "TC_DOMAIN"     "${DOMAIN}"
    set_env_var .env "LICENSE_TOKEN" "${LICENSE}"

    # ─── 6. EULA gate ──────────────────────────────────────────────────
    if [ "${SKIP_EULA}" != "1" ]; then
        cat <<'EOF'

By proceeding you confirm that you have read and accept the usework.space
self-hosted EULA: https://usework.space/legal/self-hosted-eula

  - The image is licensed, not open source.
  - Telemetry is limited to the allow-list documented in the EULA
    (license id, instance fingerprint, app version, aggregate counts).
    No project names, user emails, or vault data ever leaves this host.
  - The license can be revoked if the EULA is violated.

EOF
        printf 'Type "yes" to accept and continue: '
        read EULA_ANSWER
        [ "${EULA_ANSWER}" = "yes" ] || die "EULA not accepted."
    fi

    # ─── 7. Prompt for initial admin user ───────────────────────────────
    # The admin's account is the first sign-in for the desktop client. We
    # collect the credentials BEFORE bringing the stack up so the operator
    # isn't surprised by a prompt after `docker compose pull` finishes
    # downloading hundreds of MB.
    if [ -z "${ADMIN_EMAIL}" ]; then
        printf "Initial admin email (you'll sign in with this): "
        read ADMIN_EMAIL
    fi
    [ -n "${ADMIN_EMAIL}" ] || die "Admin email is required."

    if [ -z "${ADMIN_PASSWORD}" ]; then
        # `read -s` hides input; -s isn't POSIX but Bash/Zsh both support
        # it. Fall back to plain read if the shell rejects it.
        printf "Initial admin password: "
        if (read -s ADMIN_PASSWORD) 2>/dev/null; then
            printf '\n'
            printf "Confirm: "
            read -s ADMIN_PASSWORD_CONFIRM
            printf '\n'
        else
            read ADMIN_PASSWORD
            printf "Confirm: "
            read ADMIN_PASSWORD_CONFIRM
        fi
        [ "${ADMIN_PASSWORD}" = "${ADMIN_PASSWORD_CONFIRM}" ] || die "Passwords did not match."
    fi
    [ -n "${ADMIN_PASSWORD}" ] || die "Admin password is required."

    # ─── 8. Bring the stack up ──────────────────────────────────────────
    log "Starting containers"
    docker compose pull
    docker compose up -d

    # ─── 9. Watch boot, then create the admin user ──────────────────────
    log "Watching app boot — Ctrl+C to detach (containers keep running)"

    # `docker compose logs -f --tail=0 app` streams new lines only. We
    # capture exit status from inside the loop via a temp file because
    # `while read | …` runs the body in a subshell on most POSIX shells.
    STATUS_FILE="$(mktemp)"
    trap 'rm -f "${STATUS_FILE}"' EXIT

    docker compose logs -f --tail=0 app | while IFS= read -r line; do
        printf '%s\n' "${line}"
        case "${line}" in
            *"License OK"*)         echo ok    > "${STATUS_FILE}"; break ;;
            *"License check failed"*|*"FATAL:"*)
                                    echo fail  > "${STATUS_FILE}"; break ;;
        esac
    done

    STATUS="$(cat "${STATUS_FILE}" 2>/dev/null || echo unknown)"
    if [ "${STATUS}" != "ok" ]; then
        warn "License check failed. Run \`docker compose logs app\` for details."
        exit 1
    fi

    # ─── 10. Bootstrap the admin user ───────────────────────────────────
    log "Creating admin user ${ADMIN_EMAIL}"
    # The artisan command reads TC_ADMIN_PASSWORD from the environment so
    # the secret never appears in argv (audit H6). `docker compose exec -e`
    # passes it via the container's env, never the host process listing.
    if docker compose exec -T \
            -e TC_ADMIN_PASSWORD="${ADMIN_PASSWORD}" \
            app sh -c \
                'php artisan tc:admin:create "$0" --name="$1"' \
                "${ADMIN_EMAIL}" "${ADMIN_NAME}"; then
        log "Admin user ready. Open https://${DOMAIN}/ and sign in with ${ADMIN_EMAIL}."
    else
        RC=$?
        if [ "${RC}" = "2" ]; then
            log "An admin user already exists — leaving it in place. Visit https://${DOMAIN}/ to sign in."
        else
            warn "Admin user creation failed. Run \`docker compose logs app\` and \`usework reset-password\` if needed."
            exit 1
        fi
    fi
}

main "$@"
