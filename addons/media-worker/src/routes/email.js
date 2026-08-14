/**
 * Email route — send + template compilation.
 *
 * Endpoints:
 *   POST /api/email/send         — send email (nodemailer)
 *   POST /api/email/compile-mjml — compile MJML to HTML
 */

import { Router } from 'express';

export const emailRouter = Router();

// ── POST /send — send email via nodemailer ──────────────────
emailRouter.post('/send', async (req, res) => {
  try {
    const { to, subject, html, text, from, cc, bcc, attachments, smtp } = req.body || {};
    if (!to || !subject || (!html && !text)) {
      return res.status(400).json({ success: false, error: 'Missing to, subject, or html/text' });
    }

    const nodemailer = (await import('nodemailer')).default;

    const transporter = nodemailer.createTransport({
      host: smtp?.host || process.env.SMTP_HOST || 'localhost',
      port: parseInt(smtp?.port || process.env.SMTP_PORT || '587', 10),
      secure: smtp?.secure ?? (parseInt(smtp?.port || process.env.SMTP_PORT || '587', 10) === 465),
      auth: smtp?.user
        ? { user: smtp.user, pass: smtp.pass }
        : process.env.SMTP_USER
          ? { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS || '' }
          : undefined,
    });

    const info = await transporter.sendMail({
      from: from || process.env.SMTP_FROM || 'noreply@designstudio.local',
      to: Array.isArray(to) ? to.join(', ') : to,
      cc: cc ? (Array.isArray(cc) ? cc.join(', ') : cc) : undefined,
      bcc: bcc ? (Array.isArray(bcc) ? bcc.join(', ') : bcc) : undefined,
      subject,
      html,
      text: text || undefined,
      attachments: attachments || undefined,
    });

    res.json({
      success: true,
      messageId: info.messageId,
      accepted: info.accepted,
      rejected: info.rejected,
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /compile-mjml — compile MJML to HTML ──────────────
emailRouter.post('/compile-mjml', async (req, res) => {
  try {
    const { mjml: mjmlInput, options } = req.body || {};
    if (!mjmlInput) {
      return res.status(400).json({ success: false, error: 'Missing mjml content' });
    }

    		const mjml2html = ( await import( 'mjml' ) ).default;
    		// mjml 5.x is async (mjml 4.x returned the result synchronously —
    		// awaiting works for both).
    		const result = await mjml2html( mjmlInput, {
      minify: options?.minify !== false,
      validationLevel: options?.validationLevel || 'soft',
      ...options,
    });

    res.json({
      success: true,
      html: result.html,
      errors: result.errors || [],
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /parse — parse raw email (headers, body, attachments) ─
emailRouter.post('/parse', async (req, res) => {
  try {
    const { source } = req.body || {};
    if (!source) {
      return res.status(400).json({ success: false, error: 'Missing source (raw email string)' });
    }
    const { simpleParser } = await import('mailparser');
    const parsed = await simpleParser(source);
    res.json({
      success: true,
      subject: parsed.subject,
      from: parsed.from?.text,
      to: parsed.to?.text,
      cc: parsed.cc?.text,
      date: parsed.date,
      text: parsed.text,
      html: parsed.html || null,
      attachments: (parsed.attachments || []).map((a) => ({
        filename: a.filename,
        contentType: a.contentType,
        size: a.size,
      })),
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});
