<?php
/**
 * Fix missing translation keys in all 3 locale files (EN, FR, AR).
 * Run from project root: php scripts/fix_translations.php
 */

$enFile = "translations/messages.en.json";
$frFile = "translations/messages.fr.json";
$arFile = "translations/messages.ar.json";

$en = json_decode(file_get_contents($enFile), true);
$fr = json_decode(file_get_contents($frFile), true);
$ar = json_decode(file_get_contents($arFile), true);

function setNested(&$arr, $key, $value) {
    $parts = explode(".", $key);
    $ref = &$arr;
    foreach ($parts as $i => $part) {
        if ($i === count($parts) - 1) {
            $ref[$part] = $value;
        } else {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
    }
}

function sortRecursive(&$arr) {
    ksort($arr);
    foreach ($arr as $k => &$v) {
        if (is_array($v) && !array_is_list($v)) {
            sortRecursive($v);
        }
    }
}

function flatten($arr, $prefix = "") {
    $result = [];
    foreach ($arr as $k => $v) {
        $key = $prefix ? "$prefix.$k" : $k;
        if (is_array($v) && !array_is_list($v)) {
            $result = array_merge($result, flatten($v, $key));
        } else {
            $result[$key] = is_array($v) ? json_encode($v) : $v;
        }
    }
    return $result;
}

// ==========================================
// STEP 1: Add missing EN keys
// ==========================================

$newEnKeys = [
    "activity.new" => "New Activity",
    "command_center.alerts_description" => "Recent alerts and system notifications",
    "command_center.pipeline" => "Pipeline",
    "common.insights" => "Insights",
    "common.open" => "Open",
    "common.supply" => "Supply",
    "common.system" => "System",
    "common.unread" => "Unread",
    "dashboard.active" => "Active",
    "dashboard.activity_summary" => "Activity Summary",
    "dashboard.click" => "Click",
    "dashboard.click_rate" => "Click Rate",
    "dashboard.email_metrics" => "Email Metrics",
    "dashboard.emails" => "Emails",
    "dashboard.emails_today" => "Emails Today",
    "dashboard.engagement" => "Engagement",
    "dashboard.last_24h" => "Last 24h",
    "dashboard.last_7d" => "Last 7 Days",
    "dashboard.open" => "Open",
    "dashboard.open_rate" => "Open Rate",
    "dashboard.reply_rate" => "Reply Rate",
    "dashboard.to" => "to",
    "lead.new" => "New Lead",
    "lead.pipeline" => "Lead Pipeline",
    "notification.plural" => "Notifications",
    "quote.new" => "New Quote",
    "rfq.type.framework" => "Framework Agreement",
    "rfq.type.npi" => "NPI (New Product Introduction)",
    "rfq.type.standard" => "Standard RFQ",
    "rfq.type_label" => "RFQ Type",
    "rfq.won.description" => "This RFQ has been won. Congratulations!",
    "rfq.won.title" => "RFQ Won!",
    "rfq.won_label" => "Won",
    "task.new" => "New Task",
    "playbook.flash.invalid_actions" => "Invalid actions configuration for playbook.",
    "playbook.flash.invalid_rules" => "Invalid rules configuration for playbook.",
];

$added = 0;
foreach ($newEnKeys as $key => $value) {
    setNested($en, $key, $value);
    $added++;
}
echo "Added $added keys to EN.\n";

sortRecursive($en);
file_put_contents($enFile, json_encode($en, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

// ==========================================
// STEP 2: Sync FR and AR
// ==========================================

$flatEn = flatten($en);
$flatFr = flatten($fr);
$flatAr = flatten($ar);

$missingFr = array_diff(array_keys($flatEn), array_keys($flatFr));
$missingAr = array_diff(array_keys($flatEn), array_keys($flatAr));

echo "Missing from FR: " . count($missingFr) . "\n";
echo "Missing from AR: " . count($missingAr) . "\n";

// French translations
$frTranslations = [
    "activity.new" => "Nouvelle Activit\u00e9",
    "command_center.alerts_description" => "Alertes r\u00e9centes et notifications syst\u00e8me",
    "command_center.pipeline" => "Pipeline",
    "common.insights" => "Informations",
    "common.open" => "Ouvert",
    "common.supply" => "Approvisionnement",
    "common.system" => "Syst\u00e8me",
    "common.unread" => "Non lu",
    "dashboard.active" => "Actif",
    "dashboard.activity_summary" => "R\u00e9sum\u00e9 des Activit\u00e9s",
    "dashboard.click" => "Clic",
    "dashboard.click_rate" => "Taux de Clic",
    "dashboard.email_metrics" => "M\u00e9triques Email",
    "dashboard.emails" => "Emails",
    "dashboard.emails_today" => "Emails Aujourd\u2019hui",
    "dashboard.engagement" => "Engagement",
    "dashboard.last_24h" => "Derni\u00e8res 24h",
    "dashboard.last_7d" => "Derniers 7 Jours",
    "dashboard.open" => "Ouvert",
    "dashboard.open_rate" => "Taux d\u2019Ouverture",
    "dashboard.reply_rate" => "Taux de R\u00e9ponse",
    "dashboard.to" => "\u00e0",
    "lead.new" => "Nouveau Lead",
    "lead.pipeline" => "Pipeline des Leads",
    "notification.plural" => "Notifications",
    "quote.new" => "Nouveau Devis",
    "rfq.type.framework" => "Accord-cadre",
    "rfq.type.npi" => "NPI (Nouveau Produit en Introduction)",
    "rfq.type.standard" => "RFQ Standard",
    "rfq.type_label" => "Type de RFQ",
    "rfq.won.description" => "Ce RFQ a \u00e9t\u00e9 remport\u00e9. F\u00e9licitations !",
    "rfq.won.title" => "RFQ Remport\u00e9 !",
    "rfq.won_label" => "Gagn\u00e9",
    "task.new" => "Nouvelle T\u00e2che",
    "playbook.flash.invalid_actions" => "Configuration d\u2019actions invalide pour le playbook.",
    "playbook.flash.invalid_rules" => "Configuration de r\u00e8gles invalide pour le playbook.",
];

foreach ($missingFr as $key) {
    $value = $frTranslations[$key] ?? $flatEn[$key];
    setNested($fr, $key, $value);
}
sortRecursive($fr);
file_put_contents($frFile, json_encode($fr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
echo "Synced " . count($missingFr) . " keys to FR.\n";

// Arabic translations
$arTranslations = [
    "activity.new" => "\u0646\u0634\u0627\u0637 \u062c\u062f\u064a\u062f",
    "command_center.alerts_description" => "\u0627\u0644\u062a\u0646\u0628\u064a\u0647\u0627\u062a \u0627\u0644\u0623\u062e\u064a\u0631\u0629 \u0648\u0625\u0634\u0639\u0627\u0631\u0627\u062a \u0627\u0644\u0646\u0638\u0627\u0645",
    "command_center.pipeline" => "\u062e\u0637 \u0627\u0644\u0623\u0646\u0627\u0628\u064a\u0628",
    "common.insights" => "\u0631\u0624\u0649",
    "common.open" => "\u0645\u0641\u062a\u0648\u062d",
    "common.supply" => "\u0627\u0644\u062a\u0648\u0631\u064a\u062f",
    "common.system" => "\u0627\u0644\u0646\u0638\u0627\u0645",
    "common.unread" => "\u063a\u064a\u0631 \u0645\u0642\u0631\u0648\u0621",
    "dashboard.active" => "\u0646\u0634\u0637",
    "dashboard.activity_summary" => "\u0645\u0644\u062e\u0635 \u0627\u0644\u0623\u0646\u0634\u0637\u0629",
    "dashboard.click" => "\u0646\u0642\u0631\u0629",
    "dashboard.click_rate" => "\u0645\u0639\u062f\u0644 \u0627\u0644\u0646\u0642\u0631",
    "dashboard.email_metrics" => "\u0645\u0642\u0627\u064a\u064a\u0633 \u0627\u0644\u0628\u0631\u064a\u062f \u0627\u0644\u0625\u0644\u0643\u062a\u0631\u0648\u0646\u064a",
    "dashboard.emails" => "\u0631\u0633\u0627\u0626\u0644 \u0627\u0644\u0628\u0631\u064a\u062f",
    "dashboard.emails_today" => "\u0631\u0633\u0627\u0626\u0644 \u0627\u0644\u064a\u0648\u0645",
    "dashboard.engagement" => "\u0627\u0644\u062a\u0641\u0627\u0639\u0644",
    "dashboard.last_24h" => "\u0622\u062e\u0631 24 \u0633\u0627\u0639\u0629",
    "dashboard.last_7d" => "\u0622\u062e\u0631 7 \u0623\u064a\u0627\u0645",
    "dashboard.open" => "\u0645\u0641\u062a\u0648\u062d",
    "dashboard.open_rate" => "\u0645\u0639\u062f\u0644 \u0627\u0644\u0641\u062a\u062d",
    "dashboard.reply_rate" => "\u0645\u0639\u062f\u0644 \u0627\u0644\u0631\u062f",
    "dashboard.to" => "\u0625\u0644\u0649",
    "lead.new" => "\u0639\u0645\u064a\u0644 \u0645\u062d\u062a\u0645\u0644 \u062c\u062f\u064a\u062f",
    "lead.pipeline" => "\u062e\u0637 \u0623\u0646\u0627\u0628\u064a\u0628 \u0627\u0644\u0639\u0645\u0644\u0627\u0621 \u0627\u0644\u0645\u062d\u062a\u0645\u0644\u064a\u0646",
    "notification.plural" => "\u0627\u0644\u0625\u0634\u0639\u0627\u0631\u0627\u062a",
    "quote.new" => "\u0639\u0631\u0636 \u0633\u0639\u0631 \u062c\u062f\u064a\u062f",
    "rfq.type.framework" => "\u0627\u062a\u0641\u0627\u0642\u064a\u0629 \u0625\u0637\u0627\u0631",
    "rfq.type.npi" => "NPI (\u0625\u062f\u062e\u0627\u0644 \u0645\u0646\u062a\u062c \u062c\u062f\u064a\u062f)",
    "rfq.type.standard" => "RFQ \u0642\u064a\u0627\u0633\u064a",
    "rfq.type_label" => "\u0646\u0648\u0639 RFQ",
    "rfq.won.description" => "\u062a\u0645 \u0627\u0644\u0641\u0648\u0632 \u0628\u0647\u0630\u0627 RFQ. \u062a\u0647\u0627\u0646\u064a\u0646\u0627!",
    "rfq.won.title" => "RFQ \u0641\u0627\u0632!",
    "rfq.won_label" => "\u0641\u0627\u0632",
    "task.new" => "\u0645\u0647\u0645\u0629 \u062c\u062f\u064a\u062f\u0629",
    "playbook.flash.invalid_actions" => "\u062a\u0643\u0648\u064a\u0646 \u0625\u062c\u0631\u0627\u0621\u0627\u062a \u063a\u064a\u0631 \u0635\u0627\u0644\u062d \u0644\u0644\u0639\u0628 playbook.",
    "playbook.flash.invalid_rules" => "\u062a\u0643\u0648\u064a\u0646 \u0642\u0648\u0627\u0639\u062f \u063a\u064a\u0631 \u0635\u0627\u0644\u062d \u0644\u0644\u0639\u0628 playbook.",
];

foreach ($missingAr as $key) {
    $value = $arTranslations[$key] ?? $flatEn[$key];
    setNested($ar, $key, $value);
}
sortRecursive($ar);
file_put_contents($arFile, json_encode($ar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
echo "Synced " . count($missingAr) . " keys to AR.\n";

// ==========================================
// STEP 3: Verify
// ==========================================

$enFinal = flatten(json_decode(file_get_contents($enFile), true));
$frFinal = flatten(json_decode(file_get_contents($frFile), true));
$arFinal = flatten(json_decode(file_get_contents($arFile), true));

echo "\n=== VERIFICATION ===\n";
echo "EN: " . count($enFinal) . " keys\n";
echo "FR: " . count($frFinal) . " keys\n";
echo "AR: " . count($arFinal) . " keys\n";

$stillMissingFr = array_diff(array_keys($enFinal), array_keys($frFinal));
$stillMissingAr = array_diff(array_keys($enFinal), array_keys($arFinal));
echo "Still missing from FR: " . count($stillMissingFr) . "\n";
echo "Still missing from AR: " . count($stillMissingAr) . "\n";

if (count($stillMissingFr) > 0) {
    echo "FR missing: " . implode(", ", array_slice($stillMissingFr, 0, 20)) . "\n";
}
if (count($stillMissingAr) > 0) {
    echo "AR missing: " . implode(", ", array_slice($stillMissingAr, 0, 20)) . "\n";
}

echo "\nDone!\n";
