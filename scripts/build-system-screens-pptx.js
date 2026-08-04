const pptxgen = require('pptxgenjs');

const pptx = new pptxgen();
pptx.layout = 'LAYOUT_WIDE';
pptx.author = 'Juma Ibrahim';
pptx.subject = 'Live system screenshots';
pptx.title = 'Apex Sports Club - System Walkthrough';
pptx.company = 'Apex Sports Club';
pptx.lang = 'en-KE';
pptx.theme = {
  headFontFace: 'Aptos Display', bodyFontFace: 'Aptos', lang: 'en-KE'
};

const W = 13.333, H = 7.5;
const NAVY = '0D1830', BLUE = '346DEB', TEAL = '18C58F', WHITE = 'FFFFFF', INK = '17233A', MUTED = '61708A', PALE = 'F5F7FB';
const base = 'C:/xampp/htdocs/Apex Sports Club/screenshots/defense';
const shot = (file) => `${base}/${file}`;

function addFooter(s, n) {
  s.addShape(pptx.ShapeType.line, { x: .55, y: 7.02, w: 12.2, h: 0, line: { color: 'DCE4F0', width: .6 } });
  s.addText('APEX SPORTS CLUB  |  LIVE SYSTEM WALKTHROUGH', { x: .58, y: 7.11, w: 5, h: .18, fontSize: 7.5, bold: true, color: MUTED, charSpace: 1.1, margin: 0 });
  s.addText(String(n), { x: 12.45, y: 7.08, w: .3, h: .2, fontSize: 8, color: MUTED, align: 'right', margin: 0 });
}
function addTitle(s, eyebrow, title, sub) {
  s.addText(eyebrow.toUpperCase(), { x: .62, y: .46, w: 4.5, h: .22, fontSize: 8.5, bold: true, color: TEAL, charSpace: 1.35, margin: 0 });
  s.addText(title, { x: .6, y: .72, w: 12.05, h: .5, fontSize: 27, bold: true, color: INK, margin: 0, breakLine: false });
  s.addText(sub, { x: .62, y: 1.31, w: 11.8, h: .32, fontSize: 11, color: MUTED, margin: 0 });
}
function addShot(s, imagePath, caption) {
  s.addShape(pptx.ShapeType.roundRect, { x: .62, y: 1.84, w: 8.28, h: 4.66, rectRadius: .1, fill: { color: 'E2E8F0' }, line: { color: 'D7E0EA', width: 1 } });
  s.addImage({ path: imagePath, x: .69, y: 1.91, w: 8.14, h: 4.52, sizing: { type: 'contain', x: .69, y: 1.91, w: 8.14, h: 4.52 } });
  s.addText(caption, { x: .7, y: 6.55, w: 8.1, h: .2, fontSize: 8.5, italic: true, color: MUTED, align: 'center', margin: 0 });
}
function addPoints(s, points) {
  s.addText('WHAT THIS SCREEN SHOWS', { x: 9.32, y: 1.95, w: 3.35, h: .25, fontSize: 9, bold: true, color: BLUE, charSpace: .7, margin: 0 });
  let y = 2.35;
  points.forEach((point, i) => {
    s.addShape(pptx.ShapeType.ellipse, { x: 9.34, y: y + .07, w: .13, h: .13, fill: { color: i === 0 ? TEAL : BLUE }, line: { color: i === 0 ? TEAL : BLUE } });
    s.addText(point, { x: 9.58, y, w: 2.85, h: .55, fontSize: 13, color: INK, breakLine: false, fit: 'shrink', margin: 0 });
    y += .9;
  });
}

// 1. Title
let s = pptx.addSlide(); s.background = { color: NAVY };
s.addImage({ path: shot('01-home.png'), x: 6.62, y: 0, w: 6.713, h: 7.5, sizing: { type: 'cover', x: 6.62, y: 0, w: 6.713, h: 7.5 } });
s.addShape(pptx.ShapeType.rect, { x: 6.62, y: 0, w: 6.713, h: 7.5, fill: { color: NAVY, transparency: 42 }, line: { color: NAVY, transparency: 100 } });
s.addShape(pptx.ShapeType.rect, { x: 0, y: 0, w: 7.15, h: 7.5, fill: { color: NAVY }, line: { color: NAVY } });
s.addText('APEX SPORTS CLUB', { x: .7, y: .72, w: 4.4, h: .25, fontSize: 10, bold: true, color: TEAL, charSpace: 1.8, margin: 0 });
s.addText('Live System\nWalkthrough', { x: .68, y: 1.35, w: 5.55, h: 1.48, fontSize: 39, bold: true, color: WHITE, breakLine: false, margin: 0, valign: 'mid' });
s.addText('A presentation built from the running Apex Sports Club interface.', { x: .72, y: 3.2, w: 5.35, h: .55, fontSize: 17, color: 'C9D4E5', margin: 0 });
s.addShape(pptx.ShapeType.line, { x: .72, y: 4.2, w: 1.1, h: 0, line: { color: TEAL, width: 2.5 } });
s.addText('Juma Ibrahim\nBIT/2024/56566', { x: .72, y: 6.25, w: 3, h: .52, fontSize: 12, color: WHITE, margin: 0 });

