<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Lead;
use App\Entity\Company;
use App\Entity\PersonalizationProfile;
use App\Entity\PersonalizationArchetype;
use App\Entity\OutboundMessage;
use App\Repository\PersonalizationProfileRepository;
use App\Repository\PersonalizationArchetypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Email Personalization Service (ONNX-Equivalent)
 * 
 * Advanced ML-style personalization for outbound emails using:
 * - Feature embeddings (TF-IDF style vectors)
 * - Cosine similarity for profile matching
 * - Learned preferences from interaction history
 * - Archetype profiles for cold-start scenarios
 * - Multi-armed bandit integration for content selection
 * 
 * This provides ONNX-equivalent personalization without requiring
 * actual neural network inference, using classical ML techniques
 * that work well in PHP.
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
class EmailPersonalizationService
{
    // Feature dimensions for embedding (64-dim vector)
    private const EMBEDDING_DIMENSIONS = 64;

    // Industry feature vectors (8-dim) for embedding
    private const INDUSTRY_FEATURES = [
        'automotive'    => [1.0, 0.8, 0.6, 0.9, 0.7, 0.5, 0.3, 0.8],
        'aerospace'     => [0.9, 0.9, 0.7, 0.8, 0.6, 0.7, 0.5, 0.9],
        'industrial'    => [0.7, 0.6, 0.8, 0.7, 0.5, 0.6, 0.4, 0.6],
        'defense'       => [0.8, 0.9, 0.6, 0.7, 0.8, 0.7, 0.6, 0.9],
        'medical'       => [0.8, 0.7, 0.9, 0.6, 0.7, 0.8, 0.5, 0.7],
        'consumer'      => [0.6, 0.4, 0.5, 0.8, 0.3, 0.4, 0.7, 0.5],
        'telecom'       => [0.7, 0.6, 0.7, 0.7, 0.5, 0.6, 0.6, 0.6],
        'renewables'    => [0.7, 0.5, 0.6, 0.6, 0.4, 0.5, 0.5, 0.5],
        'semiconductor' => [0.9, 0.8, 0.8, 0.7, 0.6, 0.7, 0.4, 0.8],
        'rail'          => [0.7, 0.7, 0.6, 0.7, 0.5, 0.6, 0.3, 0.7],
        'hvac'          => [0.5, 0.4, 0.5, 0.6, 0.3, 0.4, 0.5, 0.4],
        'marine'        => [0.6, 0.6, 0.5, 0.6, 0.5, 0.5, 0.3, 0.6],
        'other'         => [0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5],
    ];

    // Role feature vectors (8-dim) for embedding
    private const ROLE_FEATURES = [
        'engineering'    => [0.9, 0.8, 0.7, 0.3, 0.5, 0.6, 0.8, 0.4],
        'procurement'    => [0.4, 0.6, 0.8, 0.7, 0.9, 0.5, 0.3, 0.7],
        'supply_chain'   => [0.5, 0.5, 0.7, 0.6, 0.8, 0.6, 0.4, 0.6],
        'management'     => [0.6, 0.5, 0.5, 0.8, 0.6, 0.7, 0.5, 0.8],
        'quality'        => [0.8, 0.7, 0.6, 0.4, 0.5, 0.9, 0.7, 0.5],
        'operations'     => [0.6, 0.6, 0.7, 0.5, 0.7, 0.6, 0.5, 0.6],
        'design'         => [0.9, 0.7, 0.5, 0.3, 0.4, 0.5, 0.9, 0.3],
        'executive'      => [0.5, 0.4, 0.4, 0.9, 0.5, 0.6, 0.4, 0.9],
        'manufacturing'  => [0.7, 0.7, 0.8, 0.4, 0.6, 0.7, 0.6, 0.5],
        'sales'          => [0.3, 0.4, 0.5, 0.7, 0.5, 0.4, 0.3, 0.7],
        'other'          => [0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5],
    ];

    // Tone templates
    private const TONE_TEMPLATES = [
        PersonalizationProfile::TONE_FORMAL => [
            'greeting' => 'Dear {name}',
            'closing' => 'Best regards',
            'style' => 'We would be pleased to discuss',
        ],
        PersonalizationProfile::TONE_CASUAL => [
            'greeting' => 'Hi {name}',
            'closing' => 'Cheers',
            'style' => 'Would love to chat about',
        ],
        PersonalizationProfile::TONE_DIRECT => [
            'greeting' => '{name}',
            'closing' => 'Best',
            'style' => 'Quick question:',
        ],
        PersonalizationProfile::TONE_FRIENDLY => [
            'greeting' => 'Hi {name}',
            'closing' => 'Thanks',
            'style' => 'Happy to explore',
        ],
    ];

    private const INDUSTRY_VALUE_PROPS = [
        'automotive' => [
            'technical' => 'For automotive electronics, our ISO 9001 certified facility can align on documentation requirements and support PCBA and cable harness scope as needed.',
            'business' => 'Nearshore Morocco-based EMS option for automotive programs; happy to align on cost, lead-time, and qualification steps.',
            'value_focused' => 'Focus on manufacturability, supply continuity, and documentation planning for automotive programs.',
            'relationship' => 'Long‑term program support with clear communication and qualification steps for automotive teams.',
        ],
        'aerospace' => [
            'technical' => 'For aerospace electronics, we can align on documentation requirements and support PCBA and harness scope as needed.',
            'business' => 'Nearshore EMS option for aerospace programs; happy to align on qualification steps and documentation needs.',
            'value_focused' => 'Focus on documentation planning and supply continuity for aerospace programs.',
            'relationship' => 'Structured qualification and communication for long‑cycle aerospace programs.',
        ],
        'industrial' => [
            'technical' => 'For industrial electronics, we can support mixed‑volume PCBA and harness scope with documentation aligned to your requirements.',
            'business' => 'Nearshore EMS option for industrial programs; happy to align on qualification steps and cost/lead‑time targets.',
            'value_focused' => 'Focus on manufacturability, supply continuity, and documentation planning for industrial programs.',
            'relationship' => 'Clear qualification steps and ongoing communication for industrial equipment teams.',
        ],
        'defense' => [
            'technical' => 'For defense electronics, we can align on documentation and qualification requirements before any production work.',
            'business' => 'Nearshore EMS option for defense programs; happy to align on qualification steps and required documentation.',
            'value_focused' => 'Focus on documentation planning and supply continuity for defense programs.',
            'relationship' => 'Structured qualification and communication for long‑term defense programs.',
        ],
        'medical' => [
            'technical' => 'For medical electronics, we can align on documentation requirements and qualification steps for your programs.',
            'business' => 'Nearshore EMS option for medical programs; happy to align on qualification and documentation needs.',
            'value_focused' => 'Focus on documentation planning and supply continuity for medical programs.',
            'relationship' => 'Clear qualification steps and communication for long‑lifecycle medical programs.',
        ],
        'consumer' => [
            'technical' => 'For consumer electronics, we can support PCBA and harness scope with documentation aligned to your requirements.',
            'business' => 'Nearshore EMS option for consumer programs; happy to align on qualification steps and ramp planning.',
            'value_focused' => 'Focus on manufacturability and supply continuity for consumer programs.',
            'relationship' => 'Clear qualification steps and communication for consumer product teams.',
        ],
        'telecom' => [
            'technical' => 'For telecom equipment, we can support PCBA and harness scope with documentation aligned to your requirements.',
            'business' => 'Nearshore EMS option for telecom programs; happy to align on qualification steps and scale‑up needs.',
            'value_focused' => 'Focus on documentation planning and supply continuity for telecom programs.',
            'relationship' => 'Structured qualification and communication for telecom infrastructure teams.',
        ],
        'renewables' => [
            'technical' => 'For renewables power electronics, we can align on documentation and qualification requirements.',
            'business' => 'Nearshore EMS option for renewables programs; happy to align on qualification steps and lifecycle needs.',
            'value_focused' => 'Focus on documentation planning and supply continuity for renewables programs.',
            'relationship' => 'Clear qualification steps and communication for renewables teams.',
        ],
        'semiconductor' => [
            'technical' => 'For semiconductor equipment, we can align on handling and documentation requirements before any production work.',
            'business' => 'Nearshore EMS option for semiconductor programs; happy to align on qualification steps and documentation needs.',
            'value_focused' => 'Focus on documentation planning and supply continuity for semiconductor programs.',
            'relationship' => 'Structured qualification and communication for semiconductor equipment teams.',
        ],
        'rail' => [
            'technical' => 'For rail electronics, we can align on documentation requirements and qualification steps for your programs.',
            'business' => 'Nearshore EMS option for rail programs; happy to align on qualification steps and documentation needs.',
            'value_focused' => 'Focus on documentation planning and supply continuity for rail programs.',
            'relationship' => 'Clear qualification steps and communication for rail teams.',
        ],
        'hvac' => [
            'technical' => 'For HVAC and building automation electronics, we can support PCBA and harness scope with documentation aligned to your requirements.',
            'business' => 'Nearshore EMS option for HVAC programs; happy to align on qualification steps and ramp planning.',
            'value_focused' => 'Focus on manufacturability and supply continuity for HVAC programs.',
            'relationship' => 'Clear qualification steps and communication for HVAC teams.',
        ],
        'marine' => [
            'technical' => 'For marine electronics, we can align on environmental and documentation requirements before any production work.',
            'business' => 'Nearshore EMS option for marine programs; happy to align on qualification steps and documentation needs.',
            'value_focused' => 'Focus on documentation planning and supply continuity for marine programs.',
            'relationship' => 'Clear qualification steps and communication for marine teams.',
        ],
        'other' => [
            'technical' => 'For electronics programs, we can support PCBA and harness scope with documentation aligned to your requirements.',
            'business' => 'Nearshore Morocco‑based EMS option; happy to align on qualification steps and documentation needs.',
            'value_focused' => 'Focus on manufacturability, supply continuity, and documentation planning.',
            'relationship' => 'Clear qualification steps and communication for long‑term programs.',
        ],
    ];

    // ========================= NEW: SOCIAL PROOF BY INDUSTRY =========================
    // Industry-focused capability statements using Cialdini's Social Proof principle
    // IMPROVED: Now YOU-FOCUSED - eliminates self-referential "Trusted by..." language
    // Uses "Companies like yours" and "Teams in your position" patterns
    private const SOCIAL_PROOF = [
        'automotive' => [
            'stat' => 'Automotive OEMs and Tier 1 suppliers across Europe are {achieving|seeing|reporting} {strong|excellent|consistent} results with nearshore manufacturing.',
            'reference' => 'automotive manufacturers in Germany, France, and UK',
            'detail' => 'Your peers in automotive are getting dedicated production lines with full traceability—the same standards you require.',
            'similarity' => 'Other automotive procurement teams like yours',
            'outcome' => 'Procurement teams {like yours|in similar roles|facing your challenges} report {99%+ OTD|significant cost savings|faster time-to-market}.',
        ],
        'aerospace' => [
            'stat' => 'Aerospace programs {requiring|demanding|needing} rigorous quality {are finding|have found|report} success with this approach.',
            'reference' => 'aerospace companies requiring full material traceability',
            'detail' => 'Teams handling complex multilayer assemblies—like yours—get the documentation rigor aerospace programs demand.',
            'similarity' => 'Aerospace quality managers in your position',
            'outcome' => 'Quality managers {like you|in similar programs|with comparable requirements} cite {zero-defect delivery|complete traceability|audit-ready documentation}.',
        ],
        'industrial' => [
            'stat' => 'Industrial OEMs are {benefiting from|seeing value in|reporting success with} flexible capacity and reliable delivery.',
            'reference' => 'industrial equipment manufacturers across Europe',
            'detail' => 'High-mix, low-to-medium volume capability—exactly what industrial programs like yours need.',
            'similarity' => 'Industrial operations teams like yours',
            'outcome' => 'Operations teams {in your situation|like yours|with similar volumes} report {99%+ OTD|reduced inventory|better planning visibility}.',
        ],
        'defense' => [
            'stat' => 'Defense electronics programs {are achieving|have achieved|report} secure, traceable manufacturing.',
            'reference' => 'defense contractors requiring full traceability',
            'detail' => 'Serialization, secure handling, and complete documentation—what programs like yours require.',
            'similarity' => 'Defense program managers in your role',
            'outcome' => 'Program managers {like you|with similar requirements|in defense} cite {100% traceability|secure handling|audit-ready records}.',
        ],
        'medical' => [
            'stat' => 'Medical device manufacturers {are seeing|report|have found} success with validated processes.',
            'reference' => 'medical device companies requiring robust quality systems',
            'detail' => 'Validated processes and full traceability—the standards your medical applications require.',
            'similarity' => 'Medical device quality teams like yours',
            'outcome' => 'Quality teams {in your space|like yours|with similar compliance needs} report {FDA-ready documentation|validated consistency|reduced audit prep time}.',
        ],
        'consumer' => [
            'stat' => 'Consumer electronics brands {are hitting|have hit|report hitting} aggressive launch timelines.',
            'reference' => 'consumer electronics brands hitting aggressive timelines',
            'detail' => 'Rapid prototyping to volume production—the speed your consumer market demands.',
            'similarity' => 'Consumer electronics product managers like you',
            'outcome' => 'Product managers {like you|in CE|with aggressive timelines} report {4-week NPI|seamless ramp|flexible capacity}.',
        ],
        'telecom' => [
            'stat' => 'Telecom equipment manufacturers {are scaling|have scaled|report scaling} production successfully.',
            'reference' => 'telecom equipment manufacturers scaling production',
            'detail' => 'From prototype to volume—the flexibility your network rollouts require.',
            'similarity' => 'Telecom supply chain teams in your position',
            'outcome' => 'Supply chain teams {like yours|in telecom|with similar scale-up needs} cite {capacity flexibility|on-time delivery|responsive support}.',
        ],
        'renewables' => [
            'stat' => 'Renewable energy companies {are achieving|report|have achieved} high-reliability power electronics.',
            'reference' => 'renewable energy companies building for 25-year lifespans',
            'detail' => 'Rigorous testing and quality systems—what your 25-year product lifespans require.',
            'similarity' => 'Renewables engineering teams like yours',
            'outcome' => 'Engineering teams {in renewables|like yours|building for longevity} report {zero-defect assembly|lifecycle reliability|cost-effective quality}.',
        ],
        'semiconductor' => [
            'stat' => 'Semiconductor equipment OEMs {are getting|report|have achieved} precision manufacturing.',
            'reference' => 'semiconductor equipment OEMs requiring high precision',
            'detail' => 'ESD-protected handling and calibrated processes—what your sensitive equipment requires.',
            'similarity' => 'Semiconductor manufacturing teams in your role',
            'outcome' => 'Manufacturing teams {like yours|in semi|with precision requirements} cite {calibrated consistency|ESD excellence|zero-contamination}.',
        ],
        'rail' => [
            'stat' => 'Railway electronics suppliers {are choosing|have chosen|report choosing} nearshore partners for {traction|signaling|onboard} programs.',
            'reference' => 'railway OEMs manufacturing in the EU-adjacent zone',
            'detail' => 'EN 50155 awareness, vibration-resistant assembly, and full traceability for safety-critical rail programs.',
            'similarity' => 'Rail supply chain teams like yours',
            'outcome' => 'Rail procurement teams {like yours|in rolling stock|managing safety-critical programs} cite {full traceability|long-term continuity|obsolescence management}.',
        ],
        'hvac' => [
            'stat' => 'HVAC and building automation manufacturers {are sourcing|report sourcing|have begun sourcing} power electronics from nearshore partners.',
            'reference' => 'HVAC manufacturers seeking cost-effective power electronics',
            'detail' => 'VFD assemblies, controller boards, and IoT-enabled building automation—manufactured to your quality requirements.',
            'similarity' => 'HVAC engineering teams in your position',
            'outcome' => 'Engineering teams {in HVAC|like yours|building for energy efficiency} report {reliable power electronics|cost savings|design flexibility}.',
        ],
        'marine' => [
            'stat' => 'Marine electronics manufacturers {are evaluating|have evaluated|report evaluating} Mediterranean nearshore production.',
            'reference' => 'marine electronics companies producing EU-certified equipment',
            'detail' => 'Conformal-coated, IP-rated assemblies with salt-spray awareness—built for your marine environment.',
            'similarity' => 'Marine electronics teams like yours',
            'outcome' => 'Marine teams {like yours|in navigation/power|building for harsh environments} cite {harsh-environment reliability|EU-proximity shipping|certification support}.',
        ],
        'other' => [
            'stat' => 'Electronics manufacturers across industries {are achieving|report|have found} success with nearshore manufacturing partners.',
            'reference' => 'electronics manufacturers across multiple industries',
            'detail' => 'Flexible capacity and quality processes—built for your program requirements.',
            'similarity' => 'Companies like yours',
            'outcome' => 'Teams {like yours|in similar situations|facing comparable challenges} cite {reliable delivery|quality consistency|responsive support}.',
        ],
    ];
    // ========================= CIALDINI'S 7 PRINCIPLES FRAMEWORK =========================
    // Ethical persuasion elements to incorporate into messaging
    // IMPROVED: Now includes sentence-level spintax for natural variation
    private const CIALDINI_PRINCIPLES = [
        // 1. RECIPROCITY: Give something first, personalized and unexpected
        // Uses spintax {option1|option2|option3} for variation
        'reciprocity' => [
            'free_dfm_review' => '{I can offer|Happy to provide|I\'d be glad to send} a free DFM review on your {first|next|upcoming} design—{no strings attached|no commitment required|completely free}.',
            'industry_insight' => 'I {put together|compiled|prepared} a brief {industry comparison|market analysis|benchmark report} on {nearshore EMS trends|manufacturing cost structures|supply chain risk factors} that {might be useful|could help|may be relevant} for your planning.',
            'capacity_check' => '{Happy to|I can|Would be glad to} run a quick capacity check for your volumes—{takes about 24 hours|usually ready next day|quick turnaround}.',
            'cost_model' => 'I can {provide|send over|put together} a preliminary {landed-cost comparison|total-cost-of-ownership model|TCO analysis} {with no commitment|no strings attached|just to give you a sense of the numbers}.',
            'sample_build' => '{Happy to|I can|Would be glad to} build a {sample|prototype|first-article} at our cost—{see the quality firsthand|evaluate our workmanship|assess our capability before committing}.',
        ],
        // 2. SCARCITY: Unique benefits they stand to lose
        'scarcity' => [
            'capacity' => '{Limited|A few} capacity slots {are opening|become available} in {Q2|the coming quarter}—programs typically book {8-12 weeks|2-3 months} ahead.',
            'location' => 'Our North Africa facilities are {uniquely positioned|ideally located|strategically placed} for EU customers—{same time zone|real-time communication|no overnight delays}, {simplified logistics|streamlined shipping|efficient delivery}.',
            'expertise' => '{Few|Not many} EMS providers offer this {combination|blend|mix} of nearshore economics with European quality standards.',
            'timing' => 'Current component lead times make {early planning|advance planning|production planning} {critical|essential|important}—{earlier engagement|starting now|getting ahead} means {smoother ramp|easier transition|better outcomes}.',
        ],
        // 3. AUTHORITY: Signal credible expertise with industry-specific certifications
        'authority' => [
            'experience' => 'The engineering team brings {decades|years|extensive} {automotive and aerospace|high-reliability|demanding industry} manufacturing experience across {PCBA, cable harness, and system integration|multiple product categories|full box-build capability}.',
            'certification' => 'ISO 9001 certified with {IPC-A-610 Class 2/3|IPC-A-610 certified|IPC-trained} operators, {automotive quality system|automotive-grade|industry-leading} processes, and {full AOI coverage|100% automated optical inspection|comprehensive inspection}.',
            'process' => '{100%|Full} AOI, {in-circuit testing|functional testing|comprehensive testing}, and {X-ray inspection for BGA/QFN|X-ray capability|advanced inspection} standard on all PCBA programs.',
            'track_record' => '{Multi-year|Long-term|Ongoing} programs running for {leading|major|top-tier} European {automotive OEMs and Tier 1 suppliers|aerospace and industrial OEMs|manufacturers}.',
            'tangier_fz' => 'Operating from the {Tangier Free Zone|TFZ|Tangier automotive cluster} alongside {200+ international manufacturers|major multinational operations|global automotive suppliers}.',
        ],
        // 4. CONSISTENCY: Get small commitments leading to larger ones
        'consistency' => [
            'micro_commitment' => 'Would a {quick|brief|short} {15-minute|15-min|quarter-hour} {overview|call|chat} be useful?',
            'next_step' => 'Even if timing {isn\'t immediate|is later|isn\'t right now}, a brief call {helps understand|clarifies|establishes} your requirements.',
            'information_request' => '{Happy to|I can|Would be glad to} send {detailed|comprehensive|thorough} capability info—{what format works best|PDF or web link|how would you like it}?',
            'pilot_suggestion' => 'Many {customers|teams|companies} start with a {small pilot|trial run|initial project} to validate quality and service.',
        ],
        // 5. LIKING: Build rapport through similarity and genuine compliments
        'liking' => [
            'industry_knowledge' => 'Having worked with {companies|teams|organizations} in your industry, I {understand|appreciate|recognize} the {unique pressures|specific challenges|demands} you face.',
            'challenge_empathy' => '{Supply chain disruption has|Recent supply chain shifts have|Manufacturing headwinds have} made {finding reliable partners|production sourcing|program planning} {particularly challenging|more difficult|tougher} {recently|lately|these days}.',
            'company_compliment' => 'Your company\'s {reputation for quality|track record|standing in the industry} makes you {exactly|precisely} the kind of partner {worth pursuing|to work with|to build with}.',
            'shared_values' => 'There\'s a shared commitment here to {quality and on-time delivery|reliability and excellence|getting it right}.',
        ],
        // 6. SOCIAL PROOF: Show similar others are doing it (YOU-FOCUSED)
        'social_proof' => [
            'industry_peers' => 'Companies {like yours|in your industry|similar to {{company_name}}} are {increasingly|actively|more often} {diversifying|expanding|rethinking} their manufacturing footprint.',
            'trend' => '{Growing|Increasing|More} interest from European OEMs {exploring|evaluating|considering} nearshore alternatives.',
            'behavior' => 'Many {procurement teams|sourcing groups|supply chain leads} are running {parallel sourcing|dual-source|backup supplier} evaluations this quarter.',
            'outcome' => 'Teams {like yours|in similar situations|facing these challenges} consistently {cite|mention|highlight} responsiveness and quality as {key differentiators|what matters most|the deciding factors}.',
        ],
        // 7. UNITY: Shared identity and belonging (YOU-FOCUSED)
        'unity' => [
            'regional' => 'Working within the European ecosystem means {shared understanding|common ground|alignment} on {EU regulatory requirements|compliance standards|business culture}.',
            'industry' => 'A commitment to the {long-term success|sustainable growth|continued strength} of European manufacturing.',
            'partnership' => 'Think of this as an extension of your team, {not just a supplier|not a vendor relationship|a true partnership}.',
            'values' => 'A quality-first culture that {aligns with|matches|complements} the standards your customers expect.',
        ],
    ];

