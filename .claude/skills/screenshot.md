# Screenshot

Take mobile/tablet/desktop screenshots of the running Lamb dev site and display them inline.

## Steps

1. Parse args (format: `[/path] [outdir]`). Default path: `/`. Default outdir: `/tmp/shots`.

2. Ensure the dev server is running on port 8747:
   ```bash
   lsof -ti:8747 || (php -S 0.0.0.0:8747 -t src >> /tmp/lamb-server.log 2>&1 & sleep 1)
   ```

3. Run the screenshot script:
   ```bash
   cd /home/user/lamb && PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/root/.cache/ms-playwright/chromium-1194/chrome-linux/chrome pnpm exec node scripts/screenshot.mjs <path> <outdir>
   ```

4. Read and display each output PNG using the Read tool:
   - `<outdir>/mobile.png`
   - `<outdir>/tablet.png`
   - `<outdir>/desktop.png`

## Before/After Comparison

When the user asks for a before/after comparison:
1. `git stash` → shoot to `<outdir>/before/` → `git stash pop` → shoot to `<outdir>/after/`
2. Display before and after images side by side (before first, then after, for each viewport).
