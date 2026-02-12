# Disaster Recovery Guide: Restic Backup Module

## Overview: The Three-Legged Stool

A complete Drupal site backup strategy relies on **three independent components**:

```
Complete Recovery = Code (Git) + Database + Files
         │              │           │          │
         │              │           │          └─ User uploads, private files
         │              │           └─ Content, config, state (SQL)
         │              └─ Modules, themes, custom code
         └─ Three-Legged Stool: All three legs required for stability
```

### This Module's Role

**Restic Backup** handles **Leg #3: Files**
- User-uploaded files (`web/sites/default/files/`)
- Private files (`private/`)
- Generated assets (image styles, compiled CSS/JS)
- Any other non-code, non-database content

### The Other Two Legs

**Leg #1: Code** → Already backed up via Git (GitHub/GitLab remote repository)
- Commit history provides version tracking
- Branch protection ensures code safety
- Clone from remote anytime

**Leg #2: Database** → Use [Retention Database Backup](https://www.drupal.org/project/retention_database_backup) module
- Automated SQL dumps with 4-tier retention policy
- Commit-hash tagging for code/database alignment
- Compressed backups with optional GPG encryption

**Why separate modules?** Each "leg" has different requirements:
- Code: Never deleted, always in git
- Database: Changes frequently, needs retention tiers
- Files: Large binaries, need incremental backups (restic)

This modular approach lets you choose your own database backup solution while still having comprehensive file protection.

---

## When the UI Won't Help You

### Use Cases for UI Restore (When Site Works)

The Drupal UI restore feature (`/admin/config/system/restic-backup/restore`) is useful when:

1. **Accidental File Deletion** (most common)
   - Marketing team deleted important product images
   - Developer removed wrong asset directory
   - Need specific files from yesterday's backup

2. **Content Rollback**
   - Bad image update pushed live
   - Restore last night's files to staging for comparison
   - Cherry-pick specific files to production

3. **Testing/Development**
   - Clone production files to staging environment
   - Test new feature against production assets
   - Developers without shell access can restore via UI

4. **Audit Trail**
   - UI logs all restore operations to watchdog
   - Non-technical admins can restore files
   - Clear accountability (who restored what when)

### Disaster Scenarios (UI Unavailable)

When Drupal is down, inaccessible, or compromised, you need **direct restic CLI access**:

- 💥 **Database corruption** → Drupal won't bootstrap, UI unavailable
- 🔥 **Server hardware failure** → Complete system loss
- 🚨 **Security breach** → Server compromised, reinstall required
- 💀 **Accidental deletion of web root** → No files, no Drupal
- 🌊 **Data center disaster** → Need to rebuild on new infrastructure

**This guide focuses on those disaster scenarios.**

---

## Prerequisites (Document Before Disaster Strikes)

### Environment Documentation Checklist

Complete this checklist NOW and store it securely (password manager, printed copy, company wiki):

```markdown
# Site Recovery Information: [SITE NAME]

## Infrastructure
□ Server OS: _____________ (e.g., Ubuntu 22.04 LTS)
□ PHP version: _____________ (e.g., 8.3.2)
□ Web server: _____________ (e.g., Nginx 1.24 / Apache 2.4)
□ Database: _____________ (e.g., MariaDB 10.11 / PostgreSQL 15)

## PHP Extensions (required)
□ Enabled: gd, opcache, pdo_mysql, xml, mbstring, curl, zip, ...
□ Disabled/Not needed: _____________

## External Services
□ Redis/Memcache: _____________ (host:port, version)
□ Solr: _____________ (URL, core name, version)
□ Mail service: _____________ (SMTP host, port, credentials location)
□ CDN: _____________ (provider, zone ID)
□ Backup storage: _____________ (SFTP/S3 details)

## Restic Repository
□ Repository type: _____________ (local / SFTP / S3)
□ Repository path: _____________ (full path or connection string)
□ Repository password location: _____________ (where stored securely)
□ Encryption enabled: ☐ Yes ☐ No

## Access Credentials (secure location)
□ Server SSH: _____________ (username, key location)
□ Database: _____________ (username, password location)
□ Restic password: _____________ (stored in: _____________)
□ Git repository: _____________ (GitHub/GitLab URL, access tokens)

## Critical Paths
□ Web root: _____________ (e.g., /var/www/html)
□ Private files: _____________ (e.g., /var/www/html/private)
□ Public files: _____________ (e.g., /var/www/html/web/sites/default/files)
□ Composer binary: _____________ (e.g., /usr/local/bin/composer)
□ Drush binary: _____________ (e.g., /usr/local/bin/drush)

## Drupal Configuration
□ Site name: _____________
□ Admin email: _____________
□ Trusted host patterns: _____________
□ Config sync directory: _____________ (e.g., ../config/sync)

## Last Updated
□ Date: _____________
□ By: _____________
□ Verified working: ☐ Yes ☐ No (last DR drill: _________)
```

