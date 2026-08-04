from pathlib import Path
from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import inch
from reportlab.platypus import BaseDocTemplate, Frame, PageTemplate, Paragraph, Spacer, PageBreak, KeepTogether, Image
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfbase import pdfmetrics

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'output' / 'pdf' / 'Apex_Sports_Club_Defense_Handbook.pdf'
OUT.parent.mkdir(parents=True, exist_ok=True)

NAVY = colors.HexColor('#102A43')
BLUE = colors.HexColor('#2563A8')
TEAL = colors.HexColor('#14857A')
GOLD = colors.HexColor('#B7791F')
PALE = colors.HexColor('#EEF5FA')
INK = colors.HexColor('#1F2937')
MUTED = colors.HexColor('#52606D')

styles = getSampleStyleSheet()
styles.add(ParagraphStyle(name='CoverTitle', parent=styles['Title'], fontName='Helvetica-Bold', fontSize=28, leading=34, textColor=NAVY, alignment=TA_CENTER, spaceAfter=14))
styles.add(ParagraphStyle(name='CoverSub', parent=styles['Normal'], fontName='Helvetica', fontSize=13, leading=18, textColor=MUTED, alignment=TA_CENTER, spaceAfter=10))
styles.add(ParagraphStyle(name='Kicker', parent=styles['Normal'], fontName='Helvetica-Bold', fontSize=8.5, leading=11, textColor=TEAL, spaceAfter=6, uppercase=True))
styles.add(ParagraphStyle(name='H1x', parent=styles['Heading1'], fontName='Helvetica-Bold', fontSize=18, leading=22, textColor=NAVY, spaceAfter=10))
styles.add(ParagraphStyle(name='H2x', parent=styles['Heading2'], fontName='Helvetica-Bold', fontSize=11.5, leading=14, textColor=BLUE, spaceBefore=8, spaceAfter=5))
styles.add(ParagraphStyle(name='Bodyx', parent=styles['BodyText'], fontName='Helvetica', fontSize=9.7, leading=13.3, textColor=INK, spaceAfter=6))
styles.add(ParagraphStyle(name='Bulx', parent=styles['BodyText'], fontName='Helvetica', fontSize=9.5, leading=13, textColor=INK, leftIndent=13, firstLineIndent=-8, spaceAfter=3))
styles.add(ParagraphStyle(name='Say', parent=styles['BodyText'], fontName='Helvetica-Oblique', fontSize=10, leading=14, textColor=NAVY, backColor=PALE, borderColor=colors.HexColor('#C8DCEB'), borderWidth=0.5, borderPadding=8, spaceBefore=4, spaceAfter=8))
styles.add(ParagraphStyle(name='Footerx', parent=styles['Normal'], fontName='Helvetica', fontSize=8, textColor=MUTED, alignment=TA_CENTER))

def header_footer(canvas, doc):
    canvas.saveState()
    if doc.page > 1:
        canvas.setStrokeColor(colors.HexColor('#D9E2EC'))
        canvas.setLineWidth(.5)
        canvas.line(doc.leftMargin, letter[1]-0.48*inch, letter[0]-doc.rightMargin, letter[1]-0.48*inch)
        canvas.setFont('Helvetica-Bold', 8)
        canvas.setFillColor(TEAL)
        canvas.drawString(doc.leftMargin, letter[1]-0.37*inch, 'APEX SPORTS CLUB  |  DEFENSE HANDBOOK')
        canvas.setFont('Helvetica', 8)
        canvas.setFillColor(MUTED)
        canvas.drawRightString(letter[0]-doc.rightMargin, 0.42*inch, f'Page {doc.page}')
    canvas.restoreState()

def p(txt, style='Bodyx'):
    return Paragraph(txt, styles[style])

def bullets(items):
    return [p('&bull; ' + item, 'Bulx') for item in items]

def page(title, kicker, sections, say=None):
    flow = [p(kicker, 'Kicker'), p(title, 'H1x')]
    for heading, body in sections:
        flow.append(p(heading, 'H2x'))
        if isinstance(body, list):
            flow.extend(bullets(body))
        else:
            flow.append(p(body))
    if say:
        flow.append(p('<b>Suggested words</b><br/>' + say, 'Say'))
    flow.append(PageBreak())
    return flow