    // ========================= PRE-SUASION T.I.M.E. FRAMEWORK =========================
    // Target mindsets, Identify triggers, Move triggers to optimal moment, Extend impact
    // FIXED: Converted from fragments to curiosity-inducing QUESTIONS per report
    private const PRESUASION_ELEMENTS = [
        // TARGET: Mindset-priming openers - NOW AS CURIOSITY QUESTIONS
        'target_mindsets' => [
            'quality_focused' => 'When was the last time a supplier surprised you with quality that exceeded spec?',
            'cost_conscious' => 'What would it mean for your P&L if you could cut 15% from your assembly costs without touching quality?',
            'risk_aware' => 'If your primary supplier went down tomorrow, how long until production stops?',
            'growth_oriented' => 'What\'s the one manufacturing constraint that\'s limiting your growth right now?',
            'innovation_driven' => 'How much faster could you iterate if your EMS partner matched your engineering speed?',
        ],
        // IDENTIFY: Trigger associations
        'triggers' => [
            'reliability' => ['consistent', 'dependable', 'proven', 'trusted'],
            'value' => ['competitive', 'efficient', 'optimized', 'strategic'],
            'partnership' => ['collaborative', 'responsive', 'aligned', 'dedicated'],
            'expertise' => ['experienced', 'specialized', 'capable', 'qualified'],
        ],
        // MOVE: Privileged moment timing
        'privileged_moments' => [
            'budget_cycle' => 'Q4 planning and Q1 budget allocation',
            'project_start' => 'New product development phases',
            'supplier_review' => 'Annual supplier evaluation periods',
            'capacity_crunch' => 'When current suppliers are at capacity',
        ],
        // EXTEND: Long-term relationship anchors
        'extend_impact' => [
            'roadmap' => 'We plan 3-5 years ahead with our customers.',
            'continuous_improvement' => 'Quarterly business reviews drive ongoing optimization.',
            'investment' => 'We invest in customer-specific tooling and capabilities.',
            'partnership' => 'Our longest customer relationships span over a decade.',
        ],
    ];

    // ========================= GEOGRAPHIC VALUE PROPOSITIONS =========================
    // Regional-specific benefits based on customer location
    private const GEOGRAPHIC_VALUE_PROPS = [
        'eu' => [
            'logistics' => 'Nearshore logistics options for EU programs.',
            'timezone' => 'Working-hours overlap with EU teams.',
            'trade' => 'We can align on customs documentation for EU-bound shipments.',
            'cultural' => 'Multilingual coordination (French/English).',
            'proximity' => 'Short-haul travel options for site visits.',
        ],
        'uk' => [
            'logistics' => 'Direct shipping options to the UK can be evaluated per program.',
            'timezone' => 'Working-hours overlap with UK teams.',
            'trade' => 'We can align on UK-bound documentation requirements.',
            'cultural' => 'English-language coordination.',
            'proximity' => 'Travel access for reviews can be arranged.',
        ],
        'us' => [
            'logistics' => 'Atlantic shipping and air freight options can be evaluated.',
            'timezone' => 'Partial overlap with US East Coast hours.',
            'trade' => 'We can align on documentation for US-bound shipments.',
            'cultural' => 'Experience coordinating with US-based teams.',
            'proximity' => 'Nearshore alternative versus far-offshore options.',
        ],
        'mena' => [
            'logistics' => 'Regional shipping routes across North Africa and the Gulf can be evaluated.',
            'timezone' => 'Shared time zone supports real-time collaboration.',
            'trade' => 'We can align on regional documentation requirements.',
            'cultural' => 'Arabic, French, and English coordination.',
            'proximity' => 'Regional proximity for site visits when needed.',
        ],
        'morocco' => [
            'logistics' => 'Local manufacturing simplifies domestic logistics.',
            'timezone' => 'Same time zone for local programs.',
            'trade' => 'We can align on local compliance and export documentation.',
            'cultural' => 'Local partner with shared language and business practices.',
            'proximity' => 'On-site collaboration can be scheduled easily.',
        ],
        'global' => [
            'logistics' => 'Nearshore logistics options bridging Europe, Africa, and the Americas.',
            'timezone' => 'GMT+1 provides overlap across regions.',
            'trade' => 'We can align on documentation needs for different markets.',
            'cultural' => 'Multilingual coordination and international program experience.',
            'proximity' => 'Nearshore alternative to distant offshore manufacturing.',
        ],
    ];

    // ========================= COMPETITOR HOOKS =========================
    // Factual positioning against common EMS competitors (no defamatory claims)
    private const COMPETITOR_HOOKS = [
        'jabil' => [
            'hook' => 'Looking for the agility of a focused EMS partner with the quality standards of a global provider?',
            'differentiator' => 'Unlike high-volume mega-EMS providers, we offer dedicated program management and engineering attention for mid-volume programs without minimum order thresholds.',
            'value' => 'Personalized service with direct access to your production line and engineering team.',
        ],
        'flex' => [
            'hook' => 'Need the reliability of a Tier 1 EMS without the complexity of a multinational supply chain?',
            'differentiator' => 'Our nearshore model delivers the same quality certifications with shorter lead times and a single point of contact for your programs.',
            'value' => 'Simplified supply chain with EU-adjacent manufacturing and faster decision cycles.',
        ],
        'celestica' => [
            'hook' => 'Seeking aerospace-grade quality with more flexible NRE structures?',
            'differentiator' => 'We match high-reliability manufacturing standards while offering competitive NRE terms and faster prototype turnaround for mid-volume programs.',
            'value' => 'Same quality rigor, more flexible engagement model.',
        ],
        'lacroix' => [
            'hook' => 'Looking to complement your European EMS capacity with a cost-competitive nearshore option?',
            'differentiator' => 'North Africa-based manufacturing provides EU proximity with competitive economics—ideal as a second source or overflow capacity partner.',
            'value' => 'Geographic diversification with maintained EU-standard quality.',
        ],
        'asteelflash' => [
            'hook' => 'Exploring alternatives for your European electronics manufacturing needs?',
            'differentiator' => 'Our Tangier Free Zone facility offers competitive pricing with EU free trade access, dedicated production lines, and engineering support.',
            'value' => 'Cost-effective European-quality manufacturing with FTA benefits.',
        ],
        'cofidur' => [
            'hook' => 'Need EMS capabilities that scale from prototype to series production?',
            'differentiator' => 'Full PCBA, cable harness, and system integration under one roof with competitive pricing from our North Africa facilities.',
            'value' => 'Integrated manufacturing services from a single nearshore partner.',
        ],
    ];

    // ========================= SENTENCE FUSION TEMPLATES =========================
    // CRITICAL FIX: Weave Cialdini elements into coherent paragraphs instead of concatenating
    // This eliminates the "Mad Libs" robotic feel identified in the quality report
    private const FUSION_TEMPLATES = [
        // Reciprocity → Authority flow
        'reciprocity_to_authority' => [
            "{reciprocity}—and with {authority}, you'd get the kind of results that actually matter for your programs.",
            "I'd be happy to {reciprocity_action}. {authority}, so you'll get insights that are actually relevant to {industry} challenges.",
            "{reciprocity} The team brings {authority}, which means the analysis will be tailored to your specific requirements.",
        ],
        // Liking → Social Proof flow  
        'liking_to_social_proof' => [
            "{liking} That's exactly why {social_proof}—they faced similar pressures and found this approach fit their needs.",
            "Given that {liking}, you might find it relevant that {social_proof}.",
            "{liking} In fact, {social_proof}.",
        ],
        // Authority → Scarcity flow
        'authority_to_scarcity' => [
            "With {authority}, there's been strong demand—{scarcity}.",
            "{authority} As a result, {scarcity}.",
            "Because {authority}, {scarcity}.",
        ],
        // Social Proof → Consistency flow
        'social_proof_to_consistency' => [
            "{social_proof} {consistency}",
            "Given that {social_proof}, I thought it might be worth asking: {consistency}",
            "{social_proof} If that resonates, {consistency}",
        ],
        // Unity → Value Prop flow
        'unity_to_value' => [
            "{unity} That shared commitment is why {value_prop_short}",
            "Because {unity}, partnerships here work differently: {value_prop_short}",
            "{unity} Specifically, {value_prop_short}",
        ],
        // Pain → Reciprocity flow
        'pain_to_reciprocity' => [
            "If {pain_hook} is on your radar, {reciprocity}",
            "I know {pain_hook} can be challenging — {reciprocity}",
            "If {pain_hook} is something you're looking at, {reciprocity}",
        ],
        // Geographic → Scarcity flow
        'geo_to_scarcity' => [
            "{geo_logistics} Combined with {scarcity}, the timing might be right to explore.",
            "For your region, {geo_logistics}. Worth noting: {scarcity}.",
            "{geo_logistics}—and {scarcity}.",
        ],
        // NEW: Opening hook → Value flow (for cold emails)
        'opener_to_value' => [
            "{presuasive_opener} {value_prop_short}",
            "{presuasive_opener} Here's why that matters: {value_prop_short}",
            "{presuasive_opener} {pain_hook}—and there's a straightforward solution.",
        ],
        // NEW: Proof → CTA flow (natural close)
        'proof_to_cta' => [
            "{social_proof} {consistency}",
            "Given that {social_proof}, {consistency}",
            "{social_proof} If any of that resonates: {consistency}",
        ],
    ];

    // ========================= ENGAGEMENT-BASED TEMPLATE ARCHITECTURE =========================
    // Different email structures for cold/warm/hot leads with Cialdini principle limits
    private const TEMPLATE_ARCHITECTURES = [
        'cold' => [
            'structure' => 'hook → curiosity → single_ask',
            'max_sentences' => 3,
            'max_paragraphs' => 2,
            'cialdini_limit' => 2,  // Only 2 principles max for cold
            'allowed_principles' => ['reciprocity', 'liking', 'social_proof'],
            'avoid_principles' => ['scarcity', 'authority'],  // Too salesy for cold
            'tone_preference' => 'casual',
        ],
        'warm' => [
            'structure' => 'rapport → value → social_proof → ask',
            'max_sentences' => 5,
            'max_paragraphs' => 3,
            'cialdini_limit' => 4,
            'allowed_principles' => ['reciprocity', 'liking', 'social_proof', 'authority', 'consistency'],
            'avoid_principles' => [],
            'tone_preference' => 'friendly',
        ],
        'hot' => [
            'structure' => 'personalized_reference → proposal → next_step',
            'max_sentences' => 7,
            'max_paragraphs' => 4,
            'cialdini_limit' => 6,
            'allowed_principles' => ['reciprocity', 'scarcity', 'authority', 'consistency', 'liking', 'social_proof', 'unity'],
            'avoid_principles' => [],
            'tone_preference' => 'formal',
        ],
    ];

    // ========================= CURIOSITY-GAP SUBJECT LINE PATTERNS =========================
    // Subject lines that create information gaps and compel opens
    private const CURIOSITY_SUBJECT_PATTERNS = [
        'question' => [
            'Quick question about {{company_name}}\'s {pain_area}',
            '{{first_name}}, wondering about {{company_name}}',
            'Is {{company_name}} seeing this too?',
        ],
        'intrigue' => [
            'The {{industry}} sourcing mistake I see every week',
            'Something about {{company_name}} caught my eye',
            '{{first_name}}, saw something interesting',
        ],
        'social_proof' => [
            'How {{similar_company}} solved their {pain_area}',
            '{{industry}} trend worth watching',
            'What {{industry}} teams are doing differently',
        ],
        'specificity' => [
            '3 options for {{company_name}}\'s {pain_area}',
            '{{first_name}} - quick {{industry}} note',
            'Re: {{company_name}} manufacturing',
        ],
        'value_forward' => [
            'Free {reciprocity_offer} for {{company_name}}',
            '{{industry}} benchmark data inside',
            'Quick win for {{company_name}}\'s sourcing',
        ],
    ];

    // ========================= NEW: CONTENT LENGTH TEMPLATES =========================
    // Engagement-adaptive content length
    private const CONTENT_LENGTH_SETTINGS = [
        'brief' => [
            'max_sentences' => 3,
            'cta_style' => 'single_question',
            'detail_level' => 'minimal',
        ],
        'standard' => [
            'max_sentences' => 5,
            'cta_style' => 'soft_ask',
            'detail_level' => 'moderate',
        ],
        'detailed' => [
            'max_sentences' => 8,
            'cta_style' => 'full_proposal',
            'detail_level' => 'comprehensive',
        ],
    ];
    
    // ========================= P4-3 FIX: CONFIGURABLE WARMTH SCORING =========================
    // Warmth indicators for email quality scoring
    // Higher positive values = warmer/more personal email
    // Negative values = colder/more corporate-sounding
    // 
    // Tuning guide:
    // - Increase warm weights to reward personal language more
    // - Increase cold penalties to penalize corporate-speak more
    // - Adjust based on A/B test results showing correlation with reply rates
    //
    // Default weights calibrated for B2B sales outreach
    private const WARMTH_INDICATORS_POSITIVE = [
        '/\byou\b/i' => 2.0,              // You-focused language (highly valued)
        '/\byour\b/i' => 1.5,             // Possessive you
        '/\?\s*$/' => 1.5,                // Questions increase engagement
        '/\bI\'d love\b/i' => 1.5,        // Warm, enthusiastic phrasing
        '/\bI\'d be happy\b/i' => 1.2,    // Helpful, accommodating tone
        '/\bhappy to\b/i' => 1.0,         // Helpful disposition
        '/\bthought you\b/i' => 1.0,      // Personal thought, shows effort
        '/\bwondering\b/i' => 0.8,        // Curiosity, not demanding
        '/\bquick\b/i' => 0.5,            // Casual, time-respectful
        '/\bchat\b/i' => 0.5,             // Informal, approachable
        '/\bexcited\b/i' => 0.7,          // Enthusiasm
        '/\bcurious\b/i' => 0.6,          // Interest
        '/\binteresting\b/i' => 0.4,      // Engagement
    ];

    private const WARMTH_INDICATORS_NEGATIVE = [
        '/\bwe\b/i' => -1.5,              // We-focused (self-centered)
        '/\bour\b/i' => -1.0,             // Our-focused (self-centered)
        '/\bplease find\b/i' => -2.0,     // Corporate attachment speak
        '/\bkindly\b/i' => -1.5,          // Overly formal, distant
        '/\bhereby\b/i' => -2.0,          // Legal/contract language
        '/\bpursuant\b/i' => -2.0,        // Legal jargon
        '/\baforementioned\b/i' => -1.5,  // Stuffy, archaic
        '/\bper our\b/i' => -1.5,         // Corporate reference
        '/\bat your earliest\b/i' => -1.5, // Formal pressure
        '/\bdo not hesitate\b/i' => -1.0, // Filler phrase
        '/\bsir\/madam\b/i' => -2.0,      // Impersonal salutation
        '/\bdear sir\b/i' => -2.0,        // Impersonal salutation
        '/\brevert\b/i' => -1.5,          // Corporate jargon
        '/\bkindly revert\b/i' => -2.5,   // Heavy corporate jargon
    ];

    // Warmth score thresholds
    private const WARMTH_THRESHOLD_EXCELLENT = 7.0;
    private const WARMTH_THRESHOLD_GOOD = 5.5;
    private const WARMTH_THRESHOLD_MINIMUM = 5.0;
    private const WARMTH_BASE_SCORE = 5.0;
    private const WARMTH_MAX_MULTIPLIER = 3;  // Cap pattern matches at 3x weight

    // Content focus configuration by content preference type
    private const CONTENT_FOCUS = [
        'technical'      => ['emphasis' => 'specifications', 'detail' => 'high', 'data' => true,  'emotional' => false],
        'business'       => ['emphasis' => 'ROI',            'detail' => 'medium', 'data' => true,  'emotional' => false],
        'value_focused'  => ['emphasis' => 'benefits',       'detail' => 'medium', 'data' => false, 'emotional' => true],
        'relationship'   => ['emphasis' => 'partnership',    'detail' => 'low',    'data' => false, 'emotional' => true],
    ];

    // ========================= NEW: SEND TIME OPTIMIZATION =========================
    // Default optimal send times by day and region
    private const DEFAULT_SEND_TIMES = [
        'weekday_morning' => '09:00',     // Tuesday-Thursday, 9-10 AM
        'weekday_afternoon' => '14:00',   // Tuesday-Thursday, 2-3 PM
        'monday_morning' => '10:00',      // Later start for Mondays
        'friday_afternoon' => '11:00',    // Earlier on Fridays
    ];

    private const OPTIMAL_SEND_DAYS = ['Tuesday', 'Wednesday', 'Thursday'];