**Store this information in at least 3 places:**
1. Password manager (1Password, Bitwarden, etc.)
2. Printed copy in secure physical location
3. Company wiki / secure documentation system

---

## Recovery Scenarios

### Scenario 1: Lost Files (Site Running, Files Missing)

**Situation**: Drupal works, but user uploaded files are missing or corrupted.

**Recovery via UI** (easiest):
1. Go to `/admin/config/system/restic-backup/restore`
2. Select the snapshot (latest pre-selected by default)
3. Choose restore mode:
   - **Full Snapshot**: Restores all backed-up files
   - **Specific Files**: Enter paths to restore individual files/directories
4. Confirm and restore

**Recovery via Drush** (if shell access available):
```bash
# List available snapshots
ddev exec drush restic:snapshots

# Restore latest snapshot to original locations
ddev exec drush restic:restore latest

# Restore specific snapshot
ddev exec drush restic:restore 5d784c2d

# Restore specific files only (advanced)
ddev exec drush restic:restore latest --include="web/sites/default/files/images"
```

**Recovery via direct restic CLI** (Drupal unavailable):
```bash
# List snapshots
restic -r /path/to/restic-repo snapshots

# Restore latest snapshot
restic -r /path/to/restic-repo restore latest --target /

# Restore specific files or directories
restic -r /path/to/restic-repo restore latest \
  --target / \
  --include "var/www/html/web/sites/default/files/images"

# Restore to temporary location for inspection
restic -r /path/to/restic-repo restore latest \
  --target /tmp/restore-inspection
```

---

### Scenario 2: Complete Site Loss (Server Gone)

**Situation**: Hardware failure, data center disaster, or complete server compromise requiring rebuild.

#### Step 1: Provision New Server

1. **Install base OS** (match documented version from checklist)
2. **Install required software**:
   ```bash
   # Example for Ubuntu/Debian
   sudo apt-get update
   sudo apt-get install -y \
     php8.3 php8.3-cli php8.3-fpm php8.3-mysql php8.3-gd \
     php8.3-xml php8.3-mbstring php8.3-curl php8.3-zip \
     nginx mariadb-server composer git restic
   ```

3. **Install restic**:
   ```bash
   # Option 1: Via package manager (Ubuntu 22.04+)
   sudo apt-get install restic

   # Option 2: Download latest binary
   wget https://github.com/restic/restic/releases/download/v0.16.4/restic_0.16.4_linux_amd64.bz2
   bunzip2 restic_0.16.4_linux_amd64.bz2
   sudo mv restic_0.16.4_linux_amd64 /usr/local/bin/restic
   sudo chmod +x /usr/local/bin/restic
   ```

#### Step 2: Restore Code from Git

```bash
# Create web root directory
sudo mkdir -p /var/www/html
sudo chown $USER:$USER /var/www/html
cd /var/www/html

# Clone repository
git clone https://github.com/your-org/your-site.git .

# Checkout specific commit (if database backup has commit hash)
# This ensures code matches database schema
git checkout abc123def

# Install dependencies
composer install --no-dev --optimize-autoloader
```

#### Step 3: Restore Database

**Using Retention Database Backup module** (recommended):

```bash
# If you have install/database.sql.gz in git repo
cd /var/www/html
gunzip -k install/database.sql.gz

# Import database
drush sql-cli < install/database.sql

# Or if you have separate database backup
gunzip -k /path/to/backups/20260209T143022-main-a1b2c3d4.sql.gz
drush sql-cli < /path/to/backups/20260209T143022-main-a1b2c3d4.sql
```

**Using other database backup**:
```bash
# Standard SQL import
mysql -u root -p your_database_name < backup.sql
```

#### Step 4: Restore Files with Restic

