# SynapseMail demo mailbox generator.
# Copyright (c) 2026 SynapseCyber.io. Developed by Milad Ahmadipour (https://milad.io). CC BY-NC-ND 4.0.
# Usage: python3 tools/demo-mail.py app/plugins/synapse_demo/mail   (needs Pillow)
# Writes the demo mailbox (.eml files) for plugins/synapse_demo/mail/<folder>/.
# Dates are placeholders (@@<minutes ago>) that the demo storage turns into real dates relative to "now".
import base64, io, os, sys, textwrap
from PIL import Image, ImageDraw

OUT = sys.argv[1]
ME = 'Demo User <demo@synapsemail.test>'
n = {}

def pdf_bytes(title, lines):
    # minimal valid one-page PDF with text
    content = "BT /F1 18 Tf 60 760 Td (" + title + ") Tj ET\n"
    y = 720
    for l in lines:
        content += f"BT /F1 12 Tf 60 {y} Td ({l}) Tj ET\n"
        y -= 20
    objs = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
        f"<< /Length {len(content)} >>\nstream\n{content}endstream",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ]
    out = b"%PDF-1.4\n"; offs = []
    for i, o in enumerate(objs, 1):
        offs.append(len(out)); out += f"{i} 0 obj\n{o}\nendobj\n".encode()
    x = len(out)
    out += f"xref\n0 {len(objs)+1}\n0000000000 65535 f \n".encode()
    for o in offs: out += f"{o:010d} 00000 n \n".encode()
    out += f"trailer\n<< /Size {len(objs)+1} /Root 1 0 R >>\nstartxref\n{x}\n%%EOF\n".encode()
    return out

def png_bytes(w, h, top, bottom, sun=True):
    im = Image.new('RGB', (w, h)); d = ImageDraw.Draw(im)
    for y in range(h):
        t = y / h
        d.line([(0, y), (w, y)], fill=tuple(int(top[i] * (1 - t) + bottom[i] * t) for i in range(3)))
    if sun: d.ellipse((w*0.62, h*0.18, w*0.80, h*0.18 + w*0.18), fill=(255, 214, 140))
    d.polygon([(0, h), (w*0.3, h*0.55), (w*0.55, h), ], fill=(30, 52, 64))
    d.polygon([(w*0.35, h), (w*0.7, h*0.45), (w, h*0.8), (w, h)], fill=(22, 40, 50))
    b = io.BytesIO(); im.save(b, 'PNG', optimize=True); return b.getvalue()

def b64(data):
    return "\n".join(textwrap.wrap(base64.b64encode(data).decode(), 76))

def write(folder, ago, frm, to, subject, body, html=None, attach=None, extra='', cc=None):
    n[folder] = n.get(folder, 0) + 1
    hdr = [f"From: {frm}", f"To: {to}"] + ([f"Cc: {cc}"] if cc else []) + [
        f"Subject: {subject}", f"Date: @@{ago}",
        f"Message-ID: <demo-{folder.lower()}-{n[folder]}@synapsemail.test>", "MIME-Version: 1.0"]
    if extra: hdr += extra.strip().split("\n")
    def enc_subject(h):
        # encode non-ASCII headers as RFC 2047
        out = []
        for line in h:
            k, v = line.split(': ', 1)
            if any(ord(c) > 127 for c in v):
                if k in ('From', 'To', 'Cc') and '<' in v:
                    name, addr = v.rsplit('<', 1)
                    v = '=?UTF-8?B?' + base64.b64encode(name.strip().encode()).decode() + '?= <' + addr
                else:
                    v = '=?UTF-8?B?' + base64.b64encode(v.encode()).decode() + '?='
            out.append(f"{k}: {v}")
        return out
    hdr = enc_subject(hdr)
    def text_part(ctype, txt):
        return f"Content-Type: {ctype}; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n{b64(txt.encode())}\n"
    if html:
        alt = f"Content-Type: multipart/alternative; boundary=\"alt{n[folder]}\"\n\n--alt{n[folder]}\n" + text_part('text/plain', body) \
            + f"--alt{n[folder]}\n" + text_part('text/html', html) + f"--alt{n[folder]}--\n"
    else:
        alt = text_part('text/plain', body)
    if attach:
        mix = f"Content-Type: multipart/mixed; boundary=\"mix{n[folder]}\"\n\n--mix{n[folder]}\n" + alt
        for name, ctype, data in attach:
            mix += f"--mix{n[folder]}\nContent-Type: {ctype}; name=\"{name}\"\nContent-Disposition: attachment; filename=\"{name}\"\nContent-Transfer-Encoding: base64\n\n{b64(data)}\n"
        mix += f"--mix{n[folder]}--\n"
        msg = "\n".join(hdr) + "\n" + mix
    else:
        msg = "\n".join(hdr) + "\n" + alt
    os.makedirs(f"{OUT}/{folder}", exist_ok=True)
    with open(f"{OUT}/{folder}/{n[folder]:02d}.eml", 'w') as f:
        f.write(msg.replace("\n", "\r\n"))

