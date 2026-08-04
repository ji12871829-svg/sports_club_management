const pptxgen = require("pptxgenjs");
const React = require("react");
const ReactDOMServer = require("react-dom/server");
const sharp = require("sharp");
const {
  FaFutbol, FaUsers, FaMoneyBillWave, FaRobot, FaWhatsapp, FaDatabase,
  FaCogs, FaCheckCircle, FaExclamationTriangle, FaLightbulb, FaClipboardList,
  FaLayerGroup, FaBullseye, FaSearch, FaFlask, FaClipboardCheck, FaRoute
} = require("react-icons/fa");

const PRIMARY = "028090";
const SECONDARY = "00A896";
const ACCENT = "02C39A";
const DARK = "023436";
const INK = "1B2B2C";
const MUTED = "5C7A7B";
const LIGHT_BG = "F4FAF9";
const WHITE = "FFFFFF";

function renderIconSvg(IconComponent, color, size = 256) {
  return ReactDOMServer.renderToStaticMarkup(
    React.createElement(IconComponent, { color, size: String(size) })
  );
}
async function iconPng(IconComponent, color, size = 256) {
  const svg = renderIconSvg(IconComponent, color, size);
  const buf = await sharp(Buffer.from(svg)).png().toBuffer();
  return "image/png;base64," + buf.toString("base64");
}

function iconCircle(slide, iconData, x, y, d, circleColor) {
  slide.addShape(pres.shapes.OVAL, { x, y, w: d, h: d, fill: { color: circleColor } });
  const pad = d * 0.24;
  slide.addImage({ data: iconData, x: x + pad, y: y + pad, w: d - pad * 2, h: d - pad * 2 });
}

const pres = new pptxgen();
pres.layout = "LAYOUT_WIDE";
pres.author = "Juma Ibrahim";
pres.title = "Apex Sports Club Management System";

const W = 13.33, H = 7.5;

