<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

require_once '../config/db_connect.php';
require_once '../includes/gemini_client.php';
require_once '../includes/url.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limiter.php';

$member_first = htmlspecialchars($_SESSION['first_name'] ?? 'Member');

// ── AJAX handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chat_action'])) {
    header('Content-Type: application/json');
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'member_csrf')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing CSRF token. Please reload the page.']);
        exit;
    }
    // Gemini calls cost money per request — cap chat turns per client.
    if (!rate_limit_check(client_rate_key('chatbot'), 12, 60)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many messages. Please wait a minute and try again.']);
        exit;
    }
    try {
    $action = $_POST['chat_action'];

    if ($action === 'send') {
        $userMsg = trim((string)($_POST['message'] ?? ''));
        if ($userMsg === '') {
            echo json_encode(['error' => 'Empty message.']);
            exit;
        }

        // Session history limit (20 messages)
        if (!isset($_SESSION['chatbot_history'])) $_SESSION['chatbot_history'] = [];
        $history = &$_SESSION['chatbot_history'];
        if (count($history) >= 40) {
            // Trim oldest pair
            array_splice($history, 0, 2);
        }

        // Build system context
        $systemCtx = "You are the Apex Sports Club AI assistant. You help club members with:
- Booking facilities and training sessions
- Membership plans, renewals, and pauses
- Fixture schedules, results, and league standings
- Coach information and availability
- Payments, receipts, and billing questions
- Club rules, policies, and announcements

The member's name is {$_SESSION['first_name']}. 
Today's date is " . date('d M Y') . ".
Always be friendly, concise, and helpful. If you don't know specific club data, say so and suggest where to find it. Never invent data.";

        // Build conversation history for context
        $historyText = '';
        foreach ($history as $h) {
            $historyText .= ($h['role'] === 'user' ? "Member: " : "Assistant: ") . $h['content'] . "\n";
        }

        $fullPrompt = $systemCtx . "\n\n--- Conversation so far ---\n" . $historyText . "Member: " . $userMsg . "\nAssistant:";

        $result = asc_gemini_generate_text($fullPrompt, [
            'temperature'     => 0.6,
            'maxOutputTokens' => 400,
            'timeout'         => 20,
        ]);

        if (!empty($result['success'])) {
            $reply = trim($result['text']);
            $history[] = ['role' => 'user',      'content' => $userMsg];
            $history[] = ['role' => 'assistant',  'content' => $reply];
            echo json_encode(['success' => true, 'reply' => $reply]);
        } else {
            echo json_encode(['error' => $result['error'] ?? 'No response from AI.']);
        }
        exit;
    }

    if ($action === 'clear') {
        $_SESSION['chatbot_history'] = [];
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action.']);
    exit;
    } catch (Throwable $e) {
        $ajax_err = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'An internal error occurred.';
        echo json_encode(['error' => $ajax_err]);
        exit;
    }
}

