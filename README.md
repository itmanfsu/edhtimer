# Nerd Cave Comander Clock

An interactive turn timer for multiplayer Magic: The Gathering Commander games. The site supports local games, optional per-player elimination clocks, shared Firebase rooms, and a floating Chrome timer.

Live site: [https://edhtimer.com](https://edhtimer.com)

## Features

- Configurable two-to-six-player games
- Custom player names and turn length
- Optional overall elimination clock for each player
- Shared rooms that synchronize across browsers
- Pause, resume, next-player, player-selection, and time-adjustment controls
- Chrome Document Picture-in-Picture timer with a popup fallback
- Optional translucent bookmarklet overlay for other webpages
- Installable Progressive Web App for Android and iPhone home screens
- Twelve-hour inactive-room expiration and manual shared-game deletion
- Responsive layout and keyboard shortcuts

## Project structure

```text
.
|-- .buildkite/pipeline.yml        Buildkite deployment pipeline
|-- deploy-edhtimer.sh             Server-side deployment command
|-- firebase-database-rules.json   Realtime Database access rules
|-- index.html                     Complete application: HTML, CSS, and JavaScript
|-- overlay.html                   Compact shared-room overlay
|-- bookmarklet.html               Bookmarklet installation page
|-- manifest.webmanifest           PWA name, colors, and phone icons
|-- service-worker.js              Installable same-origin app shell
|-- app-icons/                      PWA and Apple touch icons
`-- README.md
```

The application is intentionally self-contained in `index.html`. It does not require Node.js, a build process, or a package manager. Firebase modules and web fonts load from their official CDNs.

## Local development

Serve the directory through a local web server. ES modules and Firebase authentication may not behave correctly when `index.html` is opened directly through a `file://` URL.

For example, use any static-server extension or existing local HTTP server, then open the served page in Chrome.

## Firebase

The application uses:

- Firebase Anonymous Authentication
- Firebase Realtime Database
- Firebase App Check with reCAPTCHA Enterprise
- Database server-time offset for synchronized countdown calculations

Firebase configuration is declared near the beginning of the module script in `index.html`. Firebase web configuration values are public identifiers; security is enforced through Authentication, App Check, and Realtime Database rules.

### Required console settings

1. Enable **Authentication > Sign-in method > Anonymous**.
2. Open **Realtime Database > Rules**.
3. Publish the contents of `firebase-database-rules.json`.
4. Register `edhtimer.com` and `www.edhtimer.com` with Firebase App Check.

App Check is initialized before Authentication and Realtime Database. After deploying an App Check change, confirm requests appear as **Verified** in **Firebase Console > App Check > APIs** before enabling enforcement for Realtime Database.

### Shared-room model

Each room is stored under `/rooms/{ROOM_CODE}`. Actions write a compact state snapshot containing player information, timer values, and a synchronization timestamp. Connected clients subscribe to that room with `onValue()`.

The timer does not write on every animation frame. Each browser counts down locally and writes only when a user performs an action. On receipt or reconnection, a client calculates time elapsed since the saved server-adjusted timestamp. This keeps devices synchronized while limiting database traffic.

## Deployment

The GitHub `main` branch is the deployment source.

```text
GitHub main -> Buildkite -> SSH -> /home/ec2-user/deploy-edhtimer.sh
```

The deployment script runs a fast-forward-only pull in:

```text
/var/www/edhtiimer.com
```

The server directory retains the original double-`i` spelling, while the public domain is `edhtimer.com`.

Apache serves both `edhtimer.com` and `www.edhtimer.com`, redirects HTTP to HTTPS, and uses a Let's Encrypt certificate.

## Controls

- **Space:** Pause or resume the timer
- **N:** Advance to the next active player
- **Player card:** Start that player's turn
- **Turn counter:** Advances only after the active-player rotation wraps to the first remaining player
- **Float timer:** Open an always-on-top timer in supported Chrome versions

## Bookmarklet overlay

Visit `https://edhtimer.com/bookmarklet.html` and drag the installation button to Chrome's bookmarks bar. The bookmark injects a draggable iframe pointing to `overlay.html` into the active webpage. It can adjust opacity, close without affecting the host page, and uses the same Firebase room state as the main timer.

The main timer's **Browser overlay** control opens this installation page in a new tab.

The feature is isolated to `overlay.html` and `bookmarklet.html`. The `pre-bookmarklet` Git tag marks the production revision immediately before these files were added.

## Phone installation

The website is an installable Progressive Web App. Android users can use Chrome's **Install app** prompt. iPhone and iPad users can open the site in Safari and choose **Share > Add to Home Screen**. The installed app opens in standalone mode and continues using the same Firebase rooms and Buildkite-deployed website code.

The service worker caches only the same-origin application shell. Firebase authentication, App Check, and real-time modules remain network-managed to avoid pinning backend code to an old release.

## Room lifecycle

Every shared-room action refreshes `lastActive` and `expiresAt`. Rooms expire after 12 hours without a pause, player change, or time adjustment. Joining an expired room deletes it immediately. **End shared game** lets any participant delete the current shared room after confirmation; all connected clients are then returned to the setup screen.

`scripts/cleanup-expired-rooms.php` is designed for a nightly EC2 cron job. It requires a Firebase Admin service-account credential through `GOOGLE_APPLICATION_CREDENTIALS`; the credential must remain outside both the repository and `/var/www/edhtiimer.com`.

## Future security improvements

- Enable Firebase App Check with reCAPTCHA Enterprise
- Add strict schema and size validation to database rules
- Increase room-code length
- Automatically expire abandoned rooms
- Add Content Security Policy and other browser security headers

## Author

Made by Thomas Lyle.
