#!/usr/bin/env bash
#
# Builds twocans' Synology package from this folder:
#
#   synology/build.sh [build number]   →  dist/twocans-<version>-<build>.spk
#
# The build number (1 unless given) goes after twocans' own version, as DSM
# wants one: a package rebuilt for the same release gets the next. Install
# the .spk in Package Center → Manual Install. See synology/README.md.
#
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(tr -d ' \n' < backend/VERSION)
BUILD=${1:-1}
[[ "$BUILD" =~ ^[0-9]+$ ]] || { echo "The build number is a number: synology/build.sh 2" >&2; exit 2; }
SPK_VERSION="$VERSION-$BUILD"

work=$(mktemp -d); trap 'rm -rf "$work"' EXIT
spk=$work/spk; target=$work/target
mkdir -p "$spk" "$target/app" dist

# twocans itself, as git has it — this folder's copy of each file git tracks,
# changes included — without what only matters to its repository.
git ls-files -z | grep -zvE '^(synology/|\.github/|docs/)' \
  | xargs -0 -I{} sh -c '[ -e "{}" ] && printf "%s\0" "{}"' \
  | tar --null -T - -cf - | tar -C "$target/app" -xf -
# Files the app writes as it runs hold this machine's own details; the
# package gets them as committed.
for f in docker/asterisk/etc/voicemail.conf; do git show "HEAD:$f" > "$target/app/$f"; done

# The package's own files, beside it.
cp -r synology/target/. "$target/"
cp -r synology/setup "$target/setup"
echo "$SPK_VERSION" > "$target/build-id"
# DSM keeps a window's script until its version changes.
sed -i "s/\"version\": \"1\"/\"version\": \"$SPK_VERSION\"/" "$target/ui/config"

# Icons: Package Center's, and the main menu's, from the app's own.
python3 - "$target/ui/images" "$spk" <<'PY'
import sys, os
from PIL import Image
src = Image.open("backend/assets/pwa/icon-512.png").convert("RGBA")
ui, spk = sys.argv[1], sys.argv[2]
os.makedirs(ui, exist_ok=True)
for size in (16, 24, 32, 48, 64, 72, 128, 256):
    src.resize((size, size), Image.LANCZOS).save(f"{ui}/{size}.png")
src.resize((64, 64), Image.LANCZOS).save(f"{spk}/PACKAGE_ICON.PNG")
src.resize((256, 256), Image.LANCZOS).save(f"{spk}/PACKAGE_ICON_256.PNG")
PY

find "$target" -type d -exec chmod 755 {} +
find "$target" -type f -exec chmod 644 {} +
chmod 755 "$target/ui/api.cgi" "$target/setup/run.sh" "$target/app/install.sh" "$target/app/twocans" "$target/app/get.sh"
tar -C "$target" --owner=0 --group=0 --numeric-owner -czf "$spk/package.tgz" .

cp -r synology/scripts synology/conf synology/WIZARD_UIFILES "$spk/"
chmod 755 "$spk"/scripts/* "$spk"/WIZARD_UIFILES/*.sh
chmod 644 "$spk/scripts/common" "$spk"/conf/* "$spk/WIZARD_UIFILES/uninstall_uifile"
sed "s/@VERSION@/$SPK_VERSION/" synology/INFO.in > "$spk/INFO"
echo "extractsize=\"$(( $(du -sk "$target" | cut -f1) ))\"" >> "$spk/INFO"
echo "checksum=\"$(md5sum "$spk/package.tgz" | cut -d' ' -f1)\"" >> "$spk/INFO"

out="dist/twocans-$SPK_VERSION.spk"
tar -C "$spk" --owner=0 --group=0 --numeric-owner -cf "$out" \
  INFO package.tgz scripts conf WIZARD_UIFILES PACKAGE_ICON.PNG PACKAGE_ICON_256.PNG
echo "$out ($(du -h "$out" | cut -f1))"
