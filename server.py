#!/usr/bin/env python3
"""Local server for Dili Paints: static files + offerte inbox without PHP."""

from __future__ import annotations

import csv
import io
import json
import os
import secrets
import smtplib
import ssl
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone, timedelta
from email.message import EmailMessage
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"
CONFIG_PATH = DATA / "config.json"
LEADS_PATH = DATA / "leads.json"
CSV_PATH = DATA / "leads.csv"
SESSIONS: set[str] = set()
BRUSSELS = timezone(timedelta(hours=2))


def load_config() -> dict:
    defaults = {
        "notify_emails": ["fadil.vasolli@telenet.be"],
        "from_name": "Dili Paints",
        "from_email": "fadil.vasolli@telenet.be",
        "admin_password": "DiliLeads2026",
        "smtp_host": "",
        "smtp_port": 587,
        "smtp_user": "",
        "smtp_password": "",
    }
    if CONFIG_PATH.exists():
        defaults.update(json.loads(CONFIG_PATH.read_text(encoding="utf-8")))
    return defaults


def load_leads() -> list:
    if not LEADS_PATH.exists():
        return []
    try:
        data = json.loads(LEADS_PATH.read_text(encoding="utf-8"))
        return data if isinstance(data, list) else []
    except json.JSONDecodeError:
        return []


def save_leads(leads: list) -> None:
    DATA.mkdir(exist_ok=True)
    LEADS_PATH.write_text(json.dumps(leads, indent=2, ensure_ascii=False), encoding="utf-8")
    with CSV_PATH.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.writer(handle, delimiter=";")
        writer.writerow(["Datum", "Naam", "E-mail", "Telefoon", "Bericht", "Status", "ID"])
        for lead in leads:
            writer.writerow([
                lead.get("created_at", ""),
                lead.get("name", ""),
                lead.get("email", ""),
                lead.get("phone", ""),
                lead.get("message", ""),
                lead.get("status", ""),
                lead.get("id", ""),
            ])


def send_via_formsubmit(lead: dict, recipients: list[str]) -> bool:
    payload = json.dumps({
        "name": lead["name"],
        "email": lead["email"],
        "phone": lead["phone"] or "-",
        "message": lead["message"],
        "_subject": f"Nieuwe offerteaanvraag van {lead['name']}",
        "_captcha": "false",
        "_template": "table",
        "_replyto": lead["email"],
        "_cc": "fadil.vasolli@telenet.be",
    }).encode("utf-8")
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36",
    }
    delivered = False
    ctx = ssl.create_default_context()
    for to in recipients:
        req = urllib.request.Request(
            f"https://formsubmit.co/ajax/{urllib.parse.quote(to)}",
            data=payload,
            headers=headers,
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=15, context=ctx) as response:
                result = json.loads(response.read().decode("utf-8", errors="replace"))
        except urllib.error.HTTPError as exc:
            raw = exc.read().decode("utf-8", errors="replace")
            try:
                result = json.loads(raw)
            except json.JSONDecodeError:
                result = {"message": raw}
        except Exception:
            continue
        success = result.get("success")
        message = str(result.get("message", ""))
        if success is True or success == "true" or "activat" in message.lower():
            delivered = True
    return delivered


def send_via_smtp(lead: dict, config: dict, recipients: list[str]) -> bool:
    host = (config.get("smtp_host") or "").strip()
    user = (config.get("smtp_user") or "").strip()
    password = config.get("smtp_password") or ""
    if not host or not user or not password:
        return False

    body = (
        f"Nieuwe offerteaanvraag via de website\n\n"
        f"Datum: {lead['created_at']}\n"
        f"Naam: {lead['name']}\n"
        f"E-mail: {lead['email']}\n"
        f"Telefoon: {lead['phone'] or '-'}\n\n"
        f"Bericht:\n{lead['message']}\n"
    )
    message = EmailMessage()
    message["Subject"] = f"Nieuwe offerteaanvraag van {lead['name']}"
    message["From"] = f"{config['from_name']} <{config['from_email']}>"
    message["To"] = ", ".join(recipients)
    message["Reply-To"] = lead["email"]
    message.set_content(body)
    try:
        with smtplib.SMTP(host, int(config.get("smtp_port") or 587), timeout=15) as smtp:
            smtp.starttls()
            smtp.login(user, password)
            smtp.send_message(message)
        return True
    except Exception:
        return False


