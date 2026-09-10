#!/usr/bin/env bash
set -euo pipefail

EXPECTED_CERT="f1aa5b79df60b0918fdbf40734b37169b03d6d43d51a8b9ca7ad8dcfb128686c"
MODULE="${OPENSC_PKCS11_MODULE:-/opt/homebrew/lib/opensc-pkcs11.so}"
PYTHON="${PYTHON:-/opt/homebrew/bin/python3}"

if [[ $# -lt 1 || $# -gt 2 ]]; then
    printf 'Usage: %s INPUT.ascfenc [OUTPUT.json]\n' "$0" >&2
    exit 2
fi

INPUT="$1"
OUTPUT="${2:-${INPUT%.ascfenc}.json}"

if [[ ! -f "$INPUT" ]]; then
    printf 'Input file not found: %s\n' "$INPUT" >&2
    exit 1
fi
if [[ -e "$OUTPUT" ]]; then
    printf 'Refusing to overwrite existing output: %s\n' "$OUTPUT" >&2
    exit 1
fi
if [[ ! -f "$MODULE" ]]; then
    printf 'OpenSC PKCS#11 module not found: %s\n' "$MODULE" >&2
    exit 1
fi
if ! command -v pkcs11-tool >/dev/null 2>&1; then
    printf 'pkcs11-tool is required. Install OpenSC first.\n' >&2
    exit 1
fi
if [[ ! -x "$PYTHON" ]]; then
    printf 'Python not found: %s\n' "$PYTHON" >&2
    exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

"$PYTHON" - "$INPUT" "$TMP_DIR" "$EXPECTED_CERT" <<'PY'
import base64
import json
import pathlib
import sys

source = pathlib.Path(sys.argv[1])
target = pathlib.Path(sys.argv[2])
expected = sys.argv[3]

data = json.loads(source.read_text(encoding="utf-8"))
required = {"format", "version", "content_cipher", "key_cipher", "certificate_sha256", "aad", "encrypted_key", "iv", "tag", "ciphertext"}
if set(data) != required:
    raise SystemExit("Invalid encrypted export schema")
if data["format"] != "aimf-secure-forms-encrypted" or data["version"] != 1:
    raise SystemExit("Unsupported encrypted export format")
if data["content_cipher"] != "AES-256-GCM" or data["key_cipher"] != "RSA-2048-OAEP":
    raise SystemExit("Unsupported encryption algorithms")
if data["certificate_sha256"] != expected:
    raise SystemExit("Export was not encrypted to the expected AIMF certificate")
for name in ("aad", "encrypted_key", "iv", "tag", "ciphertext"):
    try:
        decoded = base64.b64decode(data[name], validate=True)
    except Exception as exc:
        raise SystemExit(f"Invalid base64 value: {name}") from exc
    (target / name).write_bytes(decoded)
PY

printf 'Touch the YubiKey if it flashes, then enter the PIV PIN when prompted.\n'
pkcs11-tool \
    --module "$MODULE" \
    --login \
    --decrypt \
    --id 03 \
    --mechanism RSA-PKCS-OAEP \
    --hash-algorithm SHA-1 \
    --mgf MGF1-SHA1 \
    --input-file "$TMP_DIR/encrypted_key" \
    --output-file "$TMP_DIR/data_key"

"$PYTHON" - "$TMP_DIR" "$OUTPUT" <<'PY'
import json
import pathlib
import sys
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

source = pathlib.Path(sys.argv[1])
output = pathlib.Path(sys.argv[2])
key = (source / "data_key").read_bytes()
iv = (source / "iv").read_bytes()
tag = (source / "tag").read_bytes()
ciphertext = (source / "ciphertext").read_bytes()
aad = (source / "aad").read_bytes()
if len(key) != 32 or len(iv) != 12 or len(tag) != 16:
    raise SystemExit("Invalid decrypted key or AES-GCM parameters")
plaintext = AESGCM(key).decrypt(iv, ciphertext + tag, aad)
parsed = json.loads(plaintext.decode("utf-8"))
output.write_text(json.dumps(parsed, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
PY

chmod 600 "$OUTPUT"
printf 'Decrypted export written to: %s\n' "$OUTPUT"