H = 60; D = 1440
wrap = lambda inner, bg='#ffffff', fg='#1f2933': f'<div style="background:{bg};color:{fg};font-family:Arial,sans-serif;font-size:14px;line-height:1.6;padding:24px">{inner}</div>'

# ---------------- INBOX (oldest first; newest has the highest number) ----------------
write('INBOX', 9*D, 'Acme Hosting Billing <billing@acme-hosting.example>', ME, 'Invoice 2026-114 for your hosting plan',
      "Hello,\n\nThank you for your payment. Your invoice for the Business Hosting plan is attached.\n\nAmount: 24.00 USD\nPeriod: 1 Nov 2026 to 30 Nov 2026\n\nAcme Hosting (sample company)",
      attach=[('Invoice-2026-114.pdf', 'application/pdf', pdf_bytes('Invoice 2026-114', ['Acme Hosting (sample)', 'Business Hosting plan', 'Amount: 24.00 USD', 'Status: Paid']))],
      extra='X-Demo-Flags: SEEN')
write('INBOX', 6*D, 'Build Server <ci@build.example>', ME, 'Build #482 passed on main',
      "Build #482 passed.\n\nBranch: main\nDuration: 3 min 12 s\nTests: 318 passed, 0 failed\n\nThis is a sample notification.", extra='X-Demo-Flags: SEEN')
write('INBOX', 4*D, 'Daniel Park <daniel@northwind.example>', ME, 'Re: Launch checklist',
      "Hi,\n\nI finished the DNS part. SPF and DKIM are in place, DMARC is set to p=quarantine for now.\n\nStill open:\n- final copy for the landing page\n- screenshots for the press kit\n\nDaniel\n\n> On Monday, Demo User wrote:\n> Can you take the DNS records? I will do the screenshots.\n> Thanks!",
      extra='X-Demo-Flags: SEEN ANSWERED\nIn-Reply-To: <launch-1@synapsemail.test>')
write('INBOX', 3*D, 'Layla Hassan <layla@desert.example>', ME, 'اجتماع فريق التصميم يوم الأحد',
      "مرحبًا،\n\nنذكّرك باجتماع فريق التصميم يوم الأحد الساعة العاشرة صباحًا.\nسنراجع الواجهة الجديدة للهاتف ونتفق على الألوان النهائية.\n\nمع التحية،\nليلى",
      html=wrap('<div dir="rtl" style="text-align:right;font-family:Tahoma,Arial"><p>مرحبًا،</p><p>نذكّرك <b>باجتماع فريق التصميم</b> يوم الأحد الساعة العاشرة صباحًا.</p><p>سنراجع الواجهة الجديدة للهاتف ونتفق على الألوان النهائية.</p><p>مع التحية،<br>ليلى</p></div>'),
      extra='X-Demo-Flags: SEEN')
write('INBOX', 2*D, 'Reza Karimi <reza@pars.example>', ME, 'گزارش ماهانهٔ پروژه',
      "سلام،\n\nگزارش ماهانهٔ پروژه آماده است. خلاصه:\n- نسخهٔ موبایل کامل شد\n- ورود دومرحله‌ای برای همهٔ کاربران فعال شد\n- مرور امنیتی هفتهٔ بعد انجام می‌شود\n\nارادتمند،\nرضا",
      html=wrap('<div dir="rtl" style="text-align:right;font-family:Tahoma,Arial"><p>سلام،</p><p>گزارش ماهانهٔ پروژه آماده است. خلاصه:</p><ul><li>نسخهٔ موبایل کامل شد</li><li><b>ورود دومرحله‌ای</b> برای همهٔ کاربران فعال شد</li><li>مرور امنیتی هفتهٔ بعد انجام می‌شود</li></ul><p>ارادتمند،<br>رضا</p></div>'))
