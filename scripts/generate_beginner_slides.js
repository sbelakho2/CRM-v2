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
    purpose: 'Daily operating cockpit for sales, marketing, and operations health.',
    entity: 'Cross-module KPIs',
    doc: 'SYSTEM_OVERVIEW + GUIDANCE_NOTIFICATION_SYSTEM'
  },
  companies: {
    name: 'Company Management',
    purpose: 'Maintain target and active buyer accounts with qualification context.',
    entity: 'Company',
    doc: 'SYSTEM_OVERVIEW + LEADS_TO_COMPANIES_GUIDE'
  },
  contacts: {
    name: 'Contact Management',
    purpose: 'Track procurement stakeholders and relationship quality by account.',
    entity: 'Contact',
    doc: 'SYSTEM_OVERVIEW'
  },
  leads: {
    name: 'Lead Operations',
    purpose: 'Review, approve, deny, and convert discovered leads into CRM entities.',
    entity: 'Lead',
    doc: 'WEBCRAWLER_README + LEADS_TO_COMPANIES_GUIDE'
  },
  rfqs: {
    name: 'RFQ Pipeline',
    purpose: 'Move opportunities from intake through status gates to outcome analysis.',
    entity: 'RFQ',
    doc: 'SYSTEM_OVERVIEW'
  },
  'email-campaigns': {
    name: 'Email Campaigns',
    purpose: 'Manage campaign lifecycle, send execution, and engagement tracking.',
    entity: 'EmailCampaign + EmailSend',
    doc: 'EMAIL_CAMPAIGN_QUICK_REFERENCE'
  },
  'abm-dashboard': {
    name: 'ABM Workspace',
    purpose: 'Prioritize high-value target accounts and coordinate focused plays.',
    entity: 'ABM Account/Playbook',
    doc: 'SYSTEM_OVERVIEW + ABM docs'
  },
  webcrawler: {
    name: 'Webcrawler Discovery',
    purpose: 'Generate and enrich high-relevance lead candidates with region-configurable fit scoring.',
    entity: 'Discovered company/contact candidates',
    doc: 'WEBCRAWLER_README + LEADBOT_INTEGRATION'
  },
  'lead-discovery': {
    name: 'Lead Discovery Queue',
    purpose: 'Triage discovered leads before conversion into account and contact records.',
    entity: 'Lead queue',
    doc: 'WEBCRAWLER_README'
  },
  'command-center': {
    name: 'Command Center',
    purpose: 'Operational intelligence panel for alerts, actions, metrics, and quote signals.',
    entity: 'Cross-module operational signals',
    doc: 'SYSTEM_OVERVIEW'
  },
  reports: {
    name: 'Report Builder',
    purpose: 'Create reusable analytics artifacts for performance and pipeline review.',
    entity: 'Report definitions + exports',
    doc: 'SYSTEM_OVERVIEW'
  },
  calendar: {
    name: 'Calendar Planning',
    purpose: 'Schedule events and synchronize time-based execution with sales motion.',
    entity: 'Calendar events',
    doc: 'SYSTEM_OVERVIEW'
  },
  meetings: {
    name: 'Meeting Scheduler',
    purpose: 'Generate booking links, expose availability, and capture meeting pipeline.',
    entity: 'Meeting',
    doc: 'SYSTEM_OVERVIEW'
  },
  admin: {
    name: 'Administration',
    purpose: 'Control users, datasets, audit, and custom-field governance.',
    entity: 'Users/Datasets/Fields/Audit records',
    doc: 'SYSTEM_OVERVIEW + deployment docs'
  },
  profile: {
    name: 'User Profile',
    purpose: 'Manage user preferences and personal account context.',
    entity: 'User profile',
    doc: 'NEW_USER_GUIDE'
  },
  activities: {
    name: 'Activity Tracking',
    purpose: 'Capture interactions and keep account engagement history accurate.',
    entity: 'Activity',
    doc: 'SYSTEM_OVERVIEW + GUIDANCE_NOTIFICATION_SYSTEM'
  },
  tasks: {
    name: 'Task Execution',
    purpose: 'Plan, prioritize, and complete operational commitments.',
    entity: 'Task',
    doc: 'SYSTEM_OVERVIEW'
  },
  playbooks: {
    name: 'ABM Playbooks',
    purpose: 'Define repeatable account engagement motions for target segments.',
    entity: 'Playbook',
    doc: '04-ABM-Automation docs'
  },
  'quote-copilot': {
    name: 'Quote CoPilot',
    purpose: 'Support quote analysis and sourcing decisions with assisted workflows.',
    entity: 'Quote + BOM insights',
    doc: 'Quote controller/service implementation'
  },
  'quote-review': {
    name: 'Quote Review',
    purpose: 'Validate pricing and commercial readiness before customer-facing steps.',
    entity: 'Quote review records',
    doc: 'Quote review workflow implementation'
  },
  login: {
    name: 'Authentication',
    purpose: 'Authenticate users and protect application routes.',
    entity: 'Session + user credentials',
    doc: 'SecurityController + FORGOT_PASSWORD_FEATURE'
  },
  register: {
    name: 'Authentication',
    purpose: 'Register new users with role-scoped access to CRM operations.',
    entity: 'User account records',
    doc: 'RegistrationController'
  },
  'forgot-password': {
    name: 'Authentication Recovery',
    purpose: 'Provide secure password reset initiation without account enumeration risk.',
    entity: 'Password reset token workflow',
    doc: 'FORGOT_PASSWORD_FEATURE + SecurityController'
  },
  compliance: {
    name: 'Compliance Tracking',
    purpose: 'Track document readiness, expiry risk, and remediation actions.',
    entity: 'Compliance document requirements',
    doc: 'SYSTEM_OVERVIEW + GUIDANCE_NOTIFICATION_SYSTEM'
  },
  'supplier-portal': {
    name: 'Supplier Portal',
    purpose: 'Manage external supplier registration and participation signals.',
    entity: 'Supplier portal records',
    doc: 'SYSTEM_OVERVIEW'
  },
  'comp-crawler': {
    name: 'Competitive Crawler',
    purpose: 'Monitor competitor footprint changes and watchlist intelligence.',
    entity: 'Competitor pages and signals',
    doc: 'LEADBOT/WEBCRAWLER docs'
  },
  'autonomous-sales': {
    name: 'Autonomous Sales',
    purpose: 'Control and observe distributed outbound automation workflows.',
    entity: 'Autonomous orchestration state',
    doc: 'AUTONOMOUS_SALES_V2'
  },
  guidance: {
    name: 'Guidance Notifications',
    purpose: 'Provide contextual best-practice prompts after critical user actions.',
    entity: 'Session guidance events',
    doc: 'GUIDANCE_NOTIFICATION_SYSTEM'
  },
  webinars: {
    name: 'Webinar Operations',
    purpose: 'Plan sessions and route webinar activity into follow-up workflows.',
    entity: 'Webinar + registrations',
    doc: 'SYSTEM_OVERVIEW'
  },
  'currency-converter': {
    name: 'Currency Converter',
    purpose: 'Normalize commercial values for quoting and procurement scenarios.',
    entity: 'FX conversion records',
    doc: 'Controller + service implementation'
  },
  'discovery-pipeline': {
    name: 'Discovery Pipeline',
    purpose: 'Track discovery throughput and progress from capture to qualification.',
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
    purpose: 'Operate this page as part of standard customer lifecycle execution.',
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
      detail: 'This page manages automated execution logic while preserving human strategic control.',
      action: 'Tune automation boundaries and monitor outputs for quality drift.',
      outcome: 'Scales outreach without sacrificing targeting precision.'
    },
    {
      test: /^\/quote-copilot|^\/quote-review|^\/rfqs/,
      detail: 'This screen governs commercial momentum from inquiry to quote decision.',
      action: 'Prioritize value, timing, and risk signals before moving the opportunity forward.',
      outcome: 'Improves predictability of revenue pipeline and quote conversion quality.'
    },
    {
      test: /^\/lead-discovery|^\/leads|^\/webcrawler|^\/discovery-pipeline/,
      detail: 'This page controls how new opportunities are discovered, filtered, and promoted.',
      action: 'Use relevance evidence first, then decide whether to approve, deny, or enrich.',
      outcome: 'Raises top-of-funnel quality while reducing manual triage overhead.'
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
      detail: 'This screen governs commercial momentum from inquiry to quote decision.',
      action: 'Prioritize value, timing, and risk signals before moving the opportunity forward.',
      outcome: 'Improves predictability of revenue pipeline and quote conversion quality.'
    },
    {
      test: /(^|\W)(lead|discovery|webcrawler|dork|keyword|crawler)(\W|$)/,
      detail: 'This page controls how new opportunities are discovered, filtered, and promoted.',
      action: 'Use relevance evidence first, then decide whether to approve, deny, or enrich.',
      outcome: 'Raises top-of-funnel quality while reducing manual triage overhead.'
    },
    {
      test: /(^|\W)(campaign|email|track\/|open|click|replied|bounced)(\W|$)/,
      detail: 'This view manages outreach execution and feedback loops from recipient behavior.',
      action: 'Calibrate audience, cadence, and message quality using engagement telemetry.',
      outcome: 'Converts messaging data into repeatable campaign improvements.'
    },
    {
      test: /(^|\W)(task|kanban|my-day)(\W|$)/,
      detail: 'This screen organizes execution workload and enforces day-to-day follow-through.',
      action: 'Balance assignments by urgency, owner capacity, and stage readiness.',
      outcome: 'Improves completion discipline and reduces missed follow-ups.'
    },
    {
      test: /(^|\W)(meeting|calendar|slot|my-link)(\W|$)|\/book\//,
      detail: 'This page converts scheduling intent into confirmed interactions with clear ownership.',
      action: 'Protect calendar capacity while shortening the time from outreach to meeting.',
      outcome: 'Increases booked conversations and keeps engagement cadence stable.'
    },
    {
      test: /(^|\W)(compliance|audit|dataset|import|history)(\W|$)/,
      detail: 'This view safeguards data governance, traceability, and operational compliance posture.',
      action: 'Resolve exceptions early and preserve clear evidence of all system changes.',
      outcome: 'Reduces compliance risk and improves trust in operational reporting.'
    },
    {
      test: /(^|\W)(command-center|alerts?|metrics?|analysis)(\W|$)/,
      detail: 'This page centralizes high-signal indicators for fast leadership decisions.',
      action: 'Address alerts first, then drill into blockers with highest business impact.',
      outcome: 'Shortens response time to risk and opportunity shifts.'
    },
    {
      test: /(^|\W)(report|analytics|dashboard|overview|stats)(\W|$)/,
      detail: 'This view translates operational activity into measurable performance insight.',
      action: 'Compare trend direction, not just absolute numbers, before deciding next moves.',
      outcome: 'Supports better prioritization with evidence-backed decisions.'
    },
    {
      test: /(^|\W)(autonomous-sales|automation|playbooks?)(\W|$)/,
      detail: 'This page manages automated execution logic while preserving human strategic control.',
      action: 'Tune automation boundaries and monitor outputs for quality drift.',
      outcome: 'Scales outreach without sacrificing targeting precision.'
    },
    {
      test: /(^|\W)(company|contact|account|profile|supplier-portal)(\W|$)/,
      detail: 'This screen strengthens master records used by every downstream workflow.',
      action: 'Maintain complete, current stakeholder data before activating next-stage actions.',
      outcome: 'Improves CRM data integrity and personalization readiness.'
    }
  ];

  for (const intent of intents) {
    if (intent.test.test(haystack)) {
      return intent;
    }
  }

  return {
    detail: 'This page contributes to end-to-end CRM workflow continuity.',
    action: 'Use the primary controls to keep records and decisions synchronized.',
    outcome: 'Maintains reliable execution handoffs across teams.'
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
  const uiContext = hasRealSubtitle ? `UI context: ${subtitleClean}.` : 'UI context: focused operational workspace.';
  const segments = routePath.replace(/^\//, '').split('/').filter(Boolean);
  const facet = segments[1] || '';

  const modeLens = {
    auth: 'security gate and access control',
    dashboard: 'cross-module decision cockpit',
    index: 'operational list and triage surface',
    create: 'new-record intake and validation surface',
    edit: 'record correction and governance surface',
    detail: 'single-record decision workspace',
    review: 'qualification and approval checkpoint',
    pipeline: 'stage progression and bottleneck control surface',
    analytics: 'performance interpretation and optimization surface',
    discovered: 'pre-activation screening queue',
    kanban: 'visual execution board',
    myday: 'daily personal execution view',
    timeline: 'chronological evidence stream',
    search: 'high-speed retrieval workspace',
    generate: 'assistant generation workflow',
    dork: 'search-query engineering surface',
    expansion: 'keyword amplification workspace'
  };

  const fallback = {
    functionText: `${heading}: This page serves as the ${modeLens[mode] || 'module execution surface'} for ${profile.name}. ${uiContext}`,
    actions: [
      'Use the primary controls on this screen to complete the current workflow step without leaving module context.',
      'Validate data quality before saving so downstream reports, automations, and handoffs remain reliable.',
      `Anchor actions to module objective: ${profile.purpose}`
    ],
    outcomes: [
      'Commits state changes (or filtered views) tied to this route and preserves an auditable operational trail.',
      'Improves team execution quality by reducing ambiguity about the next action owner and lifecycle step.',
      'Feeds cleaner operational data into dashboard, analytics, and decision workflows.'
    ]
  };

  const facetHints = {
    alerts: 'This page specifically handles alert triage and severity-based response order.',
    actions: 'This page focuses on converting detected signals into assigned next actions.',
    metrics: 'This page emphasizes KPI interpretation and trend confidence over raw totals.',
    data: 'This page is optimized for underlying operational data review and drill-down.',
    leads: 'This page concentrates on qualification quality and promotion readiness.',
    quotes: 'This page concentrates on quote readiness, risk, and commercial timing.',
    review: 'This page is a formal decision checkpoint with explicit approve/deny intent.',
    discovered: 'This page is a pre-activation queue where low-quality noise is filtered out.',
    pipeline: 'This page focuses on stage movement, bottleneck control, and cycle-time health.',
    analytics: 'This page is built for optimization decisions based on measurable outcomes.',
    history: 'This page preserves change chronology for governance and rollback confidence.',
    import: 'This page is designed for controlled ingestion with validation and auditability.',
    watchlists: 'This page manages strategic monitoring sets for ongoing competitive observation.',
    search: 'This page prioritizes retrieval speed and precision in large datasets.'
  };

  const modeOverlays = {
    create: {
      functionAdd: 'It is an intake boundary where quality at entry directly determines downstream workflow reliability.',
      actionAdd: 'Verify required fields, ownership, and stage defaults before commit to avoid cleanup cycles later.',
      outcomeAdd: 'Creates new records that immediately influence pipeline, reporting, and automation context.'
    },
    edit: {
      functionAdd: 'It is a correction and enrichment boundary that prevents drift in active records.',
      actionAdd: 'Apply minimal, intentional edits and confirm linked workflows still reflect business reality.',
      outcomeAdd: 'Updates existing records while preserving continuity for forecasting and collaboration.'
    },
    review: {
      functionAdd: 'It is a governance checkpoint where evidence quality drives lifecycle state transitions.',
      actionAdd: 'Record rationale with each decision so quality patterns can be improved over time.',
      outcomeAdd: 'Produces auditable qualification outcomes used in conversion and prioritization logic.'
    },
    pipeline: {
      functionAdd: 'It is a flow-control surface where velocity and conversion health are managed simultaneously.',
      actionAdd: 'Resolve blockers at the current stage before pulling more volume into the pipeline.',
      outcomeAdd: 'Makes stage progression explicit, improving forecast explainability and accountability.'
    },
    analytics: {
      functionAdd: 'It is an optimization layer where teams convert performance signals into process changes.',
      actionAdd: 'Compare cohorts and time windows before choosing interventions to avoid reactive decisions.',
      outcomeAdd: 'Creates evidence-backed optimization direction for future cycles.'
    },
    discovered: {
      functionAdd: 'It is a quality gate that protects core CRM entities from low-confidence additions.',
      actionAdd: 'Promote only records with clear fit evidence and actionable next steps.',
      outcomeAdd: 'Raises net signal quality entering companies/contacts/lead workflows.'
    },
    auth: {
      functionAdd: 'It is a security boundary that protects every business route behind trusted identity checks.',
      actionAdd: 'Treat every auth/recovery response as security-sensitive and non-enumerating.',
      outcomeAdd: 'Maintains secure session state and reduces abuse exposure across the platform.'
    }
  };

  const ruleTable = [
    {
      test: /^\/$/,
      data: {
        functionText: `${heading}: Strategic command surface that merges pipeline health, reminders, and conversion signals for day-start prioritization. ${uiContext}`,
        actions: [
          'Review pending lead approvals, RFQ stage risk, and task backlog before assigning team priorities.',
          'Use KPI drift vs prior period to decide whether to push discovery, outreach, or quote acceleration today.',
          'Convert dashboard signals into explicit owner-level actions instead of passive monitoring.'
        ],
        outcomes: [
          'No direct entity creation; this route aggregates cross-module indicators into a single decision layer.',
          'Improves leadership response speed when conversion or activity trends deviate from target.',
          'Aligns sales, marketing, and operations around one shared operational truth each cycle.'
        ]
      }
    },
    {
      test: /^\/abm-dashboard/,
      data: {
        functionText: `${heading}: ABM control layer for selecting target accounts, orchestrating playbooks, and measuring account progression against strategic segments. ${uiContext}`,
        actions: [
          'Prioritize accounts by fit, engagement, and buying signals before launching account-specific plays.',
          'Use account and playbook views together to connect strategy intent with concrete next outreach actions.',
          'Keep ABM account metadata complete so campaign, lead, and quote workflows inherit accurate context.'
        ],
        outcomes: [
          'Maintains ABM entity state used by downstream campaign targeting and account-level reporting.',
          'Improves focus on high-value opportunities rather than high-volume but low-fit activity.',
          'Increases conversion efficiency by aligning outreach sequences to account maturity and intent.'
        ]
      }
    },
    {
      test: /^\/activities/,
      data: {
        functionText: `${heading}: Interaction evidence layer that records calls, meetings, and touchpoints used to evaluate account momentum and follow-up discipline. ${uiContext}`,
        actions: [
          'Log each customer interaction with outcome context so next-step decisions are based on facts, not memory.',
          'Use timeline/history to detect stalled accounts and trigger corrective outreach before opportunities cool.',
          'Treat activity quality as a leading indicator for RFQ progression and campaign effectiveness.'
        ],
        outcomes: [
          'Writes or retrieves activity records linked to companies/contacts for full relationship traceability.',
          'Raises forecast confidence by making engagement recency and quality visible across the team.',
          'Strengthens accountability because every follow-up decision is timestamped and attributable.'
        ]
      }
    },
    {
      test: /^\/admin\/(audit|custom-fields|datasets|users)/,
      data: {
        functionText: `${heading}: Governance and platform-control surface for maintaining data model integrity, user access, and operational traceability. ${uiContext}`,
        actions: [
          'Use this page to enforce schema discipline, import governance, and controlled permission changes.',
          'Review audit/history outputs before major process changes to avoid silent regressions in production data.',
          'Apply admin changes with rollback awareness and clear ownership to reduce operational risk.'
        ],
        outcomes: [
          'Persists configuration and governance state that affects behavior across all business modules.',
          'Reduces compliance and reliability risk through explicit change tracking and controlled administration.',
          'Improves trust in analytics by protecting data structure consistency over time.'
        ]
      }
    },
    {
      test: /^\/autonomous-sales/,
      data: {
        functionText: `${heading}: Automation control plane for autonomous outbound execution, quality guardrails, and distributed orchestration monitoring. ${uiContext}`,
        actions: [
          'Tune automation boundaries and monitor output quality before scaling volume.',
          'Use this route to verify learning loops, targeting quality, and fail-safe behavior remain healthy.',
          'Escalate uncertain or high-risk outcomes back to human review rather than forcing full automation.'
        ],
        outcomes: [
          'Updates automation state and influences behavior of downstream autonomous outreach pipelines.',
          'Improves throughput without sacrificing relevance when guardrails are actively maintained.',
          'Creates measurable separation between controlled automation gains and unmanaged spam risk.'
        ]
      }
    },
    {
      test: /^\/(calendar|meetings)/,
      data: {
        functionText: `${heading}: Scheduling operations surface that converts outreach intent into booked conversations and keeps follow-up cadence predictable. ${uiContext}`,
        actions: [
          'Manage slots, availability, and booking links to reduce friction from first interest to confirmed meeting.',
          'Use calendar signals to protect high-value selling time and avoid scheduling bottlenecks.',
          'Ensure meeting outcomes flow back into activities/tasks so commitments remain executable.'
        ],
        outcomes: [
          'Persists schedule and booking state tied to pipeline execution timelines.',
          'Increases meeting conversion by shortening the delay between engagement and appointment.',
          'Strengthens operational rhythm by linking schedule data to downstream actions.'
        ]
      }
    },
    {
      test: /^\/command-center/,
      data: {
        functionText: `${heading}: Real-time operational intelligence panel for alerts, metric shifts, and action prioritization across active revenue workflows. ${uiContext}`,
        actions: [
          'Triages alerts by business impact first, then drills into root-cause pages for corrective action.',
          'Use data/actions/metrics views together to convert signal detection into immediate execution.',
          'Treat this page as escalation control, not passive reporting.'
        ],
        outcomes: [
          'Aggregates and surfaces operational events from multiple modules into one response layer.',
          'Reduces reaction latency when opportunities or risks change quickly.',
          'Improves cross-functional coordination by exposing the same critical signal set to all owners.'
        ]
      }
    },
    {
      test: /^\/(comp-crawler|webcrawler|lead-discovery|discovery-pipeline|leads)/,
      data: {
        functionText: `${heading}: Discovery and qualification surface for finding, scoring, and promoting high-fit targets into the active CRM funnel. ${uiContext}`,
        actions: [
          'Apply relevance evidence, dedupe checks, and qualification thresholds before promotion decisions.',
          'Use review/discovered views to keep low-fit noise out of core company and contact datasets.',
          'Treat discovery as a precision pipeline: fewer but higher-fit additions outperform raw volume.'
        ],
        outcomes: [
          'Creates or updates lead/discovery entities with explicit status transitions and review rationale.',
          'Improves top-of-funnel quality and reduces wasted downstream selling effort.',
          'Builds a cleaner candidate stream for ABM, campaign targeting, and RFQ generation.'
        ]
      }
    },
    {
      test: /^\/(companies|contacts|profile|supplier-portal)/,
      data: {
        functionText: `${heading}: Master-data workspace that keeps account and stakeholder records complete, current, and execution-ready. ${uiContext}`,
        actions: [
          'Maintain high-quality account/contact attributes before triggering RFQ, campaign, or meeting actions.',
          'Use discovered/new/edit flows to prevent duplicate or incomplete records from entering core operations.',
          'Treat profile and supplier data as relationship infrastructure, not static reference info.'
        ],
        outcomes: [
          'Persists core CRM entities that every downstream workflow relies on.',
          'Improves personalization, routing accuracy, and handoff quality across teams.',
          'Raises long-term data trust by reducing fragmentation and stale ownership.'
        ]
      }
    },
    {
      test: /^\/(quote-copilot|quote-review|quote|rfqs)/,
      data: {
        functionText: `${heading}: Commercial execution surface where opportunity economics, quote readiness, and stage movement are actively controlled. ${uiContext}`,
        actions: [
          'Validate pricing, risk, and readiness signals before advancing quote or RFQ stage.',
          'Use pipeline and analytics views to separate velocity issues from win-rate quality issues.',
          'Keep commercial decisions traceable so negotiation outcomes can be learned and repeated.'
        ],
        outcomes: [
          'Persists RFQ/quote status changes and supporting decisions tied to revenue forecasting.',
          'Improves conversion quality by enforcing structured commercial checkpoints.',
          'Supports stronger forecast confidence through explicit stage evidence.'
        ]
      }
    },
    {
      test: /^\/(email-campaigns|webinars)/,
      data: {
        functionText: `${heading}: Outreach orchestration surface for campaign/webinar execution, audience management, and engagement telemetry loops. ${uiContext}`,
        actions: [
          'Build or adjust sequences based on observed open/click/reply behavior and audience fit.',
          'Use send and analytics pages as one loop: launch, measure, refine, relaunch.',
          'Coordinate outreach timing with lead and RFQ states so messaging remains context-aware.'
        ],
        outcomes: [
          'Writes campaign/send entities and tracks engagement events for measurable iteration.',
          'Improves outreach efficiency by reducing untargeted volume and increasing response relevance.',
          'Feeds richer engagement signals back into discovery, ABM, and pipeline prioritization.'
        ]
      }
    },
    {
      test: /^\/(reports|currency-converter)/,
      data: {
        functionText: `${heading}: Analytical support layer for decision-grade reporting and commercial normalization workflows. ${uiContext}`,
        actions: [
          'Use this page to standardize comparisons and remove ambiguity from performance interpretation.',
          'Treat report/conversion outputs as operational inputs for next actions, not static artifacts.',
          'Validate assumptions (filters, rates, periods) before sharing outputs externally.'
        ],
        outcomes: [
          'Produces decision-support artifacts and normalized values consumed by sales operations.',
          'Improves consistency of executive and team-level planning decisions.',
          'Reduces avoidable errors caused by inconsistent metrics or manual conversions.'
        ]
      }
    },
    {
      test: /^\/(tasks|guidance)/,
      data: {
        functionText: `${heading}: Execution discipline layer that turns intent into assigned, time-bound, and trackable follow-up actions. ${uiContext}`,
        actions: [
          'Prioritize due actions by urgency and business impact, then close or re-scope explicitly.',
          'Use guidance prompts to avoid workflow gaps after key actions (new company, activity, conversion).',
          'Keep ownership and due-date hygiene strict to prevent hidden pipeline decay.'
        ],
        outcomes: [
          'Persists task/guidance state that operationalizes next steps after major workflow events.',
          'Improves follow-up reliability and reduces dropped commitments.',
          'Creates a visible execution backbone connecting CRM data to real work completion.'
        ]
      }
    },
    {
      test: /^\/(login|register|forgot-password)/,
      data: {
        functionText: `${heading}: Identity and account-protection surface ensuring only authorized users enter and recover access safely. ${uiContext}`,
        actions: [
          'Complete authentication or recovery flow with security-first handling of user identity.',
          'Avoid revealing account existence or sensitive state during login/reset interactions.',
          'Use this route as a control boundary before any operational module is exposed.'
        ],
        outcomes: [
          'Creates authenticated session state or secure recovery flow tokens based on policy.',
          'Reduces account-takeover and enumeration risk in daily operations.',
          'Protects all downstream business workflows through strict access boundaries.'
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
      'We open with shared visibility so leadership can prioritize from one operational truth.',
      'This is where strategic alignment happens before teams split into module-level execution.'
    ],
    'abm-dashboard': [
      'Here we decide which accounts deserve focused attention and coordinated plays.',
      'This is the strategic targeting layer where ABM focus is set before outreach spend.'
    ],
    companies: [
      'This is the master-account layer where downstream execution quality is determined.',
      'Strong account records here prevent friction across every later stage.'
    ],
    contacts: [
      'This is the stakeholder intelligence layer that powers personalized execution.',
      'Accurate contact context here drives better outreach timing and conversion quality.'
    ],
    leads: [
      'This is the qualification frontier where we choose what enters the core funnel.',
      'The team filters for fit here so sales effort stays focused and economical.'
    ],
    'lead-discovery': [
      'This is where discovery volume is converted into qualified opportunity flow.',
      'The objective here is precision: promote only leads with actionable evidence.'
    ],
    rfqs: [
      'This is the commercial control layer where opportunity value is protected.',
      'This is where quote readiness and stage confidence are actively managed.'
    ],
    'email-campaigns': [
      'This is the growth loop where messaging performance becomes measurable learning.',
      'This stage turns outreach execution into repeatable signal-driven improvement.'
    ],
    'command-center': [
      'This is the response center for converting live signals into immediate action.',
      'Here the team turns alerts into coordinated execution before risk compounds.'
    ],
    reports: [
      'This is where operational output becomes decision-grade insight.',
      'This layer translates activity into clear planning and accountability signals.'
    ],
    tasks: [
      'This is the discipline layer where commitments become completed work.',
      'Execution reliability is built here through ownership and due-date control.'
    ]
  };

  const modeLeads = {
    auth: [
      'We begin by protecting access before any revenue workflow is exposed.',
      'Security boundaries are established here before business routes are unlocked.'
    ],
    create: [
      'At this moment, the team creates a new object that unlocks downstream execution.',
      'This is a record-intake step where entry quality determines later velocity.'
    ],
    edit: [
      'At this point, the team corrects and enriches data to preserve trust and momentum.',
      'This step stabilizes active workflows by fixing record quality at the source.'
    ],
    review: [
      'This is a decision gate where quality evidence determines progression.',
      'Here we enforce standards before promoting work to the next lifecycle state.'
    ],
    pipeline: [
      'This stage balances velocity with quality to keep conversion healthy.',
      'Pipeline control happens here: unblock flow while protecting win probability.'
    ],
    analytics: [
      'This stage converts observed outcomes into optimization decisions.',
      'Performance interpretation happens here before strategy is adjusted.'
    ],
    index: [
      'This step gives the team operational control over active work.',
      'Here execution is prioritized and routed to the right owners.'
    ]
  };

  const leadOptions = resourceLeads[resource] || modeLeads[mode] || ['At this stage, this page advances execution in the customer lifecycle.'];
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
    `Route contract from code: ${oneLine(item.routeName)} [${oneLine(item.method)}].`,
    `Documentation basis: ${getModuleProfile(item.routePath || '').doc}.`
  ];
}

const metadata = JSON.parse(fs.readFileSync(metadataPath, 'utf8'));
const entries = metadata.entries || [];

const introSlides = `
<section>
  <h1>CRM v2 Executive Walkthrough</h1>
  <p>Narrative presentation generated from live product pages, routes, and implementation documentation.</p>
  <p><small>Generated: ${esc(new Date(metadata.generatedAt || Date.now()).toLocaleString())}</small></p>
</section>
<section>
  <h2>Business Story</h2>
  <ul>
    <li>Converts fragmented sales activity into one managed lifecycle: discovery → qualification → RFQ → quote → retention</li>
    <li>Links growth engines (ABM, campaigns, web discovery) with execution engines (tasks, meetings, pipeline control)</li>
    <li>Turns operational behavior into measurable outcomes through route-level governance and analytics loops</li>
  </ul>
</section>
<section>
  <h2>How To Present Each Slide</h2>
  <ol>
    <li>Open with narrative: what problem this page solves in the journey</li>
    <li>Show live demo focus: the 2 controls that drive execution</li>
    <li>Close with business outcome: what changes in speed, quality, or conversion</li>
    <li>Reference implementation anchor to prove this is built into the product, not concept-only</li>
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
  ${showSubtitle ? `<p><strong>UI Context:</strong> ${esc(subtitle)}</p>` : ''}
  <p><strong>Narrative:</strong> ${esc(conciseStory)}</p>
  <p><strong>Core Function:</strong> ${esc(conciseFunction)}</p>
  <p><strong>Live Demo Focus:</strong></p>
  <ul>
    <li>${esc(demoBullets[0] || '')}</li>
    <li>${esc(demoBullets[1] || '')}</li>
  </ul>
  <p><strong>Business Outcomes:</strong></p>
  <ul>
    <li>${esc(impactBullets[0] || '')}</li>
    <li>${esc(impactBullets[1] || '')}</li>
  </ul>
  <p><small>Implementation anchor: ${esc(notes[0])} ${esc(notes[1])}</small></p>
</section>
`;
}).join('\n');

const outroSlide = `
<section>
  <h2>Closing Narrative</h2>
  <p>The platform’s advantage is orchestration: every page moves the same revenue journey forward with auditable state transitions and measurable feedback loops.</p>
  <p><small>Demo sequence: Dashboard → Discovery → Master Data → Commercial Pipeline → Execution Discipline → Growth Loops → Reporting.</small></p>
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
