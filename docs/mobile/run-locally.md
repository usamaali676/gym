# Running it on your own machine (Windows + Laragon)

**Status: written, not run on Windows by the author.** If a step fails, send the last lines of its output.

## 1. The website and the API

```powershell
cd C:\laragon\www\gymApp
powershell -ExecutionPolicy Bypass -File scripts\windows-local-setup.ps1
```

(Use Laragon's *Terminal* so that `git`, `php`, `composer`, `node` and `npm` are on the PATH.) The script pulls the branch,
installs dependencies, creates `.env` and a SQLite database, migrates and seeds, builds the assets and serves on
`http://localhost:8000`. Add `-RenderClips` to also render and register the exercise demo clips (slow, needs ffmpeg).
The first member account is created by registering in the website or the app; the seeder also creates the demo data
(see the README for the admin account).

## 2. The mobile app

Install Flutter (3.47 or newer) and Android Studio with an emulator. Then:

```powershell
cd C:\laragon\www\gymApp\mobile
flutter pub get
flutter run
```

* **Android emulator:** nothing else to set; a debug build talks to `http://10.0.2.2:8000`, which is your PC's port 8000.
* **A real phone on your Wi-Fi:** `flutter run --dart-define=API_BASE_URL=http://<your PC's IP>:8000` (the script prints it) and
  allow PHP through the Windows firewall for private networks.
* **iOS** needs a Mac with Xcode; it cannot be built on Windows.
* **Windows desktop / Chrome:** the app targets Android and iOS only; use the emulator.

The camera form coach needs a real phone camera (an emulator's virtual camera is not meaningful for pose detection).
