# Mobile Android app

The mobile app is a Capacitor wrapper around the Laravel admin mobile flow.

## Local test on a phone

1. Install Node packages:

```powershell
npm install
```

2. Start Laravel on the local network. Replace `192.168.1.25` with your computer IPv4 address:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

3. For local testing, temporarily replace `server.url` in `capacitor.config.json` with your computer IP:

```json
"server": {
  "url": "http://192.168.1.25:8000",
  "cleartext": true
}
```

4. Create the Android project once:

```powershell
npm run mobile:add:android
```

5. Sync Capacitor:

```powershell
npm run mobile:sync
```

6. Open Android Studio:

```powershell
npm run mobile:open
```

From Android Studio, connect a phone with USB debugging enabled and press Run.

## Build APK

After syncing, build from Android Studio or run:

```powershell
npm run mobile:build:android
```

For a production app, set `server.url` in `capacitor.config.json` to the real HTTPS domain before syncing:

```json
"server": {
  "url": "https://sklad.nikolacars.kiev.ua",
  "cleartext": false
}
```

## Entry point

The app opens `/admin/mobile/parts`, so users can choose a donor and add parts with photos.
