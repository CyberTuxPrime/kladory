#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Kladory contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Baut das Installationspaket kladory-<version>.tar.gz für ein GitHub-Release.
# Aufruf im Projektordner:   bash tools/release-paket.sh
# Ergebnis liegt eine Ebene höher (neben dem Projektordner).
set -euo pipefail

cd "$(dirname "$0")/.."
version=$(grep -oP '(?<=<version>)[^<]+' appinfo/info.xml)
ziel="$(cd .. && pwd)/kladory-${version}.tar.gz"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

mkdir "$tmp/kladory"
# alles außer Git-Daten, Bildschirmfotos und Hilfsdateien
tar --exclude=.git --exclude=.github --exclude=screenshots --exclude=__pycache__ \
    --exclude='*.tar.gz' --exclude=.gitignore -cf - . | tar -xf - -C "$tmp/kladory"
tar -czf "$ziel" -C "$tmp" kladory

echo "Fertig: $ziel"
echo "Version: $version"
sha256sum "$ziel"
