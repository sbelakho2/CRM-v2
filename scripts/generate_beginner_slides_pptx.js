const fs = require('fs');
const path = require('path');
const PptxGenJS = require('pptxgenjs');

const ROOT = path.resolve(__dirname, '..');
const htmlPath = path.join(ROOT, 'CRM_V2_BEGINNER_PRESENTATION_SLIDES.html');
const outputPath = path.join(ROOT, 'CRM_V2_BEGINNER_PRESENTATION_SLIDES.pptx');

if (!fs.existsSync(htmlPath)) {
  throw new Error(`Missing HTML deck: ${htmlPath}`);
}

const html = fs.readFileSync(htmlPath, 'utf8');

function decodeEntities(value) {
  return String(value || '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'");
}

function stripTags(value) {
  return decodeEntities(String(value || '').replace(/<[^>]*>/g, ' ')).replace(/\s+/g, ' ').trim();
}

function getSections(doc) {
  const sections = [];
  const sectionRegex = /<section>([\s\S]*?)<\/section>/g;
  let match;
  while ((match = sectionRegex.exec(doc)) !== null) {
    sections.push(match[1]);
  }
  return sections;
}

function parseSection(sectionHtml) {
  const titleMatch = sectionHtml.match(/<h[1-3][^>]*>([\s\S]*?)<\/h[1-3]>/i);
  const title = stripTags(titleMatch ? titleMatch[1] : 'CRM v2');

  const imageMatch = sectionHtml.match(/<img[^>]*src="([^"]+)"/i);
  const imageSrc = imageMatch ? imageMatch[1] : null;

  const pRegex = /<p[^>]*>([\s\S]*?)<\/p>/gi;
  const paragraphs = [];
  let p;
  while ((p = pRegex.exec(sectionHtml)) !== null) {
    const text = stripTags(p[1]);
    if (text) paragraphs.push(text);
  }

  const liRegex = /<li[^>]*>([\s\S]*?)<\/li>/gi;
  const bullets = [];
  let li;
  while ((li = liRegex.exec(sectionHtml)) !== null) {
    const text = stripTags(li[1]);
    if (text) bullets.push(text);
  }

  return { title, imageSrc, paragraphs, bullets };
}

const pptx = new PptxGenJS();
pptx.layout = 'LAYOUT_WIDE'; // 13.333 x 7.5
pptx.author = 'CRM v2';
pptx.company = 'Starz Morocco';
pptx.subject = 'CRM v2 Executive Walkthrough';
pptx.title = 'CRM v2 Presentation';
pptx.lang = 'en-US';

const sections = getSections(html);

sections.forEach((sectionHtml) => {
  const data = parseSection(sectionHtml);
  const slide = pptx.addSlide();

  slide.background = { color: 'FFFFFF' };

  slide.addText(data.title, {
    x: 0.4,
    y: 0.2,
    w: 12.5,
    h: 0.5,
    fontSize: 20,
    bold: true,
    color: '1F2937'
  });

  if (data.imageSrc) {
    const imgPath = path.join(ROOT, data.imageSrc);
    if (fs.existsSync(imgPath)) {
      slide.addImage({ path: imgPath, x: 0.4, y: 0.85, w: 7.2, h: 6.1 });
    }

    const textLines = [];
    data.paragraphs.forEach((p) => {
      if (/^(Module:|Module Purpose:|On-screen header:|Where it fits:|What this page does:|Why it matters:|Try this in the demo:)/i.test(p)) {
        textLines.push(p);
      }
    });
    data.bullets.slice(0, 6).forEach((b) => textLines.push(`• ${b}`));

    const rightText = textLines.join('\n');
    slide.addShape(pptx.ShapeType.roundRect, {
      x: 7.8,
      y: 0.85,
      w: 5.1,
      h: 6.1,
      line: { color: 'D1D5DB', pt: 1 },
      fill: { color: 'F9FAFB' },
      radius: 0.08
    });

    slide.addText(rightText || 'CRM v2 workflow slide', {
      x: 8.0,
      y: 1.0,
      w: 4.7,
      h: 5.7,
      fontSize: 12,
      color: '111827',
      valign: 'top',
      breakLine: true
    });
  } else {
    const content = [
      ...data.paragraphs,
      ...data.bullets.map((b) => `• ${b}`)
    ].join('\n\n');

    slide.addText(content || 'CRM v2 presentation', {
      x: 0.8,
      y: 1.1,
      w: 11.8,
      h: 5.9,
      fontSize: 18,
      color: '111827',
      valign: 'top',
      breakLine: true
    });
  }

  slide.addText('CRM v2 Executive Walkthrough', {
    x: 0.4,
    y: 7.1,
    w: 6.5,
    h: 0.2,
    fontSize: 9,
    color: '6B7280'
  });
});

pptx.writeFile({ fileName: outputPath }).then(() => {
  console.log(`Created ${outputPath} with ${sections.length} slides.`);
});
