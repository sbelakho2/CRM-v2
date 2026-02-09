#!/usr/bin/env node
/**
 * Playwright Team Page Scraper
 * 
 * Renders JS-heavy pages and extracts team/leadership/about content.
 * Called from PHP via exec() with JSON output to stdout.
 * 
 * Usage: node scripts/scrape-team-page.js <url> [--pages=about,team,leadership]
 * 
 * Output: JSON array of scraped pages with raw HTML of relevant sections,
 *         plus any contacts found via DOM analysis.
 */

const { chromium } = require('playwright');

const TEAM_PAGE_PATHS = [
    '', // homepage
    '/about', '/about-us', '/about/',
    '/team', '/our-team', '/team/',
    '/leadership', '/leadership-team', '/leadership/',
    '/management', '/management-team',
    '/people', '/our-people',
    '/company', '/company/team',
    '/who-we-are',
    '/staff', '/our-staff',
    '/executives', '/board',
    '/contact', '/contact-us',
];

// CSS selectors that commonly contain team member cards
const TEAM_CARD_SELECTORS = [
    '.team-member', '.team-card', '.staff-member', '.member-card',
    '.leadership-card', '.leader-card', '.person-card', '.people-card',
    '.executive-card', '.bio-card', '.profile-card',
    '[class*="team-member"]', '[class*="team-card"]',
    '[class*="leadership"]', '[class*="staff-member"]',
    '[class*="people-card"]', '[class*="executive"]',
    '[class*="person-card"]', '[class*="bio-card"]',
    '[class*="profile-card"]', '[class*="member-card"]',
    // Broader: sections containing team info
    '.team', '.leadership', '.management-team',
    '[class*="team-section"]', '[class*="leadership-section"]',
];

// Selectors for navigation links that lead to team pages
const NAV_LINK_KEYWORDS = [
    'about', 'team', 'leadership', 'people', 'company',
    'management', 'who we are', 'our team', 'staff', 'executives',
];

const TIMEOUT = 15000; // 15s per page
const MAX_PAGES = 8;