def send_mail(lead: dict, config: dict) -> bool:
    recipients = [email for email in config.get("notify_emails", []) if email]
    if not recipients:
        return False
    targets = [item for item in config.get("formsubmit_ids", []) if item]
    targets.extend(email for email in recipients if email and email not in targets)
    if send_via_formsubmit(lead, targets):
        return True
    return send_via_smtp(lead, config, recipients)


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT), **kwargs)

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path
        if path.startswith("/data/") or path in {"/data", "/lib.php"}:
            self.send_error(403)
            return
        if path in {"/admin.php", "/admin"}:
            self.render_admin(urllib.parse.parse_qs(parsed.query))
            return
        if path == "/index.html":
            query = parsed.query
            location = "/main.html" + (f"?{query}" if query else "")
            self.send_response(301)
            self.send_header("Location", location)
            self.end_headers()
            return
        if path in {"/", "/main.html"}:
            self.path = "/main.html"
        super().do_GET()

    def do_POST(self):
        parsed = urllib.parse.urlparse(self.path)
        length = int(self.headers.get("Content-Length", "0") or 0)
        raw = self.rfile.read(length)
        form = urllib.parse.parse_qs(raw.decode("utf-8", errors="replace"), keep_blank_values=True)
        fields = {key: (values[0] if values else "") for key, values in form.items()}

        if parsed.path == "/email.php":
            self.handle_offerte(fields)
            return
        if parsed.path in {"/admin.php", "/admin"}:
            self.handle_admin_post(fields)
            return
        self.send_error(404)

    def wants_json(self) -> bool:
        accept = self.headers.get("Accept", "")
        requested = self.headers.get("X-Requested-With", "")
        return "application/json" in accept or requested.lower() == "fetch"

    def json_response(self, payload: dict, code: int = 200):
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def handle_offerte(self, fields: dict):
        if fields.get("company_url", "").strip():
            self.json_response({"ok": True, "mailed": True, "message": "Bedankt, uw bericht is verzonden."})
            return

        name = fields.get("name", "").strip()
        email = fields.get("email", "").strip()
        phone = fields.get("phone", "").strip()
        message = fields.get("message", "").strip()
        if not name or not message or "@" not in email:
            self.json_response({"ok": False, "message": "Vul naam, een geldig e-mailadres en een bericht in."}, 422)
            return

        lead = {
            "id": secrets.token_hex(8),
            "created_at": datetime.now(BRUSSELS).strftime("%Y-%m-%d %H:%M:%S"),
            "name": name[:120],
            "email": email[:160],
            "phone": phone[:40],
            "message": message[:4000],
            "status": "nieuw",
        }
        leads = load_leads()
        leads.insert(0, lead)
        save_leads(leads)
        sent = send_mail(lead, load_config())
        text = (
            "Bedankt, uw bericht is verzonden. We nemen zo snel mogelijk contact op."
            if sent
            else "Bedankt, uw aanvraag is bewaard. We nemen zo snel mogelijk contact op."
        )
        if self.wants_json():
            self.json_response({"ok": True, "mailed": sent, "message": text})
            return
        self.send_response(303)
        self.send_header("Location", "/main.html?status=ok#contact")
        self.end_headers()

    def is_authed(self) -> bool:
        cookie = self.headers.get("Cookie", "")
        for part in cookie.split(";"):
            name, _, value = part.strip().partition("=")
            if name == "dili_admin" and value in SESSIONS:
                return True
        return False

    def handle_admin_post(self, fields: dict):
        config = load_config()
        action = fields.get("action", "")
        if action == "login":
            if secrets.compare_digest(fields.get("password", ""), str(config["admin_password"])):
                token = secrets.token_hex(16)
                SESSIONS.add(token)
                self.send_response(303)
                self.send_header("Set-Cookie", f"dili_admin={token}; HttpOnly; Path=/; SameSite=Lax")
                self.send_header("Location", "/admin.php")
                self.end_headers()
                return
            self.render_admin({}, error="Onjuist wachtwoord.")
            return
        if not self.is_authed():
            self.render_admin({}, error="Log opnieuw in.")
            return
        if action == "status":
            lead_id = fields.get("id", "")
            status = fields.get("status", "nieuw")
            if status not in {"nieuw", "contact"}:
                status = "nieuw"
            leads = load_leads()
            for lead in leads:
                if lead.get("id") == lead_id:
                    lead["status"] = status
            save_leads(leads)
        self.send_response(303)
        self.send_header("Location", "/admin.php")
        self.end_headers()

    def render_admin(self, query: dict, error: str = ""):
        if "logout" in query:
            self.send_response(303)
            self.send_header("Set-Cookie", "dili_admin=; Max-Age=0; Path=/")
            self.send_header("Location", "/admin.php")
            self.end_headers()
            return
        if "export" in query and self.is_authed():
            save_leads(load_leads())
            data = CSV_PATH.read_bytes() if CSV_PATH.exists() else b""
            self.send_response(200)
            self.send_header("Content-Type", "text/csv; charset=utf-8")
            self.send_header("Content-Disposition", 'attachment; filename="dili-offertes.csv"')
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
            return

        authed = self.is_authed()
        leads = load_leads() if authed else []
        html = io.StringIO()
        html.write("<!DOCTYPE html><html lang='nl'><head><meta charset='UTF-8'>")
        html.write("<meta name='viewport' content='width=device-width, initial-scale=1'>")
        html.write("<title>Offertes | Dili Paints</title><link rel='stylesheet' href='main.css'>")
        html.write("<style>.admin-wrap{max-width:1080px;margin:40px auto 80px;padding:0 20px}")
        html.write(".admin-card{background:#fff;border-radius:20px;padding:28px;box-shadow:var(--shadow)}")
        html.write(".lead{border:1px solid var(--line);border-radius:16px;padding:18px;margin:0 0 14px;background:var(--paper)}")
        html.write(".lead-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}")
        html.write(".lead-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}")
        html.write(".lead-actions a,.lead-actions button{font:inherit;font-size:.85rem;font-weight:700;border-radius:999px;padding:8px 12px;text-decoration:none;border:0;cursor:pointer}")
        html.write(".lead-actions a{background:var(--navy);color:#fff}.lead-actions button{background:#fff;color:var(--navy);border:1px solid var(--line)}")
        html.write(".login-form{max-width:360px;display:flex;flex-direction:column;gap:12px}")
        html.write(".login-form input{padding:12px 14px;border-radius:12px;border:1px solid var(--line);font:inherit}")
        html.write(".message{white-space:pre-wrap}.muted{color:var(--muted)}.badge{font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--gold)}</style></head><body>")
        html.write("<header class='site-header'><div class='nav-bar'><a class='brand' href='/main.html'>")
        html.write("<span class='brand-mark'><img src='/icon.png' class='logo' alt=''></span><span class='brand-name'>Dili Paints</span></a>")
        if authed:
            html.write("<a class='nav-cta' style='display:inline-flex' href='/admin.php?export=1'>Export CSV</a>")
            html.write("<a href='/admin.php?logout=1'>Uitloggen</a>")
        html.write("</div></header><main class='admin-wrap'><div class='admin-card'>")
        if not authed:
            html.write("<p class='eyebrow'>Intern</p><h1>Offertes</h1>")
            html.write("<p class='muted'>Log in om aanvragen te bekijken, te beantwoorden en te exporteren.</p>")
            if error:
                html.write(f"<p class='form-status error'>{escape(error)}</p>")
            html.write("<form class='login-form' method='post'><input type='hidden' name='action' value='login'>")
            html.write("<label>Wachtwoord<input type='password' name='password' required></label>")
            html.write("<button class='btn btn-primary' type='submit'>Open inbox</button></form>")
        else:
            html.write("<p class='eyebrow'>Inbox</p><h1>Offerteaanvragen</h1>")
            html.write(f"<p class='muted'>{len(leads)} bewaarde aanvraag{'en' if len(leads) != 1 else ''}.</p>")
            if not leads:
                html.write("<p class='muted'>Nog geen aanvragen.</p>")
            for lead in leads:
                mailto = "mailto:" + urllib.parse.quote(lead.get("email", "")) + "?subject=" + urllib.parse.quote("Re: uw offerteaanvraag bij Dili Paints")
                next_status = "nieuw" if lead.get("status") == "contact" else "contact"
                label = "Markeer als nieuw" if lead.get("status") == "contact" else "Markeer als gecontacteerd"
                html.write("<article class='lead'><div class='lead-head'>")
                html.write(f"<strong>{escape(lead.get('name', ''))}</strong>")
                html.write(f"<span class='badge'>{escape(lead.get('status', 'nieuw'))} · {escape(lead.get('created_at', ''))}</span></div>")
                html.write(f"<div><a href='mailto:{escape(lead.get('email', ''))}'>{escape(lead.get('email', ''))}</a></div>")
                if lead.get("phone"):
                    html.write(f"<div><a href='tel:{escape(lead.get('phone', ''))}'>{escape(lead.get('phone', ''))}</a></div>")
                html.write(f"<p class='message'>{escape(lead.get('message', ''))}</p><div class='lead-actions'>")
                html.write(f"<a href='{escape(mailto)}'>Beantwoord via e-mail</a>")
                html.write("<form method='post'><input type='hidden' name='action' value='status'>")
                html.write(f"<input type='hidden' name='id' value='{escape(lead.get('id', ''))}'>")
                html.write(f"<input type='hidden' name='status' value='{next_status}'>")
                html.write(f"<button type='submit'>{label}</button></form></div></article>")
        html.write("</div></main></body></html>")
        body = html.getvalue().encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def escape(value: str) -> str:
    return (
        str(value)
        .replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


if __name__ == "__main__":
    DATA.mkdir(exist_ok=True)
    if not LEADS_PATH.exists():
        save_leads([])
    port = int(os.environ.get("PORT", "8766"))
    server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    print(f"Dili Paints server: http://127.0.0.1:{port}/main.html")
    print(f"Offerte-inbox:     http://127.0.0.1:{port}/admin.php")
    server.serve_forever()
