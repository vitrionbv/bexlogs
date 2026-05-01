# BexLogs Authenticator (Browser Extension)

A small Chrome / Firefox extension whose only job is to capture
BookingExperts session cookies after you log in (using your normal browser,
including Microsoft SSO + MFA), and POST them to a BexLogs server.

## Why

BookingExperts has no public API for log data. The BexLogs server runs a
headless Playwright worker that scrapes the rendered logs page on your
behalf. That worker can't do interactive Microsoft SSO — so we capture
cookies in your real browser and reuse them in the worker's headless one.

## Installing (sideload)

### Chrome / Edge / Brave

1. Download `bexlogs-extension.zip` from the BexLogs **Authenticate** page.
2. Unzip it somewhere permanent (e.g. `~/bexlogs-extension/`).
3. Open `chrome://extensions`.
4. Toggle **Developer mode** (top right).
5. Click **Load unpacked** and select the unzipped folder.

### Firefox

1. Download and unzip as above.
2. Open `about:debugging#/runtime/this-firefox`.
3. Click **Load Temporary Add-on…** and pick `manifest.json` inside the
   unzipped folder.
4. _(Firefox forgets temporary extensions on restart. For permanent install,
   load through Firefox AMO once published.)_

## Using

1. Click the BexLogs icon in the toolbar.
2. Enter your **BexLogs server URL** (e.g. `https://bexlogs.example.com`).
   Saved between sessions.
3. Open the BexLogs **Authenticate** page in another tab; click
   _Generate pairing code_ and copy the code.
4. Paste it into the extension popup.
5. Pick **production** or **staging**.
6. Click **Open BookingExperts & capture cookies**.
7. Log in normally (Microsoft SSO is fine).
8. The extension reads your session cookies and POSTs them to BexLogs.
   You'll see a desktop notification when it's done.

## Building from source

This is plain JS, no bundler. To produce a distributable zip:

```bash
cd extension
./build.sh   # produces build/bexlogs-extension.zip
```