write('INBOX', 30*H, 'The Weekly Packet <news@packetweekly.example>', ME, 'The Weekly Packet, issue 42: passkeys, DMARC and more',
      "The Weekly Packet, issue 42\n\n1. Why DMARC reports matter\n2. Passkeys for small teams\n3. Five habits for safer email\n\nSample newsletter.",
      html='<div style="background:#f4f1ea;padding:24px;font-family:Georgia,serif;color:#2b2b2b"><div style="max-width:560px;margin:auto;background:#ffffff;border-top:6px solid #e85d3f;padding:28px">'
           '<h1 style="margin:0 0 4px;color:#e85d3f;font-size:26px">The Weekly Packet</h1><p style="margin:0 0 18px;color:#777">Issue 42 · a sample newsletter</p>'
           '<h2 style="color:#222;font-size:18px">1. Why DMARC reports matter</h2><p>DMARC tells receiving servers what to do with mail that fails SPF or DKIM. Start with <b>p=none</b>, read the reports, then move to quarantine.</p>'
           '<h2 style="color:#222;font-size:18px">2. Passkeys for small teams</h2><p>Passkeys remove passwords from the sign-in. They work best together with a second factor on shared devices.</p>'
           '<h2 style="color:#222;font-size:18px">3. Five habits for safer email</h2><ol><li>Unique passwords</li><li>2FA everywhere</li><li>Check the sender address</li><li>Do not open unexpected attachments</li><li>Sign out on shared computers</li></ol>'
           '<p style="text-align:center;margin-top:24px"><span style="display:inline-block;background:#e85d3f;color:#fff;padding:10px 18px;border-radius:4px">Read online</span></p></div></div>',
      extra='X-Demo-Flags: SEEN')
write('INBOX', 20*H, 'Sara Collins <sara@northwind.example>', ME, 'Translation check / بررسی ترجمه',
      "Hi! Could you check the Persian line in the footer?\n\nسلام! لطفاً خط فارسی پانویس را بررسی کن.\n\nThe English part is final. Thanks!\nSara",
      html=wrap('<p>Hi! Could you check the Persian line in the footer?</p><p dir="rtl" style="text-align:right;font-family:Tahoma">سلام! لطفاً خط فارسی پانویس را بررسی کن.</p><p>The English part is final. Thanks!<br>Sara</p>'))
write('INBOX', 8*H, 'Maya Lopez <maya@studio.example>', ME, 'Photos from the team offsite',
      "Hi everyone,\n\nHere is one of my favourite photos from the offsite. More are coming soon.\n\nMaya",
      attach=[('offsite-sunset.png', 'image/png', png_bytes(640, 400, (255, 166, 120), (44, 62, 80)))])
write('INBOX', 5*H, 'Sara Collins <sara@northwind.example>', ME, 'Q4 security review: action items',
      "Hi,\n\nThanks for joining the security review. Action items:\n\n1. Turn on 2FA for all admin accounts (required policy).\n2. Ask every user to add a passcode.\n3. Review the deliverability score (SPF, DKIM, DMARC).\n4. Schedule the encrypted backup every Friday.\n\nThe full report is attached.\n\nBest,\nSara",
      attach=[('Security-Review-Q4.pdf', 'application/pdf', pdf_bytes('Q4 Security Review', ['Sample report for the SynapseMail demo', '1. 2FA required for admins', '2. Passcode for every user', '3. SPF, DKIM, DMARC checked', '4. Weekly encrypted backup']))],
      extra='X-Demo-Flags: FLAGGED')
write('INBOX', 2*H, 'SynapseMail Security <security@synapsemail.test>', ME, 'New sign-in to your account',
      "We noticed a new sign-in to your account.\n\nDevice: Chrome on macOS\nWhen: a few minutes ago\n\nIf this was you, no action is needed. This is a sample message.",
      html=wrap('<h2 style="margin-top:0">New sign-in to your account</h2><table style="border-collapse:collapse"><tr><td style="padding:4px 12px 4px 0;color:#667">Device</td><td><b>Chrome on macOS</b></td></tr><tr><td style="padding:4px 12px 4px 0;color:#667">When</td><td>a few minutes ago</td></tr></table><p>If this was you, no action is needed. If not, change your password and check your passcode and 2FA settings.</p><p style="color:#889;font-size:12px">This is a sample message in the SynapseMail demo.</p>'))
