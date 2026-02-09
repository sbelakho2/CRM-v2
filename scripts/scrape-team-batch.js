#!/usr/bin/env node
/**
 * Batch Playwright Team Page Scraper
 * 
 * Processes MULTIPLE company websites in a SINGLE browser session.
 * ~10x faster than launching a separate browser per company.
 * 
 * Usage: 
 *   echo '["https://example.com","https://other.com"]' | node scripts/scrape-team-batch.js
 *   node scripts/scrape-team-batch.js --urls='["https://example.com"]'
 * 
 * Input: JSON array of website URLs (via stdin or --urls= argument)
 * Output: JSON object mapping URL → scrape result (same format as scrape-team-page.js)
 */

const { chromium } = require('playwright');

// Only the most productive paths — cut from 18+ to 8
const TEAM_PAGE_PATHS = [
    '', // homepage
    '/about', '/about-us',
    '/team', '/our-team',
    '/leadership',
    '/management',
    '/contact',
];

const TEAM_CARD_SELECTORS = [
    '.team-member', '.team-card', '.staff-member', '.member-card',
    '.leadership-card', '.person-card', '.profile-card',
    '[class*="team-member"]', '[class*="team-card"]',
    '[class*="leadership"]', '[class*="person-card"]',
    '[class*="executive"]', '[class*="member-card"]',
    '.team', '.leadership', '.management-team',
];

const NAV_LINK_KEYWORDS = [
    'about', 'team', 'leadership', 'people', 'management', 'who we are',
];

const TIMEOUT = 10000; // 10s per page (reduced from 15s)
const MAX_PAGES_PER_SITE = 5; // reduced from 8
const CONCURRENCY = 3; // process 3 sites at once in parallel tabs

/**
 * Scrape a single site within an existing browser context
 */