async function build() {
  const icons = {};
  const specs = [
    ["futbol", FaFutbol, WHITE], ["users", FaUsers, WHITE], ["money", FaMoneyBillWave, WHITE],
    ["robot", FaRobot, WHITE], ["whatsapp", FaWhatsapp, WHITE], ["db", FaDatabase, WHITE],
    ["cogs", FaCogs, WHITE], ["check", FaCheckCircle, WHITE], ["warn", FaExclamationTriangle, WHITE],
    ["bulb", FaLightbulb, WHITE], ["clip", FaClipboardList, WHITE], ["layers", FaLayerGroup, WHITE],
    ["target", FaBullseye, WHITE], ["search", FaSearch, WHITE], ["flask", FaFlask, WHITE],
    ["clipcheck", FaClipboardCheck, WHITE], ["route", FaRoute, WHITE],
  ];
  for (const [key, Comp, color] of specs) icons[key] = await iconPng(Comp, color, 256);

  // ---------- Slide 1: Title ----------
  let s = pres.addSlide();
  s.background = { color: DARK };
  s.addShape(pres.shapes.OVAL, { x: W - 3.2, y: -1.6, w: 5, h: 5, fill: { color: PRIMARY, transparency: 55 } });
  s.addShape(pres.shapes.OVAL, { x: -1.8, y: H - 2.6, w: 4, h: 4, fill: { color: SECONDARY, transparency: 65 } });
  iconCircle(s, icons.futbol, 0.9, 0.9, 0.9, PRIMARY);
  s.addText("APEX SPORTS CLUB", {
    x: 0.9, y: 2.5, w: 10.5, h: 1.0, fontSize: 40, bold: true, color: WHITE, fontFace: "Cambria",
  });
  s.addText("Management System", {
    x: 0.9, y: 3.35, w: 10.5, h: 0.7, fontSize: 26, color: ACCENT, fontFace: "Calibri",
  });
  s.addText("A Web-Based, AI-Assisted Platform for Member, Fixture, Payment & Communication Management", {
    x: 0.9, y: 4.15, w: 9.5, h: 0.8, fontSize: 15, color: "C9DEDD", fontFace: "Calibri",
  });
  s.addText([
    { text: "Juma Ibrahim", options: { bold: true, breakLine: true } },
    { text: "Registration No: BIT/2024/56566" },
  ], { x: 0.9, y: 6.5, w: 8, h: 0.7, fontSize: 13, color: WHITE, fontFace: "Calibri" });

  // ---------- Slide 2: Agenda ----------
  s = pres.addSlide();
  s.background = { color: WHITE };
  s.addText("Presentation Outline", { x: 0.7, y: 0.5, w: 10, h: 0.8, fontSize: 30, bold: true, color: INK, fontFace: "Cambria" });
  const agenda = [
    ["Introduction & Problem", "Why the system is needed"],
    ["Literature Review", "Existing systems & the gap"],
    ["Methodology", "How the system was built"],
    ["Analysis & Design", "Requirements & architecture"],
    ["Implementation & Testing", "What was built & verified"],
    ["Conclusion & Recommendations", "Outcomes & next steps"],
  ];
  let ax = 0.7, ay = 1.7, colW = 5.9, rowH = 1.55;
  agenda.forEach((item, i) => {
    const col = i % 2, row = Math.floor(i / 2);
    const x = ax + col * (colW + 0.35), y = ay + row * (rowH + 0.15);
    s.addShape(pres.shapes.ROUNDED_RECTANGLE, {
      x, y, w: colW, h: rowH - 0.15, rectRadius: 0.08, fill: { color: LIGHT_BG },
      shadow: { type: "outer", color: "000000", blur: 6, offset: 2, angle: 45, opacity: 0.08 },
    });
    s.addShape(pres.shapes.OVAL, { x: x + 0.25, y: y + 0.25, w: 0.55, h: 0.55, fill: { color: PRIMARY } });
    s.addText(String(i + 1), { x: x + 0.25, y: y + 0.25, w: 0.55, h: 0.55, align: "center", valign: "middle", fontSize: 18, bold: true, color: WHITE, margin: 0 });
    s.addText(item[0], { x: x + 0.95, y: y + 0.18, w: colW - 1.15, h: 0.45, fontSize: 16, bold: true, color: INK, fontFace: "Calibri" });
    s.addText(item[1], { x: x + 0.95, y: y + 0.62, w: colW - 1.15, h: 0.55, fontSize: 12.5, color: MUTED, fontFace: "Calibri" });
  });

  // ---------- Slide 3: Background & Problem ----------
  s = pres.addSlide();
  s.background = { color: WHITE };
  s.addText("Background & Problem Statement", { x: 0.7, y: 0.5, w: 11.5, h: 0.7, fontSize: 28, bold: true, color: INK, fontFace: "Cambria" });
  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: 0.7, y: 1.5, w: 5.6, h: 5.1, rectRadius: 0.1, fill: { color: LIGHT_BG } });
  iconCircle(s, icons.warn, 1.05, 1.85, 0.7, PRIMARY);
  s.addText("The Problem", { x: 1.95, y: 1.9, w: 4, h: 0.6, fontSize: 18, bold: true, color: INK, fontFace: "Calibri" });
  s.addText(
    "Sports clubs rely on paper records, spreadsheets, and WhatsApp groups to manage members, fixtures, and payments — fragmented tools that do not talk to each other.",
    { x: 1.05, y: 2.7, w: 4.9, h: 1.6, fontSize: 13.5, color: INK, fontFace: "Calibri", valign: "top" }
  );
  s.addText([
    { text: "Lost or duplicated member records", options: { bullet: true, breakLine: true } },
    { text: "Delayed, hard-to-track payments", options: { bullet: true, breakLine: true } },
    { text: "Inconsistent member communication", options: { bullet: true } },
  ], { x: 1.05, y: 4.3, w: 4.9, h: 1.9, fontSize: 13, color: INK, fontFace: "Calibri", paraSpaceAfter: 8 });

  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: 6.55, y: 1.5, w: 6.1, h: 5.1, rectRadius: 0.1, fill: { color: PRIMARY } });
  iconCircle(s, icons.bulb, 6.9, 1.85, 0.7, SECONDARY);
  s.addText("The Response", { x: 7.8, y: 1.9, w: 4.6, h: 0.6, fontSize: 18, bold: true, color: WHITE, fontFace: "Calibri" });
  s.addText(
    "Apex centralises member management, fixtures & live scoring, payments, and communication in one platform — with optional AI insights and WhatsApp click-to-chat.",
    { x: 6.9, y: 2.7, w: 5.5, h: 1.6, fontSize: 13.5, color: "E4F5F3", fontFace: "Calibri" }
  );
  s.addText([
    { text: "General Objective:", options: { bold: true, breakLine: true, color: ACCENT } },
    { text: "Design and develop a web-based sports club management system that centralises member, fixture, payment, and communication management." },
  ], { x: 6.9, y: 4.4, w: 5.5, h: 1.9, fontSize: 13, color: WHITE, fontFace: "Calibri" });

  // ---------- Slide 4: Specific Objectives ----------
  s = pres.addSlide();
  s.background = { color: WHITE };
  s.addText("Specific Objectives", { x: 0.7, y: 0.5, w: 10, h: 0.7, fontSize: 28, bold: true, color: INK, fontFace: "Cambria" });
  const objectives = [
    ["users", "Member Management", "Registration, attendance & medical records"],
    ["futbol", "Fixtures & Standings", "Live scoring & automatic league tables"],
    ["money", "Payments", "Paystack & M-Pesa with automated receipts"],
    ["robot", "AI Integration", "OpenRouter / Gemini reports, predictions & chatbot"],
    ["whatsapp", "Notifications", "WhatsApp click-to-chat + Brevo email"],
  ];
  const ow = 2.32, gap = 0.2, ox0 = 0.7, oy = 1.9;
  objectives.forEach((o, i) => {
    const x = ox0 + i * (ow + gap);
    s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x, y: oy, w: ow, h: 3.9, rectRadius: 0.1, fill: { color: LIGHT_BG },
      shadow: { type: "outer", color: "000000", blur: 6, offset: 2, angle: 45, opacity: 0.08 } });
    iconCircle(s, icons[o[0]], x + (ow - 0.9) / 2, oy + 0.35, 0.9, PRIMARY);
    s.addText(o[1], { x: x + 0.12, y: oy + 1.5, w: ow - 0.24, h: 0.9, fontSize: 14, bold: true, color: INK, align: "center", fontFace: "Calibri" });
    s.addText(o[2], { x: x + 0.15, y: oy + 2.4, w: ow - 0.3, h: 1.4, fontSize: 11.5, color: MUTED, align: "center", fontFace: "Calibri" });
  });

  // ---------- Slide 5: Literature Review comparison ----------
  s = pres.addSlide();
  s.background = { color: WHITE };
  s.addText("Literature Review: Existing Systems", { x: 0.7, y: 0.5, w: 11, h: 0.7, fontSize: 28, bold: true, color: INK, fontFace: "Cambria" });
  const tblHeader = ["System", "Member Mgmt", "Payments", "AI", "WhatsApp/SMS", "Cost"].map(t => ({
    text: t, options: { fill: { color: PRIMARY }, color: WHITE, bold: true, fontSize: 12 },
  }));
  const tblRows = [
    ["TeamSnap", "Yes", "Card only", "No", "Push only", "Subscription"],
    ["SportsEngine", "Yes", "Yes", "No", "Email only", "Subscription"],
    ["Playpass", "Yes", "Yes", "Limited", "No", "Subscription"],
    ["Manual / WhatsApp groups", "Manual", "Manual", "None", "Manual", "Free, labour-heavy"],
  ].map(row => row.map(t => ({ text: t, options: { fontSize: 11.5, color: INK } })));
  const apexRow = ["Apex (this system)", "Yes", "Paystack + M-Pesa", "OpenRouter / Gemini", "WhatsApp (wa.me) + Brevo", "Low-cost"].map(t => ({
    text: t, options: { fontSize: 11.5, bold: true, color: WHITE, fill: { color: SECONDARY } },
  }));
  s.addTable([tblHeader, ...tblRows, apexRow], {
    x: 0.7, y: 1.5, w: 11.9, colW: [2.6, 1.9, 2.0, 1.8, 2.1, 1.5],
    border: { pt: 0.5, color: "D6E6E4" }, autoPage: false, rowH: 0.6,
  });
  s.addText("Existing platforms are either feature-rich but costly and foreign-payment-centric, or free but entirely manual.",
    { x: 0.7, y: 6.1, w: 11.9, h: 0.6, fontSize: 13, italic: true, color: MUTED, fontFace: "Calibri" });

  // ---------- Slide 6: Research Gap ----------
  s = pres.addSlide();
  s.background = { color: DARK };
  iconCircle(s, icons.search, 0.9, 0.8, 1.0, PRIMARY);
  s.addText("The Research Gap", { x: 0.7, y: 2.0, w: 11.9, h: 0.8, fontSize: 30, bold: true, color: WHITE, fontFace: "Cambria" });
  s.addText(
    "No existing system combines affordable, locally relevant payment handling with AI-driven predictions and reporting, and low-cost WhatsApp-based communication — in a single no-framework PHP platform.",
    { x: 0.7, y: 3.0, w: 11.0, h: 1.3, fontSize: 17, color: "C9DEDD", fontFace: "Calibri" }
  );
  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: 0.7, y: 4.6, w: 11.9, h: 1.9, rectRadius: 0.1, fill: { color: PRIMARY } });
  s.addText("Apex closes this gap by integrating member management, Paystack/M-Pesa payments, AI features via OpenRouter/Gemini, and WhatsApp click-to-chat notifications into one cohesive, low-cost system.",
    { x: 1.1, y: 4.85, w: 11.1, h: 1.5, fontSize: 15, color: WHITE, fontFace: "Calibri", valign: "middle" });

  // ---------- Slide 7: Methodology ----------
  s = pres.addSlide();
  s.background = { color: WHITE };
  s.addText("Methodology: Iterative Development", { x: 0.7, y: 0.5, w: 11, h: 0.7, fontSize: 28, bold: true, color: INK, fontFace: "Cambria" });
  const cycle = [
    ["clip", "1. Requirement Identification", "Next valuable feature from scope or audits"],
    ["cogs", "2. Design & Implementation", "PHP/MySQL logic + Bootstrap styling"],
    ["flask", "3. Local Deployment & Testing", "Verify behaviour in the browser"],
    ["check", "4. Review & Correction", "Fix errors before the next feature"],
  ];
  const cw = 2.85, cgap = 0.25, cx0 = 0.7, cy = 1.9;
  cycle.forEach((c, i) => {
    const x = cx0 + i * (cw + cgap);
    s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x, y: cy, w: cw, h: 3.6, rectRadius: 0.1, fill: { color: i % 2 === 0 ? PRIMARY : SECONDARY } });
    iconCircle(s, icons[c[0]], x + (cw - 0.8) / 2, cy + 0.3, 0.8, DARK);
    s.addText(c[1], { x: x + 0.15, y: cy + 1.35, w: cw - 0.3, h: 0.8, fontSize: 13, bold: true, color: WHITE, align: "center", fontFace: "Calibri" });
    s.addText(c[2], { x: x + 0.2, y: cy + 2.25, w: cw - 0.4, h: 1.2, fontSize: 11, color: "EAF6F4", align: "center", fontFace: "Calibri" });
    if (i < cycle.length - 1) {
      s.addShape(pres.shapes.OVAL, { x: x + cw + 0.02, y: cy + 1.7, w: 0.22, h: 0.22, fill: { color: MUTED } });
    }
  });
  s.addText("Stack: PHP 7.4+ . MySQL (mysqli) . Bootstrap 5 . Font Awesome . Cloudflare Turnstile . Brevo API . Paystack . M-Pesa Daraja . OpenRouter / Google Gemini API",
    { x: 0.7, y: 5.9, w: 11.9, h: 0.6, fontSize: 11.5, color: MUTED, fontFace: "Calibri", italic: true });

  // ---------- Slide 8: Analysis - feature categories ----------
  s = pres.addSlide();
  s.background = { color: WHITE };
  s.addText("Analysis: Functional Requirement Areas", { x: 0.7, y: 0.5, w: 11.5, h: 0.7, fontSize: 27, bold: true, color: INK, fontFace: "Cambria" });
  const areas = [
    ["futbol", "Match & Competition", "Live scoring, standings, top scorers, MoM voting"],
    ["users", "Membership & Engagement", "Renewals, attendance, injuries, polls"],
    ["money", "Financial Management", "Refunds, revenue dashboard, receipts, expenses"],
    ["whatsapp", "Communication", "WhatsApp click-to-chat, Brevo email, announcements"],
    ["clip", "Administration", "Activity logs, system health, GDPR data export"],
    ["robot", "AI & Extras", "OpenRouter/Gemini reports, predictions, chatbot, gallery"],
  ];
  const aw = 3.85, ah = 1.65, agapx = 0.2, agapy = 0.2, ax0 = 0.7, ay0 = 1.85;
  areas.forEach((a, i) => {
    const col = i % 3, row = Math.floor(i / 3);
    const x = ax0 + col * (aw + agapx), y = ay0 + row * (ah + agapy);
    s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x, y, w: aw, h: ah, rectRadius: 0.09, fill: { color: LIGHT_BG },
      shadow: { type: "outer", color: "000000", blur: 5, offset: 2, angle: 45, opacity: 0.07 } });
    iconCircle(s, icons[a[0]], x + 0.2, y + 0.32, 0.55, PRIMARY);
    s.addText(a[1], { x: x + 0.9, y: y + 0.15, w: aw - 1.05, h: 0.5, fontSize: 13, bold: true, color: INK, fontFace: "Calibri" });
    s.addText(a[2], { x: x + 0.9, y: y + 0.68, w: aw - 1.05, h: 0.85, fontSize: 10.5, color: MUTED, fontFace: "Calibri" });
  });

  // ---------- Slide 9: Design - architecture ----------
  s = pres.addSlide();
  s.background = { color: WHITE };
  s.addText("Design: Three-Layer Architecture", { x: 0.7, y: 0.5, w: 11, h: 0.7, fontSize: 28, bold: true, color: INK, fontFace: "Cambria" });
  const layers = [
    ["layers", "Presentation Layer", "PHP pages in public/ and admin/, Bootstrap 5 + Font Awesome, shared includes/ header & footer"],
    ["cogs", "Application / Logic Layer", "PHP scripts for scoring, payments, renewals, AI requests, prepared mysqli statements"],
    ["db", "Data Layer", "MySQL (utf8mb4), schema evolved via the scripts/migrate.php runner; 48 numbered migrations"],
  ];
  let ly = 1.8;
  layers.forEach((l, i) => {
    s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: 0.7, y: ly, w: 11.9, h: 1.5, rectRadius: 0.09,
      fill: { color: [PRIMARY, SECONDARY, DARK][i] } });
    s.addShape(pres.shapes.OVAL, { x: 1.0, y: ly + 0.35, w: 0.8, h: 0.8, fill: { color: "FFFFFF", transparency: 60 } });
    s.addImage({ data: icons[l[0]], x: 1.2, y: ly + 0.55, w: 0.4, h: 0.4 });
    s.addText(l[1], { x: 2.1, y: ly + 0.2, w: 4.2, h: 0.7, fontSize: 16, bold: true, color: WHITE, fontFace: "Calibri", valign: "middle" });
    s.addText(l[2], { x: 6.4, y: ly + 0.15, w: 6.0, h: 1.2, fontSize: 12.5, color: "EAF6F4", fontFace: "Calibri", valign: "middle" });
    ly += 1.7;
  });

  // ---------- Slide 10: Implementation & Testing ----------
  s = pres.addSlide();
  s.background = { color: WHITE };
  s.addText("Implementation & Testing", { x: 0.7, y: 0.5, w: 10, h: 0.7, fontSize: 28, bold: true, color: INK, fontFace: "Cambria" });
  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: 0.7, y: 1.5, w: 5.7, h: 5.1, rectRadius: 0.1, fill: { color: LIGHT_BG } });
  iconCircle(s, icons.clipcheck, 1.05, 1.85, 0.7, PRIMARY);
  s.addText("Testing Approach", { x: 1.95, y: 1.95, w: 4.2, h: 0.6, fontSize: 16, bold: true, color: INK, fontFace: "Calibri" });
  s.addText([
    { text: "Functional testing — feature behaves as intended", options: { bullet: true, breakLine: true } },
    { text: "Regression testing — related features still work", options: { bullet: true, breakLine: true } },
    { text: "Technical testing — API, query, and form checks", options: { bullet: true } },
  ], { x: 1.05, y: 2.75, w: 4.9, h: 1.6, fontSize: 13, color: INK, fontFace: "Calibri", paraSpaceAfter: 10 });
  s.addText("Manual browser verification after every feature, plus PHPUnit tests for helper functions in tests/.",
    { x: 1.05, y: 4.5, w: 4.9, h: 1.6, fontSize: 12.5, italic: true, color: MUTED, fontFace: "Calibri" });

  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: 6.65, y: 1.5, w: 6.0, h: 5.1, rectRadius: 0.1, fill: { color: DARK } });
  iconCircle(s, icons.warn, 7.0, 1.85, 0.7, SECONDARY);
  s.addText("Issues Found & Resolved", { x: 7.9, y: 1.95, w: 4.5, h: 0.6, fontSize: 16, bold: true, color: WHITE, fontFace: "Calibri" });
  s.addText([
    { text: "Ambiguous SQL columns -> qualified with table aliases", options: { bullet: true, breakLine: true } },
    { text: "M-Pesa callback not recording -> public ngrok tunnel", options: { bullet: true, breakLine: true } },
    { text: "Gemini 401 -> switched to OpenRouter (simple Bearer token)", options: { bullet: true, breakLine: true } },
    { text: "PHP warnings on missing columns -> db_column_exists() guards", options: { bullet: true, breakLine: true } },
    { text: "Login CSRF mismatch -> per-form csrf_ensure() keys", options: { bullet: true } },
  ], { x: 7.0, y: 2.75, w: 5.5, h: 3.4, fontSize: 12.5, color: "EAF6F4", fontFace: "Calibri", paraSpaceAfter: 8 });

  // ---------- Slide 11: Conclusion - objectives achieved ----------
  s = pres.addSlide();
  s.background = { color: WHITE };
  s.addText("Conclusion: Objectives Achieved", { x: 0.7, y: 0.5, w: 11, h: 0.7, fontSize: 28, bold: true, color: INK, fontFace: "Cambria" });
  const achieved = [
    "Member registration & management, incl. attendance and medical records",
    "Live scoring and standings via a PIN-protected referee page",
    "Online payments with Paystack & M-Pesa, plus receipt generation",
    "AI: OpenRouter/Gemini predictions, automated reports, member chatbot",
    "WhatsApp click-to-chat notifications and Brevo email delivery",
  ];
  let cy2 = 1.8;
  achieved.forEach((txt) => {
    iconCircle(s, icons.check, 0.7, cy2, 0.5, SECONDARY);
    s.addText(txt, { x: 1.4, y: cy2, w: 11.0, h: 0.5, fontSize: 15, color: INK, fontFace: "Calibri", valign: "middle" });
    cy2 += 0.85;
  });

  // ---------- Slide 12: Recommendations ----------
  s = pres.addSlide();
  s.background = { color: DARK };
  s.addText("Recommendations", { x: 0.7, y: 0.5, w: 8, h: 0.8, fontSize: 30, bold: true, color: WHITE, fontFace: "Cambria" });
  const recs = [
    ["route", "Complete Laragon migration", "Lighter-weight local dev environment"],
    ["clip", "Finish audited features", "Prioritise member-facing impact"],
    ["users", "Mobile companion app", "Extend access beyond the browser"],
    ["layers", "Multi-club support", "Evaluate for broader adoption"],
  ];
  const rw = 2.85, rgap = 0.25, rx0 = 0.7, ry = 1.9;
  recs.forEach((r, i) => {
    const x = rx0 + i * (rw + rgap);
    s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x, y: ry, w: rw, h: 3.6, rectRadius: 0.1, fill: { color: PRIMARY } });
    iconCircle(s, icons[r[0]], x + (rw - 0.75) / 2, ry + 0.3, 0.75, SECONDARY);
    s.addText(r[1], { x: x + 0.15, y: ry + 1.25, w: rw - 0.3, h: 0.9, fontSize: 13, bold: true, color: WHITE, align: "center", fontFace: "Calibri" });
    s.addText(r[2], { x: x + 0.2, y: ry + 2.2, w: rw - 0.4, h: 1.2, fontSize: 11, color: "C9DEDD", align: "center", fontFace: "Calibri" });
  });

  // ---------- Slide 13: Thank you ----------
  s = pres.addSlide();
  s.background = { color: DARK };
  s.addShape(pres.shapes.OVAL, { x: -2, y: -2, w: 6, h: 6, fill: { color: PRIMARY, transparency: 55 } });
  s.addShape(pres.shapes.OVAL, { x: W - 3, y: H - 3, w: 5, h: 5, fill: { color: SECONDARY, transparency: 60 } });
  iconCircle(s, icons.futbol, W / 2 - 0.6, 2.2, 1.2, PRIMARY);
  s.addText("Thank You", { x: 0, y: 3.7, w: W, h: 1.0, fontSize: 40, bold: true, color: WHITE, align: "center", fontFace: "Cambria" });
  s.addText("Questions & Discussion", { x: 0, y: 4.6, w: W, h: 0.6, fontSize: 18, color: ACCENT, align: "center", fontFace: "Calibri" });
  s.addText("Juma Ibrahim  |  BIT/2024/56566", { x: 0, y: 6.6, w: W, h: 0.5, fontSize: 12, color: "C9DEDD", align: "center", fontFace: "Calibri" });

  const notes = [
    "Introduce yourself and the project title. State that this is a full-scope sports club management system.",
    "Walk through the six sections. Keep this brief — under 30 seconds.",
    "Explain the real-world pain point first (manual tools), then pivot to how Apex responds. State the general objective clearly.",
    "Go through each objective quickly — these map directly to the modules you will show later.",
    "Point out that other systems solve pieces of this problem but not all together. Use the table to make the comparison concrete.",
    "State the gap clearly: no one combines local Paystack/M-Pesa payments + AI + WhatsApp + no-framework low cost. This is Apex's contribution.",
    "Explain why iterative development was chosen — feature-by-feature build with testing at each step. Name the actual tech stack briefly.",
    "Group the requirements into these six areas. You do not need to list every feature — the categories are enough.",
    "Explain the three layers and why separating them mattered for maintainability as features were added.",
    "Describe how testing was done (manual browser verification + PHPUnit helper tests) and be ready to discuss specific issues you hit and fixed.",
    "Go through each objective and confirm it was achieved — this is your strongest closing evidence.",
    "Present these as forward-looking, not as unfinished work. Frame them as natural next steps.",
    "Invite questions. Be ready to discuss any of the technical fixes or the payment/AI flows in more depth if asked.",
  ];
  pres.slides.forEach((slide, i) => { if (notes[i]) slide.addNotes(notes[i]); });

  await pres.writeFile({ fileName: "Apex_Presentation.pptx" });
  console.log("done");
}

build().catch(e => { console.error(e); process.exit(1); });