**Important**: Set RESTIC_PASSWORD environment variable or restic will prompt interactively.

```bash
# Set repository password (from your secure documentation)
export RESTIC_PASSWORD="your-secure-password"

# For SFTP repository
export RESTIC_REPOSITORY="sftp://user@backup-server:/backups/drupal-restic"

# For S3 repository
export RESTIC_REPOSITORY="s3:s3.amazonaws.com/bucket-name/drupal-restic"
export AWS_ACCESS_KEY_ID="your-key"
export AWS_SECRET_ACCESS_KEY="your-secret"

# For local repository (if accessible)
export RESTIC_REPOSITORY="/mnt/backups/restic-repo"

# List available snapshots
restic snapshots

# Restore latest snapshot to original locations
cd /var/www/html
restic restore latest --target /var/www/html

# Or restore specific snapshot
restic restore 5d784c2d --target /var/www/html

# Verify files restored
ls -lh web/sites/default/files/
ls -lh private/
```

#### Step 5: Configure Drupal

```bash
# Set file permissions
sudo chown -R www-data:www-data web/sites/default/files
sudo chown -R www-data:www-data private
sudo chmod -R 755 web/sites/default/files
sudo chmod -R 755 private

# Copy settings.php if not in git (or configure from template)
cp web/sites/default/default.settings.php web/sites/default/settings.php
nano web/sites/default/settings.php
# Add database credentials, trusted host patterns, etc.

# Run database updates (in case code is newer than backup)
drush updatedb -y

# Rebuild caches
drush cache:rebuild

# Import configuration (if using config management)
drush config:import -y

# Verify site status
drush status
```

#### Step 6: Configure Web Server

**Nginx example**:
```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/html/web;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

```bash
# Enable site and restart nginx
sudo ln -s /etc/nginx/sites-available/example.com /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

#### Step 7: Verify and Test

```bash
# Test database connection
drush sql:query "SELECT COUNT(*) FROM users"

# Test file access
curl -I https://example.com/sites/default/files/test-image.jpg

# Check Drupal status
drush status

# Verify cron runs
drush cron

# Check recent content
drush content:list
```

---

### Scenario 3: Database-Only Corruption (Files Intact)

**Situation**: Database is corrupted or lost, but files and code are intact.

**Quick Recovery**:
1. Code is already in place (no action needed)
2. Files are already in place (no action needed)
3. **Only restore database**:
   ```bash
   # Using Retention Database Backup
   cd /var/www/html
   gunzip -k install/database.sql.gz
   drush sql-cli < install/database.sql
   drush updatedb
   drush cache:rebuild
   ```

**No file restoration needed** - Restic backup can stay untouched.

---

### Scenario 4: Development/Staging Environment Setup

**Situation**: New developer needs production-like files for local development.

**Process**:
1. **Code**: Clone git repository
2. **Database**: Use `install/database.sql.gz` from retention_database_backup
3. **Files**: Restore latest restic snapshot

```bash
# In DDEV or local environment
git clone https://github.com/your-org/your-site.git
cd your-site
ddev start

# Import database
ddev import-db --src=install/database.sql.gz

# Initialize restic repository (one-time setup)
ddev exec drush restic:init

# Restore latest files
ddev exec drush restic:restore latest

# Rebuild caches
ddev exec drush cache:rebuild

# Site ready for development
ddev launch
```

---

## Testing Your Disaster Recovery Plan

**Critical**: Untested DR plans fail during actual disasters. Schedule quarterly drills.

### Quarterly DR Drill Checklist

**Recommended frequency**: Every 3 months

**Goal**: Verify you can recover from total loss in < 4 hours

#### Pre-Drill Preparation
- [ ] Designate test server/VM (do NOT use production)
- [ ] Gather environment documentation checklist
- [ ] Verify access to all credentials
- [ ] Block off 4 hours in team calendar
- [ ] Notify stakeholders this is a drill

#### Drill Steps
- [ ] **Minute 0**: Start timer
- [ ] **Step 1**: Provision fresh server (or wipe test server)
- [ ] **Step 2**: Install required software packages
- [ ] **Step 3**: Clone code from git repository
- [ ] **Step 4**: Restore database backup
- [ ] **Step 5**: Restore files with restic
- [ ] **Step 6**: Configure Drupal (settings.php, permissions)
- [ ] **Step 7**: Verify site functions (login, view content, upload file)
- [ ] **Minute X**: Stop timer, record duration

