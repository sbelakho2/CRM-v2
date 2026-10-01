<?php

/**
 * CI gate: route authorization coverage.
 *
 * SYSTEM-AWARE CONTRACT: this CRM has exactly two kinds of route:
 *   1. Routes guarded by Symfony's #[IsGranted] attribute (any value,
 *      including PUBLIC_ACCESS for token-authenticated public surfaces
 *      like live quotes and public meeting booking) — declared IN CODE on
 *      the controller class or the action method.
 *   2. Routes reachable only through the access_control PUBLIC_ACCESS
 *      allowlist in config/packages/security.yaml (login, logout,
 *      registration + email verification, password reset, email
 *      unsubscribe, campaign click tracking, provider webhooks).
 *
 * A controller file with NEITHER is a route whose authorization policy is
 * implicit and unauditable — exactly how the round-8 task IDOR happened.
 * New controller: add #[IsGranted(...)] or, if genuinely anonymous, add
 * the file to the reviewed allowlist BELOW with a one-line reason AND make
 * sure security.yaml's access_control actually scopes it.
 *
 * Exit 0 = every controller carries an explicit policy; exit 1 = violation.
 */

$root = dirname(__DIR__, 2);

$reviewedAnonymous = [
    // file => reason (must mirror config/packages/security.yaml access_control)
    'src/Controller/SecurityController.php' => 'login/logout/forgot-password — PUBLIC_ACCESS by design (access_control ^/login, ^/logout, ^/forgot-password, ^/reset-password)',
    'src/Controller/RegistrationController.php' => 'registration + signed email verification — PUBLIC_ACCESS (access_control ^/register, ^/verify-email, ^/resend-verification); self-registration disabled by default (app.public_registration_enabled)',
    'src/Controller/EmailUnsubscribeController.php' => 'one-click unsubscribe from signed campaign links — PUBLIC_ACCESS (access_control ^/email/unsubscribe)',
    'src/Controller/EmailWebhookController.php' => 'Mailgun/Sendgrid delivery webhooks — PUBLIC_ACCESS (access_control ^/webhook/email); verified by provider signatures',
    'src/Controller/Api/WebEventController.php' => 'public web-event tracking pixel endpoint — PUBLIC_ACCESS (access_control ^/email-campaigns/track family); CSRF-checked',
];

$failures = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src/Controller', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $relative = str_replace($root . '/', '', $path);
    $code = (string) file_get_contents($path);

    $hasPolicy = strpos($code, 'IsGranted') !== false
        || strpos($code, 'Security(') !== false; // #[Sensio\FrameworkExtraBundle\Configuration\Security] or #[Security] attribute

    if (!$hasPolicy && !isset($reviewedAnonymous[$relative])) {
        $failures[] = sprintf(
            '%s declares routes with NO authorization policy: no #[IsGranted]/#[Security] attribute and not in the reviewed anonymous allowlist. Add #[IsGranted(\'ROLE_USER\')] (or PUBLIC_ACCESS for deliberate anonymous surfaces — then also scope it in security.yaml access_control) or add the file to check-route-authorization.php\'s allowlist with a reason.',
            $relative
        );
    }

    // Anonymous allowlist entries must stay in sync with security.yaml:
    // if a reviewed file stops being truly anonymous (gains IsGranted),
    // prune the allowlist entry so the list never rots.
    if ($hasPolicy && isset($reviewedAnonymous[$relative]) && strpos($code, 'PUBLIC_ACCESS') === false) {
        $failures[] = sprintf(
            '%s is in the reviewed-anonymous allowlist but now declares IsGranted without PUBLIC_ACCESS — prune the stale allowlist entry.',
            $relative
        );
    }
}

if ($failures !== []) {
    fwrite(STDERR, "✖ route-authorization gate FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo "✓ route-authorization gate passed (every controller carries an explicit authorization policy or a reviewed anonymous reason)\n";
