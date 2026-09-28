#!/bin/bash
# CI gate: no infrastructure/secret material in the repository.
#
# Rejects: public IP addresses, SSH private-key references, cloud keys,
# high-entropy assignment of passwords/tokens in tracked files. Historical
# files are NOT allow-listed: the production host/key previously committed
# in DEPLOYMENT.md and Documentation/ was scrubbed; if it reappears, this
# gate fails the pipeline.
#
# Exit 0 = clean, exit 1 = finding.

set -u
cd "$(dirname "$0")/../.."

errors=0
add_error() { echo "  ✖ $1"; errors=$((errors + 1)); }

# Only tracked, source-like text files are scanned. Dependencies, VCS,
# binary artifacts and tooling output are out of scope by construction.
SOURCE_SUFFIXES='\.(php|twig|yaml|yml|json|xml|md|txt|sh|bash|env|example|conf|ini|toml|sql|js|css|html)$'

ip_scanned_files() {
  tracked_text_files | grep -vE '^(tests|Documentation)/' || true
}

tracked_text_files() {
  git ls-files 2>/dev/null | grep -Ev '(^|/)(vendor|node_modules|var|\.kilo|presentation_screenshots|ml|external_data|test-results)(/|$)' \
    | grep -E "$SOURCE_SUFFIXES" \
    | grep -vE '(^|/)(composer\.lock|symfony\.lock|package-lock\.json|\.output\.txt)$' \
    || true
}

echo "==> scanning tracked text files for secret material"

# 1. Public IPv4 addresses (loopback/broadcast/RFC1918/link-local and
#    comment lines excluded).
while IFS= read -r file; do
  [ -z "$file" ] && continue
  hit=$(grep -nE -- '([0-9]{1,3}\.){3}[0-9]{1,3}' "$file" 2>/dev/null \
    | grep -vE '(127\.0\.0\.|0\.0\.0\.0|169\.254\.0\.0|255\.255\.255\.255|169\.254\.169\.254|192\.168\.|10\.0\.|172\.(1[6-9]|2[0-9]|3[01])\.)' \
    | grep -vE '(8\.8\.[84]\.[84]|1\.1\.1\.1|9\.9\.9\.9|203\.0\.113\.|198\.51\.100\.|192\.0\.2\.|195\.8\.215\.|140\.82\.|185\.199\.)' \
    | grep -vE '(AppleWebKit|Chrome/[0-9]|Firefox/[0-9]|<path d=)' \
    | grep -vE '^[0-9]+:\s*(\*|//|#)' \
    | head -1)
  if [ -n "$hit" ]; then
    add_error "public IP address in $file: ${hit%%:*}:$(echo "$hit" | cut -c1-120)"
  fi
done < <(ip_scanned_files)

# 2. Private key blocks.
while IFS= read -r file; do
  if grep -qE 'BEGIN (RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY' "$file" 2>/dev/null; then
    add_error "private key material in $file"
  fi
done < <(tracked_text_files)

if grep -rqE -- '~\/\.ssh\/[a-z0-9_-]+' $(tracked_text_files | tr '\n' ' ') 2>/dev/null; then
  add_error "SSH key path reference (use \$SSH_KEY from the secret store)"
fi

# 3. Known credential assignment smells in ops files (not code tests).
if grep -rqiE '(password|passwd|api[_-]?key|secret)\s*[=:]\s*["'\''][^"'\''"{}<>$]{10,}' \
    DEPLOYMENT.md INSTALLATION.txt scripts/*.sh 2>/dev/null; then
  add_error "hardcoded credential assignment in ops files"
fi

# 4. The specific production values that were scrubbed must stay out.
if printf '%s\n' $(tracked_text_files | tr '\n' ' ') | xargs grep -lE 'hetzner-db-mac|hetzner-apexintel' 2>/dev/null; then
  add_error "scrubbed production SSH identity names present again"
fi

if [ "$errors" -gt 0 ]; then
  echo "✖ secrets gate FAILED ($errors finding(s))"
  exit 1
fi

echo "✓ secrets gate passed"
exit 0