#### Success Criteria
- ✅ Site loads and functions correctly
- ✅ Can log in as admin
- ✅ Can view existing content
- ✅ Can upload new files
- ✅ All external services work (mail, Solr, Redis)
- ✅ Completed in < 4 hours (adjust based on organization needs)

#### Post-Drill Actions
- [ ] Document any issues encountered
- [ ] Update environment documentation with new information
- [ ] Fix broken credentials or outdated documentation
- [ ] Schedule next drill (add to calendar now)
- [ ] Share results with team and management

### Common Drill Failures (Learn From Others)

| Failure | Why It Happens | Prevention |
|---------|----------------|------------|
| **Can't access backups** | Credentials expired/changed | Store credentials in password manager, test monthly |
| **Code mismatch** | Forgot which commit matches backup | Always align git commit with database backup timestamp |
| **Missing PHP extensions** | Documentation outdated | Run `php -m` quarterly, update checklist |
| **Restic password lost** | Stored in team member's brain | Store in password manager with 2+ access holders |
| **Backup corrupted** | Never verified integrity | Run `restic check` monthly |
| **Process too slow** | No practice, manual errors | Automate with scripts, document edge cases |

---

## Recovery Time Objectives (RTO) and Recovery Point Objectives (RPO)

### Expected Recovery Times

| Scenario | RTO (Time to Recover) | RPO (Data Loss Window) | Complexity |
|----------|-----------------------|------------------------|------------|
| **Lost files** (UI restore) | < 15 minutes | 0-24 hours (last backup) | Low |
| **Lost files** (CLI restore) | < 30 minutes | 0-24 hours | Low |
| **Database corruption** | < 1 hour | 0-24 hours | Medium |
| **Complete site loss** | 2-4 hours | 0-24 hours | High |
| **Data center disaster** | 4-8 hours | 0-24 hours | Very High |

### Factors Affecting RTO

**Faster recovery** when you have:
- ✅ Up-to-date environment documentation
- ✅ Recent DR drill experience
- ✅ Automated scripts for provisioning
- ✅ Accessible backups (not tape archives)
- ✅ Offsite backups close to new server location

**Slower recovery** when:
- ❌ First time performing recovery
- ❌ Missing credentials or documentation
- ❌ Corrupted backups (no verification testing)
- ❌ Dependencies on unavailable team members
- ❌ Complex external service integrations

### Improving Your RTO/RPO

**To reduce RTO (faster recovery)**:
1. Practice DR drills quarterly
2. Document common issues and solutions
3. Create provisioning scripts (Ansible, Terraform)
4. Maintain runbooks for each scenario

**To reduce RPO (less data loss)**:
1. Increase backup frequency (hourly if critical)
2. Use multiple backup locations (local + remote)
3. Enable real-time replication for critical files
4. Monitor backup success (alerting on failures)

---

## Appendix A: Direct Restic CLI Commands

When Drupal UI and Drush are unavailable, use these direct CLI commands.

### Repository Operations

```bash
# Set password (required for all commands)
export RESTIC_PASSWORD="your-secure-password"

# Initialize new repository (one-time setup)
restic -r /path/to/restic-repo init

# Check repository integrity
restic -r /path/to/restic-repo check

# Get repository statistics
restic -r /path/to/restic-repo stats
```

### Snapshot Management

```bash
# List all snapshots
restic -r /path/to/restic-repo snapshots

# List snapshots with detailed info
restic -r /path/to/restic-repo snapshots --verbose

# Find specific snapshot
restic -r /path/to/restic-repo snapshots | grep "2026-02-09"

# Show files in a snapshot
restic -r /path/to/restic-repo ls 5d784c2d

# Search for specific file in snapshots
restic -r /path/to/restic-repo find "important-file.jpg"
```

### Restore Operations

```bash
# Restore latest snapshot to original locations
restic -r /path/to/restic-repo restore latest --target /

# Restore specific snapshot
restic -r /path/to/restic-repo restore 5d784c2d --target /

# Restore to custom location (for inspection)
restic -r /path/to/restic-repo restore latest --target /tmp/restore

# Restore only specific directories
restic -r /path/to/restic-repo restore latest \
  --target / \
  --include "var/www/html/web/sites/default/files"

# Restore specific file
restic -r /path/to/restic-repo restore latest \
  --target / \
  --include "var/www/html/private/important-document.pdf"

# Restore excluding certain paths
restic -r /path/to/restic-repo restore latest \
  --target / \
  --exclude "*.log" \
  --exclude "cache/*"
```

