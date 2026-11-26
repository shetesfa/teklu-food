#!/bin/sh
# Render sets $PORT (default 10000); make Apache listen on it.
# Local runs without $PORT keep Apache on port 80.
set -e
PORT_TO_USE="${PORT:-80}"
if [ "$PORT_TO_USE" != "80" ]; then
  sed -i "s/^Listen 80$/Listen $PORT_TO_USE/" /etc/apache2/ports.conf
  sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT_TO_USE>/" /etc/apache2/sites-enabled/000-default.conf
fi
exec apache2-foreground
