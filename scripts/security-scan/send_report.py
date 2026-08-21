#!/usr/bin/env python3
# ==============================================================================
# TrackFlow — security scan email sender.
#
# Reads the report body from stdin and sends it via SMTP. SMTP settings and the
# recipient come from environment variables (exported by prod-security-scan.sh
# from scan.conf). When --severity is "urgent" the message is flagged
# high-priority so it visually stands out in the inbox.
#
# Usage:
#   echo "<body>" | send_report.py --severity urgent|warn|clean --subject "..."
#
# If SMTP_HOST is unset the script prints the email to stdout (dry-run), which
# is handy for testing without sending anything.
# ==============================================================================
import argparse
import os
import smtplib
import ssl
import sys
from email.message import EmailMessage
from email.utils import formatdate, make_msgid


def env(name, default=None):
    v = os.environ.get(name)
    return v if v not in (None, "") else default


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--severity", choices=["urgent", "warn", "clean"], required=True)
    ap.add_argument("--subject", required=True)
    args = ap.parse_args()

    body = sys.stdin.read()

    mail_to = env("MAIL_TO", "giorgio@g8labs.it")
    mail_from = env("MAIL_FROM", env("SMTP_USER", "trackflow-monitor@g8labs.it"))
    from_name = env("MAIL_FROM_NAME", "TrackFlow - Monitor Sicurezza")

    msg = EmailMessage()
    msg["Subject"] = args.subject
    msg["From"] = f"{from_name} <{mail_from}>"
    msg["To"] = mail_to
    msg["Date"] = formatdate(localtime=True)
    msg["Message-ID"] = make_msgid(domain="g8labs.it")
    if args.severity == "urgent":
        # Make urgent reports stand out in most mail clients.
        msg["X-Priority"] = "1 (Highest)"
        msg["X-MSMail-Priority"] = "High"
        msg["Importance"] = "High"
    msg.set_content(body)

    host = env("SMTP_HOST")
    if not host:
        sys.stderr.write("[send_report] SMTP_HOST non impostato — dry-run, stampo l'email:\n\n")
        print(f"To: {mail_to}")
        print(f"Subject: {args.subject}")
        print(f"Severity: {args.severity}\n")
        print(body)
        return 0

    port = int(env("SMTP_PORT", "587"))
    security = env("SMTP_SECURITY", "starttls").lower()  # ssl | starttls | none
    user = env("SMTP_USER")
    password = env("SMTP_PASS")

    try:
        if security == "ssl":
            ctx = ssl.create_default_context()
            with smtplib.SMTP_SSL(host, port, context=ctx, timeout=30) as s:
                if user:
                    s.login(user, password)
                s.send_message(msg)
        else:
            with smtplib.SMTP(host, port, timeout=30) as s:
                s.ehlo()
                if security == "starttls":
                    s.starttls(context=ssl.create_default_context())
                    s.ehlo()
                if user:
                    s.login(user, password)
                s.send_message(msg)
    except Exception as e:  # noqa: BLE001 - we want any failure surfaced to the cron log
        sys.stderr.write(f"[send_report] INVIO FALLITO: {e}\n")
        return 1

    sys.stderr.write(f"[send_report] Email inviata a {mail_to} (severity={args.severity})\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