### Backup Operations (Emergency)

```bash
# Create manual backup
restic -r /path/to/restic-repo backup \
  /var/www/html/web/sites/default/files \
  /var/www/html/private

# Backup with exclusions
restic -r /path/to/restic-repo backup \
  /var/www/html/web/sites/default/files \
  --exclude "*.tmp" \
  --exclude "php/twig/*" \
  --exclude "styles/*"
```

### Maintenance Operations

```bash
# Prune old snapshots (apply retention policy)
restic -r /path/to/restic-repo forget \
  --keep-daily 7 \
  --keep-weekly 4 \
  --keep-monthly 12 \
  --keep-yearly 3 \
  --prune

# Remove unreferenced data
restic -r /path/to/restic-repo prune

# Rebuild repository index
restic -r /path/to/restic-repo rebuild-index
```

### SFTP/S3 Repository Examples

```bash
# SFTP repository
export RESTIC_REPOSITORY="sftp://backup-user@backup-server:/backups/drupal-restic"
export RESTIC_PASSWORD="your-password"
restic snapshots

# S3 repository
export RESTIC_REPOSITORY="s3:s3.amazonaws.com/your-bucket/drupal-restic"
export AWS_ACCESS_KEY_ID="your-key-id"
export AWS_SECRET_ACCESS_KEY="your-secret-key"
export RESTIC_PASSWORD="your-restic-password"
restic snapshots
```

---

## Appendix B: Emergency Contact Information Template

Create a document with this information and store it securely:

```markdown
# Emergency Recovery Contacts: [SITE NAME]

## Primary Contacts
- **Site Administrator**: _____________ (phone, email)
- **DevOps Lead**: _____________ (phone, email, backup contact)
- **Database Administrator**: _____________ (phone, email)
- **Hosting Provider Support**: _____________ (phone, ticket URL)

## Backup Access
- **Backup Location**: _____________
- **Access Method**: _____________ (SFTP/S3/Local)
- **Credentials Stored In**: _____________ (password manager, safe)
- **Backup Verification Last Run**: _____________

## External Services
- **DNS Provider**: _____________ (login URL, credentials location)
- **SSL Certificates**: _____________ (provider, renewal date)
- **CDN**: _____________ (provider, support contact)
- **Mail Service**: _____________ (provider, support contact)
- **Monitoring**: _____________ (service, alert contacts)

## Escalation Path
1. Site admin attempts recovery (RTO: 1 hour)
2. If unsuccessful → DevOps lead (RTO: 2 hours)
3. If unsuccessful → Hosting provider + consultant (RTO: 4 hours)
4. If unsuccessful → Full team + management notification

## Notification List (Disaster Communication)
- Management: _____________
- Technical team: _____________
- Customer support: _____________
- Marketing/PR: _____________

## Insurance/Legal
- **Insurance Provider**: _____________ (policy #, contact)
- **Legal Counsel**: _____________ (firm, contact, data breach protocol)
- **Compliance Officer**: _____________ (for GDPR/HIPAA incidents)

## Last Updated
- Date: _____________
- By: _____________
- Next review: _____________
```

---

## Additional Resources

### Restic Documentation
- Official Docs: https://restic.readthedocs.io/
- GitHub: https://github.com/restic/restic
- Forum: https://forum.restic.net/

### Complementary Modules
- **Retention Database Backup**: https://www.drupal.org/project/retention_database_backup
  - Handles database backups with 4-tier retention policy
  - Commit-hash tagging for code/database alignment
  - Perfect complement for complete site protection

### Drupal Backup Best Practices
- Backup & Migrate: https://www.drupal.org/project/backup_migrate (full-featured alternative)
- Config Management: Use `drush config:export` for configuration backups
- Version Control: Keep all custom code in Git

---

## Support and Contributions

If you encounter issues or have suggestions:
- **Issue Queue**: https://www.drupal.org/project/issues/restic_backup
- **Documentation**: This guide and README.md
- **Community**: #backup on Drupal Slack

Remember: **The best disaster recovery plan is the one you've tested.** Schedule your first DR drill today.