// 2-7 Real application views
const slides = [
  ['Member access begins with a secure, focused sign-in experience', 'Member login', '04-member-login.png', ['Dedicated member sign-in area', 'Email and password fields', 'Cloudflare Turnstile verification', 'Password-recovery route']],
  ['The public homepage unifies the club’s most visible services', 'Landing page', '01-home.png', ['Clear club identity and navigation', 'Direct actions for tickets and membership', 'Fixtures, memberships and bookings highlighted', 'WhatsApp support entry point']],
  ['Fixtures and standings make competition information easy to find', 'Competition hub', '02-fixtures.png', ['League selector across sports', 'Competitive league table', 'Scheduled fixtures area', 'Historic performance log']],
  ['Facilities are presented as bookable club resources', 'Facilities catalogue', '03-facilities.png', ['Facility name, type and location', 'Capacity visible before booking', 'Authenticated booking action', 'Multiple sports spaces in one catalogue']],
  ['The ticket gateway gives fans a simple route into matchday access', 'Fan ticketing', '05-tickets.png', ['Public ticket gateway', 'Membership remains optional for match passes', 'Tickets grouped by sport', 'Empty state communicates when no fixtures are listed']],
  ['Administration is separated from the member portal', 'Administrator access', '06-admin-login.png', ['Distinct administrative sign-in', 'Administrator dashboard entry point', 'Two-factor-protected messaging', 'Role-based operational control']],
];
slides.forEach(([title, eyebrow, img, points], i) => {
  s = pptx.addSlide(); s.background = { color: WHITE };
  addTitle(s, eyebrow, title, 'Live capture from the locally running Apex Sports Club application.');
  addShot(s, shot(img), `Actual system screen: ${eyebrow}`);
  addPoints(s, points);
  addFooter(s, i + 2);
});

// 8. Summary
s = pptx.addSlide(); s.background = { color: PALE };
s.addText('The live interface demonstrates a connected club experience', { x: .72, y: .72, w: 11.8, h: .55, fontSize: 29, bold: true, color: INK, margin: 0 });
s.addText('Public discovery, member access and administrator control are presented as separate but connected journeys.', { x: .75, y: 1.38, w: 10.8, h: .3, fontSize: 13, color: MUTED, margin: 0 });
[['Discover', 'Homepage, fixtures, facilities and tickets'], ['Join & use', 'Member login and authenticated self-service'], ['Manage', 'Administrator access and operational oversight']].forEach((x, i) => {
  const left = .78 + i * 4.14;
  s.addShape(pptx.ShapeType.roundRect, { x: left, y: 2.25, w: 3.62, h: 2.48, rectRadius: .12, fill: { color: WHITE }, line: { color: 'DCE4F0', width: 1 } });
  s.addShape(pptx.ShapeType.ellipse, { x: left + .32, y: 2.57, w: .46, h: .46, fill: { color: i === 0 ? BLUE : i === 1 ? TEAL : 'E94B4B' }, line: { color: i === 0 ? BLUE : i === 1 ? TEAL : 'E94B4B' } });
  s.addText(x[0], { x: left + .34, y: 3.27, w: 2.85, h: .35, fontSize: 20, bold: true, color: INK, margin: 0 });
  s.addText(x[1], { x: left + .34, y: 3.84, w: 2.85, h: .5, fontSize: 12.5, color: MUTED, margin: 0 });
});
s.addText('The next stage is to demonstrate the authenticated workflows - member records, payments, bookings, live scoring, reporting and security controls - using safe test accounts.', { x: .78, y: 5.55, w: 11.6, h: .45, fontSize: 15, color: INK, align: 'center', margin: 0 });
addFooter(s, 8);

pptx.writeFile({ fileName: 'C:/xampp/htdocs/Apex Sports Club/output/pptx/Apex_Live_System_Walkthrough.pptx' });
