// server.js
const express = require('express');
const bodyParser = require('body-parser');
const puppeteer = require('puppeteer');
const fetch = require('node-fetch');

const app = express();
app.use(bodyParser.json({ limit: '5mb' }));

// Provider tokens to look for in network calls or URLs
const PROVIDER_TOKENS = [
  'player', 'embed', 'mp4upload', 'vap', 'yourupload', 'mp4', 'videas', 'vk.com', 'ok.ru',
  'mega', 'megacdn', 'mega.nz', 'megavideo', 'stream', 'cloud', 'vidmoly', 'yourupload',
  'mail.ru', 'sibnet', 'openload', 'dropapk', 'vidstream', 'gdrive', 'gvideo', 'gogoplay',
  'fastly', 'cdn', 'video', 'hqq', 'ok', 'vk', 'mp4', 'm3u8', '.m3u8'
];

function looksLikeProvider(url) {
  if (!url) return false;
  const low = url.toLowerCase();
  return PROVIDER_TOKENS.some(tok => low.includes(tok));
}

async function resolveFinalUrl(candidate, timeout = 15000) {
  // Try to follow redirects; prefer HEAD then GET fallback
  try {
    const resp = await fetch(candidate, { method: 'HEAD', redirect: 'follow', timeout });
    if (resp && resp.url) return resp.url;
  } catch (e) {
    // HEAD sometimes blocked -> try GET
    try {
      const resp2 = await fetch(candidate, { method: 'GET', redirect: 'follow', timeout });
      if (resp2 && resp2.url) return resp2.url;
    } catch (e2) {
      return candidate;
    }
  }
  return candidate;
}

app.post('/scrape', async (req, res) => {
  const { url, waitForSelector, timeout = 25000 } = req.body || {};
  if (!url) return res.status(400).json({ error: 'missing url' });

  let browser;
  const results = [];
  const seenUrls = new Set();

  try {
    browser = await puppeteer.launch({
      args: ['--no-sandbox', '--disable-setuid-sandbox'],
      headless: true,
    });
    const page = await browser.newPage();

    // Block images/fonts can speed up
    await page.setRequestInterception(true);
    page.on('request', reqt => {
      const resourceType = reqt.resourceType();
      if (resourceType === 'image' || resourceType === 'font' || resourceType === 'stylesheet') {
        return reqt.abort();
      }
      reqt.continue();
    });

    // Collect network requests/responses that look like embeds
    const networkCandidates = new Set();

    page.on('request', r => {
      const u = r.url();
      if (looksLikeProvider(u)) networkCandidates.add(u);
    });

    page.on('response', async response => {
      try {
        const req = response.request();
        const urlResp = response.url();
        if (looksLikeProvider(urlResp)) networkCandidates.add(urlResp);

        // If response is JSON and possibly contains embed url fields, try parse
        const ct = response.headers()['content-type'] || '';
        if (ct.includes('application/json')) {
          const txt = await response.text();
          // try to find urls in json
          const matches = txt.match(/https?:\/\/[^\s"'}]+/g);
          if (matches) {
            matches.forEach(m => {
              if (looksLikeProvider(m)) networkCandidates.add(m);
            });
          }
        }
      } catch (err) {
        // ignore
      }
    });

    // Load page
    await page.goto(url, { waitUntil: 'networkidle2', timeout });

    // Optionally wait for a selector if caller provides
    if (waitForSelector) {
      try {
        await page.waitForSelector(waitForSelector, { timeout: 5000 });
      } catch (e) {
        // not fatal
      }
    }

    // Give extra time for dynamic embeds (small delay)
    await page.waitForTimeout(700);

    // Collect iframe srcs from DOM
    const iframeSrcs = await page.evaluate(() => {
      const arr = [];
      document.querySelectorAll('iframe').forEach(f => {
        const src = f.getAttribute('src') || f.getAttribute('data-src') || f.src;
        if (src) arr.push({src: src, title: f.getAttribute('title') || '', id: f.id || ''});
      });
      return arr;
    });

    // Add the iframe srcs to candidates
    for (const it of iframeSrcs) {
      if (it.src && !seenUrls.has(it.src)) {
        seenUrls.add(it.src);
        results.push({
          method: 'iframe',
          src: it.src,
          title: it.title || '',
        });
      }
    }

    // Add network candidates
    for (const u of networkCandidates) {
      if (!seenUrls.has(u)) {
        seenUrls.add(u);
        results.push({
          method: 'network',
          src: u,
        });
      }
    }

    // Also attempt to read server list names from page if present (helpful for mapping)
    const serverNames = await page.evaluate(() => {
      const servers = [];
      // common site patterns: list of buttons or anchors with server names
      document.querySelectorAll('a, button, li, span').forEach(el => {
        const text = (el.textContent || '').trim();
        const cls = (el.className || '').toString();
        const href = el.getAttribute ? el.getAttribute('href') : null;
        if (text.length && text.length < 30) {
          // heuristic: Arabic or short tokens may be server label
          if (/vk|ok|videa|mp4upload|yourupload|megaupload|mega|stream|vidmoly|videas|sibnet|upload/i.test(text)) {
            servers.push({label: text, href: href || ''});
          }
        }
        // also look for data-server attributes
        if (el.dataset && el.dataset.server) {
          servers.push({label: el.dataset.server, href: href || ''});
        }
      });
      return servers;
    });

    // Try resolving final URLs (follow redirects) for candidates
    const resolved = [];
    for (const r of results) {
      // optionally skip data: URIs
      if (!r.src || r.src.startsWith('data:')) continue;
      let candidate = r.src;
      // make absolute if protocol-relative
      if (candidate.startsWith('//')) candidate = 'https:' + candidate;
      if (!candidate.startsWith('http')) {
        // try to build absolute relative to origin
        try {
          const base = new URL(url);
          candidate = new URL(candidate, base).toString();
        } catch (e) {
          // skip if cannot resolve
        }
      }
      let final = candidate;
      try {
        final = await resolveFinalUrl(candidate, 10000);
      } catch (e) {
        final = candidate;
      }
      resolved.push({
        method: r.method,
        src: candidate,
        final: final,
        title: r.title || '',
      });
    }

    await browser.close();
    return res.json({ url, servers: serverNames, embeds: resolved });
  } catch (err) {
    if (browser) try { await browser.close(); } catch(e){}
    console.error('scrape error:', err);
    return res.status(500).json({ error: String(err) });
  }
});

const PORT = process.env.PORT || 3001;
app.listen(PORT, () => {
  console.log(`Scraper service listening on ${PORT}`);
});
