import { chromium } from 'playwright';
const FPS = 30;
const b = await chromium.launch();
const p = await b.newPage({ viewport: { width: 1920, height: 1080 } });
await p.goto('http://127.0.0.1:8790/tools/reel/reel.html'); await p.evaluate(() => window.ready); await p.waitForTimeout(300);
const dur = await p.evaluate(() => DURATION);
const n = Math.round(dur * FPS);
for (let f = 0; f < n; f++) {
  await p.evaluate(t => seek(t), f / FPS);
  await p.screenshot({ path: `frames/f${String(f).padStart(4, '0')}.jpg`, type: 'jpeg', quality: 95 });
}
console.log('frames', n); await b.close();