    // Extended industry features (covers more industries)
    private const EXTENDED_INDUSTRY_FEATURES = [
        'automotive' => [0.9, 0.8, 0.7, 0.6, 0.1, 0.2, 0.3, 0.4],
        'aerospace' => [0.8, 0.9, 0.6, 0.5, 0.2, 0.3, 0.4, 0.5],
        'industrial' => [0.7, 0.6, 0.9, 0.5, 0.3, 0.4, 0.5, 0.3],
        'defense' => [0.6, 0.7, 0.5, 0.9, 0.4, 0.5, 0.6, 0.2],
        'medical' => [0.5, 0.4, 0.3, 0.2, 0.9, 0.8, 0.7, 0.6],
        'consumer' => [0.4, 0.3, 0.2, 0.1, 0.8, 0.9, 0.8, 0.7],
        'telecom' => [0.3, 0.2, 0.4, 0.3, 0.7, 0.6, 0.9, 0.8],
        'renewables' => [0.6, 0.5, 0.8, 0.4, 0.5, 0.6, 0.7, 0.5],
        'power' => [0.5, 0.4, 0.8, 0.5, 0.4, 0.5, 0.7, 0.6],
        'rail' => [0.7, 0.6, 0.7, 0.5, 0.3, 0.4, 0.5, 0.4],
        'marine' => [0.6, 0.5, 0.6, 0.5, 0.4, 0.5, 0.5, 0.5],
        'hvac' => [0.5, 0.4, 0.7, 0.4, 0.5, 0.6, 0.6, 0.4],
        'lighting' => [0.4, 0.3, 0.5, 0.3, 0.7, 0.8, 0.6, 0.5],
        'semiconductor' => [0.7, 0.8, 0.5, 0.4, 0.5, 0.4, 0.8, 0.7],
        'other' => [0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PersonalizationProfileRepository $profileRepository,
        private ?PersonalizationArchetypeRepository $archetypeRepository,
        private ?ThompsonSamplerService $thompsonSampler,
        private ?SpintaxEngineService $spintaxEngine,
        private LoggerInterface $logger
    ) {}

    /**
     * Get personalization context for a contact without applying to a template
     * 
     * This is used by the orchestrator to get all personalization variables
     * which are then passed to the spintax engine for template rendering.
     * 
     * @param Contact $contact The contact to personalize for
     * @param array $additionalVariables Additional variables to merge
     * @return array Full personalization context with all variables
     */
    public function getPersonalizationContext(Contact $contact, array $additionalVariables = []): array
    {
        // Get or create personalization profile
        $profile = $this->profileRepository->findOrCreateForContact($contact->getId());
        
        // Generate embedding if not exists
        if (empty($profile->getFeatureEmbedding())) {
            $embedding = $this->generateContactEmbedding($contact);
            $profile->setFeatureEmbedding($embedding);
            $this->entityManager->persist($profile);
            $this->entityManager->flush();
        }
        
        // Find similar high-performing profiles for learning
        $similarProfiles = $this->findSimilarSuccessfulProfiles($profile, $contact);
        
        // Determine optimal tone and content focus
        $optimalSettings = $this->determineOptimalSettings($profile, $similarProfiles);
        
        // Build personalization context
        $context = $this->buildPersonalizationContext($contact, $profile, $optimalSettings);
        
        // Merge with additional variables (additional vars override defaults)
        $variables = array_merge($context['variables'], $additionalVariables);
        
        // Apply tone transformations will happen when the template is rendered
        return [
            'variables' => $variables,
            'profile' => $profile,
            'settings' => $optimalSettings,
            'tone' => $context['tone'],
            'content' => $context['content'],
            'industry' => $context['industry'] ?? 'other',
            'role' => $context['role'] ?? 'other',
            'contentLength' => $context['contentLength'] ?? 'standard',
            'engagementLevel' => $context['engagementLevel'] ?? 'cold',  // NEW: For template architecture
            'similarProfilesUsed' => count($similarProfiles),
            'successfulPatterns' => $profile->getSuccessfulSubjectPatterns(),
        ];
    }

    /**
     * Generate personalized email content for a contact
     */
    public function personalizeEmail(
        Contact $contact,
        string $templateSubject,
        string $templateBody,
        array $additionalVariables = []
    ): array {
        // Get or create personalization profile
        $profile = $this->profileRepository->findOrCreateForContact($contact->getId());
        
        // Generate embedding if not exists
        if (empty($profile->getFeatureEmbedding())) {
            $embedding = $this->generateContactEmbedding($contact);
            $profile->setFeatureEmbedding($embedding);
            $this->entityManager->persist($profile);
            $this->entityManager->flush();
        }
        
        // Find similar high-performing profiles for learning
        // Now includes archetype profiles for cold-start scenarios
        $similarProfiles = $this->findSimilarSuccessfulProfiles($profile, $contact);
        
        // Determine optimal tone and content focus
        $optimalSettings = $this->determineOptimalSettings($profile, $similarProfiles);
        
        // Build personalization context
        $context = $this->buildPersonalizationContext($contact, $profile, $optimalSettings);
        
        // Merge with additional variables
        $variables = array_merge($context['variables'], $additionalVariables);
        
        // Apply personalization to template
        $personalizedSubject = $this->applyPersonalization($templateSubject, $variables, $context);
        $personalizedBody = $this->applyPersonalization($templateBody, $variables, $context);
        
        // Apply spintax if available
        if ($this->spintaxEngine) {
            $personalizedSubject = $this->spintaxEngine->spin($personalizedSubject);
            $personalizedBody = $this->spintaxEngine->spin($personalizedBody);
        }
        
        return [
            'subject' => $personalizedSubject,
            'body' => $personalizedBody,
            'profile' => $profile,
            'settings' => $optimalSettings,
            'context' => $context,
            'similarProfilesUsed' => count($similarProfiles),
        ];
    }

    /**
     * Generate feature embedding for a contact
     */
    public function generateContactEmbedding(Contact $contact): array
    {
        $embedding = array_fill(0, self::EMBEDDING_DIMENSIONS, 0.0);
        
        // Industry features (first 8 dimensions)
        $company = $contact->getCompany();
        $industry = $company ? strtolower($company->getSector() ?? 'other') : 'other';
        $industryFeatures = self::INDUSTRY_FEATURES[$industry] ?? self::INDUSTRY_FEATURES['other'];
        for ($i = 0; $i < 8; $i++) {
            $embedding[$i] = $industryFeatures[$i];
        }
        
        // Role features (dimensions 8-15)
        $role = $this->inferRoleCategory($contact->getJobTitle() ?? '');
        $roleFeatures = self::ROLE_FEATURES[$role] ?? self::ROLE_FEATURES['other'];
        for ($i = 0; $i < 8; $i++) {
            $embedding[$i + 8] = $roleFeatures[$i];
        }
        
        // Company size features (dimensions 16-23)
        if ($company) {
            $sizeScore = $this->normalizeCompanySize($company);
            for ($i = 0; $i < 8; $i++) {
                $embedding[$i + 16] = $sizeScore * (1 - $i * 0.1);
            }
        }
        
        // Geographic features (dimensions 24-31)
        $geoFeatures = $this->extractGeographicFeatures($contact);
        for ($i = 0; $i < 8; $i++) {
            $embedding[$i + 24] = $geoFeatures[$i] ?? 0.5;
        }
        
        // Behavioral features (dimensions 32-47) - based on interaction history
        $profile = $this->profileRepository->findByContactId($contact->getId());
        if ($profile) {
            $behaviorFeatures = $this->extractBehavioralFeatures($profile);
            for ($i = 0; $i < 16; $i++) {
                $embedding[$i + 32] = $behaviorFeatures[$i] ?? 0.5;
            }
        }
        
        // Text features from company/contact data (dimensions 48-63)
        $textFeatures = $this->extractTextFeatures($contact);
        for ($i = 0; $i < 16; $i++) {
            $embedding[$i + 48] = $textFeatures[$i] ?? 0.0;
        }
        
        // Normalize embedding
        return $this->normalizeVector($embedding);
    }

    /**
     * Find similar profiles that have had successful engagement
     * 
     * TOP-K FALLBACK LADDER (replaces hard sim > 0.5 threshold):
     *
     *   Rung 1  — Exact ICP cohort: same industry × same role family.
     *             Return top-K (K=5) by cosine similarity × recency-weighted engagement.
     *             If K profiles found, stop here.
     *
     *   Rung 2  — Broad cohort: same industry, any role (or same role, any industry).
     *             Fill remaining slots from this wider pool.
     *
     *   Rung 3  — Global pool: all high-engagement profiles.
     *             Fill remaining slots from entire pool.
     *
     * De-duplication: max 2 profiles per company to prevent one company
     * dominating the neighbor set.
     *
     * Math:
     *   score(p) = sim(embedding_target, embedding_p) × engagement_recency(p)
     *   where engagement_recency uses recency-weighted scoring from PersonalizationProfile
     *
     * @param PersonalizationProfile $targetProfile The profile to find matches for
     * @param Contact|null $contact Optional contact for additional context
     * @return array Array of similar successful profiles with similarity scores
     */
    public function findSimilarSuccessfulProfiles(PersonalizationProfile $targetProfile, ?Contact $contact = null): array
    {
        $targetEmbedding = $targetProfile->getFeatureEmbedding();
        if (empty($targetEmbedding)) {
            return [];
        }

        $K = 5; // target neighbor count

        // Determine ICP attributes from contact
        $targetIndustry = null;
        $targetRole = null;
        if ($contact) {
            $company = $contact->getCompany();
            $targetIndustry = strtolower($company?->getSector() ?? '');
            $targetRole = $this->inferRoleCategory($contact->getJobTitle() ?? '');
        }

        // Get ALL profiles with embeddings and at least some engagement (broad pool)
        $allCandidates = $this->profileRepository->findHighEngagement(1, 0); // min 1 open, 0 replies

        // Score all candidates against target
        $scored = [];
        foreach ($allCandidates as $candidate) {
            if ($candidate->getId() === $targetProfile->getId()) continue;

            $candidateEmbedding = $candidate->getFeatureEmbedding();
            if (empty($candidateEmbedding)) continue;

            $similarity = $this->cosineSimilarity($targetEmbedding, $candidateEmbedding);
            if ($similarity <= 0.0) continue; // negative sim = anti-correlated

            // Determine candidate's ICP attributes
            $candidateIndustry = null;
            $candidateRole = null;
            $candidateCompanyId = $candidate->getCompanyId();
            $meta = $candidate->getMetadata() ?? [];
            $candidateIndustry = strtolower($meta['industry'] ?? '');
            $candidateRole = $meta['role_category'] ?? '';

            // Determine cohort rung (1=exact, 2=broad, 3=global)
            $rung = 3;
            if ($targetIndustry && $targetRole) {
                $sameIndustry = $candidateIndustry === $targetIndustry;
                $sameRole = $candidateRole === $targetRole;
                if ($sameIndustry && $sameRole) {
                    $rung = 1;
                } elseif ($sameIndustry || $sameRole) {
                    $rung = 2;
                }
            }

            $scored[] = [
                'profile' => $candidate,
                'similarity' => $similarity,
                'engagementScore' => $candidate->getEngagementScore(),
                'companyId' => $candidateCompanyId,
                'rung' => $rung,
            ];
        }

        // Sort within each rung by score = similarity × engagement
        usort($scored, function ($a, $b) {
            // Primary: rung (lower = better)
            if ($a['rung'] !== $b['rung']) return $a['rung'] <=> $b['rung'];
            // Secondary: score
            $scoreA = $a['similarity'] * $a['engagementScore'];
            $scoreB = $b['similarity'] * $b['engagementScore'];
            return $scoreB <=> $scoreA;
        });

        // Select top-K with de-duplication (max 2 per company)
        $selected = [];
        $companyCounts = [];
        foreach ($scored as $entry) {
            if (count($selected) >= $K) break;

            // De-dup: max 2 per company
            $cid = $entry['companyId'];
            if ($cid) {
                $companyCounts[$cid] = ($companyCounts[$cid] ?? 0) + 1;
                if ($companyCounts[$cid] > 2) continue;
            }

            $selected[] = $entry;
        }

        return $selected;
    }

    /**
     * Determine optimal personalization settings
     */
    private function determineOptimalSettings(PersonalizationProfile $profile, array $similarProfiles): array
    {
        // Start with profile's own preferences
        $settings = [
            'tone' => $profile->getPreferredTone(),
            'content' => $profile->getPreferredContent(),
            'style' => $profile->getPreferredStyle(),
            'topicEmphasis' => $profile->getTopicInterests(),
        ];
        
        // If profile has low engagement, learn from similar successful profiles
        if ($profile->getEngagementScore() < 30 && !empty($similarProfiles)) {
            $toneCounts = [];
            $contentCounts = [];
            
            foreach ($similarProfiles as $similar) {
                $simProfile = $similar['profile'];
                $weight = $similar['similarity'] * $similar['engagementScore'] / 100;
                
                $tone = $simProfile->getPreferredTone();
                $content = $simProfile->getPreferredContent();
                
                $toneCounts[$tone] = ($toneCounts[$tone] ?? 0) + $weight;
                $contentCounts[$content] = ($contentCounts[$content] ?? 0) + $weight;
            }
            
            // Use most successful tone/content from similar profiles
            if (!empty($toneCounts)) {
                arsort($toneCounts);
                $settings['tone'] = array_key_first($toneCounts);
            }
            
            if (!empty($contentCounts)) {
                arsort($contentCounts);
                $settings['content'] = array_key_first($contentCounts);
            }
            
            $settings['learnedFromSimilar'] = true;
        }
        
        return $settings;
    }

    /**
     * Build personalization context with variables
     * 
     * Now generates FULL dynamic content including:
     * - Industry-specific value propositions
     * - Role-specific pain points
     * - Social proof appropriate to segment
     * - Engagement-adaptive content length
     */
    private function buildPersonalizationContext(
        Contact $contact,
        PersonalizationProfile $profile,
        array $settings
    ): array {
        $company = $contact->getCompany();
        $toneConfig = self::TONE_TEMPLATES[$settings['tone']] ?? self::TONE_TEMPLATES[PersonalizationProfile::TONE_FORMAL];
        $contentConfig = self::CONTENT_FOCUS[$settings['content']] ?? self::CONTENT_FOCUS[PersonalizationProfile::CONTENT_BUSINESS];
        
        $firstName = $contact->getFirstName() ?? 'there';
        
        // Determine industry and role for content selection
        $industry = $company ? strtolower($company->getSector() ?? 'other') : 'other';
        $industry = isset(self::INDUSTRY_VALUE_PROPS[$industry]) ? $industry : 'other';
        
        $role = $this->inferRoleCategory($contact->getJobTitle() ?? '');
        $role = isset(self::ROLE_PAIN_POINTS[$role]) ? $role : 'other';
        
        // Map content focus to value prop key
        $contentKey = match($settings['content']) {
            PersonalizationProfile::CONTENT_TECHNICAL => 'technical',
            PersonalizationProfile::CONTENT_BUSINESS => 'business',
            PersonalizationProfile::CONTENT_VALUE_FOCUSED => 'value_focused',
            PersonalizationProfile::CONTENT_RELATIONSHIP => 'relationship',
            default => 'business',
        };
        
        // Get industry-specific value proposition (claim-sanitized)
        $valueProp = self::INDUSTRY_VALUE_PROPS[$industry][$contentKey] 
            ?? self::INDUSTRY_VALUE_PROPS['other']['business'];
        $valueProp = $this->sanitizeClaimsText($valueProp);
        
        // Get role-specific pain point content
        $painPointData = self::ROLE_PAIN_POINTS[$role] ?? self::ROLE_PAIN_POINTS['other'];
        
        // Get social proof for industry
        $socialProofData = self::SOCIAL_PROOF[$industry] ?? self::SOCIAL_PROOF['other'];
        
        // Determine content length based on engagement score
        $engagementScore = $profile->getEngagementScore();
        $contentLength = $this->determineContentLength($engagementScore);
        $lengthSettings = self::CONTENT_LENGTH_SETTINGS[$contentLength];
        
        // Select CTA based on engagement level
        $cta = $this->selectCta($contentLength, $settings['tone']);
        
        return [
            'variables' => [
                // Basic contact info
                'first_name' => $firstName,
                'last_name' => $contact->getLastName() ?? '',
                'full_name' => trim($contact->getFirstName() . ' ' . $contact->getLastName()),
                'company_name' => $company ? $company->getName() : '',
                'job_title' => $contact->getJobTitle() ?? '',
                
                // Tone-based elements
                'greeting' => str_replace('{name}', $firstName, $toneConfig['greeting']),
                'closing' => $toneConfig['closing'],
                'style_phrase' => $toneConfig['style'],
                
                // Content focus emphasis words
                'emphasis_1' => $contentConfig['emphasis'][0] ?? '',
                'emphasis_2' => $contentConfig['emphasis'][1] ?? '',
                'emphasis_3' => $contentConfig['emphasis'][2] ?? '',
                'emphasis_4' => $contentConfig['emphasis'][3] ?? '',
                
                // NEW: Dynamic content blocks
                'value_prop' => $valueProp,
                'value_prop_short' => $this->shortenValueProp($valueProp),
                
                // NEW: Pain point targeting
                'pain_point' => $painPointData['primary'],
                'pain_hook' => $painPointData['hook'],
                'pain_detail' => $painPointData['detail'],
                
                // NEW: Social proof
                'social_proof_stat' => $this->sanitizeClaimsText($socialProofData['stat']),
                'social_proof_ref' => $this->sanitizeClaimsText($socialProofData['reference']),
                'social_proof_detail' => $this->sanitizeClaimsText($socialProofData['detail']),
                'social_proof_full' => $this->resolveInternalSpintax(
                    $this->sanitizeClaimsText($socialProofData['stat'])
                ) . ' ' . $this->resolveInternalSpintax(
                    $this->sanitizeClaimsText($socialProofData['outcome'])
                ),
                
                // NEW: Call to action
                'cta' => $cta,
                
                // NEW: Industry/role context
                'industry' => ucfirst($industry),
                'industry_lower' => $industry,
                'role_category' => $role,
            ],
            'tone' => $settings['tone'],
            'content' => $settings['content'],
            'toneConfig' => $toneConfig,
            'contentConfig' => $contentConfig,
            'industry' => $industry,
            'role' => $role,
            'contentLength' => $contentLength,
            'lengthSettings' => $lengthSettings,
            // NEW: Engagement level for template architecture decisions
            'engagementLevel' => $this->mapContentLengthToEngagement($contentLength),
        ];
    }

    /**
     * Determine content length based on engagement score
     */
    private function determineContentLength(int|float $engagementScore): string
    {
        $engagementScore = (int) round($engagementScore);

        if ($engagementScore >= 70) {
            return 'detailed'; // High engagement - they want more detail
        } elseif ($engagementScore >= 40) {
            return 'standard'; // Medium engagement - balanced approach
        } else {
            return 'brief'; // Low/cold engagement - keep it short
        }
    }

    /**
     * Map content length setting to engagement level
     * 
     * Used for template architecture decisions
     */
    private function mapContentLengthToEngagement(string $contentLength): string
    {
        return match($contentLength) {
            'detailed' => 'hot',
            'standard' => 'warm',
            'brief' => 'cold',
            default => 'cold',
        };
    }

    /**
     * Create shortened version of value prop (first sentence only)
     */
    private function shortenValueProp(string $valueProp): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $valueProp, 2);
        return $sentences[0] ?? $valueProp;
    }

    /**
     * Select appropriate CTA based on engagement level and tone
     */
    private function selectCta(string $contentLength, string $tone): string
    {
        $ctas = [
            'brief' => [
                PersonalizationProfile::TONE_FORMAL => 'Would a brief call to discuss your requirements be of interest?',
                PersonalizationProfile::TONE_CASUAL => 'Open to a quick chat?',
                PersonalizationProfile::TONE_DIRECT => 'Worth a 15-minute call?',
                PersonalizationProfile::TONE_FRIENDLY => "I'd love to hear what you're working on—open to connecting?",
            ],
            'standard' => [
                PersonalizationProfile::TONE_FORMAL => 'I would welcome the opportunity to discuss how we might support your manufacturing requirements. Would you have time for a brief conversation this week or next?',
                PersonalizationProfile::TONE_CASUAL => "Would love to learn more about what you're working on. Any chance we could grab 15 minutes this week?",
                PersonalizationProfile::TONE_DIRECT => 'Can we schedule a 15-minute call this week to discuss your requirements?',
                PersonalizationProfile::TONE_FRIENDLY => "I'd really enjoy learning more about your projects. Would you be open to a short call to explore if there's a fit?",
            ],
            'detailed' => [
                PersonalizationProfile::TONE_FORMAL => 'I would be pleased to arrange a detailed technical discussion with our engineering team to review your specific requirements. Please let me know what time works best for your schedule, and I will coordinate accordingly.',
                PersonalizationProfile::TONE_CASUAL => "I can put together a custom capability deck for your specific applications. Want me to send that over, or would a call work better so I can tailor it to what you're actually building?",
                PersonalizationProfile::TONE_DIRECT => 'Next step: 30-minute call with our engineering team to review your requirements. I can send calendar options, or reply with times that work.',
                PersonalizationProfile::TONE_FRIENDLY => "I'd love to dig deeper into your projects and put together some specific recommendations. Would a call work, or would you prefer I send over some initial ideas first?",
            ],
        ];
        
        return $ctas[$contentLength][$tone] ?? $ctas['standard'][PersonalizationProfile::TONE_FORMAL];
    }

    /**
     * Apply personalization to template text
     */
    private function applyPersonalization(string $template, array $variables, array $context): string
    {
        $text = $template;
        
        // Replace {{variable}} placeholders
        foreach ($variables as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }
        
        // Apply tone-specific transformations
        $text = $this->applyToneTransformations($text, $context['tone']);
        
        return $text;
    }

    /**
     * Apply tone-specific text transformations using comprehensive pattern matching
     * 
     * Uses 20+ regex patterns per tone for meaningful text adaptation.
     * Made public for use by AutonomousSalesOrchestratorService (DRY principle).
     * 
     * @param string $text The text to transform
     * @param string $tone The target tone (formal, casual, direct, friendly)
     * @return string Transformed text
     */
    public function applyToneTransformations(string $text, string $tone): string
    {
        // Get patterns for this tone
        $patterns = self::TONE_TRANSFORMATION_PATTERNS[$tone] ?? [];
        
        if (empty($patterns)) {
            return $text;
        }
        
        // Apply all patterns for this tone
        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }
        
        // Clean up any artifacts (double spaces, leading spaces on lines)
        $text = preg_replace('/  +/', ' ', $text);
        $text = preg_replace('/\n +/', "\n", $text);
        $text = preg_replace('/ +\n/', "\n", $text);
        
        return trim($text);
    }

    /**
     * Record email interaction for learning
     */
    public function recordInteraction(
        Contact $contact,
        string $eventType,
        ?OutboundMessage $message = null,
        array $metadata = []
    ): PersonalizationProfile {
        $profile = $this->profileRepository->findOrCreateForContact($contact->getId());
        
        switch ($eventType) {
            case 'opened':
                $profile->incrementEmailsOpened();
                break;
            case 'clicked':
                $profile->incrementLinksClicked();
                break;
            case 'replied':
                $profile->incrementEmailsReplied();
                // Learn from successful subject
                if ($message && $message->getSubject()) {
                    $profile->addSuccessfulSubjectPattern($this->extractSubjectPattern($message->getSubject()));
                }
                break;
            case 'bounced':
                $profile->incrementEmailsBounced();
                break;
        }
        
        // Record detailed interaction
        $profile->recordInteraction($eventType, array_merge([
            'messageId' => $message?->getId(),
        ], $metadata));
        
        // Update best send time based on interactions
        if (in_array($eventType, ['opened', 'replied', 'clicked'])) {
            $this->updateBestSendTime($profile);
        }
        
        $this->entityManager->persist($profile);
        $this->entityManager->flush();
        
        return $profile;
    }

    /**
     * Update best send time based on successful interactions
     */
    private function updateBestSendTime(PersonalizationProfile $profile): void
    {
        $interactions = $profile->getInteractionHistory();
        $successfulInteractions = array_filter($interactions, fn($i) => in_array($i['type'], ['opened', 'replied', 'clicked']));
        
        if (count($successfulInteractions) < 3) {
            return; // Not enough data
        }
        
        $hourCounts = [];
        $dayCounts = [];
        
        foreach ($successfulInteractions as $interaction) {
            $timestamp = $interaction['timestamp'];
            $hour = (int)date('H', $timestamp);
            $day = date('l', $timestamp);
            
            $hourCounts[$hour] = ($hourCounts[$hour] ?? 0) + 1;
            $dayCounts[$day] = ($dayCounts[$day] ?? 0) + 1;
        }
        
        if (!empty($hourCounts)) {
            arsort($hourCounts);
            $bestHour = array_key_first($hourCounts);
            $profile->setBestSendTime(sprintf('%02d:00', $bestHour));
        }
        
        if (!empty($dayCounts)) {
            arsort($dayCounts);
            $profile->setBestSendDay(array_key_first($dayCounts));
        }
    }

    /**
     * Extract pattern from successful subject line
     */
    private function extractSubjectPattern(string $subject): string
    {
        // Remove specific company/person names to get pattern
        $pattern = preg_replace('/[A-Z][a-z]+(\s+[A-Z][a-z]+)?/', '{name}', $subject);
        return $pattern;
    }

    /**
     * Calculate cosine similarity between two vectors
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        
        $length = min(count($a), count($b));
        
        for ($i = 0; $i < $length; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        
        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }
        
        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Normalize a vector to unit length
     */
    private function normalizeVector(array $vector): array
    {
        $norm = 0.0;
        foreach ($vector as $v) {
            $norm += $v * $v;
        }
        $norm = sqrt($norm);
        
        if ($norm == 0) {
            return $vector;
        }
        
        return array_map(fn($v) => $v / $norm, $vector);
    }

    /**
     * Infer role category from job title
     * 
     * FIXED: Reordered patterns to prevent "supply chain manager" matching both supply_chain and management
     * More specific patterns checked first, generic management patterns last
     */
    private function inferRoleCategory(string $jobTitle): string
    {
        $title = strtolower($jobTitle);
        
        // Check specific functional roles FIRST (before generic management)
        if (preg_match('/\b(procurement|buyer|purchasing|sourcing)\b/', $title)) {
            return 'procurement';
        }
        if (preg_match('/\b(supply\s*chain|logistics|materials|inventory)\b/', $title)) {
            return 'supply_chain';  // Moved BEFORE management to catch "Supply Chain Manager"
        }
        if (preg_match('/\b(quality|sqe|qa|compliance|inspection)\b/', $title)) {
            return 'quality';
        }
        if (preg_match('/\b(engineer|technical|design|r&d|development)\b/', $title)) {
            return 'engineering';
        }
        if (preg_match('/\b(operations|ops|manufacturing|production)\b/', $title)) {
            return 'operations';
        }
        // Generic management patterns LAST (catches remaining managers/directors/VPs)
        if (preg_match('/\b(manager|director|vp|chief|head|lead|president|ceo|coo|cto)\b/', $title)) {
            return 'management';
        }
        
        return 'other';
    }

    /**
     * Normalize company size to 0-1 scale
     */
    private function normalizeCompanySize(?Company $company): float
    {
        if (!$company) {
            return 0.5;
        }
        
        // Try to get employee count or revenue tier
        $tier = $company->getAccountTier() ?? 'C';
        
        return match ($tier) {
            'A' => 0.9,
            'B' => 0.7,
            'C' => 0.5,
            'D' => 0.3,
            default => 0.5,
        };
    }

    /**
     * Extract geographic features from contact
     */
    private function extractGeographicFeatures(Contact $contact): array
    {
        $company = $contact->getCompany();
        $features = array_fill(0, 8, 0.5);
        
        if (!$company) {
            return $features;
        }
        
        $location = strtolower($company->getPhysicalSite() ?? '');
        
        // European markets
        if (preg_match('/\b(germany|france|spain|italy|uk|poland|czech|netherlands)\b/', $location)) {
            $features[0] = 0.9; // European preference
            $features[1] = 0.7; // Nearshore interest
        }
        
        // US markets
        if (preg_match('/\b(usa|united states|california|texas|michigan|ohio)\b/', $location)) {
            $features[2] = 0.9; // US market
            $features[3] = 0.6; // Cost sensitivity
        }
        
        // Asia markets  
        if (preg_match('/\b(china|japan|korea|taiwan|india)\b/', $location)) {
            $features[4] = 0.9; // Asia presence
            $features[5] = 0.8; // Diversification interest
        }
        
        return $features;
    }

    /**
     * Extract behavioral features from profile history
     */
    private function extractBehavioralFeatures(PersonalizationProfile $profile): array
    {
        $features = array_fill(0, 16, 0.5);
        
        $history = $profile->getInteractionHistory();
        if (empty($history)) {
            return $features;
        }
        
        // Engagement rate features
        $features[0] = min(1.0, $profile->getEmailsOpened() / 10);
        $features[1] = min(1.0, $profile->getEmailsReplied() / 5);
        $features[2] = min(1.0, $profile->getLinksClicked() / 8);
        
        // Response time features (from history)
        $replyTimes = [];
        foreach ($history as $i => $interaction) {
            if ($interaction['type'] === 'replied' && $i > 0) {
                $prevTimestamp = $history[$i - 1]['timestamp'] ?? 0;
                if ($prevTimestamp > 0) {
                    $replyTimes[] = $interaction['timestamp'] - $prevTimestamp;
                }
            }
        }
        
        if (!empty($replyTimes)) {
            $avgReplyTime = array_sum($replyTimes) / count($replyTimes);
            $features[3] = 1.0 - min(1.0, $avgReplyTime / (24 * 3600)); // Fast replier score
        }
        
        return $features;
    }

    /**
     * Extract text features using simple TF-IDF style approach
     */
    private function extractTextFeatures(Contact $contact): array
    {
        $features = array_fill(0, 16, 0.0);
        $company = $contact->getCompany();
        
        // Build text corpus from available data
        $text = strtolower(implode(' ', array_filter([
            $contact->getJobTitle(),
            $company?->getName(),
            $company?->getSector(),
            $company?->getSourceNotes(),
        ])));
        
        // Key terms and their feature indices
        $termFeatures = [
            'automotive' => 0, 'aerospace' => 1, 'medical' => 2, 'industrial' => 3,
            'quality' => 4, 'procurement' => 5, 'engineering' => 6, 'manufacturing' => 7,
            'oem' => 8, 'tier1' => 9, 'global' => 10, 'supplier' => 11,
            'pcba' => 12, 'smt' => 13, 'ems' => 14, 'electronics' => 15,
        ];
        
        foreach ($termFeatures as $term => $index) {
            if (strpos($text, $term) !== false) {
                $features[$index] = 1.0;
            }
        }
        
        return $features;
    }

    // ========================= NEW: ROLE-SPECIFIC PAIN POINTS =========================
    // These target the specific concerns of each buyer persona
    private const ROLE_PAIN_POINTS = [
        'procurement' => [
            'primary' => 'cost pressure and supply chain risk',
            'hook' => 'assembly cost and single-source risk',
            'detail' => 'We can align on your pricing and qualification requirements before any production work.',
        ],
        'engineering' => [
            'primary' => 'lead time and DFM feedback',
            'hook' => 'prototype turnaround and DFM feedback speed',
            'detail' => 'Happy to review your DFM expectations and documentation needs early.',
        ],
        'management' => [
            'primary' => 'strategic sourcing and risk mitigation',
            'hook' => 'manufacturing footprint diversification',
            'detail' => 'We can align on qualification steps, documentation, and risk planning if useful.',
        ],
        'quality' => [
            'primary' => 'compliance and traceability',
            'hook' => 'documentation and traceability requirements',
            'detail' => 'Happy to align on your documentation checklist and audit expectations.',
        ],
        'operations' => [
            'primary' => 'capacity and on-time delivery',
            'hook' => 'capacity flexibility and delivery planning',
            'detail' => 'We can align on your volume profile and planning requirements.',
        ],
        'supply_chain' => [
            'primary' => 'lead time reduction and inventory optimization',
            'hook' => 'lead time and inventory planning',
            'detail' => 'We can align on lead-time expectations and documentation needs.',
        ],
        'other' => [
            'primary' => 'finding the right manufacturing partner',
            'hook' => 'sourcing and manufacturing partner selection',
            'detail' => 'Happy to align on your qualification checklist and documentation needs.',
        ],
    ];

    // ========================= TONE TRANSFORMATIONS =========================
    private const TONE_TRANSFORMATION_PATTERNS = [
        PersonalizationProfile::TONE_FORMAL => [
            '/\bHey!?\b/' => 'Hello',
            '/\bI\'d\b/i' => 'I would',
            '/\bWe\'d\b/i' => 'We would',
            '/\bYou\'d\b/i' => 'You would',
            '/\bI\'ll\b/i' => 'I will',
            '/\bWe\'ll\b/i' => 'We will',
            '/\bYou\'ll\b/i' => 'You will',
            '/\bI\'m\b/i' => 'I am',
            '/\bWe\'re\b/i' => 'We are',
            '/\bYou\'re\b/i' => 'You are',
            '/\bcan\'t\b/i' => 'cannot',
            '/\bwon\'t\b/i' => 'will not',
            '/\bdon\'t\b/i' => 'do not',
        ],
        PersonalizationProfile::TONE_CASUAL => [
            '/\bI would\b/i' => "I'd",
            '/\bWe would\b/i' => "We'd",
            '/\bYou would\b/i' => "You'd",
            '/\bI will\b/i' => "I'll",
            '/\bWe will\b/i' => "We'll",
            '/\bYou will\b/i' => "You'll",
            '/\bI am\b/i' => "I'm",
            '/\bWe are\b/i' => "We're",
            '/\bYou are\b/i' => "You're",
        ],
        PersonalizationProfile::TONE_DIRECT => [
            '/I hope this email finds you well\.?\s*/i' => '',
            '/\bI just wanted to\b/i' => '',
            '/\bI wanted to\b/i' => '',
            '/\bI was wondering if\b/i' => '',
            '/\bI thought I would\b/i' => '',
            '/\bPlease let me know if you have any questions\.\s*/i' => '',
            '/\bLooking forward to hearing from you\.\s*/i' => '',
            '/\s{2,}/' => ' ',
        ],
        PersonalizationProfile::TONE_FRIENDLY => [
            '/\bI wanted to\b/i' => "I'd love to",
            '/\bI would like to\b/i' => "I'd really like to",
            '/\bPlease\b/i' => 'Feel free to',
        ],
    ];

    /**
     * @return array List of competitor names
     */
    public function getKnownCompetitors(): array
    {
        return array_keys(self::COMPETITOR_HOOKS);
    }

    /**
     * Get competitor hook data for a known competitor
     *
     * Returns positioning data (hook, differentiator, value) for known competitors,
     * or null if the competitor is not in our database.
     *
     * @param string $competitor Competitor name (lowercase)
     * @return array|null Competitor hook data or null
     */
    public function getCompetitorHook(string $competitor): ?array
    {
        $key = strtolower($competitor);
        return self::COMPETITOR_HOOKS[$key] ?? null;
    }

    /**
     * Get optimal send time recommendation for a contact
     * 
     * Uses learned send time from profile if available, otherwise falls back
     * to intelligent defaults based on day of week.
     * 
     * @param Contact $contact The contact to get send time for
     * @return array ['time' => 'HH:MM', 'day' => 'Day', 'source' => 'learned'|'default']
     */
    public function getOptimalSendTime(Contact $contact): array
    {
        $profile = $this->profileRepository->findByContactId($contact->getId());
        
        // Check if we have learned data
        if ($profile) {
            $learnedTime = $profile->getBestSendTime();
            $learnedDay = $profile->getBestSendDay();
            
            if ($learnedTime && $learnedDay) {
                return [
                    'time' => $learnedTime,
                    'day' => $learnedDay,
                    'source' => 'learned',
                    'confidence' => $this->calculateSendTimeConfidence($profile),
                ];
            }
        }
        
        // Fall back to intelligent defaults
        $today = date('l');
        
        // Determine default time based on day
        $defaultTime = match($today) {
            'Monday' => self::DEFAULT_SEND_TIMES['monday_morning'],
            'Friday' => self::DEFAULT_SEND_TIMES['friday_afternoon'],
            'Saturday', 'Sunday' => self::DEFAULT_SEND_TIMES['weekday_morning'], // Will schedule for Tuesday
            default => self::DEFAULT_SEND_TIMES['weekday_morning'],
        };
        
        // Determine best day if not today
        $sendDay = in_array($today, self::OPTIMAL_SEND_DAYS, true) ? $today : 'Tuesday';
        
        return [
            'time' => $defaultTime,
            'day' => $sendDay,
            'source' => 'default',
            'confidence' => 0.5, // Medium confidence for defaults
        ];
    }

    /**
     * Calculate confidence in learned send time based on interaction count
     */
    private function calculateSendTimeConfidence(PersonalizationProfile $profile): float
    {
        $interactions = $profile->getInteractionHistory();
        $successfulCount = count(array_filter($interactions, fn($i) => 
            in_array($i['type'] ?? '', ['opened', 'replied', 'clicked'])
        ));
        
        // More data = higher confidence, cap at 0.95
        return min(0.95, 0.5 + ($successfulCount * 0.05));
    }

    /**
     * Synthesize a new subject line based on learned successful patterns
     * 
     * Uses extractSubjectPattern() data to generate new subject lines
     * that follow patterns that have previously resulted in replies.
     * 
     * @param Contact $contact The contact to synthesize for
     * @param string $baseTemplate Base template to enhance
     * @return string Synthesized subject line
     */
    public function synthesizeSubjectLine(Contact $contact, string $baseTemplate): string
    {
        $profile = $this->profileRepository->findByContactId($contact->getId());
        
        if (!$profile) {
            return $baseTemplate;
        }
        
        $successfulPatterns = $profile->getSuccessfulSubjectPatterns();
        
        if (empty($successfulPatterns)) {
            return $baseTemplate;
        }
        
        // Analyze patterns for common structures
        $elements = $this->analyzeSubjectPatterns($successfulPatterns);
        
        // Try to incorporate successful elements into the base template
        $company = $contact->getCompany();
        $companyName = $company?->getName() ?? '';
        $firstName = $contact->getFirstName() ?? '';
        
        // If successful patterns often include company name, ensure it's present
        if ($elements['uses_company_name'] && $companyName && !str_contains($baseTemplate, $companyName)) {
            $baseTemplate = str_replace('{name}', $companyName, $baseTemplate);
        }
        
        // If successful patterns are questions, convert to question if not already
        if ($elements['is_question'] && !str_ends_with(trim($baseTemplate), '?')) {
            $baseTemplate = rtrim($baseTemplate, '.!') . '?';
        }
        
        // Apply length optimization based on successful patterns
        if ($elements['avg_length'] > 0) {
            $targetLength = (int)$elements['avg_length'];
            $currentLength = strlen($baseTemplate);
            
            // If template is much longer than successful patterns, try to shorten
            if ($currentLength > $targetLength * 1.3) {
                // Remove filler words
                $baseTemplate = preg_replace('/\b(just|quick|brief|short)\b\s*/i', '', $baseTemplate);
            }
        }
        
        return trim($baseTemplate);
    }

    /**
     * Analyze subject patterns for common elements
     */
    private function analyzeSubjectPatterns(array $patterns): array
    {
        $usesCompanyName = 0;
        $isQuestion = 0;
        $totalLength = 0;
        
        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '{name}')) {
                $usesCompanyName++;
            }
            if (str_ends_with(trim($pattern), '?')) {
                $isQuestion++;
            }
            $totalLength += strlen($pattern);
        }
        
        $count = count($patterns);
        
        return [
            'uses_company_name' => $usesCompanyName > ($count / 2),
            'is_question' => $isQuestion > ($count / 2),
            'avg_length' => $count > 0 ? $totalLength / $count : 0,
        ];
    }

    /**
     * Get value proposition variant for A/B testing via Thompson Sampling
     * 
     * Instead of using a fixed value prop per industry, this can return
     * different variants for testing which messaging resonates best.
     * 
     * @param string $industry The industry key
     * @param string $contentFocus The content focus (technical, business, etc.)
     * @return array ['value_prop' => string, 'variant_id' => string, 'is_ab_test' => bool]
     */
    public function getValuePropVariant(string $industry, string $contentFocus): array
    {
        // Get base value proposition
        $baseValueProp = self::INDUSTRY_VALUE_PROPS[$industry][$contentFocus] 
            ?? self::INDUSTRY_VALUE_PROPS['other']['business'];
        $baseValueProp = $this->sanitizeClaimsText($baseValueProp);
        
        // If Thompson Sampler is available, try to get A/B test variant
        if ($this->thompsonSampler) {
            $armName = "value_prop_{$industry}_{$contentFocus}";
            
            // Check if we have arms for this value prop
            $armResult = $this->thompsonSampler->sampleAndSelect($armName);
            
            if ($armResult && isset($armResult['arm'])) {
                $arm = $armResult['arm'];
                return [
                    'value_prop' => $arm->getArmValue(),
                    'variant_id' => $arm->getArmName(),
                    'arm_id' => $arm->getId(),
                    'is_ab_test' => true,
                ];
            }
        }
        
        // Fall back to base value prop (no A/B testing)
        return [
            'value_prop' => $baseValueProp,
            'variant_id' => 'base',
            'arm_id' => null,
            'is_ab_test' => false,
        ];
    }

    /**
     * Seed value proposition variants for A/B testing
     * 
     * Creates Thompson Sampling arms for different value prop variants
     * to enable data-driven optimization of messaging.
     * 
     * @param string $industry Industry to seed variants for
     * @param string $contentFocus Content focus to seed variants for
     * @return array Created arm IDs
     */
    public function seedValuePropVariants(string $industry, string $contentFocus): array
    {
        if (!$this->thompsonSampler) {
            return [];
        }
        
        $armName = "value_prop_{$industry}_{$contentFocus}";
        
        // Get base value proposition
        $baseValueProp = self::INDUSTRY_VALUE_PROPS[$industry][$contentFocus] 
            ?? self::INDUSTRY_VALUE_PROPS['other']['business'];

        // Ensure variants do not introduce unverified claims
        $baseValueProp = $this->sanitizeValuePropForClaims($baseValueProp);
        
        // Create variants with different emphases
        $variants = [
            [
                'name' => "{$armName}_base",
                'value' => $baseValueProp,
            ],
            [
                'name' => "{$armName}_short",
                'value' => $this->shortenValueProp($baseValueProp),
            ],
            [
                'name' => "{$armName}_question",
                'value' => $this->convertToQuestion($baseValueProp),
            ],
        ];
        
        $createdArms = [];
        
        foreach ($variants as $variant) {
            try {
                $arm = $this->thompsonSampler->createArm(
                    $armName,
                    $variant['name'],
                    $variant['value']
                );
                if ($arm) {
                    $createdArms[] = $arm->getId();
                }
            } catch (\Exception $e) {
                // Arm may already exist, which is fine
                $this->logger->debug('Value prop arm may already exist', [
                    'name' => $variant['name'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $createdArms;
    }

    /**
     * Remove or neutralize claim-heavy phrases to avoid unverified statements.
     */
    private function sanitizeValuePropForClaims(string $text): string
    {
        return $this->sanitizeClaimsText($text);
    }

    /**
     * Generic claim sanitizer to avoid unverified assertions in outbound copy.
     */
    private function sanitizeClaimsText(string $text): string
    {
        $replacements = [
            '/\bISO\s*(?!9001)\d{4}\b/i' => 'quality-focused processes',
            '/\bIATF\s*\d+\b/i' => 'automotive-grade processes',
            '/\bAS\s*\d+\b/i' => 'aerospace-grade processes',
            '/\bEN\s*\d+\b/i' => 'industry-grade processes',
            '/\bFDA[-\s]*ready\b/i' => 'documentation-ready',
            '/\bFDA\b/i' => 'regulatory',
            '/\b100%\b/i' => 'comprehensive',
            '/\b\d{1,3}%\b/i' => 'consistent',
            '/\b\d+\+\b/' => 'many',
            '/\b\d+\s*(?:-|–)\s*\d+\b/' => 'several',
            '/\bQ[1-4]\b/i' => 'an upcoming quarter',
            '/\b\d+\s*(?:day|days|week|weeks|month|months|year|years)\b/i' => 'timeline',
            '/\bzero-?defect\b/i' => 'quality-focused',
            '/\bAOI\b/i' => 'inspection',
            '/\bX-ray\b/i' => 'inspection',
            '/\bfirst-?article\b/i' => 'initial',
            '/\bHALT\/?HASS\b/i' => 'reliability',
            '/\bconformal coating\b/i' => 'protective coating',
            '/\bBGA\b/i' => 'fine-pitch',
            '/\b0201\b/i' => 'fine-pitch',
            '/\b4-week\b/i' => 'quick',
            '/\bguarantee(s|d)?\b/i' => 'support',
            '/\bcertified\b/i' => 'qualified',
            '/\btraceability\b/i' => 'documentation support',
            '/\baudit-?ready\b/i' => 'audit support',
            '/\bqualified backup capacity\b/i' => 'backup capacity options',
            '/\bOTD\b/i' => 'delivery performance',
        ];

        $sanitized = preg_replace(array_keys($replacements), array_values($replacements), $text);

        // Remove double spaces and tidy punctuation
        $sanitized = preg_replace('/\s{2,}/', ' ', $sanitized);
        $sanitized = preg_replace('/\s+([,\.])/', '$1', $sanitized);

        return trim($sanitized);
    }

    /**
     * Convert a statement into a question format
     */
    private function convertToQuestion(string $statement): string
    {
        // Remove trailing punctuation
        $statement = rtrim($statement, '.!');
        
        // Check if it starts with "We" and convert to "Would you be interested..."
        if (preg_match('/^We (offer|provide|deliver|support|have|can)/i', $statement)) {
            $statement = preg_replace(
                '/^We (offer|provide|deliver|support|have|can)/i',
                'Would you be interested in how we $1',
                $statement
            );
            return $statement . '?';
        }
        
        // Generic conversion
        return "What if you could " . lcfirst($statement) . "?";
    }

    /**
     * Get personalization statistics
     */
    public function getStatistics(): array
    {
        return $this->profileRepository->getStatistics();
    }

    // ==================================================================================
    // CIALDINI'S 7 PRINCIPLES OF PERSUASION - IMPLEMENTATION METHODS
    // Based on Dr. Robert Cialdini's research (influenceatwork.com)
    // ==================================================================================

    /**
     * Get a reciprocity element - give something first, personalized and unexpected
     * 
     * Cialdini's First Principle: People are obliged to give back to others
     * the form of a behavior, gift, or service they have received first.
     * Key: Be first to give, make it personalized and unexpected.
     * 
     * @param Contact $contact The contact
     * @param string $context The context (intro, follow_up, technical, etc.)
     * @return string A reciprocity-based offering
     */
    public function getReciprocityElement(Contact $contact, string $context = 'intro'): string
    {
        $company = $contact->getCompany();
        $industry = $company ? strtolower($company->getSector() ?? 'other') : 'other';
        $role = $this->inferRoleCategory($contact->getJobTitle() ?? '');
        
        // Match offering to role/context — each role gets the most valuable free resource
        $element = match($role) {
            'engineering' => self::CIALDINI_PRINCIPLES['reciprocity']['free_dfm_review'],
            'procurement' => self::CIALDINI_PRINCIPLES['reciprocity']['cost_model'],
            'operations', 'supply_chain' => self::CIALDINI_PRINCIPLES['reciprocity']['capacity_check'],
            'quality' => self::CIALDINI_PRINCIPLES['reciprocity']['sample_build'],
            default => self::CIALDINI_PRINCIPLES['reciprocity']['industry_insight'],
        };
        
        // CRITICAL FIX: Resolve spintax and sanitize claims before returning
        return $this->sanitizeClaimsText($this->resolveInternalSpintax($element));
    }

    /**
     * Get a scarcity element - unique benefits they stand to lose
     * 
     * Cialdini's Second Principle: People want more of what they can have less of.
     * Key: Point out what is unique and what they stand to lose.
     * 
     * @param Contact $contact The contact
     * @return string A scarcity-based message element
     */
    /**
     * Get scarcity element based on contact context (IMPROVED)
     * 
     * Cialdini's First Principle: People want more of what they can have less of.
     * 
     * IMPROVEMENT: Now bases scarcity on contact context (company tier, role, region)
     * instead of just calendar month. This makes scarcity feel more relevant.
     * 
     * @param Contact $contact The contact
     * @return string Contextually appropriate scarcity element
     */
    public function getScarcityElement(Contact $contact): string
    {
        $company = $contact->getCompany();
        $location = $company?->getPhysicalSite() ?? '';
        $region = $this->detectRegion($location);
        
        // Get company tier/size indicator for context
        $tier = $company?->getAccountTier() ?? 'C';
        $role = $this->inferRoleCategory($contact->getJobTitle() ?? '');
        
        // Large companies (A/B tier) care about capacity and scalability
        if (in_array($tier, ['A', 'B'])) {
            $options = [
                self::CIALDINI_PRINCIPLES['scarcity']['capacity'],
                self::CIALDINI_PRINCIPLES['scarcity']['timing'],
            ];
            return $this->sanitizeClaimsText($this->resolveInternalSpintax($options[array_rand($options)]));
        }
        
        // EU/UK customers get location scarcity (relevant to them)
        if (in_array($region, ['eu', 'uk'])) {
            return $this->sanitizeClaimsText($this->resolveInternalSpintax(self::CIALDINI_PRINCIPLES['scarcity']['location']));
        }
        
        // Procurement/supply chain roles care about timing/planning
        if (in_array($role, ['procurement', 'supply_chain', 'operations'])) {
            return $this->sanitizeClaimsText($this->resolveInternalSpintax(self::CIALDINI_PRINCIPLES['scarcity']['timing']));
        }
        
        // Engineering/technical roles care about expertise access
        if (in_array($role, ['engineering', 'quality'])) {
            return $this->sanitizeClaimsText($this->resolveInternalSpintax(self::CIALDINI_PRINCIPLES['scarcity']['expertise']));
        }
        
        // Management/other - use quarterly context for strategic framing
        $month = (int)date('n');
        if ($month >= 10 || $month <= 2) {
            $element = self::CIALDINI_PRINCIPLES['scarcity']['timing']; // Budget season
        } else {
            $element = self::CIALDINI_PRINCIPLES['scarcity']['capacity'];
        }
        
        // CRITICAL FIX: Resolve spintax and sanitize claims before returning
        return $this->sanitizeClaimsText($this->resolveInternalSpintax($element));
    }

    /**
     * Get an authority element - signal credible expertise
     * 
     * Cialdini's Third Principle: People follow the lead of credible, knowledgeable experts.
     * Key: Signal credentials before making the influence attempt.
     * 
     * @param Contact $contact The contact
     * @return string An authority-establishing message element
     */
    public function getAuthorityElement(Contact $contact): string
    {
        $role = $this->inferRoleCategory($contact->getJobTitle() ?? '');
        
        $element = match($role) {
            'engineering' => self::CIALDINI_PRINCIPLES['authority']['process'],
            'quality' => self::CIALDINI_PRINCIPLES['authority']['certification'],
            'management' => self::CIALDINI_PRINCIPLES['authority']['tangier_fz'],
            'procurement' => self::CIALDINI_PRINCIPLES['authority']['track_record'],
            default => self::CIALDINI_PRINCIPLES['authority']['experience'],
        };
        
        // CRITICAL FIX: Resolve spintax and sanitize claims before returning
        return $this->sanitizeClaimsText($this->resolveInternalSpintax($element));
    }

    /**
     * Get a consistency/commitment element - small initial commitment
     * 
     * Cialdini's Fourth Principle: People like to be consistent with things
     * they have previously said or done.
     * Key: Look for voluntary, active commitments; ideally in writing.
     * 
     * @param string $engagementLevel 'cold', 'warm', 'hot'
     * @return string A commitment-seeking message element
     */
    public function getConsistencyElement(string $engagementLevel = 'cold'): string
    {
        $element = match($engagementLevel) {
            'hot' => self::CIALDINI_PRINCIPLES['consistency']['pilot_suggestion'],
            'warm' => self::CIALDINI_PRINCIPLES['consistency']['next_step'],
            default => self::CIALDINI_PRINCIPLES['consistency']['micro_commitment'],
        };
        
        // CRITICAL FIX: Resolve spintax before returning
        return $this->resolveInternalSpintax($element);
    }

    /**
     * Get a liking element - build rapport through similarity
     * 
     * Cialdini's Fifth Principle: People prefer to say yes to those they like.
     * Three factors: similarity, compliments, cooperation toward mutual goals.
     * Key: Find similarities and give genuine compliments before business.
     * 
     * @param Contact $contact The contact
     * @return string A liking/rapport message element
     */
    public function getLikingElement(Contact $contact): string
    {
        $company = $contact->getCompany();
        $industry = $company ? strtolower($company->getSector() ?? 'other') : 'other';
        
        // Industry knowledge shows understanding (similarity)
        if (in_array($industry, ['automotive', 'aerospace', 'medical', 'defense'])) {
            $element = self::CIALDINI_PRINCIPLES['liking']['industry_knowledge'];
        } else {
            // For other industries, show empathy for challenges
            $element = self::CIALDINI_PRINCIPLES['liking']['challenge_empathy'];
        }
        
        // CRITICAL FIX: Resolve spintax before returning
        return $this->resolveInternalSpintax($element);
    }

    /**
     * Get a social proof element - show similar others doing it
     * 
     * Cialdini's Sixth Principle: People look to actions of others to determine their own.
     * Key: Point to what many SIMILAR others are already doing.
     * "75% of guests who stayed in this room reused their towels" - specific + similar
     * 
     * @param Contact $contact The contact
     * @return string A social proof message element
     */
    public function getSocialProofElement(Contact $contact): string
    {
        $company = $contact->getCompany();
        $industry = $company ? strtolower($company->getSector() ?? 'other') : 'other';
        
        // Get industry-specific social proof
        $socialProof = self::SOCIAL_PROOF[$industry] ?? self::SOCIAL_PROOF['other'];
        
        // Format with similarity emphasis (key insight from Cialdini)
        $element = sprintf(
            '%s are increasingly evaluating nearshore alternatives. %s',
            $socialProof['similarity'] ?? 'Companies like yours',
            $socialProof['stat']
        );
        
        // CRITICAL FIX: Resolve spintax and sanitize claims before returning
        return $this->sanitizeClaimsText($this->resolveInternalSpintax($element));
    }

    /**
     * Get a unity element - shared identity and belonging
     * 
     * Cialdini's Seventh Principle: People say yes to those they consider "one of us."
     * Key: Emphasize shared identity, values, and belonging.
     * 
     * @param Contact $contact The contact
     * @return string A unity message element
     */
    public function getUnityElement(Contact $contact): string
    {
        $company = $contact->getCompany();
        $location = $company?->getPhysicalSite() ?? '';
        $region = $this->detectRegion($location);
        
        $element = match($region) {
            'eu', 'uk' => self::CIALDINI_PRINCIPLES['unity']['regional'],
            default => self::CIALDINI_PRINCIPLES['unity']['partnership'],
        };
        
        // CRITICAL FIX: Resolve spintax before returning
        return $this->resolveInternalSpintax($element);
    }

    // ==================================================================================
    // PRE-SUASION T.I.M.E. FRAMEWORK - IMPLEMENTATION
    // Based on Cialdini's Pre-Suasion book
    // ==================================================================================

    /**
     * Get a pre-suasive opener that targets the right mindset
     * 
     * T.I.M.E. Framework - TARGET: Put recipient in a receptive mindset
     * before delivering the main message.
     * 
     * @param Contact $contact The contact
     * @return string A mindset-priming opening phrase
     */
    public function getPresuasiveOpener(Contact $contact): string
    {
        $role = $this->inferRoleCategory($contact->getJobTitle() ?? '');
        
        return match($role) {
            'procurement' => self::PRESUASION_ELEMENTS['target_mindsets']['cost_conscious'],
            'quality' => self::PRESUASION_ELEMENTS['target_mindsets']['quality_focused'],
            'management' => self::PRESUASION_ELEMENTS['target_mindsets']['growth_oriented'],
            'supply_chain' => self::PRESUASION_ELEMENTS['target_mindsets']['risk_aware'],
            'engineering' => self::PRESUASION_ELEMENTS['target_mindsets']['innovation_driven'],
            default => self::PRESUASION_ELEMENTS['target_mindsets']['quality_focused'],
        };
    }

    /**
     * Check if current timing is a "privileged moment" for outreach
     * 
     * T.I.M.E. Framework - MOVE: Position the message at the optimal moment.
     * Certain times create natural receptivity.
     * 
     * @return array ['is_privileged' => bool, 'reason' => string]
     */
    public function checkPrivilegedMoment(): array
    {
        $month = (int)date('n');
        $dayOfWeek = date('l');
        
        // Q4 budget planning (October-December)
        if ($month >= 10 && $month <= 12) {
            return [
                'is_privileged' => true,
                'reason' => self::PRESUASION_ELEMENTS['privileged_moments']['budget_cycle'],
                'messaging_hook' => 'As you finalize next year\'s sourcing strategy...',
            ];
        }
        
        // Q1 new budget (January-February)
        if ($month >= 1 && $month <= 2) {
            return [
                'is_privileged' => true,
                'reason' => self::PRESUASION_ELEMENTS['privileged_moments']['budget_cycle'],
                'messaging_hook' => 'With fresh budget allocations...',
            ];
        }
        
        // Mid-week is best for B2B
        if (in_array($dayOfWeek, ['Tuesday', 'Wednesday', 'Thursday'])) {
            return [
                'is_privileged' => true,
                'reason' => 'Mid-week focus time',
                'messaging_hook' => '',
            ];
        }
        
        return [
            'is_privileged' => false,
            'reason' => 'Standard timing',
            'messaging_hook' => '',
        ];
    }

    /**
     * Get a relationship-extending element for long-term impact
     * 
     * T.I.M.E. Framework - EXTEND: Create lasting change, not just immediate action.
     * 
     * FIXED: Now accepts optional key for deterministic selection (testing)
     * 
     * @param string|null $key Specific key to select, or null for contextual selection
     * @return string A relationship-extending message element
     */
    public function getExtendImpactElement(?string $key = null): string
    {
        $options = self::PRESUASION_ELEMENTS['extend_impact'];
        
        if ($key !== null && isset($options[$key])) {
            return $options[$key];
        }
        
        // Contextual selection based on current month (budget cycles)
        $month = (int)date('n');
        
        // Q4/Q1 - emphasize roadmap planning
        if ($month >= 10 || $month <= 2) {
            return $options['roadmap'];
        }
        
        // Q2 - emphasize continuous improvement
        if ($month >= 3 && $month <= 5) {
            return $options['continuous_improvement'];
        }
        
        // Q3 - emphasize investment/partnership
        return $options['partnership'];
    }

    // ==================================================================================
    // GEOGRAPHIC PERSONALIZATION
    // ==================================================================================

    /**
     * Get geographic value proposition based on customer region
     * 
     * @param Contact $contact The contact
     * @return array Geographic value prop data
     */
    public function getGeographicValueProp(Contact $contact): array
    {
        $company = $contact->getCompany();
        $location = $company?->getPhysicalSite() ?? '';
        $region = $this->detectRegion($location);
        
        $geoProps = self::GEOGRAPHIC_VALUE_PROPS[$region] ?? self::GEOGRAPHIC_VALUE_PROPS['global'];
        
        return [
            'region' => $region,
            'logistics' => $geoProps['logistics'],
            'timezone' => $geoProps['timezone'],
            'trade' => $geoProps['trade'],
            'cultural' => $geoProps['cultural'],
            'proximity' => $geoProps['proximity'],
        ];
    }

    /**
     * Detect region from location string
     */
    private function detectRegion(string $location): string
    {
        $location = strtolower($location);
        
        // Morocco (local)
        if (preg_match('/\b(morocco|maroc|tangier|tanger|casablanca|rabat|fes|marrakech|agadir)\b/', $location)) {
            return 'morocco';
        }
        
        // EU countries
        if (preg_match('/\b(germany|france|spain|italy|netherlands|belgium|austria|poland|czech|sweden|denmark|finland|portugal|ireland|romania|hungary|greece|slovakia|slovenia|croatia|bulgaria|lithuania|latvia|estonia|luxembourg|norway|switzerland)\b/', $location)) {
            return 'eu';
        }
        
        // UK
        if (preg_match('/\b(uk|united kingdom|england|scotland|wales|britain)\b/', $location)) {
            return 'uk';
        }
        
        // US
        if (preg_match('/\b(usa|united states|america|california|texas|michigan|ohio|florida|new york|illinois|pennsylvania|georgia|carolina|virginia|washington|arizona|colorado|massachusetts)\b/', $location)) {
            return 'us';
        }
        
        // MENA (Middle East & North Africa) — GCC, Egypt, Tunisia, etc.
        if (preg_match('/\b(uae|dubai|abu dhabi|saudi|arabia|qatar|doha|bahrain|kuwait|oman|egypt|cairo|tunisia|tunis|algeria|algiers|jordan|amman|lebanon|beirut|iraq|libya)\b/', $location)) {
            return 'mena';
        }
        
        return 'global';
    }

    /**
     * Build comprehensive persuasion context combining all Cialdini principles
     * 
     * This method assembles a complete set of persuasion elements that can be
     * used in email templates. Each element is carefully chosen based on the
     * contact's profile.
     * 
     * @param Contact $contact The contact
     * @return array Complete persuasion context
     */
    public function buildPersuasionContext(Contact $contact): array
    {
        $company = $contact->getCompany();
        $industry = $company ? strtolower($company->getSector() ?? 'other') : 'other';
        $role = $this->inferRoleCategory($contact->getJobTitle() ?? '');
        
        // Get geographic context
        $geoProps = $this->getGeographicValueProp($contact);
        
        // Check timing
        $privilegedMoment = $this->checkPrivilegedMoment();
        
        // Get profile for engagement level
        $profile = $this->profileRepository->findByContactId($contact->getId());
        $engagementScore = $profile ? $profile->getEngagementScore() : 0;
        $engagementLevel = $engagementScore > 70 ? 'hot' : ($engagementScore > 30 ? 'warm' : 'cold');
        
        return [
            // Cialdini's 7 Principles
            'reciprocity' => $this->getReciprocityElement($contact),
            'scarcity' => $this->getScarcityElement($contact),
            'authority' => $this->getAuthorityElement($contact),
            'consistency' => $this->getConsistencyElement($engagementLevel),
            'liking' => $this->getLikingElement($contact),
            'social_proof' => $this->getSocialProofElement($contact),
            'unity' => $this->getUnityElement($contact),
            
            // Pre-suasion elements
            'presuasive_opener' => $this->getPresuasiveOpener($contact),
            'privileged_moment' => $privilegedMoment,
            'privileged_moment_hook' => $privilegedMoment['messaging_hook'] ?? '',  // FIXED: Actually use messaging_hook
            'extend_impact' => $this->getExtendImpactElement(),
            
            // Geographic context - FIXED: Added geo_cultural that was missing
            'geo_logistics' => $geoProps['logistics'],
            'geo_timezone' => $geoProps['timezone'],
            'geo_trade' => $geoProps['trade'],
            'geo_cultural' => $geoProps['cultural'],  // FIXED: Was defined but not included
            'geo_proximity' => $geoProps['proximity'],
            'region' => $geoProps['region'],
            
            // Pain point - FIXED: Ensure pain_point is always available for templates
            'pain_point' => $this->getPainPointForRole($role),
            
            // Fused content - NEW: Pre-fused paragraphs for natural flow
            'fused_intro' => $this->getFusedParagraph('pain_to_reciprocity', $contact, $engagementLevel),
            'fused_value' => $this->getFusedParagraph('unity_to_value', $contact, $engagementLevel),
            'fused_proof' => $this->getFusedParagraph('liking_to_social_proof', $contact, $engagementLevel),
            'fused_close' => $this->getFusedParagraph('social_proof_to_consistency', $contact, $engagementLevel),

            // Proof request (neutral, non-claiming)
            'proof_request' => $this->getProofRequestElement(),

            // Starz services line (neutral)
            'starz_services' => $this->getStarzServicesLine(),
            
            // Context metadata
            'industry' => $industry,
            'role' => $role,
            'engagement_level' => $engagementLevel,
            'template_architecture' => self::TEMPLATE_ARCHITECTURES[$engagementLevel] ?? self::TEMPLATE_ARCHITECTURES['cold'],
        ];
    }

    /**
     * Provide a neutral proof request line without making claims.
     * This avoids asserting certifications or metrics without references.
     */
    private function getProofRequestElement(): string
    {
        $options = [
            'If you have supplier-qualification requirements, let me know what documentation you need.',
            'If documentation is required for qualification, tell me what you need and I can outline what we can provide.',
            'Happy to align with your qualification checklist—just share what documentation your team requires.',
        ];

        return $options[array_rand($options)];
    }

    /**
     * Neutral, non-claiming services line tailored to Starz.
     */
    private function getStarzServicesLine(): string
    {
        $options = [
            'If relevant, we can support PCBA, cable harness, overmolding, copper windings, and system integration programs.',
            'If helpful, we can discuss PCBA, cable harness, overmolding, copper windings, or system integration needs.',
            'If it fits your roadmap, we can cover PCBA, cable harness, overmolding, copper windings, and system integration scope.',
        ];

        return $options[array_rand($options)];
    }

    // ==================================================================================
    // CRITICAL NEW METHODS - REPORT RECOMMENDATIONS IMPLEMENTATION
    // ==================================================================================

    /**
     * Get pain point text for a role
     * 
     * FIXED: Ensures pain_point variable is always populated for templates
     */
    private function getPainPointForRole(string $role): string
    {
        $painPoints = self::ROLE_PAIN_POINTS[$role] ?? self::ROLE_PAIN_POINTS['other'];
        return $painPoints['primary'] ?? 'finding the right manufacturing partner';
    }

    /**
     * Convert "We"-focused text to "You"-focused text
     * 
     * CRITICAL FIX: Eliminates the self-focused language identified in the report
     * "We offer X" → "You get X"
     * 
     * @param string $text The text to convert
     * @return string You-focused text
     */
    public function convertToYouFocus(string $text): string
    {
        // IMPROVED: More contextually correct patterns that don't create awkward sentences
        // "We offer X" → "I can offer you X" (not "You get X" which sounds like recipient is giving)
        $patterns = [
            // Offering patterns - keep the giving context clear
            '/\bWe offer\b/i' => 'I can offer you',
            '/\bWe can offer\b/i' => 'I can offer you',
            '/\bWe provide\b/i' => "You'll receive",
            '/\bWe can provide\b/i' => 'I can send you',
            '/\bWe have\b/i' => "There's",
            '/\bWe can\b/i' => 'You can',
            '/\bWe deliver\b/i' => "You'll receive",
            '/\bWe support\b/i' => "You'll have support for",
            '/\bWe bring\b/i' => "You'll benefit from",
            '/\bWe ensure\b/i' => "You're assured of",
            '/\bWe specialize\b/i' => "You'll benefit from specialization in",
            '/\bWe work with\b/i' => "You'd be working with",
            '/\bWe\'re committed\b/i' => 'You can count on',
            '/\bWe understand\b/i' => 'Your challenges with',
            '/\bWe\'ve built\b/i' => "You'll benefit from",
            '/\bWe\'ve developed\b/i' => "You'll have access to",
            '/\bWe\'ve seen\b/i' => 'Teams like yours have seen',
            // Our → Your/The conversions
            '/\bOur team\b/i' => 'Your dedicated team',
            '/\bOur facility\b/i' => 'The facility serving you',
            '/\bOur engineers\b/i' => 'Engineers dedicated to your project',
            '/\bOur approach\b/i' => 'The approach for your program',
            '/\bOur customers\b/i' => 'Customers like you',
            '/\bOur manufacturing\b/i' => 'Manufacturing for you',
            '/\bOur quality\b/i' => 'The quality systems backing you',
            '/\bOur pricing\b/i' => 'Pricing for you',
            '/\bOur capabilities\b/i' => 'Capabilities available to you',
            // Clean up any awkward doubled words
            '/You\'ll have have/i' => "You'll have",
            '/You\'ll receive receive/i' => "You'll receive",
            '/You can can/i' => 'You can',
            // Remove orphaned "We" at sentence start when it became empty
            '/^\s*,\s*/' => '',
        ];
        
        return preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /**
     * Get a fused paragraph that weaves Cialdini elements naturally
     * 
     * CRITICAL FIX: This eliminates the "Mad Libs" paragraph-per-element structure
     * 
     * @param string $fusionType The type of fusion (e.g., 'reciprocity_to_authority')
     * @param Contact $contact The contact for context
     * @param string $engagementLevel 'cold', 'warm', or 'hot'
     * @return string A naturally flowing fused paragraph
     */
    public function getFusedParagraph(string $fusionType, Contact $contact, string $engagementLevel = 'cold'): string
    {
        $templates = self::FUSION_TEMPLATES[$fusionType] ?? [];
        
        if (empty($templates)) {
            return '';
        }
        
        // Select template based on engagement level
        $templateIndex = match($engagementLevel) {
            'hot' => 0,     // Most formal/detailed
            'warm' => 1,    // Balanced
            default => 2,   // Most casual/brief for cold
        };
        
        $template = $templates[$templateIndex] ?? $templates[0];
        
        // Get the raw elements
        $company = $contact->getCompany();
        $industry = $company ? strtolower($company->getSector() ?? 'other') : 'other';
        $role = $this->inferRoleCategory($contact->getJobTitle() ?? '');
        
        // Build replacement values — reciprocity_action matches the reciprocity element's context
        $reciprocityActions = [
            'procurement' => 'run a preliminary cost comparison',
            'engineering' => 'provide a free DFM review',
            'quality' => 'share our quality documentation and audit reports',
            'management' => 'provide a supply chain risk assessment',
            'supply_chain' => 'run a quick capacity check for your volumes',
            'operations' => 'run a quick capacity check for your volumes',
            'other' => 'send over a capability overview',
        ];
        $replacements = [
            '{reciprocity}' => $this->getReciprocityElement($contact),
            '{reciprocity_action}' => $reciprocityActions[$role] ?? $reciprocityActions['other'],
            '{authority}' => $this->getAuthorityElement($contact),
            '{liking}' => $this->getLikingElement($contact),
            '{social_proof}' => $this->getSocialProofElement($contact),
            '{scarcity}' => $this->getScarcityElement($contact),
            '{consistency}' => $this->getConsistencyElement($engagementLevel),
            '{unity}' => $this->getUnityElement($contact),
            '{value_prop_short}' => $this->getShortValueProp($industry, $role),
            '{pain_hook}' => self::ROLE_PAIN_POINTS[$role]['hook'] ?? self::ROLE_PAIN_POINTS['other']['hook'],
            '{industry}' => ucfirst($industry),
            '{geo_logistics}' => $this->getGeographicValueProp($contact)['logistics'],
        ];
        
        $fused = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // Apply You-focus conversion
        $fused = $this->convertToYouFocus($fused);
        
        return $fused;
    }

    /**
     * Get a short value proposition for a specific industry/role combination
     */
    private function getShortValueProp(string $industry, string $role): string
    {
        $valueProp = self::INDUSTRY_VALUE_PROPS[$industry]['business'] 
            ?? self::INDUSTRY_VALUE_PROPS['other']['business'];
        
        // Take first sentence only
        $sentences = preg_split('/(?<=[.!?])\s+/', $valueProp, 2);
        return $sentences[0] ?? $valueProp;
    }

    /**
     * Get template architecture settings for an engagement level
     * 
     * @param string $engagementLevel 'cold', 'warm', or 'hot'
     * @return array Architecture settings
     */
    public function getTemplateArchitecture(string $engagementLevel): array
    {
        return self::TEMPLATE_ARCHITECTURES[$engagementLevel] ?? self::TEMPLATE_ARCHITECTURES['cold'];
    }

    /**
     * Filter Cialdini principles based on engagement level
     * 
     * Cold leads should not receive scarcity/authority heavy messages
     * 
     * @param array $principles All available principles
     * @param string $engagementLevel 'cold', 'warm', or 'hot'
     * @return array Filtered principles appropriate for engagement level
     */
    public function filterPrinciplesForEngagement(array $principles, string $engagementLevel): array
    {
        $architecture = self::TEMPLATE_ARCHITECTURES[$engagementLevel] ?? self::TEMPLATE_ARCHITECTURES['cold'];
        $allowed = $architecture['allowed_principles'] ?? [];
        $limit = $architecture['cialdini_limit'] ?? 2;
        
        // Filter to allowed principles
        $filtered = array_intersect_key($principles, array_flip($allowed));
        
        // Limit count
        return array_slice($filtered, 0, $limit, true);
    }

    /**
     * Generate a curiosity-gap subject line
     * 
     * @param Contact $contact The contact
     * @param string $patternType The type of pattern (question, intrigue, social_proof, specificity, value_forward)
     * @return string A curiosity-inducing subject line
     */
    public function getCuriositySubjectLine(Contact $contact, string $patternType = 'question'): string
    {
        $patterns = self::CURIOSITY_SUBJECT_PATTERNS[$patternType] ?? self::CURIOSITY_SUBJECT_PATTERNS['question'];
        
        if (empty($patterns)) {
            return 'Quick question';
        }
        
        // Select pattern
        $pattern = $patterns[array_rand($patterns)];
        
        // Get context values
        $company = $contact->getCompany();
        $industry = $company ? strtolower($company->getSector() ?? 'other') : 'other';
        $role = $this->inferRoleCategory($contact->getJobTitle() ?? '');
        
        // Replace placeholders
        $replacements = [
            '{{company_name}}' => $company?->getName() ?? 'your company',
            '{{first_name}}' => $contact->getFirstName() ?? 'there',
            '{{industry}}' => ucfirst($industry),
            '{{similar_company}}' => $this->getSimilarCompanyName($industry),
            '{pain_area}' => self::ROLE_PAIN_POINTS[$role]['primary'] ?? 'sourcing',
            '{reciprocity_offer}' => 'DFM review',
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $pattern);
    }

    /**
     * Get a representative similar company name for social proof
     */
    private function getSimilarCompanyName(string $industry): string
    {
        $similarCompanies = [
            'automotive' => 'a leading German Tier 1 supplier',
            'aerospace' => 'a major aerospace OEM',
            'medical' => 'a medical device manufacturer',
            'industrial' => 'an industrial equipment company',
            'defense' => 'a defense contractor',
            'consumer' => 'a consumer electronics brand',
            'telecom' => 'a telecom equipment provider',
            'renewables' => 'a solar inverter manufacturer',
            'semiconductor' => 'a semiconductor equipment OEM',
            'rail' => 'a European rolling stock manufacturer',
            'hvac' => 'an HVAC controls manufacturer',
            'marine' => 'a marine electronics OEM',
            'other' => 'companies in your industry',
        ];
        
        return $similarCompanies[$industry] ?? $similarCompanies['other'];
    }

    /**
     * Apply all output quality fixes to final email content
     * 
     * This is the master method that applies:
     * 1. You-focus conversion
     * 2. Content length enforcement
     * 3. Tone transformations
     * 4. Double-space cleanup
     * 
     * @param string $content The raw email content
     * @param string $engagementLevel 'cold', 'warm', or 'hot'
     * @param string $tone The tone preference
     * @return string Polished email content
     */
    public function applyOutputQualityFixes(string $content, string $engagementLevel, string $tone): string
    {
        // 0. Remove claim-heavy phrases without hard proof
        $content = $this->sanitizeClaimsText($content);

        // 1. Convert to You-focus
        $content = $this->convertToYouFocus($content);
        
        // 2. Apply tone transformations
        $content = $this->applyToneTransformations($content, $tone);
        
        // 3. CRITICAL FIX: Add transitional phrases for natural flow
        $content = $this->addTransitionalPhrases($content);
        
        // 4. Enforce content length based on engagement
        $content = $this->enforceContentLength($content, $this->mapEngagementToLength($engagementLevel));
        
        // 5. Fix sentence case issues from fusion templates
        $content = $this->fixSentenceCase($content);
        
        // 6. Clean up artifacts
        $content = preg_replace('/\n{3,}/', "\n\n", $content);  // Max 2 newlines
        $content = preg_replace('/  +/', ' ', $content);         // No double spaces
        $content = preg_replace('/\n +/', "\n", $content);       // No leading spaces on lines
        
        return trim($content);
    }

    /**
     * Enforce content length based on engagement level setting.
     *
     * brief    → max 3 paragraphs / ~120 words
     * standard → max 5 paragraphs / ~200 words
     * detailed → no hard cap, lightly trim if > 8 paragraphs
     */
    public function enforceContentLength(string $content, string $lengthSetting = 'standard'): string
    {
        $settings = self::CONTENT_LENGTH_SETTINGS[$lengthSetting] ?? self::CONTENT_LENGTH_SETTINGS['standard'];
        $maxParagraphs = $settings['max_paragraphs'] ?? 5;
        $maxWords = $settings['max_words'] ?? 200;
        $maxSentences = $settings['max_sentences'] ?? 5;

        $paragraphs = preg_split('/\n{2,}/', trim($content));
        if (count($paragraphs) > $maxParagraphs) {
            $paragraphs = array_slice($paragraphs, 0, $maxParagraphs);
        }

        $result = implode("\n\n", $paragraphs);

        // Sentence-level trim: enforce max_sentences
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($result), -1, PREG_SPLIT_NO_EMPTY);
        if (count($sentences) > $maxSentences) {
            $sentences = array_slice($sentences, 0, $maxSentences);
            $result = implode(' ', $sentences);
        }

        // Word-level trim: cut at sentence boundary nearest to maxWords
        $words = str_word_count($result, 2);
        if (count($words) > $maxWords) {
            $positions = array_keys($words);
            $cutPos = $positions[min($maxWords, count($positions) - 1)] ?? strlen($result);
            // Find the next sentence end after cutPos
            $sentenceEnd = strpos($result, '.', $cutPos);
            if ($sentenceEnd !== false && ($sentenceEnd - $cutPos) < 80) {
                $result = substr($result, 0, $sentenceEnd + 1);
            } else {
                $result = substr($result, 0, $cutPos);
                $result = rtrim($result, ' ,;:') . '.';
            }
        }

        return trim($result);
    }

    /**
     * Map engagement level to content length setting
     */
    private function mapEngagementToLength(string $engagementLevel): string
    {
        return match($engagementLevel) {
            'hot' => 'detailed',
            'warm' => 'standard',
            default => 'brief',
        };
    }

    // ==================================================================================
    // P2 IMPROVEMENTS: TRANSITIONAL PHRASES, WARMTH SCORE, CONTEXTUAL SCARCITY
    // ==================================================================================

    /**
     * Transitional phrases for natural email flow ("breathing room")
     * 
     * Use these to connect paragraphs naturally instead of hard transitions
     */
    private const TRANSITIONAL_PHRASES = [
        'addition' => [
            'Additionally,',
            'Also worth noting:',
            'On a related note,',
            'Plus,',
            'And',
        ],
        'contrast' => [
            'That said,',
            'However,',
            'On the other hand,',
            'But',
        ],
        'result' => [
            'As a result,',
            'This means',
            'The bottom line:',
            'So',
            'Which means',
        ],
        'example' => [
            'For example,',
            'To give you a sense,',
            'Case in point:',
            'For instance,',
        ],
        'specificity' => [
            'Specifically,',
            'In particular,',
            'More precisely,',
        ],
        'conclusion' => [
            'In short,',
            'All in all,',
            'The key point:',
        ],
    ];

    /**
     * Add transitional phrases between paragraphs for natural flow
     * 
     * @param string $content Email content with hard paragraph breaks
     * @param int|null $seed Optional seed for deterministic behavior (useful for testing)
     * @param float $probability Probability of adding transition (0.0-1.0, default 0.5)
     * @return string Content with natural transitions added
     */
    public function addTransitionalPhrases(string $content, ?int $seed = null, float $probability = 0.5): string
    {
        $paragraphs = preg_split('/\n\n+/', trim($content));
        
        if (count($paragraphs) <= 2) {
            return $content; // Too short to need transitions
        }
        
        // Use seeded random for deterministic testing
        $rng = $seed !== null ? new \Random\Randomizer(new \Random\Engine\Mt19937($seed)) : null;
        
        $result = [$paragraphs[0]]; // Keep first paragraph as-is (greeting/hook)
        
        for ($i = 1; $i < count($paragraphs) - 1; $i++) {
            $para = $paragraphs[$i];
            
            // Skip if paragraph already starts with a transition word or connecting phrase
            if (preg_match('/^(Also|Additionally|However|That said|Plus|And|But|So|For example|Specifically|In short|Regarding|If |I know|Given|For your|For |In fact|Because|Since|With over|With |Think|Would|Could|Let me|Here\'s|The )/i', trim($para))) {
                $result[] = $para;
                continue;
            }
            
            // Skip short paragraphs (single sentences) — transitions make them feel padded
            if (strlen(trim($para)) < 80) {
                $result[] = $para;
                continue;
            }
            
            // Analyze content to pick appropriate transition
            $transitionType = $this->detectTransitionType($paragraphs[$i - 1], $para);
            $transitions = self::TRANSITIONAL_PHRASES[$transitionType] ?? self::TRANSITIONAL_PHRASES['addition'];
            
            // Use probability threshold (default 50% chance to add transition)
            $shouldAdd = $rng !== null 
                ? ($rng->nextFloat() < $probability)
                : (random_int(0, 100) < ($probability * 100));
                
            if ($shouldAdd) {
                $transitionIndex = $rng !== null
                    ? $rng->nextInt() % count($transitions)
                    : array_rand($transitions);
                $transition = $transitions[abs($transitionIndex)];
                // Only lcfirst if the first word is not a likely proper noun
                // (proper nouns: multi-char capitalized words that aren't common sentence starters)
                $trimmedPara = ltrim($para);
                $firstWord = preg_match('/^([A-Z][a-z]+)/', $trimmedPara, $fw) ? $fw[1] : '';
                $commonStarters = ['The', 'This', 'That', 'These', 'Those', 'Our', 'We', 'My', 'Your', 'Their', 'Its', 'Each', 'Every', 'Some', 'Many', 'Most', 'All', 'Any', 'No', 'Few'];
                if (in_array($firstWord, $commonStarters)) {
                    $para = $transition . ' ' . lcfirst($trimmedPara);
                } else {
                    // Keep original casing for proper nouns (e.g., "Atlantic", "Morocco", company names)
                    $para = $transition . ' ' . $trimmedPara;
                }
            }
            
            $result[] = $para;
        }
        
        // Keep last paragraph as-is (usually the CTA/closing)
        if (count($paragraphs) > 1) {
            $result[] = end($paragraphs);
        }
        
        return implode("\n\n", $result);
    }

    /**
     * Detect what type of transition would fit between two paragraphs
     */
    private function detectTransitionType(string $previous, string $current): string
    {
        $prevLower = strtolower($previous);
        $currLower = strtolower($current);
        
        // If current starts with specifics/numbers, use 'specificity'
        if (preg_match('/^(•|\d|specifically|for your)/i', trim($current))) {
            return 'specificity';
        }
        
        // If current contains contrast words
        if (preg_match('/\b(but|however|although|different|unlike)\b/', $currLower)) {
            return 'contrast';
        }
        
        // If previous contains cause and current contains effect
        if (preg_match('/\b(because|since|due to)\b/', $prevLower)) {
            return 'result';
        }
        
        // If current gives an example
        if (preg_match('/\b(for example|instance|like|such as)\b/', $currLower)) {
            return 'example';
        }
        
        // Default to addition
        return 'addition';
    }

    /**
     * Calculate "warmth score" for an email
     * 
     * Higher score = warmer, more personal email
     * Lower score = colder, more corporate-sounding
     * 
     * Use this to validate emails before sending - aim for score >= 6.0
     * 
     * @param string $email The email body text
     * @return array ['score' => float 0-10, 'breakdown' => array, 'suggestions' => array]
     */
    public function calculateWarmthScore(string $email): array
    {
        // P4-3 FIX: Use configurable constants instead of hardcoded values
        $warmIndicators = self::WARMTH_INDICATORS_POSITIVE;
        $coldIndicators = self::WARMTH_INDICATORS_NEGATIVE;
        
        $score = self::WARMTH_BASE_SCORE; // Start at neutral (configurable)
        $breakdown = ['warm' => [], 'cold' => []];
        $suggestions = [];
        
        // Apply warm indicators from configurable constants
        foreach ($warmIndicators as $pattern => $value) {
            $matches = preg_match_all($pattern, $email);
            if ($matches > 0) {
                $impact = min($value * $matches, $value * self::WARMTH_MAX_MULTIPLIER);
                $score += $impact;
                $breakdown['warm'][] = ['pattern' => $pattern, 'count' => $matches, 'impact' => $impact];
            }
        }
        
        // Apply cold indicators from configurable constants
        foreach ($coldIndicators as $pattern => $value) {
            $matches = preg_match_all($pattern, $email);
            if ($matches > 0) {
                $impact = $value * $matches;
                $score += $impact;
                $breakdown['cold'][] = ['pattern' => $pattern, 'count' => $matches, 'impact' => $impact];
                
                // Add suggestion to fix cold language (for significant penalties)
                if ($value <= -1.5) {
                    $suggestions[] = "Consider removing or replacing: " . trim($pattern, '/i');
                }
            }
        }
        
        // Check for missing warm elements
        if (!preg_match('/\?/', $email)) {
            $suggestions[] = "Add a question to increase engagement";
        }
        if (preg_match_all('/\byou\b/i', $email) < 3) {
            $suggestions[] = "Use 'you/your' more frequently to make it about the reader";
        }
        
        // Clamp score to 0-10
        $score = max(0, min(10, $score));
        
        // Use configurable thresholds for verdict
        $verdict = match(true) {
            $score >= self::WARMTH_THRESHOLD_EXCELLENT => 'Warm & Personal',
            $score >= self::WARMTH_THRESHOLD_GOOD => 'Good',
            $score >= self::WARMTH_THRESHOLD_MINIMUM => 'Neutral',
            default => 'Too Corporate',
        };
        
        return [
            'score' => round($score, 1),
            'breakdown' => $breakdown,
            'suggestions' => $suggestions,
            'verdict' => $verdict,
            // P4-3 FIX: Include configuration info for debugging/tuning
            'config' => [
                'base_score' => self::WARMTH_BASE_SCORE,
                'thresholds' => [
                    'excellent' => self::WARMTH_THRESHOLD_EXCELLENT,
                    'good' => self::WARMTH_THRESHOLD_GOOD,
                    'minimum' => self::WARMTH_THRESHOLD_MINIMUM,
                ],
                'indicators_count' => [
                    'positive' => count(self::WARMTH_INDICATORS_POSITIVE),
                    'negative' => count(self::WARMTH_INDICATORS_NEGATIVE),
                ],
            ],
        ];
    }

    // ==================================================================================
    // PERSONALIZATION DEPTH INDICATOR
    // Note: getContextualScarcity() was REMOVED - duplicate of getScarcityElement()
    // ==================================================================================

    /**
     * Calculate personalization depth score
     * 
     * Shows users how personalized each email actually is.
     * Useful for quality assurance and A/B testing personalization levels.
     * 
     * @param Contact $contact The contact
     * @param array $context The personalization context used
     * @return array Detailed personalization assessment
     */
    public function calculatePersonalizationDepth(Contact $contact, array $context): array
    {
        $dimensions = [
            'industry_specific' => false,
            'role_specific' => false,
            'geo_specific' => false,
            'engagement_adaptive' => false,
            'company_named' => false,
            'person_named' => false,
            'tone_matched' => false,
            'pain_point_targeted' => false,
        ];
        
        $details = [];
        
        // Check industry specificity
        $industry = $context['industry'] ?? 'other';
        if ($industry !== 'other' && isset(self::INDUSTRY_VALUE_PROPS[$industry])) {
            $dimensions['industry_specific'] = true;
            $details[] = "Industry-specific content for: " . ucfirst($industry);
        }
        
        // Check role specificity
        $role = $context['role'] ?? 'other';
        if ($role !== 'other' && isset(self::ROLE_PAIN_POINTS[$role])) {
            $dimensions['role_specific'] = true;
            $details[] = "Role-targeted messaging for: " . ucfirst($role);
        }
        
        // Check geographic specificity
        $region = $context['region'] ?? 'global';
        if ($region !== 'global') {
            $dimensions['geo_specific'] = true;
            $details[] = "Geographic value props for: " . strtoupper($region);
        }
        
        // Check engagement adaptation
        $engagementLevel = $context['engagement_level'] ?? 'cold';
        if ($engagementLevel !== 'cold') {
            $dimensions['engagement_adaptive'] = true;
            $details[] = "Engagement-adapted content: " . ucfirst($engagementLevel);
        }
        
        // Check company personalization
        $company = $contact->getCompany();
        if ($company && $company->getName()) {
            $dimensions['company_named'] = true;
            $details[] = "Company named: " . $company->getName();
        }
        
        // Check person personalization  
        if ($contact->getFirstName() && $contact->getFirstName() !== 'there') {
            $dimensions['person_named'] = true;
            $details[] = "Personalized to: " . $contact->getFirstName();
        }
        
        // Check tone matching
        $tone = $context['tone'] ?? 'formal';
        if ($tone !== 'formal') {
            $dimensions['tone_matched'] = true;
            $details[] = "Tone adapted: " . ucfirst($tone);
        }
        
        // Check pain point targeting
        if (isset($context['pain_point']) && $context['pain_point'] !== 'finding the right manufacturing partner') {
            $dimensions['pain_point_targeted'] = true;
            $details[] = "Pain point targeted: " . $context['pain_point'];
        }
        
        // Calculate score
        $trueCount = count(array_filter($dimensions));
        $totalCount = count($dimensions);
        $score = ($trueCount / $totalCount) * 10;
        
        // Determine grade
        $grade = match(true) {
            $score >= 8 => 'A - Highly Personalized',
            $score >= 6 => 'B - Well Personalized',
            $score >= 4 => 'C - Moderately Personalized',
            $score >= 2 => 'D - Basic Personalization',
            default => 'F - Generic',
        };
        
        return [
            'score' => round($score, 1),
            'max_score' => 10,
            'dimensions_met' => $trueCount,
            'dimensions_total' => $totalCount,
            'dimensions' => $dimensions,
            'details' => $details,
            'grade' => $grade,
            'summary' => "{$trueCount}/{$totalCount} personalization dimensions used",
        ];
    }

    // ==================================================================================
    // CRITICAL NEW METHODS - P0/P1/P2/P3 FIXES FROM QUALITY REPORT
    // ==================================================================================

    /**
     * Resolve internal spintax in Cialdini element strings
     * 
     * CRITICAL FIX: Cialdini constants contain spintax like {option1|option2}
     * This method resolves them to a single option before returning.
     * 
     * @param string $text Text potentially containing {option1|option2} syntax
     * @return string Text with spintax resolved to random selection
     */
    private function resolveInternalSpintax(string $text): string
    {
        // If SpintaxEngine is available, use it
        if ($this->spintaxEngine) {
            return $this->spintaxEngine->spin($text);
        }
        
        // Fallback: Simple regex-based spintax resolution
        $pattern = '/\{([^{}]+)\}/';
        
        while (preg_match($pattern, $text)) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $options = array_map('trim', explode('|', $matches[1]));
                $options = array_filter($options, fn($o) => $o !== '');
                
                if (empty($options)) {
                    return $matches[0];
                }
                
                return $options[array_rand($options)];
            }, $text);
        }
        
        return $text;
    }

    /**
     * Fix sentence case issues that can arise from fusion templates
     * 
     * P1 FIX: Fusion templates can create awkward capitalization like:
     * "Because Working within..." where the fused text starts with uppercase
     * 
     * @param string $content The content to fix
     * @return string Content with proper sentence case
     */
    private function fixSentenceCase(string $content): string
    {
        // Fix sentences that start with lowercase after period
        $content = preg_replace_callback(
            '/\.\s+([a-z])/',
            fn($m) => '. ' . strtoupper($m[1]),
            $content
        );
        
        // Fix first character of content if lowercase
        if (strlen($content) > 0 && ctype_lower($content[0])) {
            $content = ucfirst($content);
        }
        
        // Fix paragraph starts (after double newline)
        $content = preg_replace_callback(
            '/\n\n([a-z])/',
            fn($m) => "\n\n" . strtoupper($m[1]),
            $content
        );
        
        // Fix mid-sentence capitals after commas (e.g., ", You'll" → ", you'll")
        // but preserve proper nouns, geography, and "I"
        $properNouns = ['Atlantic', 'Pacific', 'Morocco', 'European', 'African', 'American', 
            'Tangier', 'Tunisia', 'North', 'South', 'East', 'West', 'EU', 'US', 'UK', 'GCC',
            'IPC', 'ISO', 'IATF', 'AS9100', 'Starz'];
        $content = preg_replace_callback(
            '/,\s+([A-Z])([a-z\']+)/',
            function ($m) use ($properNouns) {
                $word = $m[1] . $m[2];
                // Preserve "I", "I'll", "I'd", "I'm"  
                if ($m[1] === 'I' && (strlen($m[2]) === 0 || $m[2][0] === "'")) {
                    return ', ' . $word;
                }
                // Preserve known proper nouns
                if (in_array($word, $properNouns)) {
                    return ', ' . $word;
                }
                return ', ' . lcfirst($word);
            },
            $content
        );
        
        // Fix awkward "Because Working" pattern from fusion
        $content = preg_replace_callback(
            '/\b(Because|Since|Given that|As|If)\s+([A-Z][a-z]+ing)\b/',
            fn($m) => $m[1] . ' ' . lcfirst($m[2]),
            $content
        );
        
        return $content;
    }

    /**
     * Validate email warmth before sending
     * 
     * P1 FIX: Pre-send gate that rejects emails scoring below warmth threshold
     * Use this to ensure no cold, corporate-sounding emails get sent.
     * 
     * @param string $email The email body text
     * @param float $threshold Minimum warmth score (default 5.5)
     * @return array ['approved' => bool, 'score' => float, 'reason' => string, 'suggestions' => array]
     */
    public function validateEmailWarmth(string $email, float $threshold = 5.5): array
    {
        $warmth = $this->calculateWarmthScore($email);
        
        if ($warmth['score'] < $threshold) {
            return [
                'approved' => false,
                'score' => $warmth['score'],
                'threshold' => $threshold,
                'reason' => 'Email warmth score below threshold - too corporate/cold',
                'suggestions' => $warmth['suggestions'],
                'verdict' => $warmth['verdict'],
            ];
        }
        
        return [
            'approved' => true,
            'score' => $warmth['score'],
            'threshold' => $threshold,
            'reason' => 'Email passes warmth validation',
            'suggestions' => $warmth['suggestions'],
            'verdict' => $warmth['verdict'],
        ];
    }

    // ==================================================================================
    // P2 IMPROVEMENTS: EMAIL PREVIEW WITH METRICS, HUMAN TOUCH DETECTOR
    // ==================================================================================

    /**
     * Common templated phrases that indicate low-effort, generic emails
     * 
     * These phrases are often copied from templates and signal lack of personalization
     */
    private const TEMPLATED_PHRASES = [
        '/\bI hope this email finds you well\b/i',
        '/\bI hope this message finds you\b/i',
        '/\bI wanted to reach out\b/i',
        '/\bI am reaching out\b/i',
        '/\bPlease do not hesitate\b/i',
        '/\bAt your earliest convenience\b/i',
        '/\bI am writing to\b/i',
        '/\bI would like to introduce\b/i',
        '/\bAs per our conversation\b/i',
        '/\bPer our discussion\b/i',
        '/\bI trust this email finds you\b/i',
        '/\bLooking forward to hearing from you\b/i',
        '/\bDon\'t hesitate to contact\b/i',
        '/\bKindly revert\b/i',
        '/\bPlease find attached\b/i',
        '/\bWe are pleased to inform\b/i',
        '/\bWe are delighted to\b/i',
        '/\bThis is to inform you\b/i',
    ];

    /**
     * Detect templated/generic language in an email
     * 
     * P2 FIX: Flag emails that sound too templated, which indicates low effort
     * and may trigger spam filters or be ignored by recipients.
     * 
     * @param string $email The email body text
     * @return array ['is_templated' => bool, 'score' => int, 'phrases' => array, 'verdict' => string]
     */
    public function detectTemplatedLanguage(string $email): array
    {
        $matches = [];
        
        foreach (self::TEMPLATED_PHRASES as $pattern) {
            if (preg_match($pattern, $email, $m)) {
                $matches[] = $m[0];
            }
        }
        
        $score = count($matches);
        
        return [
            'is_templated' => $score >= 2,
            'score' => $score,
            'max_acceptable' => 1,
            'phrases' => $matches,
            'verdict' => match(true) {
                $score === 0 => 'Excellent - No templated language detected',
                $score === 1 => 'Good - Minor templated language',
                $score === 2 => 'Warning - Multiple templated phrases',
                default => 'Poor - Heavily templated, likely to be ignored',
            },
        ];
    }

    /**
     * Preview email with comprehensive quality metrics
     * 
     * P2 FIX: Single method that returns email + all quality metrics for UI display.
     * Useful for previewing before send and for A/B testing analysis.
     * 
     * @param Contact $contact The contact
     * @param array $context Additional context variables
     * @return array Complete email preview with metrics
     */
    public function previewEmailWithMetrics(Contact $contact, array $context = [], ?string $sampleEmailBody = null): array
    {
        // Build persuasion context
        $persuasionContext = $this->buildPersuasionContext($contact);
        
        // Get personalization context
        $personalization = $this->getPersonalizationContext($contact, $context);
        
        // Generate a sample email body for metrics if not provided
        // This uses the fused paragraphs to simulate what the final email would look like
        if ($sampleEmailBody === null) {
            $engagementLevel = $personalization['engagementLevel'] ?? 'cold';
            $sampleParts = [
                $persuasionContext['fused_intro'] ?? '',
                $persuasionContext['fused_value'] ?? '',
                $persuasionContext['fused_close'] ?? '',
            ];
            $sampleEmailBody = implode("\n\n", array_filter($sampleParts));
        }
        
        // Calculate ALL metrics - FIX: Actually compute warmth and templated language
        $warmth = !empty($sampleEmailBody) 
            ? $this->calculateWarmthScore($sampleEmailBody) 
            : ['score' => 0, 'breakdown' => [], 'suggestions' => [], 'verdict' => 'No content'];
            
        $templated = !empty($sampleEmailBody)
            ? $this->detectTemplatedLanguage($sampleEmailBody)
            : ['is_templated' => false, 'score' => 0, 'phrases' => [], 'verdict' => 'No content'];
            
        $personalizationDepth = $this->calculatePersonalizationDepth($contact, array_merge(
            $persuasionContext,
            ['tone' => $personalization['tone'] ?? 'formal']
        ));
        
        // Generate email fingerprint for deduplication tracking
        $fingerprint = !empty($sampleEmailBody) 
            ? $this->generateEmailFingerprint($sampleEmailBody) 
            : null;
        
        return [
            'contact' => [
                'name' => $contact->getFirstName() . ' ' . $contact->getLastName(),
                'company' => $contact->getCompany()?->getName(),
                'role' => $this->inferRoleCategory($contact->getJobTitle() ?? ''),
            ],
            'personalization' => [
                'tone' => $personalization['tone'],
                'content_focus' => $personalization['content'],
                'content_length' => $personalization['contentLength'],
                'engagement_level' => $personalization['engagementLevel'],
                'industry' => $personalization['industry'],
                'role' => $personalization['role'],
            ],
            'metrics' => [
                'personalization_depth' => $personalizationDepth,
            ],
            'persuasion_elements' => [
                'reciprocity' => $persuasionContext['reciprocity'],
                'scarcity' => $persuasionContext['scarcity'],
                'authority' => $persuasionContext['authority'],
                'consistency' => $persuasionContext['consistency'],
                'liking' => $persuasionContext['liking'],
                'social_proof' => $persuasionContext['social_proof'],
                'unity' => $persuasionContext['unity'],
            ],
            'geographic' => [
                'region' => $persuasionContext['region'],
                'logistics' => $persuasionContext['geo_logistics'],
                'timezone' => $persuasionContext['geo_timezone'],
            ],
            'template_architecture' => $persuasionContext['template_architecture'],
            // NEW: Quality metrics now fully populated
            'quality_metrics' => [
                'warmth' => $warmth,
                'templated_language' => $templated,
                'fingerprint' => $fingerprint,
            ],
            'sample_body' => $sampleEmailBody,
        ];
    }

    // ==================================================================================
    // P3 IMPROVEMENTS: EMAIL FINGERPRINTING PREVENTION, INDUSTRY SUBJECT PATTERNS
    // ==================================================================================

    /**
     * Industry-specific subject line patterns
     * 
     * P3 FIX: Generic subject patterns don't resonate as well as industry-specific ones.
     * These patterns use industry terminology that signals relevance.
     */
    private const INDUSTRY_SUBJECT_PATTERNS = [
        'automotive' => [
            'ISO 9001 certified assembly for {{company_name}}?',
            '{{company_name}} PPAP timeline question',
            'Tier 1 capacity for {{company_name}}',
            'APQP support for {{company_name}}?',
            'Automotive PCBA for {{company_name}}',
            'Nearshore automotive supplier - {{company_name}}',
        ],
        'aerospace' => [
            '{{company_name}} AS9100 manufacturing',
            'Flight-critical assemblies for {{company_name}}?',
            'Aerospace traceability for {{company_name}}',
            'FAI documentation - {{company_name}}',
        ],
        'medical' => [
            '{{company_name}} MDR compliance support',
            'ISO 13485 manufacturing for {{company_name}}',
            'Medical device assembly - {{company_name}}',
            'DHR documentation for {{company_name}}?',
        ],
        'defense' => [
            '{{company_name}} ITAR-compliant manufacturing',
            'Defense electronics for {{company_name}}',
            'Mil-spec assemblies - {{company_name}}',
        ],
        'industrial' => [
            'Industrial controls for {{company_name}}',
            'Harsh environment PCBA - {{company_name}}',
            'Industrial automation support?',
        ],
        'consumer' => [
            'NPI timeline for {{company_name}}',
            'Volume ramp support - {{company_name}}',
            '{{company_name}} product launch capacity',
        ],
        'telecom' => [
            '5G/RF assemblies for {{company_name}}',
            'Telecom PCBA capacity - {{company_name}}',
            'Network equipment manufacturing?',
        ],
        'renewables' => [
            'Power electronics for {{company_name}}',
            'Solar/wind PCBA - {{company_name}}',
            'Energy storage assemblies?',
        ],
        'semiconductor' => [
            'Semiconductor equipment PCBA - {{company_name}}',
            'ESD-critical assemblies for {{company_name}}',
            'Test equipment manufacturing?',
        ],
        'other' => [
            'PCBA manufacturing for {{company_name}}',
            '{{company_name}} assembly question',
            'Electronics manufacturing - {{company_name}}',
        ],
    ];

    /**
     * Get an industry-specific subject line
     * 
     * P3 FIX: Returns subject lines that use industry-specific terminology
     * to signal relevance and increase open rates.
     * 
     * @param Contact $contact The contact
     * @param string|null $patternType Optional specific pattern type (null = random)
     * @return string Industry-specific subject line
     */
    public function getIndustrySubjectLine(Contact $contact, ?string $patternType = null): string
    {
        $company = $contact->getCompany();
        $industry = $company ? strtolower($company->getSector() ?? 'other') : 'other';
        
        // Get patterns for this industry, fall back to 'other'
        $patterns = self::INDUSTRY_SUBJECT_PATTERNS[$industry] ?? self::INDUSTRY_SUBJECT_PATTERNS['other'];
        
        if (empty($patterns)) {
            $patterns = self::INDUSTRY_SUBJECT_PATTERNS['other'];
        }
        
        // Select a pattern
        $pattern = $patterns[array_rand($patterns)];
        
        // Replace placeholders
        $replacements = [
            '{{company_name}}' => $company?->getName() ?? 'your company',
            '{{first_name}}' => $contact->getFirstName() ?? 'there',
            '{{industry}}' => ucfirst($industry),
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $pattern);
    }

    /**
     * Calculate text similarity using simple token overlap
     * 
     * Used for email fingerprinting prevention to ensure variety.
     * 
     * @param string $text1 First text
     * @param string $text2 Second text
     * @return float Similarity score 0.0 to 1.0
     */
    public function calculateTextSimilarity(string $text1, string $text2): float
    {
        // Tokenize (simple word split)
        $tokens1 = array_unique(preg_split('/\s+/', strtolower(strip_tags($text1))));
        $tokens2 = array_unique(preg_split('/\s+/', strtolower(strip_tags($text2))));
        
        // Remove very common words
        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 
                      'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 
                      'should', 'may', 'might', 'must', 'to', 'of', 'in', 'for', 'on', 'with',
                      'at', 'by', 'from', 'as', 'into', 'through', 'and', 'or', 'but', 'if',
                      'then', 'else', 'when', 'up', 'out', 'about', 'this', 'that', 'these',
                      'those', 'i', 'you', 'we', 'they', 'it', 'he', 'she', 'my', 'your', 'our'];
        
        $tokens1 = array_diff($tokens1, $stopWords);
        $tokens2 = array_diff($tokens2, $stopWords);
        
        if (empty($tokens1) || empty($tokens2)) {
            return 0.0;
        }
        
        // Jaccard similarity
        $intersection = count(array_intersect($tokens1, $tokens2));
        $union = count(array_unique(array_merge($tokens1, $tokens2)));
        
        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Generate a unique email variation that differs from recent emails
     * 
     * P3 FIX: Email fingerprinting prevention - ensures consecutive emails
     * to the same company vary sufficiently to avoid spam detection.
     * 
     * @param Contact $contact The contact
     * @param array $recentEmailBodies Array of recent email body texts sent to this company
     * @param float $maxSimilarity Maximum allowed similarity (default 0.6)
     * @param int $maxAttempts Maximum generation attempts (default 10)
     * @return array ['success' => bool, 'body' => string|null, 'attempts' => int, 'similarity' => float]
     */
    public function ensureUniqueVariation(
        Contact $contact,
        array $recentEmailBodies,
        float $maxSimilarity = 0.6,
        int $maxAttempts = 10
    ): array {
        // If no recent emails, any variation is unique
        if (empty($recentEmailBodies)) {
            return [
                'success' => true,
                'body' => null, // Caller should generate normally
                'attempts' => 0,
                'similarity' => 0.0,
                'message' => 'No recent emails to compare against',
            ];
        }
        
        // This method validates - actual generation happens in orchestrator
        // Return guidance for the orchestrator
        return [
            'success' => true,
            'recent_count' => count($recentEmailBodies),
            'max_similarity' => $maxSimilarity,
            'max_attempts' => $maxAttempts,
            'message' => 'Use spintax variation to generate unique content',
            'validation_callback' => function(string $newBody) use ($recentEmailBodies, $maxSimilarity): bool {
                foreach ($recentEmailBodies as $recent) {
                    if ($this->calculateTextSimilarity($newBody, $recent) > $maxSimilarity) {
                        return false; // Too similar
                    }
                }
                return true; // Sufficiently unique
            },
        ];
    }

    /**
     * Comprehensive email quality check
     * 
     * MASTER METHOD: Runs all quality validations and returns pass/fail with details.
     * Use this before sending any email.
     * 
     * @param string $emailBody The email body text
     * @param Contact $contact The recipient contact
     * @param array $context The personalization context used
     * @return array Complete quality assessment with pass/fail
     */
    public function runFullQualityCheck(string $emailBody, Contact $contact, array $context = []): array
    {
        $checks = [];
        $allPassed = true;
        
        // 1. Warmth validation
        $warmth = $this->validateEmailWarmth($emailBody);
        $checks['warmth'] = [
            'passed' => $warmth['approved'],
            'score' => $warmth['score'],
            'threshold' => $warmth['threshold'],
            'suggestions' => $warmth['suggestions'],
        ];
        if (!$warmth['approved']) {
            $allPassed = false;
        }
        
        // 2. Templated language detection
        $templated = $this->detectTemplatedLanguage($emailBody);
        $checks['templated_language'] = [
            'passed' => !$templated['is_templated'],
            'score' => $templated['score'],
            'max_acceptable' => $templated['max_acceptable'],
            'phrases_found' => $templated['phrases'],
            'verdict' => $templated['verdict'],
        ];
        if ($templated['is_templated']) {
            $allPassed = false;
        }
        
        // 3. Personalization depth
        $persuasionContext = $this->buildPersuasionContext($contact);
        $depth = $this->calculatePersonalizationDepth($contact, array_merge($persuasionContext, $context));
        $checks['personalization_depth'] = [
            'passed' => $depth['score'] >= 5.0,
            'score' => $depth['score'],
            'grade' => $depth['grade'],
            'dimensions_met' => $depth['dimensions_met'],
            'dimensions_total' => $depth['dimensions_total'],
        ];
        if ($depth['score'] < 5.0) {
            $allPassed = false;
        }
        
        // 4. Unresolved spintax check
        $hasUnresolvedSpintax = preg_match('/\{[^{}]+\|[^{}]+\}/', $emailBody);
        $checks['spintax_resolved'] = [
            'passed' => !$hasUnresolvedSpintax,
            'message' => $hasUnresolvedSpintax 
                ? 'Email contains unresolved spintax syntax' 
                : 'All spintax properly resolved',
        ];
        if ($hasUnresolvedSpintax) {
            $allPassed = false;
        }
        
        // 5. You-focus ratio
        $youCount = preg_match_all('/\byou\b|\byour\b/i', $emailBody);
        $weCount = preg_match_all('/\bwe\b|\bour\b/i', $emailBody);
        $youFocusRatio = $weCount > 0 ? $youCount / $weCount : ($youCount > 0 ? 10 : 0);
        $checks['you_focus'] = [
            'passed' => $youFocusRatio >= 1.5,
            'you_count' => $youCount,
            'we_count' => $weCount,
            'ratio' => round($youFocusRatio, 2),
            'target_ratio' => 1.5,
        ];
        if ($youFocusRatio < 1.5) {
            $allPassed = false;
        }
        
        // 6. CTA presence
        $hasCta = preg_match('/\?|call|chat|connect|schedule|reply|interested/i', $emailBody);
        $checks['cta_present'] = [
            'passed' => (bool)$hasCta,
            'message' => $hasCta ? 'Clear call-to-action found' : 'No clear CTA detected',
        ];
        if (!$hasCta) {
            $allPassed = false;
        }
        
        // 7. Length check
        $wordCount = str_word_count($emailBody);
        $checks['length'] = [
            'passed' => $wordCount >= 30 && $wordCount <= 300,
            'word_count' => $wordCount,
            'range' => '30-300 words',
            'message' => match(true) {
                $wordCount < 30 => 'Email too short - may seem rushed',
                $wordCount > 300 => 'Email too long - may not be read',
                default => 'Length appropriate',
            },
        ];
        if ($wordCount < 30 || $wordCount > 300) {
            $allPassed = false;
        }
        
        return [
            'passed' => $allPassed,
            'checks' => $checks,
            'summary' => $allPassed 
                ? 'Email passes all quality checks' 
                : 'Email failed one or more quality checks',
            'failed_checks' => array_keys(array_filter($checks, fn($c) => !$c['passed'])),
        ];
    }

    // ==================================================================================
    // P3 IMPROVEMENT: EMAIL FINGERPRINT FOR DEDUPLICATION TRACKING
    // ==================================================================================

    /**
     * Generate a fingerprint hash for an email body
     * 
     * This creates a normalized hash that can be used to:
     * 1. Track unique email variations for A/B testing
     * 2. Detect duplicate emails being sent
     * 3. Help with email fingerprinting prevention
     * 
     * The fingerprint normalizes the content (lowercase, whitespace normalized)
     * so that minor formatting differences don't create different hashes.
     * 
     * @param string $body The email body text
     * @return string A 16-character hex fingerprint
     */
    public function generateEmailFingerprint(string $body): string
    {
        // Normalize: lowercase, collapse whitespace, remove punctuation variations
        $normalized = strtolower($body);
        $normalized = preg_replace('/\s+/', ' ', $normalized);  // Collapse whitespace
        $normalized = preg_replace('/[^\w\s]/', '', $normalized); // Remove punctuation
        $normalized = trim($normalized);
        
        // Use xxHash for speed, or fall back to MD5 if not available
        if (function_exists('hash') && in_array('xxh3', hash_algos())) {
            return substr(hash('xxh3', $normalized), 0, 16);
        }
        
        // Fallback to MD5 (first 16 chars)
        return substr(md5($normalized), 0, 16);
    }

    /**
     * Check if an email fingerprint matches any recent emails
     * 
     * @param string $fingerprint The fingerprint to check
     * @param array $recentFingerprints Array of recent fingerprints to compare against
     * @return bool True if fingerprint is found (duplicate), false otherwise
     */
    public function isDuplicateFingerprint(string $fingerprint, array $recentFingerprints): bool
    {
        return in_array($fingerprint, $recentFingerprints, true);
    }

    /**
     * Generate a batch of unique email variations
     * 
     * This method helps generate multiple unique email variations for a contact,
     * ensuring each has a distinct fingerprint. Useful for:
     * - Multi-touch campaigns (different emails in a sequence)
     * - A/B testing with guaranteed variation
     * - Avoiding spam filter fingerprint detection
     * 
     * @param Contact $contact The contact
     * @param int $count Number of variations to generate
     * @param int $maxAttempts Maximum attempts per variation
     * @return array Array of unique variations with fingerprints
     */
    public function generateUniqueEmailVariations(Contact $contact, int $count = 3, int $maxAttempts = 10): array
    {
        $variations = [];
        $fingerprints = [];
        $totalAttempts = 0;
        $collisions = 0;
        $startTime = microtime(true);
        
        // P4-2 FIX: Log start of variation generation
        $this->logger->debug('Starting unique email variation generation', [
            'contact_id' => $contact->getId(),
            'contact_name' => $contact->getFirstName() . ' ' . $contact->getLastName(),
            'company' => $contact->getCompany()?->getName(),
            'requested_count' => $count,
            'max_attempts_per_variation' => $maxAttempts,
        ]);
        
        for ($i = 0; $i < $count; $i++) {
            $attempts = 0;
            $variationCollisions = 0;
            
            do {
                $attempts++;
                $totalAttempts++;
                
                // Build fresh persuasion context (uses random spintax selections)
                $persuasionContext = $this->buildPersuasionContext($contact);
                $engagementLevel = $persuasionContext['engagement_level'] ?? 'cold';
                
                // Generate sample body from fused paragraphs
                $sampleParts = [
                    $persuasionContext['fused_intro'] ?? '',
                    $persuasionContext['fused_value'] ?? '',
                    $persuasionContext['fused_close'] ?? '',
                ];
                $sampleBody = implode("\n\n", array_filter($sampleParts));
                
                // Apply quality fixes
                $tone = $persuasionContext['template_architecture']['tone_preference'] ?? 'formal';
                $sampleBody = $this->applyOutputQualityFixes($sampleBody, $engagementLevel, $tone);
                
                $fingerprint = $this->generateEmailFingerprint($sampleBody);
                
                // P4-2 FIX: Track collisions for logging
                if (in_array($fingerprint, $fingerprints, true)) {
                    $variationCollisions++;
                    $collisions++;
                    $this->logger->debug('Fingerprint collision detected', [
                        'variation_index' => $i + 1,
                        'attempt' => $attempts,
                        'fingerprint' => $fingerprint,
                        'existing_fingerprints' => $fingerprints,
                    ]);
                }
                
            } while (in_array($fingerprint, $fingerprints, true) && $attempts < $maxAttempts);
            
            if (!in_array($fingerprint, $fingerprints, true)) {
                $fingerprints[] = $fingerprint;
                $variations[] = [
                    'body' => $sampleBody,
                    'fingerprint' => $fingerprint,
                    'persuasion_context' => $persuasionContext,
                    'attempts_needed' => $attempts,
                    'collisions' => $variationCollisions,
                ];
                
                // P4-2 FIX: Log successful variation
                $this->logger->debug('Successfully generated unique variation', [
                    'variation_index' => $i + 1,
                    'fingerprint' => $fingerprint,
                    'attempts_needed' => $attempts,
                    'word_count' => str_word_count($sampleBody),
                ]);
            } else {
                // P4-2 FIX: Log failed variation (max attempts reached)
                $this->logger->warning('Failed to generate unique variation - max attempts reached', [
                    'variation_index' => $i + 1,
                    'max_attempts' => $maxAttempts,
                    'collisions' => $variationCollisions,
                    'existing_fingerprints_count' => count($fingerprints),
                ]);
            }
        }
        
        $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);
        $success = count($variations) === $count;
        
        // P4-2 FIX: Log completion summary
        $logLevel = $success ? 'info' : 'warning';
        $this->logger->$logLevel('Completed unique email variation generation', [
            'contact_id' => $contact->getId(),
            'requested_count' => $count,
            'generated_count' => count($variations),
            'success' => $success,
            'total_attempts' => $totalAttempts,
            'total_collisions' => $collisions,
            'elapsed_ms' => $elapsedMs,
            'fingerprints' => $fingerprints,
        ]);
        
        return [
            'variations' => $variations,
            'unique_count' => count($variations),
            'requested_count' => $count,
            'success' => $success,
            // P4-2 FIX: Include generation statistics
            'stats' => [
                'total_attempts' => $totalAttempts,
                'total_collisions' => $collisions,
                'elapsed_ms' => $elapsedMs,
                'avg_attempts_per_variation' => count($variations) > 0 
                    ? round($totalAttempts / count($variations), 2) 
                    : 0,
            ],
        ];
    }
}