async function main() {
    const url = process.argv[2];
    if (!url) {
        console.error(JSON.stringify({ error: 'Usage: node scrape-team-page.js <url>' }));
        process.exit(1);
    }

    const customPaths = process.argv.find(a => a.startsWith('--pages='));
    const pathsToTry = customPaths
        ? customPaths.replace('--pages=', '').split(',').map(p => '/' + p.replace(/^\//, ''))
        : TEAM_PAGE_PATHS;

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

        // Block images, fonts, media to speed up scraping
        await context.route('**/*.{png,jpg,jpeg,gif,svg,webp,ico,woff,woff2,ttf,eot,mp4,mp3,avi}', route => route.abort());
        await context.route('**/*', (route) => {
            const resourceType = route.request().resourceType();
            if (['image', 'font', 'media', 'stylesheet'].includes(resourceType)) {
                return route.abort();
            }
            return route.continue();
        });

        const baseUrl = new URL(url).origin;
        const results = [];
        const visitedUrls = new Set();
        let pagesScraped = 0;

        // Phase 1: Discover team page URLs from homepage navigation
        const discoveredPaths = new Set(pathsToTry);
        try {
            const homePage = await context.newPage();
            await homePage.goto(baseUrl, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
            await homePage.waitForTimeout(1500); // Let JS render
            
            // Find nav links that might lead to team pages
            const navLinks = await homePage.evaluate((keywords) => {
                const links = [];
                document.querySelectorAll('nav a, header a, .menu a, .nav a, [role="navigation"] a, .navbar a, footer a').forEach(a => {
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
            await homePage.close();
        } catch (e) {
            // Homepage navigation discovery failed, continue with defaults
        }

        // Phase 2: Visit each potential team page and extract content
        for (const path of discoveredPaths) {
            if (pagesScraped >= MAX_PAGES) break;

            const pageUrl = baseUrl + path;
            const normalizedUrl = pageUrl.replace(/\/+$/, '').toLowerCase();
            if (visitedUrls.has(normalizedUrl)) continue;
            visitedUrls.add(normalizedUrl);

            const page = await context.newPage();
            try {
                const response = await page.goto(pageUrl, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
                if (!response || response.status() >= 400) {
                    await page.close();
                    continue;
                }

                // Wait for dynamic content
                await page.waitForTimeout(2000);

                // Try to dismiss cookie banners
                try {
                    const cookieBtn = await page.$('button:has-text("Accept"), button:has-text("I agree"), button:has-text("OK"), [class*="cookie"] button, [class*="consent"] button');
                    if (cookieBtn) await cookieBtn.click().catch(() => {});
                    await page.waitForTimeout(500);
                } catch {}

                // Extract structured contacts and relevant HTML
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
                    };

                    // 1. Extract JSON-LD
                    const jsonLdScripts = document.querySelectorAll('script[type="application/ld+json"]');
                    const jsonLdData = [];
                    jsonLdScripts.forEach(script => {
                        try {
                            jsonLdData.push(JSON.parse(script.textContent));
                        } catch {}
                    });
                    data.jsonLd = jsonLdData;

                    // 2. Find team sections and extract their HTML
                    let teamSections = [];
                    for (const sel of teamSelectors) {
                        try {
                            const els = document.querySelectorAll(sel);
                            els.forEach(el => teamSections.push(el));
                        } catch {}
                    }

                    if (teamSections.length > 0) {
                        data.hasTeamContent = true;
                        // Get outerHTML of unique parent containers (avoid duplicates)
                        const seen = new Set();
                        for (const el of teamSections) {
                            const parent = el.parentElement || el;
                            if (!seen.has(parent)) {
                                seen.add(parent);
                                data.teamHtml += parent.outerHTML + '\n';
                            }
                        }
                    }

                    // 3. If no team sections found, look for common content areas
                    if (!data.hasTeamContent) {
                        const contentAreas = document.querySelectorAll('main, article, .content, .page-content, #content, [role="main"]');
                        for (const area of contentAreas) {
                            const text = area.textContent.toLowerCase();
                            // Check if content area mentions team-related keywords
                            if (/(our team|leadership|management|executive|staff|founder|ceo|cto|director|president)/i.test(text)) {
                                data.hasTeamContent = true;
                                data.teamHtml = area.outerHTML;
                                break;
                            }
                        }
                    }

                    // 4. Extract all LinkedIn profile URLs
                    document.querySelectorAll('a[href*="linkedin.com/in/"]').forEach(a => {
                        const href = a.getAttribute('href');
                        const text = a.textContent.trim();
                        if (href) {
                            data.linkedinUrls.push({ url: href, text: text || null });
                        }
                    });

                    // 5. Extract mailto emails
                    document.querySelectorAll('a[href^="mailto:"]').forEach(a => {
                        const email = a.getAttribute('href').replace('mailto:', '').split('?')[0].trim().toLowerCase();
                        if (email && email.includes('@')) {
                            data.emails.push(email);
                        }
                    });

                    // 6. Extract tel: phones
                    document.querySelectorAll('a[href^="tel:"]').forEach(a => {
                        const phone = a.getAttribute('href').replace('tel:', '').trim();
                        if (phone) {
                            data.phones.push(phone);
                        }
                    });

                    // 7. DOM-based contact extraction from cards
                    const cardSelectors = [
                        '.team-member', '.team-card', '.staff-member',
                        '.leadership-card', '.person-card', '.profile-card',
                        '.member-card', '.bio-card', '.executive-card',
                        '[class*="team-member"]', '[class*="team-card"]',
                        '[class*="leadership-card"]', '[class*="person-card"]',
                        '[class*="member"]', '[class*="profile-card"]',
                    ];

                    const allCards = new Set();
                    for (const sel of cardSelectors) {
                        try {
                            document.querySelectorAll(sel).forEach(c => allCards.add(c));
                        } catch {}
                    }

                    for (const card of allCards) {
                        const contact = { first_name: null, last_name: null, job_title: null, email: null, phone: null, linkedin_url: null };

                        // Name from headings or .name class
                        let name = null;
                        for (const sel of ['h2', 'h3', 'h4', 'h5', '.name', '[class*="name"]', 'strong']) {
                            const el = card.querySelector(sel);
                            if (el) {
                                const t = el.textContent.trim();
                                if (t.length > 2 && t.length < 60 && t.split(/\s+/).length >= 2) {
                                    name = t;
                                    break;
                                }
                            }
                        }
                        if (!name) continue;

                        const nameParts = name.split(/\s+/);
                        contact.first_name = nameParts[0];
                        contact.last_name = nameParts.slice(1).join(' ');

                        // Title
                        for (const sel of ['.title', '.position', '.role', '.job-title', '[class*="title"]', '[class*="position"]', '[class*="role"]', 'p']) {
                            const el = card.querySelector(sel);
                            if (el) {
                                const t = el.textContent.trim();
                                if (t !== name && t.length > 2 && t.length < 100) {
                                    contact.job_title = t;
                                    break;
                                }
                            }
                        }

                        // Email
                        const mailLink = card.querySelector('a[href^="mailto:"]');
                        if (mailLink) {
                            contact.email = mailLink.getAttribute('href').replace('mailto:', '').split('?')[0].trim().toLowerCase();
                        }

                        // Phone
                        const telLink = card.querySelector('a[href^="tel:"]');
                        if (telLink) {
                            contact.phone = telLink.getAttribute('href').replace('tel:', '').trim();
                        }

                        // LinkedIn
                        const liLink = card.querySelector('a[href*="linkedin.com/in/"]');
                        if (liLink) {
                            contact.linkedin_url = liLink.getAttribute('href');
                        }

                        data.contacts.push(contact);
                    }

                    // Truncate teamHtml to avoid huge payloads (max 50KB)
                    if (data.teamHtml.length > 50000) {
                        data.teamHtml = data.teamHtml.substring(0, 50000);
                    }

                    return data;
                }, TEAM_CARD_SELECTORS);

                if (pageData.hasTeamContent || pageData.contacts.length > 0 || pageData.linkedinUrls.length > 0 || pageData.emails.length > 0) {
                    results.push(pageData);
                    pagesScraped++;
                }

            } catch (e) {
                // Page failed, continue to next
            } finally {
                await page.close();
            }

            // Small delay between pages
            await new Promise(r => setTimeout(r, 800 + Math.random() * 400));
        }

        await browser.close();

        // Output results as JSON
        console.log(JSON.stringify({
            baseUrl,
            pagesScraped,
            totalContacts: results.reduce((sum, r) => sum + r.contacts.length, 0),
            pages: results,
        }));

    } catch (e) {
        if (browser) await browser.close();
        console.error(JSON.stringify({ error: e.message }));
        process.exit(1);
    }
}

main();
