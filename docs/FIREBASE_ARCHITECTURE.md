# Firebase connectivity and data architecture

This document describes how Nerd Cave Comander Clock version 1.0.0 connects to Firebase, authenticates browsers, stores rooms, synchronizes timers, and deletes expired games.

## Request flow

```text
Browser, installed PWA, or bookmarklet iframe
        |
        |-- App Check proves the request came from edhtimer.com
        |-- Anonymous Authentication supplies a temporary user identity
        `-- Realtime Database reads and writes /rooms/{ROOM_CODE}
                                      |
                                      `-- onValue broadcasts state to every client
```

Every database operation must pass three independent checks:

1. The request has a valid App Check token.
2. The browser has a valid Firebase Authentication session.
3. Realtime Database Security Rules permit the requested path and operation.

## Project initialization

`index.html` contains the public Firebase web configuration. These values identify the Firebase project and database; they are not administrative credentials.

```javascript
const firebaseApp = initializeApp(firebaseConfig);
const auth = getAuth(firebaseApp);
const database = getDatabase(firebaseApp);
```

The application uses Firebase JavaScript SDK modules from Google's CDN. There is no server-side Firebase credential in the repository or browser.

## App Check

App Check initializes before Authentication and Realtime Database:

```javascript
initializeAppCheck(firebaseApp, {
  provider: new ReCaptchaEnterpriseProvider(SITE_KEY),
  isTokenAutoRefreshEnabled: true
});
```

reCAPTCHA Enterprise performs an invisible risk assessment. Firebase exchanges the result for an App Check token, attaches it to SDK requests, and refreshes it automatically. Realtime Database enforcement rejects clients that cannot produce a valid token for the registered `edhtimer.com` application.

App Check identifies an authorized application environment. It does not identify a player or determine which room that player may control.

## Anonymous Authentication

Before creating or joining a shared room, `ensureFirebase()` calls `signInAnonymously()` when the browser does not already have a Firebase user:

```javascript
async function ensureFirebase() {
  if (!auth.currentUser) await signInAnonymously(auth);
}
```

Firebase assigns the browser an anonymous UID. No name, email address, or password is requested. Another browser, private session, or device receives a different UID. Version 1.0.0 uses the UID only to satisfy the `auth != null` database rule; it does not implement host or player ownership.

## Security Rules

`firebase-database-rules.json` denies access by default and permits authenticated access only beneath `/rooms`:

```json
{
  "rules": {
    ".read": false,
    ".write": false,
    "rooms": {
      "$room": {
        ".read": "auth != null",
        ".write": "auth != null"
      }
    }
  }
}
```

`$room` represents any room code. A client that passes App Check, signs in anonymously, and knows a valid room code may read and control that room. This matches the product decision that every participant may operate the timer. It is not host-only authorization.

## Room path and schema

Every shared game is stored at:

```text
/rooms/{ROOM_CODE}
```

Representative data:

```json
{
  "players": ["Thomas", "Sarah", "Mike", "Jenny"],
  "active": 1,
  "duration": 300,
  "remaining": 247.5,
  "running": true,
  "turn": 2,
  "useGameClock": true,
  "gameDuration": 3600,
  "gameRemaining": [3310, 3205, 3412, 3350],
  "eliminated": [false, false, false, false],
  "syncedAt": 1784419200000,
  "lastActive": 1784419200000,
  "expiresAt": 1784462400000
}
```

The room stores game state, not passwords, email addresses, IP addresses, or private Firebase credentials.

## Creating a room

When shared play is selected, the browser:

1. Signs in anonymously.
2. Generates a six-character uppercase room code.
3. Creates a reference to `rooms/{code}`.
4. Writes the complete initial state with `set()`.
5. Attaches an `onValue()` listener to that room.
6. Adds the room code to the shareable URL.

`set()` replaces the room snapshot atomically.

## Joining a room

The joining browser normalizes the supplied code, authenticates anonymously, and reads the exact room path once. Missing rooms produce a user-facing error. Expired rooms are deleted. Valid rooms receive a persistent `onValue()` listener.

Shared URLs use this form:

```text
https://edhtimer.com/?room=ABC123
```

The query parameter triggers automatic joining after Firebase initialization.

## Realtime synchronization

`watchRoom()` listens only to the active room:

```javascript
onValue(activeRoomRef, snapshot => {
  if (snapshot.exists()) applyRemote(snapshot.val());
});
```

Firebase sends a new snapshot whenever any participant changes the room. `applyRemote()` replaces local state, corrects elapsed time, rerenders the player controls, and updates the floating timer.

The application does not write every animation frame. The browser animates the countdown locally and synchronizes only meaningful actions:

- Pause or resume
- Next player
- Direct player selection
- Add one minute
- Automatic player elimination
- Initial room creation

This keeps Realtime Database traffic low.

## Clock correction

Every synchronized state includes `syncedAt`. Clients also subscribe to Firebase's special `.info/serverTimeOffset` value. On receipt or reconnection, a browser calculates:

```text
Firebase-adjusted current time - syncedAt
```

It subtracts the result from the stored turn and elimination clocks. Devices therefore converge on the same displayed time even when their system clocks differ or a connection was temporarily unavailable.

## Reconnection

The Firebase SDK reconnects automatically after temporary network loss. When `onValue()` receives the latest state, timestamp correction accounts for the disconnected interval. A running clock does not rely on one browser continuously writing timer ticks.

## Room expiration and deletion

Each action refreshes:

```text
lastActive = current Firebase-adjusted time
expiresAt  = lastActive + 12 hours
```

An open main timer or bookmarklet schedules deletion at `expiresAt`. Attempting to join an already expired room also deletes it. The **End shared game** control calls `remove(activeRoomRef)` after confirmation.

Every connected listener sees the room disappear. Main timer clients return to setup, and bookmarklet controls become unavailable.

If every client closes before expiration, the dormant database record consumes minimal storage and is deleted if its code is visited after expiration. Version 1.0.0 does not run a privileged server-side cleanup job.

## Bookmarklet overlay

The bookmarklet injects an iframe like:

```text
https://edhtimer.com/overlay.html?room=ABC123
```

Because the iframe is served by `edhtimer.com`, it independently passes App Check, signs in anonymously, and subscribes to the same room. Its pause and next-player controls write the same state schema as the main application.

## PWA and service worker

The service worker caches the same-origin application shell for installation and graceful offline launch. It does not proxy, cache, or replace Firebase Authentication, App Check, or Realtime Database connections. Those remain live network services.

## Security boundary in version 1.0.0

Effective room authorization is:

```text
valid App Check token
+ anonymous Firebase user
+ knowledge of the room code
= permission to view and control the room
```

Possible future hardening includes longer room codes, strict schema validation in database rules, player membership records, and host-only administrative actions.
