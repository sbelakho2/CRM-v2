const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const metadataPath = path.join(ROOT, 'presentation_screenshots', 'metadata.json');
const outputPath = path.join(ROOT, 'CRM_V2_BEGINNER_PRESENTATION_SLIDES.html');

function esc(str) {
  return String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function oneLine(str) {
  return String(str || '').replace(/\s+/g, ' ').trim();
}

function hashText(str) {
  const text = String(str || '');
  let hash = 0;
  for (let i = 0; i < text.length; i += 1) {
    hash = (hash * 31 + text.charCodeAt(i)) >>> 0;
  }
  return hash;
}

function pickVariant(seed, variants) {
  if (!Array.isArray(variants) || variants.length === 0) return '';
  const idx = hashText(seed) % variants.length;
  return variants[idx];
}

function takeSentences(text, count = 1) {
  const value = oneLine(text);
  if (!value) return '';
  const parts = value.match(/[^.!?]+[.!?]?/g) || [value];
  return parts.slice(0, count).join(' ').replace(/\s{2,}/g, ' ').trim();
}

function getResource(routePath) {
  const normalized = String(routePath || '').trim();
  if (normalized === '/' || normalized === '') return 'dashboard';
  return normalized.replace(/^\//, '').split('/')[0] || 'dashboard';
}

function getPageMode(routePath = '', routeName = '') {
  const p = String(routePath);
  const n = String(routeName || '');

  if (p === '/' || p.endsWith('/dashboard') || /_dashboard$/.test(n)) return 'dashboard';
  if (p.endsWith('/new')) return 'create';
  if (p.endsWith('/edit')) return 'edit';
  if (p.endsWith('/pipeline')) return 'pipeline';
  if (p.includes('/analytics')) return 'analytics';
  if (p.includes('/review')) return 'review';
  if (p.includes('/discovered')) return 'discovered';
  if (p.includes('/kanban')) return 'kanban';
  if (p.includes('/my-day')) return 'myday';
  if (p.includes('/timeline')) return 'timeline';
  if (p.includes('/search')) return 'search';
  if (p.includes('/generate')) return 'generate';
  if (p.includes('/dork-generator')) return 'dork';
  if (p.includes('/keyword-expansion')) return 'expansion';
  if (p === '/login' || p === '/register' || p === '/forgot-password') return 'auth';
  if (/\/(\d+|\{id\}|\{token\})$/.test(p)) return 'detail';
  return 'index';
}

const MODULE_PROFILES = {
  dashboard: {
    name: 'Executive Dashboard',
    purpose: 'The morning cockpit — one screen that shows whether sales, marketing, and operations are healthy today.',
    entity: 'Cross-module KPIs',
    doc: 'SYSTEM_OVERVIEW + GUIDANCE_NOTIFICATION_SYSTEM'
  },
  companies: {
    name: 'Company Management',
    purpose: 'The home for every buyer account — who they are, where they are in the journey, and what we know about them.',
    entity: 'Company',
    doc: 'SYSTEM_OVERVIEW + LEADS_TO_COMPANIES_GUIDE'
  },
  contacts: {
    name: 'Contact Management',
    purpose: 'The people side of each account: procurement, engineering, and decision-makers, with how strong the relationship is.',
    entity: 'Contact',
    doc: 'SYSTEM_OVERVIEW'
  },
  leads: {
    name: 'Lead Operations',
    purpose: 'Where freshly discovered companies are reviewed, approved or denied, and promoted into the real CRM.',
    entity: 'Lead',
    doc: 'WEBCRAWLER_README + LEADS_TO_COMPANIES_GUIDE'
  },
  rfqs: {
    name: 'RFQ Pipeline',
    purpose: 'The heart of commercial work — opportunities move through clear stages from first request to final outcome.',
    entity: 'RFQ',
    doc: 'SYSTEM_OVERVIEW'
  },
  'email-campaigns': {
    name: 'Email Campaigns',
    purpose: 'Plan outreach sequences, send them, and watch how recipients respond — all in one place.',
    entity: 'EmailCampaign + EmailSend',
    doc: 'EMAIL_CAMPAIGN_QUICK_REFERENCE'
  },
  'abm-dashboard': {
    name: 'ABM Workspace',
    purpose: 'Pick the handful of accounts that really matter and coordinate the plays that win them.',
    entity: 'ABM Account/Playbook',
    doc: 'SYSTEM_OVERVIEW + ABM docs'
  },
  webcrawler: {
    name: 'Webcrawler Discovery',
    purpose: 'Find new high-fit companies on the open web and score them by how well they match our ideal customer.',
    entity: 'Discovered company/contact candidates',
    doc: 'WEBCRAWLER_README + LEADBOT_INTEGRATION'
  },
  'lead-discovery': {
    name: 'Lead Discovery Queue',
    purpose: 'A staging area to sort through new leads before they ever touch your real account or contact list.',
    entity: 'Lead queue',
    doc: 'WEBCRAWLER_README'
  },
  'command-center': {
    name: 'Command Center',
    purpose: 'The live nerve center — alerts, suggested actions, and metrics from across the platform in one view.',
    entity: 'Cross-module operational signals',
    doc: 'SYSTEM_OVERVIEW'
  },
  reports: {
    name: 'Report Builder',
    purpose: 'Build the reports you keep coming back to — pipeline, performance, and pacing — once, and reuse them.',
    entity: 'Report definitions + exports',
    doc: 'SYSTEM_OVERVIEW'
  },
  calendar: {
    name: 'Calendar Planning',
    purpose: 'Schedule the events that drive the sales motion and see them next to the pipeline they support.',
    entity: 'Calendar events',
    doc: 'SYSTEM_OVERVIEW'
  },
  meetings: {
    name: 'Meeting Scheduler',
    purpose: 'Share booking links, surface real availability, and convert interest into confirmed meetings.',
    entity: 'Meeting',
    doc: 'SYSTEM_OVERVIEW'
  },
  admin: {
    name: 'Administration',
    purpose: 'Behind-the-scenes control: users, custom fields, datasets, and the audit trail of every change.',
    entity: 'Users/Datasets/Fields/Audit records',
    doc: 'SYSTEM_OVERVIEW + deployment docs'
  },
  profile: {
    name: 'User Profile',
    purpose: 'Personal settings — how you appear in the system and how the system behaves for you.',
    entity: 'User profile',
    doc: 'NEW_USER_GUIDE'
  },
  activities: {
    name: 'Activity Tracking',
    purpose: 'A running log of every call, email, and meeting so account history is never lost in someone\u2019s inbox.',
    entity: 'Activity',
    doc: 'SYSTEM_OVERVIEW + GUIDANCE_NOTIFICATION_SYSTEM'
  },
  tasks: {
    name: 'Task Execution',
    purpose: 'Where intent becomes work — plan, assign, and close the things people actually need to do today.',
    entity: 'Task',
    doc: 'SYSTEM_OVERVIEW'
  },
  playbooks: {
    name: 'ABM Playbooks',
    purpose: 'Reusable engagement recipes for the accounts and segments you target most often.',
    entity: 'Playbook',
    doc: '04-ABM-Automation docs'
  },
  'quote-copilot': {
    name: 'Quote CoPilot',
    purpose: 'A guided helper that walks you through pricing and sourcing decisions on each quote.',
    entity: 'Quote + BOM insights',
    doc: 'Quote controller/service implementation'
  },
  'quote-review': {
    name: 'Quote Review',
    purpose: 'Final commercial check on each quote before it goes to the customer — numbers, terms, readiness.',
    entity: 'Quote review records',
    doc: 'Quote review workflow implementation'
  },
  login: {
    name: 'Authentication',
    purpose: 'The front door — confirms who you are before any business screen opens.',
    entity: 'Session + user credentials',
    doc: 'SecurityController + FORGOT_PASSWORD_FEATURE'
  },
  register: {
    name: 'Authentication',
    purpose: 'Onboards a new user and gives them only the access their role actually needs.',
    entity: 'User account records',
    doc: 'RegistrationController'
  },
  'forgot-password': {
    name: 'Authentication Recovery',
    purpose: 'A safe way to recover access — it never reveals whether an email is registered or not.',
    entity: 'Password reset token workflow',
    doc: 'FORGOT_PASSWORD_FEATURE + SecurityController'
  },
  compliance: {
    name: 'Compliance Tracking',
    purpose: 'Keeps track of required documents — what is on file, what is missing, and what is about to expire.',
    entity: 'Compliance document requirements',
    doc: 'SYSTEM_OVERVIEW + GUIDANCE_NOTIFICATION_SYSTEM'
  },
  'supplier-portal': {
    name: 'Supplier Portal',
    purpose: 'Handles supplier sign-up and tracks how actively they engage with us as a buyer.',
    entity: 'Supplier portal records',
    doc: 'SYSTEM_OVERVIEW'
  },
  'comp-crawler': {
    name: 'Competitive Crawler',
    purpose: 'Watches competitor websites for changes and feeds the team useful market intelligence.',
    entity: 'Competitor pages and signals',
    doc: 'LEADBOT/WEBCRAWLER docs'
  },
  'autonomous-sales': {
    name: 'Autonomous Sales',
    purpose: 'The control room for automated outreach — see what is running, what is working, and what to pause.',
    entity: 'Autonomous orchestration state',
    doc: 'AUTONOMOUS_SALES_V2'
  },
  guidance: {
    name: 'Guidance Notifications',
    purpose: 'Smart nudges that appear after big actions to remind you of the next best step.',
    entity: 'Session guidance events',
    doc: 'GUIDANCE_NOTIFICATION_SYSTEM'
  },
  webinars: {
    name: 'Webinar Operations',
    purpose: 'Plan webinars, capture who registered, and route their interest into proper follow-up.',
    entity: 'Webinar + registrations',
    doc: 'SYSTEM_OVERVIEW'
  },
  'currency-converter': {
    name: 'Currency Converter',
    purpose: 'Keeps every quote and report in comparable currency so cross-region comparisons stay honest.',
    entity: 'FX conversion records',
    doc: 'Controller + service implementation'
  },
  'discovery-pipeline': {
    name: 'Discovery Pipeline',
    purpose: 'Shows how new prospects flow from first discovery all the way to qualified opportunity.',
    entity: 'Discovery pipeline stages',
    doc: 'WEBCRAWLER_README'
  },
  
};

function getModuleProfile(routePath) {
  const resource = getResource(routePath);
  if (MODULE_PROFILES[resource]) return MODULE_PROFILES[resource];
  if (routePath === '/login') return MODULE_PROFILES.login;
  if (routePath === '/register') return MODULE_PROFILES.register;
  return {
    name: 'Core CRM Navigation',
    purpose: 'A working screen in the standard customer lifecycle — nothing exotic, just everyday CRM execution.',
    entity: 'Contextual module data',
    doc: 'SYSTEM_OVERVIEW'
  };
}

function detectIntent(item) {
  const routePath = String(item.routePath || '').toLowerCase();
  const routeName = String(item.routeName || '').toLowerCase();
  const heading = String(item.heading || '').toLowerCase();
  const subtitle = String(item.subtitle || '').toLowerCase();
  const haystack = `${routePath} ${routeName} ${heading} ${subtitle}`;

  const routeOverrides = [
    {
      test: /^\/playbooks\/?|^\/abm-dashboard\/playbooks/,
      detail: 'This is where the team designs repeatable plays for target accounts and decides what gets automated versus what needs a human touch.',
      action: 'Set the boundaries of the play — audience, cadence, exit rules — and watch quality signals as it runs.',
      outcome: 'Lets us scale outreach to many accounts without losing the personal feel that wins them.'
    },
    {
      test: /^\/quote-copilot|^\/quote-review|^\/rfqs/,
      detail: 'This is the commercial heart of the platform: turning an inquiry into a quote and a quote into a closed deal.',
      action: 'Look at price, timing, and risk together before you advance the deal to the next stage.',
      outcome: 'Quotes come out faster and convert more reliably because every step has been deliberately checked.'
    },
    {
      test: /^\/lead-discovery|^\/leads|^\/webcrawler|^\/discovery-pipeline/,
      detail: 'This is the entry point of the funnel — it decides which new prospects deserve real sales attention.',
      action: 'Read the evidence (fit score, source, signal) first, then approve, deny, or send back for enrichment.',
      outcome: 'The team spends time on better-fit leads and stops drowning in low-quality noise.'
    }
  ];

  for (const override of routeOverrides) {
    if (override.test.test(routePath)) {
      return override;
    }
  }

  const intents = [
    {
      test: /(^|\W)(quote|rfq|win-loss|interactive)(\W|$)/,
      detail: 'This screen keeps every active deal moving — from first inquiry through pricing to a clear win or loss.',
      action: 'Weigh deal value, timeline, and risk side-by-side before you change the stage.',
      outcome: 'Forecasts become more predictable because each move forward is backed by evidence.'
    },
    {
      test: /(^|\W)(lead|discovery|webcrawler|dork|keyword|crawler)(\W|$)/,
      detail: 'This page is the front door for new opportunities — it filters who is worth pursuing before they hit the real CRM.',
      action: 'Check the fit and freshness signals, then approve, deny, or enrich — do not just rubber-stamp.',
      outcome: 'Better leads enter the funnel and you waste fewer hours chasing the wrong companies.'
    },
    {
      test: /(^|\W)(campaign|email|track\/|open|click|replied|bounced)(\W|$)/,
      detail: 'This view runs outreach and shows you in real time how recipients are reacting to it.',
      action: 'Use opens, clicks, and replies to fine-tune who you target and how often you reach out.',
      outcome: 'Each campaign teaches you something concrete, so the next one performs measurably better.'
    },
    {
      test: /(^|\W)(task|kanban|my-day)(\W|$)/,
      detail: 'This screen turns plans into actual work — it shows who owes what and when.',
      action: 'Sort by urgency and owner load before assigning, so nothing important sits idle.',
      outcome: 'Follow-ups actually happen, and deals stop slipping because someone forgot.'
    },
    {
      test: /(^|\W)(meeting|calendar|slot|my-link)(\W|$)|\/book\//,
      detail: 'This page is where interest turns into a confirmed conversation with a clear owner and time.',
      action: 'Share booking links and protect selling time so meetings happen sooner with less back-and-forth.',
      outcome: 'More meetings get booked and the gap between “interested” and “on the calendar” shrinks.'
    },
    {
      test: /(^|\W)(compliance|audit|dataset|import|history)(\W|$)/,
      detail: 'This view protects the data trail — it shows what changed, who changed it, and what still needs attention.',
      action: 'Fix issues early and keep a clean record of every system change.',
      outcome: 'Audits go smoothly and the team can trust the numbers in every report.'
    },
    {
      test: /(^|\W)(command-center|alerts?|metrics?|analysis)(\W|$)/,
      detail: 'This page surfaces the few things leadership really needs to act on right now.',
      action: 'Clear the high-impact alerts first, then dig into the biggest blockers.',
      outcome: 'The team reacts to risk and opportunity faster instead of finding out a week late.'
    },
    {
      test: /(^|\W)(report|analytics|dashboard|overview|stats)(\W|$)/,
      detail: 'This view turns daily activity into numbers you can actually plan with.',
      action: 'Read the trend, not just today’s figure, before changing what the team focuses on.',
      outcome: 'Decisions get made on evidence instead of gut feel, and they hold up under questioning.'
    },
    {
      test: /(^|\W)(autonomous-sales|automation|playbooks?)(\W|$)/,
      detail: 'This page lets you run automated sales work while keeping the strategic decisions human.',
      action: 'Set clear guardrails and check the outputs for quality drift before scaling volume.',
      outcome: 'You get the reach of automation without the spammy feel that destroys trust.'
    },
    {
      test: /(^|\W)(company|contact|account|profile|supplier-portal)(\W|$)/,
      detail: 'This screen looks after the people-and-companies records that every other workflow depends on.',
      action: 'Keep the basics complete and current — owner, contact info, stage — before launching outreach.',
      outcome: 'Personalization works, handoffs are clean, and nobody is digging through email to figure out what we already know.'
    }
  ];

  for (const intent of intents) {
    if (intent.test.test(haystack)) {
      return intent;
    }
  }

  return {
    detail: 'This page is a working step in the standard customer journey — nothing exotic, just part of how the team keeps deals moving.',
    action: 'Use the main controls on the page to keep records and decisions in sync with reality.',
    outcome: 'Handoffs between teams stay clean because everyone is reading the same up-to-date picture.'
  };
}

function buildDeepNarrative(item, profile) {
  const routePath = String(item.routePath || '').toLowerCase();
  const routeName = String(item.routeName || '').toLowerCase();
  const mode = getPageMode(routePath, routeName);
  const resource = getResource(routePath);
  const heading = oneLine(item.heading || item.title || item.routeName || 'Page');
  const subtitle = oneLine(item.subtitle || '');
  const hasRealSubtitle = subtitle && subtitle.toLowerCase() !== 'subtitle';
  const subtitleClean = subtitle.replace(/[.!?]+$/g, '');
  const uiContext = hasRealSubtitle ? `What you see on screen: ${subtitleClean}.` : 'What you see on screen: a focused workspace for this part of the job.';
  const segments = routePath.replace(/^\//, '').split('/').filter(Boolean);
  const facet = segments[1] || '';

  const modeLens = {
    auth: 'sign-in and access gate',
    dashboard: 'big-picture decision view',
    index: 'main list and triage view',
    create: 'screen for adding new records',
    edit: 'screen for fixing and updating records',
    detail: 'single-record working view',
    review: 'approve or deny checkpoint',
    pipeline: 'stage-by-stage flow board',
    analytics: 'performance and learning view',
    discovered: 'pre-screening queue',
    kanban: 'visual board for moving work along',
    myday: 'today’s personal worklist',
    timeline: 'time-ordered history view',
    search: 'fast lookup workspace',
    generate: 'guided creation assistant',
    dork: 'search-query tuning workspace',
    expansion: 'keyword expansion workspace'
  };

  const fallback = {
    functionText: `${heading}: This page is the ${modeLens[mode] || 'working surface'} for ${profile.name}. ${uiContext}`,
    actions: [
      'Use the main controls on this screen to finish the current step without bouncing around the app.',
      'Double-check the data before saving — reports, automations, and handoffs all rely on what gets recorded here.',
      `Keep what you do aligned with the module’s purpose: ${profile.purpose}`
    ],
    outcomes: [
      'Saves the change (or filtered view) tied to this route and leaves a clear trail of what happened.',
      'Keeps execution sharp because everyone knows who owns the next step and where it sits in the lifecycle.',
      'Feeds cleaner data into dashboards, analytics, and downstream decisions.'
    ]
  };

  const facetHints = {
    alerts: 'This sub-view is specifically about working through alerts in priority order.',
    actions: 'This sub-view focuses on turning detected signals into clear next steps with an owner.',
    metrics: 'This sub-view is about reading the trend with confidence, not just staring at totals.',
    data: 'This sub-view is built for digging into the underlying records and drilling down.',
    leads: 'This sub-view is about deciding which leads are good enough to promote.',
    quotes: 'This sub-view is about whether each quote is ready, what the risk is, and when to act.',
    review: 'This sub-view is an explicit approve-or-deny moment — a real decision, not just a glance.',
    discovered: 'This sub-view is the pre-screening queue where we filter out low-quality noise.',
    pipeline: 'This sub-view is about how cleanly deals are moving stage to stage.',
    analytics: 'This sub-view exists so the team can use real numbers to decide what to change next.',
    history: 'This sub-view preserves the timeline of changes so we can audit or roll back with confidence.',
    import: 'This sub-view is a careful loading dock — validate first, then bring data in.',
    watchlists: 'This sub-view keeps a tight watch on the competitors and accounts that matter most.',
    search: 'This sub-view is tuned for finding the right record fast in a large dataset.'
  };

  const modeOverlays = {
    create: {
      functionAdd: 'It is the moment a new record is born — doing this well means less cleanup work later.',
      actionAdd: 'Fill in the required fields, set the owner, and confirm defaults before you save.',
      outcomeAdd: 'Creates a new record that will immediately show up in pipeline, reports, and automation.'
    },
    edit: {
      functionAdd: 'It is a place to fix and enrich what is already in the system, keeping active records honest.',
      actionAdd: 'Make the smallest change that fixes the truth, and check that linked records still make sense.',
      outcomeAdd: 'Updates the record so forecasts, dashboards, and teammates all see the same accurate picture.'
    },
    review: {
      functionAdd: 'It is a quality checkpoint — evidence in, decision out.',
      actionAdd: 'Write down the reason for each approve or deny so the pattern can be learned and improved.',
      outcomeAdd: 'Produces a traceable decision that feeds qualification scoring and conversion analytics.'
    },
    pipeline: {
      functionAdd: 'It is a flow board where speed and quality have to be managed together.',
      actionAdd: 'Clear the blockers in the current stage before pulling in more work from earlier stages.',
      outcomeAdd: 'Makes every stage move visible, so forecasts are easier to explain and trust.'
    },
    analytics: {
      functionAdd: 'It is the layer where the team turns performance signals into actual process changes.',
      actionAdd: 'Compare cohorts and time windows before changing anything — do not react to a single bad day.',
      outcomeAdd: 'Gives leadership evidence-backed direction for the next planning cycle.'
    },
    discovered: {
      functionAdd: 'It is a quality gate that keeps shaky new records from polluting the real CRM.',
      actionAdd: 'Only promote leads with clear fit evidence and an obvious next step.',
      outcomeAdd: 'Raises the quality of what enters the company, contact, and lead workflows.'
    },
    auth: {
      functionAdd: 'It is a security boundary — every business screen sits behind this check.',
      actionAdd: 'Treat every login or recovery response carefully and never reveal whether an account exists.',
      outcomeAdd: 'Keeps sessions secure and reduces abuse exposure across the whole platform.'
    }
  };

  const ruleTable = [
    {
      test: /^\/$/,
      data: {
        functionText: `${heading}: The morning starting point — it pulls pipeline health, reminders, and conversion trends together so leaders know where to focus first. ${uiContext}`,
        actions: [
          'Scan pending lead approvals, at-risk RFQs, and the task backlog before deciding what the team should tackle today.',
          'Compare today’s KPIs to last week or last month — the gap tells you whether to push discovery, outreach, or quote closure.',
          'Use this view to assign real next steps instead of just observing the numbers.'
        ],
        outcomes: [
          'No new records are created here — the value is in seeing every module on one screen so priorities are obvious.',
          'Leadership reacts faster when conversion or activity drifts off target.',
          'Sales, marketing, and operations all start the day from the same shared picture.'
        ]
      }
    },
    {
      test: /^\/abm-dashboard/,
      data: {
        functionText: `${heading}: The ABM control room — pick the accounts that really matter, orchestrate the plays that win them, and watch progress against the strategic target list. ${uiContext}`,
        actions: [
          'Rank accounts by fit, engagement, and buying signals before launching account-specific plays.',
          'Link account view with playbook view so the strategy on paper turns into the actual next outreach.',
          'Keep account metadata complete — campaigns and quotes downstream inherit whatever you record here.'
        ],
        outcomes: [
          'Saves the ABM account and playbook state that campaign targeting and account-level reports rely on.',
          'Focus shifts to high-value opportunities instead of high-volume but low-fit activity.',
          'Outreach lines up with how mature each account is, so conversion gets more efficient over time.'
        ]
      }
    },
    {
      test: /^\/activities/,
      data: {
        functionText: `${heading}: The history book of customer interactions — calls, meetings, and touches, all logged so account momentum is something you can actually see, not just guess at. ${uiContext}`,
        actions: [
          'Log every customer touch with a quick outcome note so future decisions are based on facts, not memory.',
          'Use the timeline to spot stalled accounts and reach out before the opportunity goes cold.',
          'Treat activity quality as an early warning for RFQ progress and campaign effectiveness.'
        ],
        outcomes: [
          'Writes activity records linked to the right company and contact so the full relationship is traceable.',
          'Forecasts get more credible because engagement recency is visible to everyone on the team.',
          'Accountability improves — every follow-up commitment has a name and a date attached.'
        ]
      }
    },
    {
      test: /^\/admin\/(audit|custom-fields|datasets|users)/,
      data: {
        functionText: `${heading}: The platform governance area — it keeps the data model clean, controls who can do what, and preserves an honest record of every change. ${uiContext}`,
        actions: [
          'Use this page to keep schema discipline, control imports, and adjust permissions carefully.',
          'Read the audit and history outputs before making big process changes — they catch silent regressions early.',
          'Make admin changes with a rollback plan and a clear owner so risk stays low.'
        ],
        outcomes: [
          'Saves configuration and governance state that quietly shapes how every business module behaves.',
          'Compliance and reliability risks go down because changes are visible and controlled.',
          'Analytics stay trustworthy because the underlying data structure is protected over time.'
        ]
      }
    },
    {
      test: /^\/autonomous-sales/,
      data: {
        functionText: `${heading}: The mission control for automated outbound — see what is running, how it is performing, and where the guardrails need adjusting. ${uiContext}`,
        actions: [
          'Tune the automation’s boundaries and check output quality before turning up the volume.',
          'Confirm the learning loops, targeting, and fail-safes are still healthy before each scale-up.',
          'Send anything risky or uncertain back to a human reviewer rather than letting automation push it through.'
        ],
        outcomes: [
          'Updates the automation’s configuration and changes how downstream outbound pipelines behave.',
          'You get more reach without sacrificing relevance, as long as the guardrails stay maintained.',
          'There is a clear gap between disciplined automation gains and the spam risk that lives without controls.'
        ]
      }
    },
    {
      test: /^\/(calendar|meetings)/,
      data: {
        functionText: `${heading}: The scheduling layer — it turns “someone is interested” into “meeting on the calendar at 2pm Tuesday” and keeps follow-up cadence predictable. ${uiContext}`,
        actions: [
          'Manage slots, availability, and booking links so it is easy to go from interest to confirmed meeting.',
          'Protect selling time by watching the calendar for bottlenecks and over-booking.',
          'Push meeting outcomes back into activities and tasks so commitments don’t vanish after the call.'
        ],
        outcomes: [
          'Saves the schedule and booking state that the pipeline timeline depends on.',
          'More meetings get booked because the time between first interest and confirmed slot is shorter.',
          'Operational rhythm improves because the schedule is tied directly to next actions.'
        ]
      }
    },
    {
      test: /^\/command-center/,
      data: {
        functionText: `${heading}: The live operations panel — alerts, metric shifts, and recommended actions across every active revenue workflow, all on one screen. ${uiContext}`,
        actions: [
          'Triage alerts by business impact first, then click through to the page that can actually fix the issue.',
          'Use the data, actions, and metrics views together so detection turns into immediate execution.',
          'Treat this page as an escalation tool — it exists to drive action, not just show pretty charts.'
        ],
        outcomes: [
          'Pulls operational events from many modules into one place where the team can respond fast.',
          'Reaction time drops when opportunities or risks shift unexpectedly.',
          'Different teams coordinate better because they are all looking at the same urgent signals.'
        ]
      }
    },
    {
      test: /^\/(comp-crawler|webcrawler|lead-discovery|discovery-pipeline|leads)/,
      data: {
        functionText: `${heading}: The discovery and qualification layer — it finds high-fit targets out on the web and decides which ones earn a spot in the real CRM funnel. ${uiContext}`,
        actions: [
          'Read the fit evidence, run dedupe checks, and apply qualification thresholds before promoting a lead.',
          'Use the review and discovered views to keep low-fit noise away from real companies and contacts.',
          'Aim for precision — a small stream of high-fit leads beats a flood of weak ones every time.'
        ],
        outcomes: [
          'Saves new or updated lead records with explicit status transitions and a clear reason for each decision.',
          'Top-of-funnel quality rises and the team stops burning hours on companies that were never going to buy.',
          'ABM, campaign targeting, and RFQ creation all get a cleaner pool of candidates to work from.'
        ]
      }
    },
    {
      test: /^\/(companies|contacts|profile|supplier-portal)/,
      data: {
        functionText: `${heading}: The master-data workspace — it keeps the people-and-companies records that every other workflow leans on accurate, current, and ready for action. ${uiContext}`,
        actions: [
          'Keep account and contact attributes clean before you trigger any RFQ, campaign, or meeting from them.',
          'Use the discovered, new, and edit flows to keep duplicates and half-finished records out of core operations.',
          'Treat profile and supplier records as relationship infrastructure, not throwaway reference data.'
        ],
        outcomes: [
          'Saves the core CRM records that every other workflow depends on.',
          'Personalization, routing, and handoffs between teams all get more accurate.',
          'Long-term trust in the data goes up because fragmentation and stale ownership go down.'
        ]
      }
    },
    {
      test: /^\/(quote-copilot|quote-review|quote|rfqs)/,
      data: {
        functionText: `${heading}: The commercial workspace — deal economics, quote readiness, and stage movement all get actively managed here, where revenue is actually won or lost. ${uiContext}`,
        actions: [
          'Check pricing, risk, and readiness signals before you push a quote or RFQ to the next stage.',
          'Use pipeline and analytics views together — they tell you whether the problem is speed or win rate.',
          'Keep a paper trail on each commercial decision so the team can repeat what works on the next deal.'
        ],
        outcomes: [
          'Saves quote and RFQ status changes plus the reasoning behind them, which feeds revenue forecasts.',
          'Conversion quality goes up because every commercial checkpoint is deliberate, not skipped.',
          'Forecasts get more credible because there is real evidence behind each stage.'
        ]
      }
    },
    {
      test: /^\/(email-campaigns|webinars)/,
      data: {
        functionText: `${heading}: The outreach engine — build campaigns or webinars, send them, and watch how the audience reacts so the next one is sharper. ${uiContext}`,
        actions: [
          'Adjust sequences based on real open, click, and reply patterns plus how well the audience fits.',
          'Treat sending and analytics as one loop: launch, measure, refine, relaunch.',
          'Time outreach against lead and RFQ state so the message arrives when it is actually relevant.'
        ],
        outcomes: [
          'Saves campaign and send records and captures every engagement event so iteration is measurable.',
          'Outreach gets more efficient because untargeted volume drops and response quality climbs.',
          'The engagement signals feed back into discovery, ABM, and pipeline so the whole system gets smarter.'
        ]
      }
    },
    {
      test: /^\/(reports|currency-converter)/,
      data: {
        functionText: `${heading}: The analytical and normalization layer — it makes numbers comparable so decisions can be made on a fair, consistent basis. ${uiContext}`,
        actions: [
          'Use this page to standardize comparisons and take guesswork out of performance discussions.',
          'Treat reports and conversions as inputs to a next decision, not as one-off documents.',
          'Sanity-check the filters, FX rates, and time periods before sharing anything outside the team.'
        ],
        outcomes: [
          'Produces decision-grade reports and normalized values that sales operations actually use.',
          'Executive and team-level planning becomes more consistent across regions and time periods.',
          'Avoidable errors from inconsistent metrics or manual conversions disappear.'
        ]
      }
    },
    {
      test: /^\/(tasks|guidance)/,
      data: {
        functionText: `${heading}: The execution layer — it turns intent into assigned, time-bound work that the team actually closes out. ${uiContext}`,
        actions: [
          'Rank due actions by urgency and impact, then close them out or re-scope honestly — no zombie tasks.',
          'Pay attention to guidance prompts after big actions (new company, activity, conversion) — they catch easy misses.',
          'Keep ownership and due-date hygiene tight so the pipeline doesn’t decay quietly.'
        ],
        outcomes: [
          'Saves task and guidance state so next steps after big workflow events are operational, not theoretical.',
          'Follow-ups actually happen and commitments stop slipping between teams.',
          'Real work completion becomes visible and tied to the CRM records that triggered it.'
        ]
      }
    },
    {
      test: /^\/(login|register|forgot-password)/,
      data: {
        functionText: `${heading}: The identity layer — it makes sure only the right people get in, and that recovering access can’t be turned into an attack. ${uiContext}`,
        actions: [
          'Complete the login or recovery flow, treating every step as security-sensitive.',
          'Never reveal whether an account exists during sign-in or password reset — keep responses neutral.',
          'Use this page as the firm boundary that protects every business screen behind it.'
        ],
        outcomes: [
          'Creates a secure session or a safe recovery token in line with policy.',
          'Account takeover and enumeration risk both go down in day-to-day operation.',
          'Every downstream business workflow stays protected because access control is strict.'
        ]
      }
    }
  ];

  const matched = ruleTable.find((rule) => rule.test.test(routePath));
  const base = matched ? { ...matched.data } : { ...fallback };

  const facetHint = facetHints[facet];
  if (facetHint) {
    base.functionText = `${base.functionText} ${facetHint}`;
  }

  const overlay = modeOverlays[mode];
  if (overlay) {
    base.functionText = `${base.functionText} ${overlay.functionAdd}`;
    base.actions = [base.actions[0], base.actions[1], overlay.actionAdd];
    base.outcomes = [base.outcomes[0], base.outcomes[1], overlay.outcomeAdd];
  }

  return base;
}

function buildExecutiveStory(item, profile) {
  const routePath = String(item.routePath || '').toLowerCase();
  const mode = getPageMode(routePath, item.routeName || '');
  const resource = getResource(routePath);
  const deep = buildDeepNarrative(item, profile);
  const functionCore = deep.functionText.replace(/^[^:]+:\s*/, '').trim();
  const seed = `${routePath}|${item.routeName}|story`;

  const resourceLeads = {
    dashboard: [
      'Start here in the morning — it’s the one screen that tells you whether the business is healthy today.',
      'This is where leadership lines up before splitting off into module-by-module execution.'
    ],
    'abm-dashboard': [
      'This is where the team decides which accounts deserve special focus and coordinated plays.',
      'The strategic targeting happens here, before a single outreach dollar gets spent.'
    ],
    companies: [
      'This is the master record for every buyer account — get it right here and every later stage gets easier.',
      'A clean account record here prevents friction in campaigns, RFQs, and handoffs down the line.'
    ],
    contacts: [
      'This is the people side of the business — the procurement leads, engineers, and decision-makers we actually talk to.',
      'Accurate contact context here means outreach lands at the right time with the right person.'
    ],
    leads: [
      'This is the qualification doorway — the team decides here what is allowed to enter the real funnel.',
      'Filtering for fit happens here so sales effort stays focused and the funnel stays clean.'
    ],
    'lead-discovery': [
      'This is where raw discovery volume gets sorted into actual qualified opportunity flow.',
      'The job here is precision: only promote leads that have real evidence and a clear next step.'
    ],
    rfqs: [
      'This is the commercial workspace where deal value is actively protected, not just observed.',
      'Quote readiness and stage confidence are managed here — it is where revenue is really won or lost.'
    ],
    'email-campaigns': [
      'This is the growth loop — outreach goes out, engagement comes back, and the next campaign gets better.',
      'This stage turns messaging work into something measurable instead of guesswork.'
    ],
    'command-center': [
      'This is the live response area — it converts signals into actual action, fast.',
      'When something starts to drift, the team coordinates here before the problem compounds.'
    ],
    reports: [
      'This is where everyday activity becomes the kind of insight you can build a plan on.',
      'This layer turns work into clear planning and accountability numbers.'
    ],
    tasks: [
      'This is the discipline layer — commitments turn into actual closed-out work here.',
      'Execution reliability is built here, one assigned task with a due date at a time.'
    ]
  };

  const modeLeads = {
    auth: [
      'Before any business workflow is exposed, we make sure the person at the keyboard is who they say they are.',
      'Access boundaries are set here, before the business screens unlock.'
    ],
    create: [
      'At this moment, the team is creating something new — a record that everything downstream will depend on.',
      'This is a record-creation moment, and the quality at entry shapes how fast things move later.'
    ],
    edit: [
      'Right now, the team is correcting or enriching what is already there — protecting the truth of the record.',
      'This step stabilizes active workflows by fixing record quality at the source.'
    ],
    review: [
      'This is a decision moment — the evidence on screen determines whether work moves forward.',
      'Standards get enforced here before anything is promoted to the next lifecycle stage.'
    ],
    pipeline: [
      'This stage is about keeping flow healthy — fast enough to win, careful enough to win well.',
      'Pipeline control happens here: clear the blockers, watch the win rate.'
    ],
    analytics: [
      'This stage turns observed performance into a real optimization decision.',
      'Performance reading happens here, before any strategy gets adjusted.'
    ],
    index: [
      'This step is where the team gets a clear handle on what is in flight and who owns it.',
      'Here, work is prioritized and routed to the right person.'
    ]
  };

  const leadOptions = resourceLeads[resource] || modeLeads[mode] || ['At this point in the journey, this page keeps the customer lifecycle moving forward.'];
  const lead = pickVariant(seed, leadOptions);
  return `${lead} ${functionCore}`;
}

function functionOfPage(item) {
  const profile = getModuleProfile(item.routePath || '');
  return buildDeepNarrative(item, profile).functionText;
}

function primaryActions(item) {
  const profile = getModuleProfile(item.routePath || '');
  return buildDeepNarrative(item, profile).actions;
}

function dataAndOutcomes(item) {
  const profile = getModuleProfile(item.routePath || '');
  return buildDeepNarrative(item, profile).outcomes;
}

function presenterNotes(item) {
  return [
    `Route in code: ${oneLine(item.routeName)} [${oneLine(item.method)}].`,
    `Docs behind it: ${getModuleProfile(item.routePath || '').doc}.`
  ];
}

const metadata = JSON.parse(fs.readFileSync(metadataPath, 'utf8'));
const entries = metadata.entries || [];

const introSlides = `
<section>
  <h1>CRM v2 — A Beginner's Walkthrough</h1>
  <p>A guided tour of the platform, built directly from the live product pages, the route map, and the implementation docs — so what you see here is exactly what you get in the app.</p>
  <p><small>Generated: ${esc(new Date(metadata.generatedAt || Date.now()).toLocaleString())}</small></p>
</section>
<section>
  <h2>The Big Picture</h2>
  <ul>
    <li>It replaces scattered sales activity with one connected journey: find a prospect, qualify them, raise an RFQ, win the quote, and keep the customer.</li>
    <li>Growth engines (ABM, email campaigns, web discovery) feed straight into execution engines (tasks, meetings, pipeline control) — no copy-paste between tools.</li>
    <li>Every action leaves a trace, so day-to-day work turns into measurable signals you can actually report on and improve.</li>
  </ul>
</section>
<section>
  <h2>How To Read Each Slide</h2>
  <ol>
    <li>Start with <em>Where it fits</em> — what problem this page solves in the customer journey.</li>
    <li>Look at <em>What this page does</em> for a plain description of the screen's job.</li>
    <li>Use <em>Try this in the demo</em> as the two clicks worth showing live.</li>
    <li>Wrap with <em>Why it matters</em> — the change in speed, quality, or conversion.</li>
    <li>The small <em>Built into the product</em> footer proves the route and docs really exist.</li>
  </ol>
</section>
`;

const screenshotSlides = entries.map((item) => {
  const title = oneLine(item.heading || item.title || item.routeName);
  const profile = getModuleProfile(item.routePath || '');
  const executiveStory = buildExecutiveStory(item, profile);
  const functionText = functionOfPage(item);
  const actions = primaryActions(item);
  const outcomes = dataAndOutcomes(item);
  const notes = presenterNotes(item);
  const subtitle = oneLine(item.subtitle);
  const showSubtitle = subtitle && !/^subtitle$/i.test(subtitle) && !/^review subtitle$/i.test(subtitle);
  const conciseStory = takeSentences(executiveStory, 2);
  const conciseFunction = takeSentences(functionText.replace(/^[^:]+:\s*/, ''), 1);
  const demoBullets = actions.slice(0, 2);
  const impactBullets = outcomes.slice(0, 2);

  return `
<section>
  <h3>${String(item.index).padStart(2, '0')}. ${esc(item.routePath)} — ${esc(title)}</h3>
  <div class="img-wrap">
    <img src="presentation_screenshots/${esc(item.fileName)}" alt="${esc(item.routePath)} screenshot" />
  </div>
  <p><strong>Module:</strong> ${esc(profile.name)} (${esc(profile.entity)})</p>
  <p><strong>Module Purpose:</strong> ${esc(profile.purpose)}</p>
  ${showSubtitle ? `<p><strong>On-screen header:</strong> ${esc(subtitle)}</p>` : ''}
  <p><strong>Where it fits:</strong> ${esc(conciseStory)}</p>
  <p><strong>What this page does:</strong> ${esc(conciseFunction)}</p>
  <p><strong>Try this in the demo:</strong></p>
  <ul>
    <li>${esc(demoBullets[0] || '')}</li>
    <li>${esc(demoBullets[1] || '')}</li>
  </ul>
  <p><strong>Why it matters:</strong></p>
  <ul>
    <li>${esc(impactBullets[0] || '')}</li>
    <li>${esc(impactBullets[1] || '')}</li>
  </ul>
  <p><small>Built into the product: ${esc(notes[0])} ${esc(notes[1])}</small></p>
</section>
`;
}).join('\n');

const outroSlide = `
<section>
  <h2>Bringing It All Together</h2>
  <p>The real power of CRM v2 is that every page pushes the same revenue journey forward — each click leaves an audit trail and feeds the next decision, so the team works as one connected system instead of a stack of disconnected tools.</p>
  <p><small>Suggested demo path: Dashboard → Discovery → Master Data → Commercial Pipeline → Execution Discipline → Growth Loops → Reporting.</small></p>
</section>
`;

const html = `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CRM v2 Beginner Slides</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/reveal.js@5.1.0/dist/reveal.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/reveal.js@5.1.0/dist/theme/white.css" />
  <style>
    .reveal { font-size: 28px; }
    .reveal .slides section { text-align: left; }
    .reveal h1, .reveal h2, .reveal h3 { margin-bottom: 0.4em; }
    .img-wrap { text-align: center; margin: 0.3em 0; }
    .img-wrap img { max-height: 48vh; width: auto; border: 1px solid #ddd; }
    .reveal p { margin: 0.22em 0; line-height: 1.24; }
    .reveal ul, .reveal ol { margin: 0.2em 0 0.2em 1.2em; }
  </style>
</head>
<body>
  <div class="reveal">
    <div class="slides">
      ${introSlides}
      ${screenshotSlides}
      ${outroSlide}
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/reveal.js@5.1.0/dist/reveal.js"></script>
  <script>
    Reveal.initialize({
      controls: true,
      progress: true,
      center: false,
      hash: true,
      transition: 'slide',
      pdfSeparateFragments: false
    });
  </script>
</body>
</html>
`;

fs.writeFileSync(outputPath, html);
console.log(`Created ${outputPath} with ${entries.length + 4} slides.`);
