<?php
/**
 * includes/whatsapp.php
 * WhatsApp click-to-chat helpers (free — opens wa.me / WhatsApp app, no API).
 */

/**
 * Format a phone number to international format for WhatsApp.
 * Converts 07XXXXXXXX → 2547XXXXXXXX
 */
function wa_format_phone(string $phone): string {
    $phone = preg_replace('/\D/', '', $phone);
    if (str_starts_with($phone, '0')) {
        $phone = '254' . substr($phone, 1);
    }
    if (!str_starts_with($phone, '254') && strlen($phone) === 9) {
        $phone = '254' . $phone;
    }
    return $phone;
}

/**
 * Generate a WhatsApp click-to-chat URL (opens the app/web; user must tap Send).
 */
function wa_link(string $phone, string $message = ''): string {
    $phone = wa_format_phone($phone);
    $url   = 'https://api.whatsapp.com/send?phone=' . $phone;
    if ($message !== '') {
        $url .= '&text=' . rawurlencode($message);
    }
    return $url;
}

/**
 * Render a WhatsApp chat button.
 */
function wa_button(string $phone, string $label = 'Chat on WhatsApp', string $message = '', string $size = 'sm'): string {
    if (!$phone) return '';
    $url = wa_link($phone, $message);
    return "<a href=\"" . htmlspecialchars($url) . "\" target=\"_blank\" rel=\"noopener\"
               class=\"btn btn-success btn-{$size} d-inline-flex align-items-center gap-1\"
               title=\"Chat on WhatsApp\">
               <i class=\"fab fa-whatsapp\"></i> {$label}
            </a>";
}

/**
 * Render a WhatsApp share button for a URL.
 */
function wa_share_button(string $text, string $url = '', string $label = 'Share on WhatsApp'): string {
    $full_text = $text . ($url ? ' ' . $url : '');
    $wa_url    = 'https://api.whatsapp.com/send?text=' . rawurlencode($full_text);
    return "<a href=\"" . htmlspecialchars($wa_url) . "\" target=\"_blank\" rel=\"noopener\"
               class=\"btn btn-success btn-sm d-inline-flex align-items-center gap-1\">
               <i class=\"fab fa-whatsapp\"></i> {$label}
            </a>";
}

/**
 * Fixed bottom-right WhatsApp button (free wa.me link). Hidden if CLUB_WHATSAPP_PHONE is empty.
 */
function wa_render_floating_widget(): string {
    $phone = defined('CLUB_WHATSAPP_PHONE') ? trim((string) CLUB_WHATSAPP_PHONE) : '';
    if ($phone === '') {
        return '';
    }

    $greeting = (defined('CLUB_WHATSAPP_GREETING') && CLUB_WHATSAPP_GREETING !== '')
        ? CLUB_WHATSAPP_GREETING
        : 'Hi Apex Sports Club, I need help with: ';
    $url   = htmlspecialchars(wa_link($phone, $greeting), ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars('Open WhatsApp to message Apex Sports Club — tap Send to start the chat', ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars('Message us on WhatsApp', ENT_QUOTES, 'UTF-8');
    $hint  = htmlspecialchars('Opens WhatsApp — tap Send to chat', ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div class="wa-floating-wrap" role="complementary" aria-label="WhatsApp support">
    <div class="wa-floating-copy">
        <span class="wa-floating-label">{$label}</span>
        <span class="wa-floating-hint">{$hint}</span>
    </div>
    <a href="{$url}" class="wa-floating-widget" target="_blank" rel="noopener noreferrer"
       aria-label="{$title}" title="{$title}">
        <i class="fab fa-whatsapp" aria-hidden="true"></i>
    </a>
</div>
<style>
.wa-floating-wrap {
    position: fixed;
    right: 1.25rem;
    bottom: 1.25rem;
    z-index: 1080;
    display: flex;
    align-items: center;
    gap: 0.65rem;
}
.wa-floating-copy {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.15rem;
    max-width: 11rem;
    opacity: 0;
    transform: translateX(8px);
    pointer-events: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
}
.wa-floating-wrap:hover .wa-floating-copy,
.wa-floating-wrap:focus-within .wa-floating-copy {
    opacity: 1;
    transform: translateX(0);
}
.wa-floating-label {
    background: #fff;
    color: #0f172a;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 0.4rem 0.7rem;
    border-radius: 8px;
    box-shadow: 0 2px 12px rgba(15, 23, 42, 0.12);
    border: 1px solid #e2e8f0;
    text-align: right;
    line-height: 1.2;
}
.wa-floating-hint {
    font-size: 0.7rem;
    color: #64748b;
    text-align: right;
    padding-right: 0.15rem;
    line-height: 1.2;
}
.wa-floating-widget {
    width: 3.5rem;
    height: 3.5rem;
    border-radius: 50%;
    background: #25d366;
    color: #fff !important;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(37, 211, 102, 0.45);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    flex-shrink: 0;
}
.wa-floating-widget:hover {
    color: #fff !important;
    transform: scale(1.08);
    box-shadow: 0 6px 20px rgba(37, 211, 102, 0.55);
}
@media (max-width: 576px) {
    .wa-floating-wrap { right: 1rem; bottom: 1rem; }
    .wa-floating-copy { display: none; }
    .wa-floating-widget { width: 3.25rem; height: 3.25rem; font-size: 1.6rem; }
}
</style>
HTML;
}

/**
 * Send a WhatsApp notification.
 * Stub — always returns false (no API provider configured).
 */
function wa_notify(string $phone, string $message): bool {
    return false;
}