PAGES = []
PAGES.append(('Apex Sports Club Management System', 'FINAL-YEAR PROJECT DEFENSE HANDBOOK', [
    ('A practical presenter guide', 'A tailored 48-page handbook for a 15-20 minute demonstration and viva. Built from the project source, migration history, current interface capture, and the submitted presentation deck.'),
    ('Candidate', 'Juma Ibrahim  |  Registration No: BIT/2024/56566'),
    ('System in one line', 'A PHP/MySQL web application that centralises club membership, fixtures, payments, communication and administrative workflows, with optional AI-assisted outputs.')
], None))
PAGES.append(('How to use this handbook', 'START HERE', [
    ('Before the defense', ['Rehearse the bold ideas, not every sentence word for word.', 'Open the member portal and administrator portal in separate browser tabs.', 'Use realistic but non-sensitive demo data; do not expose API keys, passwords, database credentials or personal member information.']),
    ('During the defense', ['Follow the demo order on page 5. Keep external integrations optional in case the internet is unavailable.', 'Say only what the current screen proves. Where a feature is assisted by AI or an external service, explain the dependency honestly.']),
    ('Important accuracy note', 'The application implements WhatsApp click-to-chat links. A separate code path describes an API queue as a future placeholder; do not claim that every WhatsApp message is automatically delivered by a live Cloud API.')
], None))
PAGES.append(('System identity and purpose', 'OPENING CONTEXT', [
    ('Problem addressed', 'Small sports clubs often divide information across paper registers, spreadsheets, payment records and WhatsApp groups. This creates duplicate records, late payment follow-up and inconsistent communication.'),
    ('Proposed solution', 'Apex brings these day-to-day workflows into one browser-based system with separate member and administrator experiences.'),
    ('Project boundary', 'It is a club management system, not a replacement for a bank, mobile-money provider, WhatsApp itself, or a final clinical medical-records platform.')
], 'Good morning. My project is Apex Sports Club Management System. It addresses fragmented club administration by bringing member, fixture, payment and communication workflows into one web application.'))
PAGES.append(('Presentation route and time budget', '15-20 MINUTE PLAN', [
    ('Recommended timing', ['1 minute: problem and objective.', '2 minutes: architecture and design choices.', '9-11 minutes: live walkthrough of the main workflows.', '3 minutes: security, data and testing.', '2 minutes: conclusion, limitations and questions.']),
    ('Rule of control', 'If a remote payment, AI request or email service is slow, narrate the design and show the stored transaction, log or fallback state instead of waiting.')
], None))
PAGES.append(('Demo sequence at a glance', 'LIVE WALKTHROUGH', [
    ('Suggested order', ['Login and landing/dashboard.', 'Member management and member-facing dashboard.', 'Fixtures, live scoring and standings.', 'Facility booking or attendance.', 'Payments and receipt trail.', 'Communication: announcements, email and WhatsApp click-to-chat.', 'AI-assisted features.', 'Reports, exports, activity log, settings and logout.']),
    ('Transition sentence', 'Each workflow shares the same database, authentication rules and audit approach, so the club moves from isolated actions to traceable operations.')
], None))
PAGES.append(('Screen 1: Login', 'DEMO SCRIPT', [
    ('What to show', ['Open the appropriate member or administrator login page.', 'Point out the email/password flow and avoid displaying a real password.', 'If configured, point out Cloudflare Turnstile and the password reset route.']),
    ('What it demonstrates', 'Authentication is separated by role. Login code verifies password hashes, regenerates the session identifier on successful login and records the member last-login timestamp.'),
    ('Likely question', 'How do you prevent brute-force attempts? Answer: the application includes a rate-limiter helper that records attempts by email or IP for login, registration and password-reset actions.')
], 'This is the entry point. The system authenticates the user before exposing personal or administrative data. Passwords are verified as hashes, and the session ID is regenerated after login to reduce session-fixation risk.'))
PAGES.append(('Screen 2: Administrator dashboard', 'DEMO SCRIPT', [
    ('What to show', ['Open the dashboard and identify its summary cards or operational shortcuts.', 'Use the menu grouping to show that administration is organised around people, competition, finance and engagement.']),
    ('What it demonstrates', 'The administrative interface consolidates workflow entry points rather than asking staff to work from separate tools. The navigation contains members, facilities, fixtures, finance, communications, security and system-health functions.'),
    ('Do not overclaim', 'Dashboard totals are operational summaries. Their correctness depends on the underlying records and queries, so the system also exposes detailed management pages and activity logs.')
], 'This dashboard is the club administrator’s operational home. It reduces navigation cost by grouping the day-to-day functions that were previously spread across different tools.'))
PAGES.append(('Screen 3: Member management', 'DEMO SCRIPT', [
    ('What to show', ['Open Members Directory; show search, member profile or a safe test record.', 'Explain that profile data can support membership, attendance, bookings and payments.', 'If available, show medical or emergency fields without disclosing a real person’s details.']),
    ('Data interaction', 'Member records are referenced by related workflows. The database uses member identifiers so payment, membership and attendance records can be associated with the correct person without copying their full profile into every table.'),
    ('Engineering principle', 'Normalisation: store a member once and reference that record. This reduces inconsistent duplicate data.')
], 'Here the administrator manages the central member record. The important design idea is that related workflows refer to the member identifier, so a change to the member profile does not require editing many duplicate records.'))
PAGES.append(('Screen 4: Member portal dashboard', 'DEMO SCRIPT', [
    ('What to show', ['Switch to the member portal or explain it from the navigation.', 'Show the member-only actions such as dashboard, bookings, memberships, payments, tickets, profile and personal history.']),
    ('Why it exists', 'A member-facing view reduces administrative workload by allowing members to view their own information and initiate permitted actions.'),
    ('Access-control point', 'The portal must use the session’s authenticated member ID, not an ID supplied by the browser, when retrieving personal records.')
], 'The member portal gives a different experience from the administrator portal. Its purpose is self-service while still ensuring that a member can access only their own data.'))
PAGES.append(('Screen 5: Fixtures and league operations', 'DEMO SCRIPT', [
    ('What to show', ['Open Match Fixtures, then optionally the league registry or season wizard.', 'Show a scheduled fixture and explain its home team, away team, date, venue and status.', 'Show standings or leaderboards after the fixture area.']),
    ('Data interaction', 'Fixtures are linked to league and team records. A helper creates or updates standings records so the table can be derived from recorded results.'),
    ('Value', 'The club can plan competition activity centrally instead of maintaining an informal list in chat messages.')
], 'This area manages the competition lifecycle: registering leagues and teams, scheduling fixtures, then using the recorded results to present a league table.'))
PAGES.append(('Screen 6: Live scoring and standings', 'DEMO SCRIPT', [
    ('What to show', ['Open Live Score Panel only with a safe demo fixture.', 'Explain score entry, match events, lineups or referee control as available.', 'Return to standings to show the outcome of updated results.']),
    ('Control', 'The referee page is PIN-protected according to the presentation material. Live scoring should be restricted because an incorrect update affects public competition information.'),
    ('Transition', 'Once the sporting event is recorded, the same system can support reports, announcements and engagement activities.')
], 'Live scoring makes the system more than a static schedule. A controlled official can record match events, and the competition information can then be reflected in standings and reports.'))
PAGES.append(('Screen 7: Facility booking and attendance', 'DEMO SCRIPT', [
    ('What to show', ['Open Facility Bookings, Booking Calendar or Attendance.', 'Create no real booking during the defense unless using a clearly labelled test slot.', 'Explain conflict review and administrative approval where shown.']),
    ('Engineering principle', 'Validation protects scarce resources. A booking is not merely a form submission; the application checks the context before a facility should be committed.'),
    ('AI boundary', 'The project contains AI-assisted booking review/suggestions. Treat the AI output as a recommendation to review, not as an autonomous authority.')
], 'This screen addresses a common operational conflict: several people may want the same resource. The system records the request and gives staff a structured way to review availability and conflicts.'))
PAGES.append(('Screen 8: Payments', 'DEMO SCRIPT', [
    ('What to show', ['Open member payments or administrator payments.', 'Show the available payment choices and a past test transaction if present.', 'Do not initiate a real charge unless expressly authorised.']),
    ('Payment design', 'The application supports Paystack checkout and M-Pesa STK Push routes, then records successful payment information in the local database. Callback handlers support confirmation flows.'),
    ('Safety statement', 'The application does not store card details. Sensitive payment processing is delegated to the payment provider.')
], 'The payment screen gives members locally relevant options. The system starts the payment with the provider, then uses confirmation/callback handling to record the transaction and support the relevant membership workflow.'))
PAGES.append(('Screen 9: Receipts, renewals and finance trail', 'DEMO SCRIPT', [
    ('What to show', ['Show a payment receipt or recent payments table.', 'Navigate to renewal reminders, revenue dashboard, refunds or expenses if time allows.']),
    ('Data interaction', 'Payments relate to a member, amount, method, date, description and provider reference. Membership activation is separated from simply starting a payment, so a successful confirmation can drive the membership update.'),
    ('Why examiners care', 'This separates intention-to-pay from verified payment, which is essential for financial integrity.')
], 'A payment request is not treated as a completed payment. The system keeps a record trail and uses confirmation information before updating the membership or issuing a receipt.'))
PAGES.append(('Screen 10: Communication', 'DEMO SCRIPT', [
    ('What to show', ['Open announcements, bulk email, membership reminders or a public WhatsApp button.', 'Show the wording of a prepared message, but do not send to real recipients during the defense.']),
    ('Implemented channels', 'Brevo is the email helper. WhatsApp click-to-chat uses wa.me links that open WhatsApp with a pre-filled message; the recipient or staff member still chooses to send.'),
    ('Honest limitation', 'A full WhatsApp Cloud API queue is not represented as a completed production delivery channel in this project.')
], 'Communication is integrated where the club needs it, but I distinguish between assisted communication and automatic delivery. The current WhatsApp feature opens a prepared chat; it does not pretend to silently send a message without user action.'))
PAGES.append(('Screen 11: AI-assisted features', 'DEMO SCRIPT', [
    ('What to show', ['Open an AI feature that is currently configured, such as match reports, predictions, tactics, smart scheduling or the Gemini hub.', 'Use a harmless demonstration prompt or a previously generated sample.']),
    ('How to explain AI', 'The PHP application sends a structured request to configured OpenRouter/Gemini services and presents the response to a human user. The AI augments content and analysis; it does not replace club decisions.'),
    ('Risk control', 'AI output can be inaccurate or unavailable. The user must review it, and the system should retain conventional workflows as a fallback.')
], 'AI is used as an assistant for tasks such as reports, suggestions and predictions. I position it as decision support: the responsible member of staff still reviews the output before acting on it.'))
PAGES.append(('Screen 12: Reports, export and auditability', 'DEMO SCRIPT', [
    ('What to show', ['Open revenue dashboard, export payments, export fixtures, activity log, system health or member data export.', 'Highlight that reports turn operational records into useful summaries.']),
    ('Governance value', 'Activity logs and exports make actions easier to trace. Data export also supports a member’s ability to obtain data held about them.'),
    ('Transition to close', 'The demonstration has shown connected workflows; the next section explains the architecture that makes those workflows possible.')
], 'The final operational area is visibility. Managers need totals and exports, but they also need to know what action occurred and when. That is why reporting and activity logging matter.'))
PAGES.append(('Screen 13: Settings and logout', 'DEMO SCRIPT', [
    ('What to show', ['Open administrator profile, two-factor authentication, roles and permissions, backup or system health only if safe.', 'Use logout to complete the session.']),
    ('Security value', 'Settings are not merely cosmetic. They govern administrative identity, authentication hardening, permissions and operational readiness.'),
    ('Closing transition', 'Having shown the user journey, I will now explain the implementation design behind it.')
], 'I end with security and logout because a management system is not complete when it can create records; it must also protect the people and data behind those records.'))
PAGES.append(('Architecture overview', 'TECHNICAL DEEP DIVE', [
    ('Three layers', ['Presentation: PHP pages under public/ and admin/ with Bootstrap and shared headers/footers.', 'Application logic: PHP helpers for fixtures, payments, renewals, notifications, AI and validation.', 'Data: MySQL accessed through mysqli, with UTF-8 capable storage and versioned migrations.']),
    ('Why this design fits', 'The separation makes the site easier to evolve: pages remain focused on interaction, while reusable business rules live in includes/ and persistence remains in the database.')
], None))
PAGES.append(('Request-to-database flow', 'TECHNICAL DEEP DIVE', [
    ('Typical path', ['A user submits a form from a PHP page.', 'The application checks session, role, CSRF token and input validity.', 'Business logic calls a helper and uses prepared mysqli statements.', 'MySQL stores or retrieves the relevant record.', 'The page returns a safe, escaped result or redirects to the next workflow.']),
    ('Why prepared statements', 'Parameters are bound separately from SQL structure, reducing SQL injection risk compared with concatenating user input into a query.')
], None))
PAGES.append(('Database evolution and core entities', 'TECHNICAL DEEP DIVE', [
    ('Migration approach', 'The project contains numbered SQL migrations 001 through 049, plus migration documentation. This provides an ordered, repeatable way to move a database schema forward rather than manually editing production tables.'),
    ('Core entities', ['Members and administrators.', 'Membership plans, memberships, payments and renewal logs.', 'Leagues, teams, fixtures, standings, match events and lineups.', 'Facilities, bookings, coaches, attendance, announcements and audit logs.']),
    ('Key defence point', 'The schema evolves with the application. Before a feature uses a new field, the deployment process must apply the relevant migration.')
], None))
PAGES.append(('Object-oriented design in the codebase', 'TECHNICAL DEEP DIVE', [
    ('Examples', 'Although much of the application is procedural PHP, several focused classes encapsulate domain logic, including AIInsightsEngine, DAO governance, churn/wellness analytics, digital membership card, green-goal tracking, marketplace and referral/loyalty features.'),
    ('Principle demonstrated', 'Encapsulation keeps related data operations together. For example, a class can receive the database connection once and expose named domain methods instead of duplicating query logic across pages.'),
    ('Balanced answer', 'This is a pragmatic no-framework system. A future refactor could make the service/repository boundaries more uniform across all modules.')
], None))
PAGES.append(('External integrations', 'TECHNICAL DEEP DIVE', [
    ('Payments', 'Paystack and Safaricom M-Pesa Daraja are invoked through server-side helpers and callbacks. Provider references are important for reconciliation.'),
    ('Communication', 'Brevo is used for email delivery. WhatsApp click-to-chat is a low-cost user-mediated route.'),
    ('AI and anti-bot protection', 'OpenRouter/Gemini support AI requests. Cloudflare Turnstile can protect selected public forms when configured.'),
    ('Resilience', 'Each dependency can fail or be unconfigured. The interface presents errors; the defense should never imply that an external service is guaranteed to be available.')
], None))
PAGES.append(('Security controls', 'TECHNICAL DEEP DIVE', [
    ('Implemented safeguards', ['Password hashing and password_verify().', 'Session ID regeneration on successful sign-in.', 'CSRF helpers for form tokens.', 'Prepared statements and output escaping helpers.', 'Rate limiting for sensitive public actions.', 'Administrator two-factor authentication and recovery-code support.', 'Role/permission helpers, activity logging and data-export capability.']),
    ('Professional caveat', 'Security is ongoing. Production deployment also needs TLS, protected environment files, key rotation, backup restoration testing, least-privilege database accounts and periodic security review.')
], None))
PAGES.append(('Authentication and authorization', 'TECHNICAL DEEP DIVE', [
    ('Authentication', 'Authentication proves who is signing in. The member and administrator logins verify credentials and create sessions.'),
    ('Authorization', 'Authorization determines what a signed-in person may do. Administrator helpers and roles/permissions support privileged actions; member pages should scope data to the current session member.'),
    ('Examiner-ready distinction', 'A user being logged in does not automatically mean they are permitted to edit every record. That is why both checks are needed.')
], None))
PAGES.append(('Payment lifecycle', 'TECHNICAL DEEP DIVE', [
    ('Flow', ['Member selects a plan or payment purpose.', 'Application validates the request and starts the selected provider process.', 'Provider returns control or calls a server callback.', 'Application validates and records the confirmed transaction, avoiding duplicate entries where possible.', 'Membership/receipt logic follows the recorded outcome.']),
    ('Why callbacks matter', 'The browser may close or lose connectivity after payment initiation. A server-side confirmation path is more reliable than trusting only a success page in the browser.')
], None))
PAGES.append(('AI design and responsible use', 'TECHNICAL DEEP DIVE', [
    ('Inputs and outputs', 'Features construct prompts from relevant club information and return generated narratives, predictions or suggestions. The output should be labelled as generated assistance.'),
    ('Guardrails to state', ['Do not send unnecessary personal or sensitive information to an AI provider.', 'Do not use generated content as the sole basis for disciplinary, medical or financial decisions.', 'Check accuracy, fairness and appropriateness before publishing.', 'Provide a non-AI fallback when the service or network fails.']),
    ('Defensible claim', 'The application demonstrates integration and workflow support, not a claim that the model is always correct.')
], None))
PAGES.append(('Testing strategy', 'QUALITY ASSURANCE', [
    ('Testing levels', ['Functional testing: confirm a feature behaves as intended.', 'Regression testing: repeat related flows after a change.', 'Technical testing: validate API, query and form behavior.', 'Manual browser verification after incremental changes.']),
    ('Automated evidence', 'The repository has a PHPUnit test suite focused on helper behavior. It includes password-strength, sanitisation, escaping, date/phone validation and selected membership/payment helper checks.'),
    ('Honest limitation', 'Some tests are explicitly placeholders or require a test database. A stronger future test suite would add isolated integration tests against seeded data.')
], None))
PAGES.append(('Test cases worth describing', 'QUALITY ASSURANCE', [
    ('Authentication', 'Correct credentials succeed; incorrect credentials fail; repeated attempts are limited; a successful login receives a fresh session identifier.'),
    ('Payments', 'Invalid payment method is rejected; missing phone blocks M-Pesa; callback/replay handling avoids recording duplicate payments; receipt is available after a confirmed payment.'),
    ('Fixtures', 'A fixture can be created with valid teams and date; permitted officials can update a score; standings reflect accepted results.'),
    ('Forms', 'Missing mandatory fields, invalid email/date values and invalid CSRF tokens do not result in a database change.')
], None))
PAGES.append(('Known limitations and candid answers', 'QUALITY ASSURANCE', [
    ('Current limitations', ['External integrations require configuration and internet access.', 'AI output needs human review and may fail.', 'WhatsApp is primarily click-to-chat, not a complete Cloud API delivery service.', 'The automated test coverage is useful but not comprehensive.', 'The no-framework codebase can benefit from further modularisation and formal deployment automation.']),
    ('Why say this', 'A strong defense distinguishes working scope from future work. Honest limits demonstrate engineering judgement, not weakness.')
], None))
PAGES.append(('Likely examiner question: Why PHP and MySQL?', 'VIVA QUESTION BANK', [
    ('Suggested answer', 'PHP and MySQL were appropriate for a server-rendered web system that needed low-cost local development, broad hosting support and straightforward relational storage. PHP allowed rapid integration with forms, sessions and APIs; MySQL suits the connected transactional records in club administration.'),
    ('Add if asked', 'The design does not depend on a framework, but a framework such as Laravel could be a future step to standardise routing, validation and testing as the system grows.')
], None))
PAGES.append(('Likely examiner question: What makes it AI-assisted?', 'VIVA QUESTION BANK', [
    ('Suggested answer', 'The system integrates configurable OpenRouter/Gemini calls to generate or assist with tasks such as reports, predictions, tactics and scheduling suggestions. The AI is invoked from the application and its response is reviewed by users; it is not a trained model built from scratch by this project.'),
    ('Avoid saying', 'Do not say the system “uses AI to make decisions” without explaining human review, provider dependency and the possibility of error.')
], None))
PAGES.append(('Likely examiner question: How is payment secure?', 'VIVA QUESTION BANK', [
    ('Suggested answer', 'Card handling is delegated to Paystack rather than stored in this application. For M-Pesa and Paystack, the application initiates the provider flow and uses provider references/callback handling to record confirmed outcomes. The system also uses server-side validation and does not accept a browser message alone as proof of payment.'),
    ('Improve further', 'In production I would verify callback signatures where supplied, use HTTPS, protect secrets in environment configuration and reconcile transactions against provider dashboards.')
], None))
PAGES.append(('Likely examiner question: How do you prevent SQL injection?', 'VIVA QUESTION BANK', [
    ('Suggested answer', 'Database operations use mysqli prepared statements and bind parameters. This keeps values separate from the SQL command. Input is also validated for expected type/range, but validation does not replace prepared statements.'),
    ('Follow-up', 'Output is escaped for HTML contexts to reduce cross-site scripting risk; different output contexts require context-appropriate encoding.')
], None))
PAGES.append(('Likely examiner question: Why not just use WhatsApp groups?', 'VIVA QUESTION BANK', [
    ('Suggested answer', 'WhatsApp is effective for conversation but is not a structured system of record for membership, payments, fixtures or access control. Apex retains the operational record in a relational database and can use WhatsApp click-to-chat as a communication bridge.'),
    ('Accuracy note', 'The implemented WhatsApp path is a low-cost pre-filled chat link. It still requires the user to tap Send in WhatsApp.')
], None))
PAGES.append(('Likely examiner question: What data relationships matter most?', 'VIVA QUESTION BANK', [
    ('Suggested answer', 'Members link to memberships and payments; leagues link to teams and fixtures; fixtures connect to scores, events, lineups and standings; facilities link to bookings. These foreign-key-style relationships let reports join related information without storing duplicates.'),
    ('Design reason', 'The database represents business facts separately and connects them through IDs, which supports consistency and reporting.')
], None))
PAGES.append(('Likely examiner question: How would you scale it?', 'VIVA QUESTION BANK', [
    ('Suggested answer', 'I would first measure usage and isolate bottlenecks. Likely steps are indexed search fields, pagination, caching frequently read summaries, moving long-running jobs to queues, centralised logging, background workers for notifications, and separating static assets or media storage.'),
    ('Multi-club path', 'Add a club/tenant identifier with carefully scoped queries and permissions, then validate isolation with automated tests before offering multi-club access.')
], None))
PAGES.append(('Likely examiner question: What did you learn from testing?', 'VIVA QUESTION BANK', [
    ('Suggested answer', 'Incremental testing exposed integration-specific problems: ambiguous SQL columns, missing schema columns, callback connectivity and configuration mismatches. I addressed these using qualified SQL aliases, schema guards/migrations, an accessible callback tunnel during local testing, and clearer error handling.'),
    ('Evidence', 'The presentation specifically records these resolved issues and the repository contains helper-focused PHPUnit tests.')
], None))
PAGES.append(('Likely examiner question: What would you improve next?', 'VIVA QUESTION BANK', [
    ('Suggested answer', 'My priorities would be comprehensive integration tests with a seeded test database, formal callback signature verification and reconciliation, a production WhatsApp API if approved, stronger monitoring, a framework-assisted modular architecture, and a mobile companion experience.'),
    ('Good prioritisation', 'I would improve reliability and security before adding more optional features.')
], None))
PAGES.append(('Likely examiner question: Why migrations?', 'VIVA QUESTION BANK', [
    ('Suggested answer', 'A migration records a controlled database change in version order. Instead of telling someone to manually add columns, the system can apply the same change consistently in another environment. This makes database evolution reproducible and traceable.'),
    ('Project evidence', 'Apex uses numbered migrations from 001 to 049, covering the core schema and later features.')
], None))
PAGES.append(('Likely examiner question: Explain OOP here', 'VIVA QUESTION BANK', [
    ('Suggested answer', 'The project uses a pragmatic mix of procedural pages and object-oriented domain modules. In the classes, a focused object encapsulates database-backed behavior for a domain such as analytics, governance, membership cards or loyalty. This improves cohesion and avoids scattering the same operations across pages.'),
    ('Balanced reflection', 'A future refactor would make this pattern more consistent by introducing service and repository layers throughout the system.')
], None))
PAGES.append(('Likely examiner question: How do you protect personal data?', 'VIVA QUESTION BANK', [
    ('Suggested answer', 'The system uses authenticated sessions, role checks, prepared statements, output escaping, password hashing, CSRF protection and rate limiting. Data should be limited to what the workflow needs, sensitive displays should be role-scoped, and exports/backups should be protected operationally.'),
    ('Caution', 'Never claim GDPR compliance solely because there is a data export page. Compliance also requires lawful basis, retention, access processes and organisational controls.')
], None))
PAGES.append(('Common demo mistakes to avoid', 'DEFENSE CRAFT', [
    ('Avoid', ['Trying to demo every menu item.', 'Waiting silently for an external API response.', 'Entering real credentials or a real payment amount on a projector.', 'Calling every WhatsApp action automatic.', 'Reading slides instead of explaining the problem and user value.', 'Claiming tests or security are complete when they are not.']),
    ('Do instead', 'Use a prepared path, narrate what each step proves, keep backup screenshots/data ready and be precise about scope.')
], None))
PAGES.append(('Recovery plan if a demo step fails', 'DEFENSE CRAFT', [
    ('If login fails', 'Use a prepared test account or explain the authentication flow from the source and login page without showing secrets.'),
    ('If payment/AI/email fails', 'State that it is an external dependency, show the configuration-aware error or a previously recorded result, then explain the callback/fallback design.'),
    ('If database is unavailable', 'Use the presentation and this handbook to explain architecture, schema and testing. Do not fabricate live results.'),
    ('Best sentence', 'The workflow is designed for this outcome; the current demonstration dependency is unavailable, so I will show the stored evidence and explain the control path.')
], None))
PAGES.append(('Pre-defense technical checklist', 'REHEARSAL', [
    ('Application', ['Start local web server and database.', 'Confirm the migration state and test data.', 'Prepare separate safe member/admin accounts.', 'Check app URLs and logout routes.', 'Clear only disposable test records if necessary.']),
    ('Integrations', ['Confirm whether internet is available.', 'Do not expose .env values.', 'Prepare screenshots/receipts/sample AI output as fallback.', 'Never make a live charge or send unapproved communication.']),
    ('Presentation', ['Open deck and app tabs in sequence.', 'Turn off unrelated notifications.', 'Use readable browser zoom and a clean desktop.'])
], None))
PAGES.append(('Rehearsal scorecard', 'REHEARSAL', [
    ('Self-check', ['Can I explain the problem in 30 seconds?', 'Can I complete the walkthrough in 10 minutes?', 'Can I distinguish authentication from authorisation?', 'Can I describe the payment callback without exaggerating?', 'Can I identify the AI provider dependency and human review?', 'Can I name three limitations and three next improvements?']),
    ('Target', 'Rehearse until each answer is clear, calm and evidence-based. A defense is strongest when the presenter can explain trade-offs, not merely click through screens.')
], None))
PAGES.append(('Closing statement', 'FINAL 30 SECONDS', [
    ('Core message', 'Apex Sports Club Management System demonstrates how a web application can consolidate the operational information of a sports club: people, competition, finance and communication.'),
    ('Evidence of engineering', 'It combines PHP/MySQL architecture, reusable domain helpers, versioned database migrations, external API integration, role-aware sessions, security controls and a growing testing practice.'),
    ('Future direction', 'The next priority is to deepen reliability, test coverage and production integration maturity while preserving the core value of a locally relevant, affordable club platform.')
], 'In conclusion, Apex replaces fragmented club administration with connected, traceable workflows. It is a working foundation with clear boundaries and a practical roadmap for stronger production readiness. Thank you; I welcome your questions.'))
PAGES.append(('Quick-reference facts', 'APPENDIX', [
    ('Technology', ['PHP 7.4+; MySQL through mysqli; Bootstrap 5; Font Awesome.', 'Cloudflare Turnstile; Brevo email; Paystack; M-Pesa Daraja; OpenRouter/Google Gemini where configured.']),
    ('Repository evidence', ['50 files in the migrations folder, including numbered migrations 001-049 and a README.', 'PHPUnit helper tests in tests/FeatureHelpersTest.php.', 'Member-facing pages under public/, administrator pages under admin/, shared logic under includes/.']),
    ('Three facts to remember', ['WhatsApp click-to-chat is implemented; automated Cloud API delivery is future work.', 'Payment initiation is distinct from confirmation/recording.', 'AI outputs are reviewed assistance, not autonomous decisions.'])
], None))

def build():
    doc = BaseDocTemplate(str(OUT), pagesize=letter, leftMargin=.7*inch, rightMargin=.7*inch, topMargin=.72*inch, bottomMargin=.68*inch)
    frame = Frame(doc.leftMargin, doc.bottomMargin, doc.width, doc.height, id='main')
    doc.addPageTemplates([PageTemplate(id='all', frames=[frame], onPage=header_footer)])
    story = []
    for title, kicker, sections, say in PAGES:
        story.extend(page(title, kicker, sections, say))
    # Remove the terminal page break so the count equals the number of planned pages.
    if isinstance(story[-1], PageBreak):
        story.pop()
    doc.build(story)
    print(OUT)

if __name__ == '__main__':
    build()