async function scrapeSite(context, siteUrl) {
    const url = siteUrl.startsWith('http') ? siteUrl : 'https://' + siteUrl;
    let baseUrl;
    try {
        baseUrl = new URL(url).origin;
    } catch {
        return { error: 'Invalid URL', baseUrl: url, pagesScraped: 0, pages: [] };
    }

    const results = [];
    const visitedUrls = new Set();
    let pagesScraped = 0;

    // Phase 1: Discover team URLs from homepage nav
    const discoveredPaths = new Set(TEAM_PAGE_PATHS);
    try {
        const homePage = await context.newPage();
        try {
            await homePage.goto(baseUrl, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
            await homePage.waitForTimeout(500); // reduced from 1500ms

            const navLinks = await homePage.evaluate((keywords) => {
                const links = [];
                document.querySelectorAll('nav a, header a, .menu a, .nav a, [role="navigation"] a, .navbar a').forEach(a => {
                    const text = (a.textContent || '').toLowerCase().trim();
                    const href = a.getAttribute('href') || '';
                    for (const kw of keywords) {
                        if (text.includes(kw) || href.toLowerCase().includes(kw.replace(/\s+/g, '-'))) {
                            links.push(href);
                            break;
                        }
                    }
                });
                return links;
            }, NAV_LINK_KEYWORDS);

            for (const link of navLinks) {
                if (link && !link.startsWith('#') && !link.startsWith('mailto:') && !link.startsWith('tel:')) {
                    try {
                        const resolved = new URL(link, baseUrl);
                        if (resolved.origin === baseUrl) {
                            discoveredPaths.add(resolved.pathname);
                        }
                    } catch {}
                }
            }
        } finally {
            await homePage.close();
        }
    } catch {}

    // Phase 2: Visit each potential team page
    for (const path of discoveredPaths) {
        if (pagesScraped >= MAX_PAGES_PER_SITE) break;

        const pageUrl = baseUrl + path;
        const normalizedUrl = pageUrl.replace(/\/+$/, '').toLowerCase();
        if (visitedUrls.has(normalizedUrl)) continue;
        visitedUrls.add(normalizedUrl);

        const page = await context.newPage();
        try {
            const response = await page.goto(pageUrl, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
            if (!response || response.status() >= 400) continue;

            await page.waitForTimeout(800); // reduced from 2000ms

            // Dismiss cookie banners quickly
            try {
                const cookieBtn = await page.$('button:has-text("Accept"), button:has-text("I agree"), [class*="cookie"] button, [class*="consent"] button');
                if (cookieBtn) await cookieBtn.click().catch(() => {});
            } catch {}

            const pageData = await page.evaluate((teamSelectors) => {
                const data = {
                    url: window.location.href,
                    title: document.title,
                    contacts: [],
                    teamHtml: '',
                    linkedinUrls: [],
                    emails: [],
                    phones: [],
                    hasTeamContent: false,
                    jsonLd: [],
                };

                // JSON-LD
                document.querySelectorAll('script[type="application/ld+json"]').forEach(script => {
                    try { data.jsonLd.push(JSON.parse(script.textContent)); } catch {}
                });

                // Team sections
                let teamSections = [];
                for (const sel of teamSelectors) {
                    try {
                        document.querySelectorAll(sel).forEach(el => teamSections.push(el));
                    } catch {}
                }

                if (teamSections.length > 0) {
                    data.hasTeamContent = true;
                    const seen = new Set();
                    for (const el of teamSections) {
                        const parent = el.parentElement || el;
                        if (!seen.has(parent)) {
                            seen.add(parent);
                            data.teamHtml += parent.outerHTML + '\n';
                        }
                    }
                }

                if (!data.hasTeamContent) {
                    const contentAreas = document.querySelectorAll('main, article, .content, .page-content, #content, [role="main"]');
                    for (const area of contentAreas) {
                        const text = area.textContent.toLowerCase();
                        if (/(our team|leadership|management|executive|staff|founder|ceo|cto|director|president)/i.test(text)) {
                            data.hasTeamContent = true;
                            data.teamHtml = area.outerHTML;
                            break;
                        }
                    }
                }

                // LinkedIn URLs
                document.querySelectorAll('a[href*="linkedin.com/in/"]').forEach(a => {
                    const href = a.getAttribute('href');
                    if (href) data.linkedinUrls.push({ url: href, text: (a.textContent || '').trim() || null });
                });

                // Emails
                document.querySelectorAll('a[href^="mailto:"]').forEach(a => {
                    const email = a.getAttribute('href').replace('mailto:', '').split('?')[0].trim().toLowerCase();
                    if (email && email.includes('@')) data.emails.push(email);
                });

                // Phones
                document.querySelectorAll('a[href^="tel:"]').forEach(a => {
                    data.phones.push(a.getAttribute('href').replace('tel:', '').trim());
                });

                // DOM contact cards
                const cardSelectors = [
                    '.team-member', '.team-card', '.staff-member',
                    '.leadership-card', '.person-card', '.profile-card',
                    '.member-card', '.bio-card', '.executive-card',
                    '[class*="team-member"]', '[class*="team-card"]',
                    '[class*="member"]', '[class*="profile-card"]',
                ];
                const allCards = new Set();
                for (const sel of cardSelectors) {
                    try { document.querySelectorAll(sel).forEach(c => allCards.add(c)); } catch {}
                }

                for (const card of allCards) {
                    const contact = { first_name: null, last_name: null, job_title: null, email: null, phone: null, linkedin_url: null };
                    let name = null;
                    for (const sel of ['h2', 'h3', 'h4', 'h5', '.name', '[class*="name"]', 'strong']) {
                        const el = card.querySelector(sel);
                        if (el) {
                            const t = el.textContent.trim();
                            if (t.length > 2 && t.length < 60 && t.split(/\s+/).length >= 2) { name = t; break; }
                        }
                    }
                    if (!name) continue;
                    const nameParts = name.split(/\s+/);
                    contact.first_name = nameParts[0];
                    contact.last_name = nameParts.slice(1).join(' ');
                    for (const sel of ['.title', '.position', '.role', '.job-title', '[class*="title"]', '[class*="position"]', '[class*="role"]', 'p']) {
                        const el = card.querySelector(sel);
                        if (el) {
                            const t = el.textContent.trim();
                            if (t !== name && t.length > 2 && t.length < 100) { contact.job_title = t; break; }
                        }
                    }
                    const mailLink = card.querySelector('a[href^="mailto:"]');
                    if (mailLink) contact.email = mailLink.getAttribute('href').replace('mailto:', '').split('?')[0].trim().toLowerCase();
                    const telLink = card.querySelector('a[href^="tel:"]');
                    if (telLink) contact.phone = telLink.getAttribute('href').replace('tel:', '').trim();
                    const liLink = card.querySelector('a[href*="linkedin.com/in/"]');
                    if (liLink) contact.linkedin_url = liLink.getAttribute('href');
                    data.contacts.push(contact);
                }

                if (data.teamHtml.length > 50000) data.teamHtml = data.teamHtml.substring(0, 50000);
                return data;
            }, TEAM_CARD_SELECTORS);

            if (pageData.hasTeamContent || pageData.contacts.length > 0 || pageData.linkedinUrls.length > 0 || pageData.emails.length > 0) {
                results.push(pageData);
                pagesScraped++;
            }
        } catch {} finally {
            await page.close();
        }

        // Minimal delay between pages on same site
        await new Promise(r => setTimeout(r, 200));
    }

    return {
        baseUrl,
        pagesScraped,
        totalContacts: results.reduce((sum, r) => sum + r.contacts.length, 0),
        pages: results,
    };
}

async function main() {
    // Read URLs from --urls= arg or stdin
    let urls;
    const urlsArg = process.argv.find(a => a.startsWith('--urls='));
    if (urlsArg) {
        urls = JSON.parse(urlsArg.replace('--urls=', ''));
    } else {
        // Read from stdin
        const chunks = [];
        for await (const chunk of process.stdin) {
            chunks.push(chunk);
        }
        const input = Buffer.concat(chunks).toString().trim();
        if (!input) {
            console.error(JSON.stringify({ error: 'No URLs provided. Pass via stdin or --urls=\'[...]\'' }));
            process.exit(1);
        }
        urls = JSON.parse(input);
    }

    if (!Array.isArray(urls) || urls.length === 0) {
        console.error(JSON.stringify({ error: 'URLs must be a non-empty JSON array' }));
        process.exit(1);
    }

    let browser;
    try {
        browser = await chromium.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--disable-extensions',
                '--disable-background-networking',
            ],
        });

        const context = await browser.newContext({
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            viewport: { width: 1366, height: 768 },
            locale: 'en-US',
        });

        // Block heavy resources
        await context.route('**/*.{png,jpg,jpeg,gif,svg,webp,ico,woff,woff2,ttf,eot,mp4,mp3,avi}', route => route.abort());
        await context.route('**/*', (route) => {
            const resourceType = route.request().resourceType();
            if (['image', 'font', 'media', 'stylesheet'].includes(resourceType)) return route.abort();
            return route.continue();
        });

        const allResults = {};

        // Process URLs in batches of CONCURRENCY
        for (let i = 0; i < urls.length; i += CONCURRENCY) {
            const batch = urls.slice(i, i + CONCURRENCY);
            const promises = batch.map(url => 
                scrapeSite(context, url).catch(e => ({ 
                    error: e.message, baseUrl: url, pagesScraped: 0, pages: [] 
                }))
            );
            const batchResults = await Promise.all(promises);
            for (let j = 0; j < batch.length; j++) {
                allResults[batch[j]] = batchResults[j];
            }
            // Brief pause between batches
            if (i + CONCURRENCY < urls.length) {
                await new Promise(r => setTimeout(r, 300));
            }
        }

        await browser.close();
        console.log(JSON.stringify(allResults));

    } catch (e) {
        if (browser) await browser.close();
        console.error(JSON.stringify({ error: e.message }));
        process.exit(1);
    }
}

main();
