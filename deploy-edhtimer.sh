#!/bin/bash
set -euo pipefail

# Keep production exactly on GitHub's main-line history; fail instead of creating
# an accidental merge commit when the server and origin ever diverge.
cd /var/www/edhtiimer.com
git pull --ff-only origin main
