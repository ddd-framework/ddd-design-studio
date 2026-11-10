#!/usr/bin/env bash
# Installs a packed @mgamadeus/ddd-design-studio tarball into a fresh consumer project and checks it as a user would:
#   1. every exports target exists in the tarball and the entries are split as documented (scripts/check-package.mjs)
#   2. the main entry imports at runtime (smoke.mjs)
#   3. a strict TypeScript consumer importing both entries compiles with moduleResolution bundler and nodenext
#
# Usage: test/consumer/run.sh <tarball> <npm|pnpm> <workDir>
set -euo pipefail

TARBALL=$(cd "$(dirname "$1")" && pwd)/$(basename "$1")
PM=$2
WORK=$3
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)

rm -rf "$WORK" && mkdir -p "$WORK/extracted"
tar -xzf "$TARBALL" -C "$WORK/extracted"
echo "── tarball contents"
node "$ROOT/scripts/check-package.mjs" "$WORK/extracted/package"

APP="$WORK/app"
mkdir -p "$APP"
cp "$HERE/smoke.mjs" "$HERE/consumer.ts" "$APP/"
cd "$APP"
echo '{"name":"consumer","private":true,"type":"module"}' > package.json
DEPS=("$TARBALL" @mgamadeus/ddd-web @reduxjs/toolkit @xyflow/react lucide-react react react-dom react-redux
  @types/react @types/react-dom typescript@^6 @types/node)
echo "── install with $PM"
if [ "$PM" = npm ]; then npm install --no-audit --no-fund "${DEPS[@]}"; else pnpm add "${DEPS[@]}"; fi

echo "── runtime import"
node smoke.mjs

for MODE in bundler nodenext; do
  if [ "$MODE" = bundler ]; then MODULE=esnext; else MODULE=nodenext; fi
  cat > "tsconfig.$MODE.json" <<JSON
{
  "compilerOptions": {
    "target": "ES2022", "lib": ["ES2022", "DOM", "DOM.Iterable"], "module": "$MODULE", "moduleResolution": "$MODE",
    "jsx": "react-jsx", "strict": true, "skipLibCheck": false, "noEmit": true, "types": ["node"]
  },
  "include": ["consumer.ts"]
}
JSON
  echo "── type check ($MODE)"
  ./node_modules/.bin/tsc -p "tsconfig.$MODE.json"
done
echo "── consumer check passed ($PM)"
