# Server Setup Guide

Reference for setting up a new project on the RoseHosting server.

---

## Server Details

| Item | Value |
|------|-------|
| Server IP | 162.246.254.78 |
| SSH Port | 7022 |
| Host | RoseHosting |
| Control Panel | cPanel |
| Domain Registrar | GoDaddy |
| DNS Manager | RoseHosting (not GoDaddy) |
| Email | cPanel (routed through hosting server) |

> **Note:** DNS is managed in RoseHosting's panel, NOT GoDaddy. GoDaddy nameservers point to RoseHosting. Always make DNS changes in RoseHosting.

---

## 1. SSH Access

```bash
ssh -p 7022 USERNAME@162.246.254.78
```

- Port **7022** (non-standard — don't forget this)
- Use your cPanel username
- Key-based auth preferred; add your public key via cPanel → SSH Access

---

## 2. cPanel Access

- URL: `https://162.246.254.78:2083` or `https://yourdomain.com:2083`
- Login: your hosting username + password
- All server management flows through here: files, databases, email, DNS, SSL, SSH keys

---

## 3. Domain Setup

### GoDaddy (registrar only)
- Change nameservers to RoseHosting's nameservers (given at account setup)
- Do nothing else in GoDaddy for DNS

### RoseHosting DNS Panel
- All A records, MX records, TXT records (SPF, DKIM, verification) go here
- Changes propagate within minutes on RoseHosting's own DNS

**Standard DNS records for a new domain:**

| Type | Name | Value |
|------|------|-------|
| A | @ | 162.246.254.78 |
| A | www | 162.246.254.78 |
| MX | @ | mail.yourdomain.com (priority 0) |
| TXT | @ | `v=spf1 mx ~all` |

---

## 4. cPanel — Add New Domain / Subdomain

1. cPanel → **Domains** (or Addon Domains / Subdomains)
2. Add domain or subdomain → set document root (e.g. `/home/USERNAME/public_html/newproject`)
3. cPanel auto-creates the directory

For a **subdomain** (e.g. `staging.yourdomain.com`):
- cPanel → Subdomains → create → set document root separately from main domain

---

## 5. SSL Certificate

1. cPanel → **SSL/TLS** → Let's Encrypt (or AutoSSL)
2. Run AutoSSL for the new domain/subdomain
3. Takes ~1 minute, renews automatically
4. Verify HTTPS works before going live

---

## 6. MySQL Database Setup

Via cPanel → **MySQL Databases**:

1. **Create database** — e.g. `USERNAME_dbname`
   - cPanel prepends your hosting username automatically
2. **Create user** — e.g. `USERNAME_dbuser` + strong password
3. **Add user to database** — grant **All Privileges**
4. Note all three: database name, username, password

Connection details:
```
host: localhost
port: 3306 (default)
dbname: USERNAME_dbname
user: USERNAME_dbuser
password: (set above)
```

> **phpMyAdmin** is available in cPanel for GUI access and importing SQL dumps.

---

## 7. PHP Configuration

- PHP version is set per-domain in cPanel → **MultiPHP Manager**
- Pick the version your project needs (prefer 8.1+ for new projects)
- cPanel → **MultiPHP INI Editor** for per-domain `php.ini` overrides:
  - `upload_max_filesize`
  - `post_max_size`
  - `max_execution_time`
  - `display_errors` (set Off in production)
  - `error_log` path if needed

---

## 8. File Deployment — Git

### One-time server setup

SSH into server:

```bash
cd ~/public_html/newproject    # or wherever the doc root is
git init
git remote add origin https://github.com/YOUR_ORG/YOUR_REPO.git
git pull origin master
```

### Deploy script (create locally as `deploy.sh`)

```bash
#!/bin/bash
ssh -p 7022 USERNAME@162.246.254.78 "cd ~/public_html/newproject && git pull origin master"
```

```bash
chmod +x deploy.sh
```

Run with `./deploy.sh` to deploy.

### For staging + production branches

- `staging` branch → staging subdomain doc root
- `master` branch → production doc root
- Two separate deploy scripts: `deployStaging.sh` and `deployProduction.sh`

**Git workflow:**
```bash
# Work on staging branch
git add <files>
git commit -m "message"
git push origin staging

# Merge to master and push
git checkout master
git merge staging
git push origin master
git checkout staging
```

### Config file (NEVER commit)

Add to `.gitignore`:
```
inc/config.php
config.php
.env
```

Manually create `config.php` on the server after first pull. Contains DB credentials and any environment-specific constants.

---

## 9. Email Setup (Deliverability)

### Create email account

cPanel → **Email Accounts** → Create
e.g. `hello@yourdomain.com`

### SPF Record

In RoseHosting DNS, add/verify TXT record on `@`:
```
v=spf1 mx ~all
```

### DKIM

1. cPanel → **Email Deliverability** → your domain → Manage
2. Click **Install** next to DKIM
3. cPanel generates the key and shows the DNS TXT record value
4. Copy it → go to RoseHosting DNS → add TXT record:
   - Name: `default._domainkey.yourdomain.com`
   - Value: (the long `v=DKIM1; k=rsa; p=...` string from cPanel)
5. Back in cPanel → Email Deliverability → Repair/Verify — should show green

### DMARC (optional but recommended)

Add TXT record in RoseHosting DNS:
- Name: `_dmarc.yourdomain.com`
- Value: `v=DMARC1; p=none; rua=mailto:admin@yourdomain.com`

### Test deliverability

Send a test email to a Gmail account and check headers. Or use [mail-tester.com](https://www.mail-tester.com).

---

## 10. Google Search Console (if needed)

1. Add property → Domain or URL prefix
2. Verify via HTML file upload (easiest): download `googleXXXXXXXX.html` → add to doc root → commit/deploy
3. Submit `sitemap.xml` once verified

---

## 11. Google Analytics (if needed)

- GA4 property → get Measurement ID (format: `G-XXXXXXXXXX`)
- Add to all public pages in `<head>`:

```html
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXXXXXXXX');
</script>
```

---

## 12. .htaccess Baseline

Place in doc root. Covers security headers, HTTPS redirect, and a custom 404:

```apache
# Force HTTPS
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Custom 404
ErrorDocument 404 /404.php

# Security headers
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set X-XSS-Protection "1; mode=block"

# Block sensitive paths from web access
<FilesMatch "\.(env|log|sh|sql|md)$">
  Order allow,deny
  Deny from all
</FilesMatch>

<IfModule mod_rewrite.c>
  RewriteRule ^inc/ - [F,L]
</IfModule>

# PHP settings (alternative to php.ini)
php_flag display_errors Off
php_value upload_max_filesize 10M
php_value post_max_size 10M
```

---

## 13. Checklist for a New Project

- [ ] Domain added in cPanel (Addon Domain or Subdomain)
- [ ] DNS A record pointing to 162.246.254.78 in RoseHosting
- [ ] SSL issued via AutoSSL
- [ ] MySQL DB + user created in cPanel, credentials saved locally
- [ ] `config.php` created on server manually (not committed)
- [ ] `.gitignore` includes `config.php`
- [ ] Git repo initialized in doc root, remote set, initial pull done
- [ ] Deploy script created and tested
- [ ] Email account created (if needed)
- [ ] SPF record verified in RoseHosting DNS
- [ ] DKIM installed in cPanel + DNS record added in RoseHosting
- [ ] `.htaccess` with HTTPS redirect and security headers in place
- [ ] PHP version set in MultiPHP Manager
- [ ] `display_errors` Off in production
