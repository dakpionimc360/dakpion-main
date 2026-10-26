// Render tools/reel/reel.html frame by frame (see the comment at the top of reel.html).
//   node tools/reel/render.mjs            → frames/      1920×1080
//   node tools/reel/render.mjs portrait   → frames-portrait/ 1080×1350 (phones)
import { chromium } from 'playwright';
import { mkdirSync } from 'fs';
const FPS = 30, portrait = process.argv[2] === 'portrait';
const [w, h, dir] = portrait ? [1080, 1350, 'frames-portrait'] : [1920, 1080, 'frames'];
mkdirSync(dir, { recursive: true });
const b = await chromium.launch();
const p = await b.newPage({ viewport: { width: w, height: h } });
await p.goto('http://127.0.0.1:8790/tools/reel/reel.html' + (portrait ? '?portrait' : ''));
await p.evaluate(() => window.ready); await p.waitForTimeout(300);
const n = Math.round(await p.evaluate(() => DURATION) * FPS);
for (let f = 0; f < n; f++) {
  await p.evaluate(t => seek(t), f / FPS);
  await p.screenshot({ path: `${dir}/f${String(f).padStart(4, '0')}.jpg`, type: 'jpeg', quality: 95 });
}
console.log(dir, n, 'frames');
await b.close();