include_once '../includes/header.php';
?>
<style>
    body { background: #f1f5f9 !important; }

    .chat-shell {
        max-width: 820px;
        margin: 0 auto;
    }

    .chat-hero {
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        border-radius: 16px;
        color: #fff;
        padding: 1.75rem 2rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 8px 30px rgba(79,70,229,.3);
    }

    .chat-hero h1 { font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px; margin: 0; }
    .chat-hero p  { color: rgba(255,255,255,.8); margin: .35rem 0 0; font-size: .9rem; }

    .chat-window {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,.06);
    }

    .chat-messages {
        height: 460px;
        overflow-y: auto;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        scroll-behavior: smooth;
    }

    .msg-row { display: flex; gap: .75rem; align-items: flex-end; }
    .msg-row.user { flex-direction: row-reverse; }

    .msg-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .85rem;
        flex-shrink: 0;
    }
    .msg-avatar.ai   { background: linear-gradient(135deg, #4f46e5, #7c3aed); color: #fff; }
    .msg-avatar.user { background: linear-gradient(135deg, #0ea5e9, #0284c7); color: #fff; }

    .msg-bubble {
        max-width: 72%;
        padding: .75rem 1rem;
        border-radius: 14px;
        font-size: .92rem;
        line-height: 1.55;
    }
    .msg-bubble.ai   { background: #f1f5f9; color: #1e293b; border-bottom-left-radius: 4px; }
    .msg-bubble.user { background: linear-gradient(135deg, #4f46e5, #7c3aed); color: #fff; border-bottom-right-radius: 4px; }

    .msg-time { font-size: .72rem; color: #94a3b8; margin-top: .25rem; }

    .typing-indicator { display: flex; align-items: center; gap: .35rem; padding: .5rem 0; }
    .typing-dot {
        width: 8px; height: 8px; border-radius: 50%;
        background: #94a3b8; animation: blink 1.4s infinite;
    }
    .typing-dot:nth-child(2) { animation-delay: .2s; }
    .typing-dot:nth-child(3) { animation-delay: .4s; }
    @keyframes blink { 0%,80%,100%{ opacity:.2; } 40%{ opacity:1; } }

    .chat-footer {
        border-top: 1px solid #f1f5f9;
        padding: 1rem 1.25rem;
        background: #fafafa;
    }

    .chat-input-wrap {
        display: flex;
        gap: .75rem;
        align-items: flex-end;
    }

    .chat-textarea {
        flex: 1;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        padding: .7rem 1rem;
        font-size: .92rem;
        resize: none;
        line-height: 1.45;
        transition: border-color .15s;
        font-family: inherit;
        max-height: 120px;
        overflow-y: auto;
    }
    .chat-textarea:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.12); }

    .btn-send {
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: #fff;
        border: none;
        border-radius: 12px;
        width: 44px; height: 44px;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        transition: opacity .15s, transform .15s;
        flex-shrink: 0;
    }
    .btn-send:hover { opacity: .9; transform: scale(1.04); }
    .btn-send:disabled { opacity: .5; cursor: not-allowed; transform: none; }

    .quick-chips {
        display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .75rem;
    }
    .chip {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        color: #2563eb;
        font-size: .8rem;
        font-weight: 600;
        padding: .35rem .8rem;
        border-radius: 20px;
        cursor: pointer;
        transition: all .15s;
        user-select: none;
    }
    .chip:hover { background: #2563eb; color: #fff; border-color: #2563eb; }

    .chat-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: .75rem; }
    .msg-count { font-size: .78rem; color: #94a3b8; }

    .empty-state {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        height: 100%; color: #94a3b8; gap: .75rem;
        text-align: center;
    }
    .empty-icon { font-size: 3rem; }
</style>

<div class="container py-4">
<div class="chat-shell">

    <div class="chat-hero">
        <div class="d-flex align-items-center gap-3">
            <div style="font-size:2.2rem;">🤖</div>
            <div>
                <h1>Apex AI Assistant</h1>
                <p>Ask anything about the club — bookings, fixtures, membership, coaches & more.</p>
            </div>
        </div>
    </div>

    <div class="chat-window">
        <div class="chat-messages" id="chatMessages">
            <div class="empty-state" id="emptyState">
                <div class="empty-icon">💬</div>
                <div>
                    <strong>Start a conversation</strong><br>
                    <small>Use the quick chips below or type your question</small>
                </div>
            </div>
        </div>

        <div class="chat-footer">
            <div class="chat-controls">
                <span class="msg-count" id="msgCount">0 / 20 messages used</span>
                <button class="btn btn-outline-secondary btn-sm" onclick="clearChat()" id="clearBtn" style="display:none;">
                    <i class="fas fa-trash-can me-1"></i>Clear
                </button>
            </div>
            <div class="quick-chips" id="quickChips">
                <span class="chip" onclick="useChip(this)">📅 When is the next fixture?</span>
                <span class="chip" onclick="useChip(this)">💳 How do I renew my membership?</span>
                <span class="chip" onclick="useChip(this)">🏟️ How do I book a facility?</span>
                <span class="chip" onclick="useChip(this)">👟 Who are the available coaches?</span>
                <span class="chip" onclick="useChip(this)">⏸️ Can I pause my subscription?</span>
            </div>
            <div class="chat-input-wrap">
                <textarea id="chatInput" class="chat-textarea" rows="1"
                    placeholder="Ask about bookings, fixtures, membership…"
                    onkeydown="handleKey(event)"></textarea>
                <button class="btn-send" id="sendBtn" onclick="sendMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>

</div>
</div>

<script>
const CSRF_TOKEN = "<?php echo htmlspecialchars(csrf_ensure('member_csrf'), ENT_QUOTES, 'UTF-8'); ?>";
const SELF = '<?php echo htmlspecialchars(app_url('public/chatbot.php')); ?>';
let msgCount = 0;
const MAX_MSGS = 20;

function scrollBottom() {
    const el = document.getElementById('chatMessages');
    el.scrollTop = el.scrollHeight;
}

function timeNow() {
    return new Date().toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' });
}

function appendMsg(role, text) {
    const box = document.getElementById('chatMessages');
    const empty = document.getElementById('emptyState');
    if (empty) empty.remove();

    const isUser = role === 'user';
    const div = document.createElement('div');
    div.className = 'msg-row ' + (isUser ? 'user' : 'ai');
    div.innerHTML = `
        <div class="msg-avatar ${isUser ? 'user' : 'ai'}">
            <i class="fas ${isUser ? 'fa-user' : 'fa-robot'}"></i>
        </div>
        <div>
            <div class="msg-bubble ${isUser ? 'user' : 'ai'}">${escHtml(text)}</div>
            <div class="msg-time ${isUser ? 'text-end' : ''}">${timeNow()}</div>
        </div>`;
    box.appendChild(div);
    scrollBottom();
}

function showTyping() {
    const box = document.getElementById('chatMessages');
    const div = document.createElement('div');
    div.id = 'typingRow';
    div.className = 'msg-row ai';
    div.innerHTML = `
        <div class="msg-avatar ai"><i class="fas fa-robot"></i></div>
        <div class="msg-bubble ai">
            <div class="typing-indicator">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
        </div>`;
    box.appendChild(div);
    scrollBottom();
}

function removeTyping() {
    const t = document.getElementById('typingRow');
    if (t) t.remove();
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}

function updateCount() {
    document.getElementById('msgCount').textContent = `${msgCount} / ${MAX_MSGS} messages used`;
    document.getElementById('clearBtn').style.display = msgCount > 0 ? '' : 'none';
}

function useChip(el) {
    document.getElementById('chatInput').value = el.textContent.trim().replace(/^[\p{Emoji}\s]+/u,'').trim();
    document.getElementById('chatInput').focus();
}

function handleKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

async function sendMessage() {
    if (msgCount >= MAX_MSGS) {
        alert('Message limit reached. Clear the conversation to continue.');
        return;
    }
    const input = document.getElementById('chatInput');
    const msg = input.value.trim();
    if (!msg) return;

    input.value = '';
    input.style.height = '';
    document.getElementById('sendBtn').disabled = true;
    document.getElementById('quickChips').style.display = 'none';

    appendMsg('user', msg);
    msgCount++;
    updateCount();
    showTyping();

    try {
        const fd = new FormData();
        fd.append('chat_action', 'send');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('message', msg);
        const res = await fetch(SELF, { method: 'POST', body: fd });
        const data = await res.json();
        removeTyping();
        if (data.success) {
            appendMsg('ai', data.reply);
        } else {
            appendMsg('ai', '⚠️ ' + (data.error || 'Something went wrong. Please try again.'));
        }
    } catch (e) {
        removeTyping();
        appendMsg('ai', '⚠️ Network error. Please check your connection.');
    }

    document.getElementById('sendBtn').disabled = false;
}

async function clearChat() {
    const fd = new FormData();
    fd.append('chat_action', 'clear');
    fd.append('csrf_token', CSRF_TOKEN);
    await fetch(SELF, { method: 'POST', body: fd });
    msgCount = 0;
    updateCount();
    document.getElementById('chatMessages').innerHTML = `
        <div class="empty-state" id="emptyState">
            <div class="empty-icon">💬</div>
            <div><strong>Start a conversation</strong><br><small>Use the quick chips below or type your question</small></div>
        </div>`;
    document.getElementById('quickChips').style.display = '';
}

// Auto-resize textarea
document.getElementById('chatInput').addEventListener('input', function() {
    this.style.height = '';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
});
</script>

<?php include_once '../includes/footer.php'; ?>