write('INBOX', 20, 'SynapseMail Team <team@synapsemail.test>', ME, 'Welcome to the SynapseMail demo 👋',
      "Welcome to the SynapseMail demo!\n\nThis mailbox has sample emails only. Nothing is sent, and your changes reset when you sign out.\n\nThings to try:\n- Settings > Security: passcode and 2FA\n- Open the Persian and Arabic emails (right-to-left)\n- Compose an email with the new editor (sending is turned off)\n- Change the colour theme\n- Open the newsletter: colours are adjusted for dark mode\n- Try it on your phone\n\nGitHub: https://github.com/xcodz/SynapseMail",
      html=wrap('<h2 style="margin-top:0">Welcome to the SynapseMail demo 👋</h2><p>This mailbox has <b>sample emails only</b>. Nothing is sent, and your changes reset when you sign out.</p><p><b>Things to try:</b></p><ul><li>Settings › Security: passcode and 2FA</li><li>Open the Persian and Arabic emails (right-to-left)</li><li>Compose an email with the new editor (sending is turned off)</li><li>Change the colour theme</li><li>Open the newsletter: colours are adjusted for dark mode</li><li>Try it on your phone 📱</li></ul><p>⭐ <a href="https://github.com/xcodz/SynapseMail">SynapseMail on GitHub</a></p>'),
      extra='X-Demo-Flags: FLAGGED')

# ---------------- other folders ----------------
write('Sent', 5*D, ME, 'Daniel Park <daniel@northwind.example>', 'Launch checklist',
      "Hi Daniel,\n\nCan you take the DNS records? I will do the screenshots.\nThanks!", extra='X-Demo-Flags: SEEN')
write('Sent', 26*H, ME, 'Reza Karimi <reza@pars.example>', 'Re: گزارش ماهانهٔ پروژه',
      "سلام رضا،\n\nممنون، گزارش عالی بود. مرور امنیتی را هم در تقویم گذاشتم.\n\nبا تشکر", extra='X-Demo-Flags: SEEN')
write('Sent', 3*H, ME, 'Sara Collins <sara@northwind.example>', 'Re: Q4 security review: action items',
      "Hi Sara,\n\n2FA is now required for all admins. I will remind everyone to add a passcode this week.\n\nThanks!", extra='X-Demo-Flags: SEEN')
write('Drafts', 40, ME, 'Maya Lopez <maya@studio.example>', 'Offsite photos: thank you!',
      "Hi Maya,\n\nThe sunset photo is amazing. Can we use it on the team page?\n\n", extra='X-Demo-Flags: SEEN DRAFT')
write('Junk', 2*D, 'Account Team <support@secure-login.example>', ME, 'URGENT: confirm your password now',
      "Your mailbox will be closed in 24 hours. Confirm your password here: http://secure-login.example/confirm\n\n(Sample phishing email. Never enter your password from a link in an email.)")
write('Junk', 1*D, 'Prize Center <winner@lucky.example>', ME, 'You won a new phone!!!',
      "Click to claim your prize today.\n\n(Sample spam email.)", extra='X-Demo-Flags: SEEN')
write('Trash', 3*D, 'Build Server <ci@build.example>', ME, 'Build #479 failed on main',
      "Build #479 failed. 2 tests failed.\n\nSample notification.", extra='X-Demo-Flags: SEEN')
write('Archive', 40*D, 'Daniel Park <daniel@northwind.example>', ME, 'Domain renewal done',
      "Hi,\n\nThe domain is renewed for two more years.\n\nDaniel", extra='X-Demo-Flags: SEEN')
write('Archive', 70*D, 'Acme Hosting Billing <billing@acme-hosting.example>', ME, 'Welcome to Acme Hosting',
      "Welcome! Your hosting account is ready.\n\nAcme Hosting (sample company)", extra='X-Demo-Flags: SEEN')

print({k: v for k, v in n.items()})
