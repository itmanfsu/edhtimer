#!/bin/bash
set -euo pipefail

cd /var/www/edhtiimer.com
git pull --ff-only origin main
