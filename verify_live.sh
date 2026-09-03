#!/bin/sh
# External check of the Dynamic Reorder install on buenaspapas.com.
# Needs no login: it only looks at what the shop serves to an anonymous visitor.
# Run before and after installing so the two outputs can be compared.

SHOP="https://buenaspapas.com"
UA="Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36"
OUT="${1:-/tmp/dr-verify}"
mkdir -p "$OUT"

echo "=============================================="
echo " Dynamic Reorder - external verification"
echo " $(date -u '+%Y-%m-%d %H:%M UTC')"
echo "=============================================="

echo
echo "[1] Module files served? (404 = not installed / not deployed)"
for f in "/modules/dynamicreorder/views/js/dynamicreorder.js" \
         "/modules/dynamicreorder/views/css/dynamicreorder.css"; do
    code=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" --max-time 60 "$SHOP$f")
    echo "    $code  $f"
done

echo
echo "[2] Front controller reachable? (302->authentication is the CORRECT"
echo "    logged-out answer; 404 = not installed; 500 = installed but erroring)"
code=$(curl -s -o "$OUT/endpoint.html" -w '%{http_code}' -A "$UA" --max-time 60 \
    "$SHOP/index.php?fc=module&module=dynamicreorder&controller=reorder")
loc=$(curl -s -o /dev/null -D - -A "$UA" --max-time 60 \
    "$SHOP/index.php?fc=module&module=dynamicreorder&controller=reorder" \
    | grep -i '^location:' | head -1 | tr -d '\r')
echo "    HTTP $code"
[ -n "$loc" ] && echo "    $loc"

echo
echo "[3] Homepage: is the module hooked in, and where does the banner point?"
curl -s -A "$UA" --max-time 90 "$SHOP/" -o "$OUT/home.html"
python3 - "$OUT/home.html" <<'PY'
import re, sys
h = open(sys.argv[1], encoding='utf-8', errors='replace').read()
print("    homepage bytes:", len(h))

asset = len(re.findall(r'dynamicreorder', h))
print("    'dynamicreorder' references in the HTML:", asset,
      "  <- 0 means the module is not hooked on this page")

cfg = 'dynamicReorderConfig' in h
print("    dynamicReorderConfig present:", cfg)

m = re.search(r'<a[^>]*href="([^"]*)"[^>]*>\s*<img[^>]*repetir[^>]*>', h, re.I)
if not m:
    m2 = re.search(r'href="([^"]*)"[^>]{0,200}repetir pedido', h, re.I)
    m = m2
print("    banner link:", m.group(1) if m else "NOT FOUND")

if m:
    href = m.group(1)
    if 'id_order=' in href:
        print("    STILL HARDCODED -> id_order is present; step 2 not done yet")
    elif 'dynamicreorder' in href:
        print("    OK -> banner points at the module")
PY

echo
echo "Done. Artefacts in $OUT"
