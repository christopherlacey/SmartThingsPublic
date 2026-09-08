<?php
/**
 * Channel redirector for the emergency contact page.
 *
 * The page links to go.php?c=call — never to a tel:/mailto:/wa.me URL. The
 * actual numbers and addresses live in a config file ABOVE the web root, so
 * they never appear in the page source, never reach a scraper crawling the
 * site, and never show up in a "view source".
 *
 * Honest limit: once someone taps a button, their own phone shows them the
 * number in the dial prompt. That is unavoidable for any click-to-call link.
 * What this stops is passive harvesting, which is the actual threat.
 */

declare(strict_types=1);

$configPath = __DIR__ . '/../.contact-config.php';
if (!is_readable($configPath)) {
    http_response_code(500);
    exit('Contact configuration missing.');
}
$contact = require $configPath;

$channel = isset($_GET['c']) ? (string) $_GET['c'] : '';

/** wa.me wants a bare number: no plus, no spaces, no punctuation. */
$wa = static function (string $key) use ($contact): string {
    $n = preg_replace('/\D+/', '', (string) ($contact[$key] ?? ''));
    return $n === '' ? '' : 'https://wa.me/' . $n;
};

$targets = [
    // --- Chris, fastest first ---------------------------------------------
    // ?call=voice asks WhatsApp for the call sheet rather than the chat thread.
    'whatsapp-call'    => fn() => ($u = $wa('chris_whatsapp_e164')) === '' ? '' : $u . '?call=voice',
    'whatsapp-message' => fn() => $wa('chris_whatsapp_e164'),
    'whatsapp'         => fn() => $wa('chris_whatsapp_e164'),   // pre-existing code, kept working
    'facetime'         => fn() => 'facetime-audio:' . ($contact['chris_facetime'] ?? ''),
    'imessage'         => fn() => 'sms:' . ($contact['chris_imessage'] ?? ''),
    'signal'           => fn() => (string) ($contact['chris_signal_url'] ?? ''),
    'telegram'         => fn() => (string) ($contact['chris_telegram_url'] ?? ''),
    'google-chat'      => fn() => (string) ($contact['chris_google_chat_url'] ?? ''),
    'sms'              => fn() => 'sms:' . ($contact['chris_phone_e164'] ?? ''),
    'email'            => fn() => 'mailto:' . ($contact['chris_email'] ?? ''),
    'schedule'         => fn() => (string) ($contact['chris_schedule_url'] ?? ''),
    'call'             => fn() => 'tel:' . ($contact['chris_phone_e164'] ?? ''),

    // --- Student or Friend of Addy ----------------------------------------
    // Deliberately not the personal cell, so students and parents never hold it.
    'addy-student'     => fn() => 'mailto:' . ($contact['addy_student_email'] ?? ''),
    'addy-friend'      => fn() => 'mailto:' . ($contact['addy_friend_email'] ?? ''),
    // The exception: "something is wrong" should be as fast as the top of the page.
    'addy-urgent'      => fn() => ($u = $wa('chris_whatsapp_e164')) === '' ? '' : $u . '?call=voice',

    // --- Everything else ---------------------------------------------------
    'brasil'           => fn() => (string) ($contact['brasil_url'] ?? ''),
    'ngns-discord'     => fn() => (string) ($contact['ngns_discord_url'] ?? ''),
    'barandcocoa'      => fn() => (string) ($contact['barandcocoa_url'] ?? ''),
    'website'          => fn() => (string) ($contact['website_url'] ?? ''),
    'linkedin'         => fn() => (string) ($contact['linkedin_url'] ?? ''),

    // --- John --------------------------------------------------------------
    'john-call'        => fn() => 'tel:' . ($contact['john_phone_e164'] ?? ''),
    'john-sms'         => fn() => 'sms:' . ($contact['john_phone_e164'] ?? ''),
];

if (!isset($targets[$channel])) {
    header('Location: ./', true, 302);
    exit;
}

$destination = ($targets[$channel])();

// An unfilled config entry must not produce a broken link. A bare "tel:" was
// already caught; a wa.me URL with no number would have sent people to
// WhatsApp's homepage, and an empty facetime-audio: does nothing at all.
// On an emergency page a visible 503 beats a link that quietly goes nowhere.
$unset = $destination === ''
    || preg_match('/^(tel:|sms:|mailto:|facetime-audio:|facetime:)$/', $destination) === 1
    || preg_match('#^https://wa\.me/(\?|$)#', $destination) === 1;

if ($unset) {
    http_response_code(503);
    exit('That contact method is not set up yet.');
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Location: ' . $destination, true, 302);
exit;
