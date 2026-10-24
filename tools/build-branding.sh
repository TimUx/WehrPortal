#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

# ImageMagick rasterizes the original SVG; no external artwork or fonts are used.
for size in 192 512; do
    convert -density 768 -background none public/images/wehrportal-icon.svg -resize "${size}x${size}" -strip "public/images/icon-${size}.png"
done
convert -density 768 -background '#1B2645' public/images/wehrportal-icon.svg -alpha remove -alpha off -resize 512x512 -strip public/images/icon-maskable-512.png
convert -density 768 -background '#1B2645' public/images/wehrportal-icon.svg -alpha remove -alpha off -resize 180x180 -strip public/images/apple-touch-icon.png
convert -density 768 -background none public/images/wehrportal-icon.svg -resize 48x48 -define icon:auto-resize=48,32,16 public/favicon.ico
cp public/images/wehrportal-logo.svg docs/logo.svg
