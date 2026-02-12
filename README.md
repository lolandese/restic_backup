# Restic Backup Module

Intelligent, incremental file backups for Drupal using the free, open-source **Restic** tool.

## Key Advantages

- **✅ Open Source & Free**: No subscription fees or vendor lock-in
- **✅ Self-Hosted**: Run backups on your own servers or cloud accounts you control
- **✅ Powerful & Efficient**: Incremental backups, deduplication, encryption, multiple storage backends

## Overview

The Restic Backup module provides automated, incremental file backups for Drupal sites. It:

- **Automatically discovers files** using `.gitignore` rules and Drupal-specific patterns
- **Excludes unnecessary files** like dependencies, regenerable assets, and version control
- **Enforces encryption** for sensitive files (settings.php, .env, private files)
- **Supports multiple storage backends** (local, SFTP, S3, B2, etc.)
- **Integrates with Drupal cron** for scheduled backups
- **Provides Drush commands** for manual operations and automation

This module is designed to complement database backup solutions like Backup & Migrate by handling file backups intelligently.

## Feature Comparison with Backup & Migrate

| Feature | Restic Backup | Backup & Migrate |
|---------|---------------|------------------|
| **File Backups** | ✅ Yes (primary) | ⚠️ Limited |
| **Database Backups** | ✅ Yes | ✅ Yes (primary) |
| **Multiple Storage Backends** | ✅ Yes (S3, B2, Azure, SFTP) | ⚠️ Limited |
| **Incremental Backups** | ✅ Native | ❌ Full backups only |
| **Deduplication** | ✅ Automatic | ❌ No |
| **Encryption** | ✅ AES-256 | ✅ Basic |
| **Compression** | ✅ Automatic | ✅ Optional |
| **Browse Snapshots** | ✅ Yes | ❌ No |
| **Restore Individual Files** | ✅ Yes | ⚠️ Limited |
| **Web Interface** | ✅ Full UI | ✅ Full UI |
| **Bandwidth Optimization** | ✅ Incremental only | ❌ Full backups |
| **Cost Optimization** | ✅ Dedup + compression | ✅ Compression only |
| **Scheduled Backups** | ✅ Via cron | ✅ Via UI |

**Key Differences:**
- **Restic Backup**: Optimized for **files and complete system backups** with cloud provider support
- **Backup & Migrate**: Optimized for **database backups** with restore UI

## Complementary Modules

This module works best as part of a complete backup strategy:

- **[Retention Database Backup](../retention_database_backup/)** - Specialized database backup with intelligent retention policies (daily, weekly, monthly, yearly tiers). Use this for database-specific backups with automatic cleanup and git integration.

**Recommended Architecture:**
1. Use **Retention Database Backup** for database backups with intelligent retention policies
2. Use **Restic Backup** for complete file system backups and remote storage consolidation
3. Both modules work independently but complement each other for comprehensive protection

### 🚨 Disaster Recovery

**For complete site recovery scenarios** (database corruption, server loss, total disaster), see **[DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)**.

This module handles **file backups** (user uploads, private files, etc.). For complete site protection, you also need:
- **Code backups**: Use Git (GitHub/GitLab)
- **Database backups**: Use [Retention Database Backup](https://www.drupal.org/project/retention_database_backup) module (recommended)

Together, these three components form the "three-legged stool" of complete Drupal backup strategy. See the disaster recovery guide for detailed recovery scenarios.

## Installation

### Prerequisites

1. **Restic binary** - Download from https://restic.readthedocs.io/en/latest/020_installation.html
   ```bash
   # macOS
   brew install restic

   # Linux (Debian/Ubuntu)
   sudo apt-get install restic

   # Or download binary
   curl -O https://github.com/restic/restic/releases/download/v0.16.0/restic_0.16.0_linux_amd64.bz2
   ```

   **For DDEV Users** (recommended for local development):

   Restic must be installed inside the DDEV container where Drupal executes. Installing restic on your host machine alone will not work.

   ```bash
   # Option 1: Permanent installation (recommended)
   # Create .ddev/web-build/Dockerfile.restic with:

   # Install restic backup tool
   RUN curl -L https://github.com/restic/restic/releases/download/v0.16.4/restic_0.16.4_linux_amd64.bz2 | \
       bunzip2 > /tmp/restic && \
       chmod +x /tmp/restic && \
       mv /tmp/restic /usr/bin/restic

   # Verify installation
   RUN restic version

   # Then rebuild your DDEV container:
   ddev restart

   # Option 2: Temporary installation (testing only)
   ddev exec "curl -L https://github.com/restic/restic/releases/download/v0.16.4/restic_0.16.4_linux_amd64.bz2 | bunzip2 > /tmp/restic && chmod +x /tmp/restic && mv /tmp/restic /usr/bin/restic"

   # Verify installation
   ddev exec restic version
   ```

   ⚠️ **Important**: Use Option 1 for permanent installation. Option 2 will be lost when the container is rebuilt.

2. **Drupal 10.3+** or **Drupal 11.x**
3. **PHP 8.1+**

### Enable Module

```bash
# Enable the module
drush pm:enable restic_backup

# Or via admin UI: Admin > Extend > search "Restic"
```

## Quick Start

### Step 1: Setup Repository

1. Go to **Admin > Configuration > System > Restic Backup**
2. Click **Setup**
3. Enter Restic binary path (e.g., `/usr/bin/restic` or `/usr/local/bin/restic`)
   - ⚠️ **Important**: Enter ONLY the path to the restic executable, not the repository path
   - ✓ Correct: `/usr/bin/restic`
   - ✗ Wrong: `/usr/bin/restic/valpellice.site` or `/var/backups/restic/repo`
4. Choose repository type: Local directory, SFTP, or S3
5. Enter repository path in the separate Repository Path field:
   - For DDEV: `/var/www/html/private/restic-repo`
   - For production: `/var/backups/drupal-restic` or similar
6. (Optional) Enable encryption with a strong password
7. Save configuration
8. Verify with: `ddev exec drush restic:validate`

### Step 2: Choose Files

1. Go to **Restic Backup** > **Files**
2. The module automatically scans your project and categorizes files:
   - **Public Files** (user uploads) - included by default
   - **Private Files** (if encryption enabled)
   - **Sensitive Config** (settings.php, .env - if encryption enabled)
   - **Regenerable** (image styles, aggregated CSS/JS - auto-excluded)
   - **Dependencies** (vendor/, node_modules/ - auto-excluded)
3. Select which files to back up
4. Save your selection

### Step 3: Set Schedule

1. Go to **Restic Backup** > **Policy & Schedule**
2. Configure retention policy:
   - Keep X daily snapshots
   - Keep X weekly snapshots
   - Keep X monthly snapshots
   - Keep X yearly snapshots
3. Set backup frequency (cron expression)
4. Enable notifications (optional)
5. Save configuration

### Step 4: Run First Backup

```bash
# Via Drush
drush restic:backup

# Or via admin UI
# Admin > Configuration > System > Restic Backup > "Backup Now"
```

## Configuration Examples

### Local Directory

```yaml
Binary Path: /usr/bin/restic
Repository Type: Local
Repository Path: /mnt/backups/drupal-restic
Encryption: Enabled (recommended)
```

### SFTP Remote

```yaml
Binary Path: /usr/bin/restic
Repository Type: SFTP
Repository Path: sftp://user@example.com:/backups/drupal
Encryption: Enabled
```

### Amazon S3

```yaml
Binary Path: /usr/bin/restic
Repository Type: S3
Repository Path: s3:s3.amazonaws.com/my-bucket/backups/drupal
Encryption: Enabled (native AES-256)
```

Set S3 credentials via environment variables:
```bash
export AWS_ACCESS_KEY_ID="your-access-key"
export AWS_SECRET_ACCESS_KEY="your-secret-key"
```

## Drush Commands

### Initialize Repository

```bash
drush restic:init --repo=/path/to/repo --password="strong-password"
```

### Run Backup

```bash
# Automatic backup (respects configured paths)
drush restic:backup

# Manual backup flag
drush restic:backup --manual
```

### List Snapshots

```bash
drush restic:snapshots
```

### Restore Files

```bash
# Restore latest snapshot to /tmp/restore
drush restic:restore latest --target=/tmp/restore

# Restore specific snapshot
drush restic:restore abc123def456 --target=/tmp/restore
```

### Apply Retention Policy

```bash
# Remove old snapshots based on retention policy
drush restic:prune
```

### Validate Configuration

```bash
# Check if Restic binary and repository are working
drush restic:validate

# Scan project and show files to be backed up
drush restic:scan
```

### Get Statistics

```bash
# Get stats for latest snapshot
drush restic:stats

# Get stats for specific snapshot
drush restic:stats --snapshot=abc123def456
```

## Security

### Encryption

The module enforces encryption for sensitive files:

- **Enabled**: Sensitive files (settings.php, .env, private files) are included
- **Disabled**: Sensitive files are automatically excluded from backups

To enable encryption:

1. Go to **Restic Backup > Setup**
2. Check "Enable Encryption"
3. Enter a strong password (store securely!)
4. Save configuration

### Password Management

**Important**: Store your Restic repository password securely!

- Do NOT save passwords in version control
- Use environment variables or secret management tools
- For production, consider integrating with Drupal's Key Module

### File Permissions

Ensure Drupal's web server user has read access to all files to be backed up:

```bash
# Check current user
ps aux | grep php-fpm  # or apache2, nginx

# Verify access to important files
sudo -u www-data ls -la web/sites/default/files/
sudo -u www-data cat web/sites/default/settings.php > /dev/null
```

## Restoration Workflow

### Quick Restore (via Admin UI)

1. Go to **Restic Backup > Snapshots**
2. Click **Restore** on desired snapshot
3. Choose target directory
4. Click **Restore**

### Command-Line Restore

```bash
# Restore specific files
restic -r /path/to/repo restore abc123 --include "web/sites/default/files/documents/*" --target=/tmp

# Restore to current directory
restic -r /path/to/repo restore latest --target ./
```

### Selective Restore

```bash
# List files in snapshot before restoring
restic -r /path/to/repo ls latest | grep "web/sites/default/files"

# Restore only specific path
restic -r /path/to/repo restore latest --include "web/sites/default/files/documents/*" --target /tmp/restore
```

## Troubleshooting

### Restic Binary Not Found

**Error**: "Restic binary not found or not working"

**Solution**:
```bash
# Verify installation
which restic
restic version

# Update config with correct path
drush config:set restic_backup.settings binary_path /usr/local/bin/restic
```

### Binary Path Concatenation Error

**Error**: `sh: 1: exec: /usr/bin/restic/valpellice.site: not found`

**Cause**: The Setup Form may incorrectly concatenate the repository path with the binary path, resulting in `/usr/bin/restic/valpellice.site` instead of `/usr/bin/restic`.

**Solution**:
```bash
# Check current binary path
ddev exec drush config:get restic_backup.settings binary_path

# If it shows an incorrect path like /usr/bin/restic/valpellice.site, fix it:
ddev exec drush config:set restic_backup.settings binary_path /usr/bin/restic -y

# Verify the fix
ddev exec drush restic:validate
```

**Prevention**: When using the Setup Form, ensure the Binary Path field contains only the path to the restic binary (e.g., `/usr/bin/restic`), not the repository path. The form now includes validation to catch this error, but if you encounter it:

1. Fix via Drush (recommended):
   ```bash
   ddev exec drush config:set restic_backup.settings binary_path /usr/bin/restic -y
   ddev exec drush config:export -y  # Persist the fix
   ```

2. Or clear and reconfigure via Setup Form (ensure correct values this time)

### Repository Permission Denied

**Error**: "Repository not found or permission denied"

**Solution**:
```bash
# Check directory permissions
ls -ld /path/to/repo

# Fix permissions for web server user
sudo chown www-data:www-data /path/to/repo
sudo chmod 755 /path/to/repo
```

### Encryption Password Not Working

**Error**: "password" or "key" error in logs

**Solution**:
1. Verify encryption is actually configured: `ddev exec drush config:get restic_backup.settings encryption_enabled`
2. Reset password in admin UI: **Restic Backup > Setup > Re-enter password**
3. Try restoring repository config: `restic -r /path/to/repo cat config`

### Large Files Causing Timeout

**Error**: Backup timeout or "subprocess timeout"

**Solution**: Process handles timeouts gracefully. Check:
```bash
# Monitor backup progress
drush restic:stats

# Check recent logs
drush watchdog:show --type=restic_backup --limit=20
```

### No Snapshots After Backup

**Error**: Backup appears to complete but no snapshots listed

**Solutions**:
1. Check repository initialization: `drush restic:validate`
2. Verify repository permissions
3. Check watchdog logs: `drush watchdog:show --type=restic_backup`

### Backup Shows 0 B Size / Input/Output Errors

**Error**: Dashboard shows "Total Size: 0 B" or backup logs show "input/output error" for many files

**Cause**: Restic cannot read files in certain cache directories, particularly:
- `web/sites/default/files/php/twig/` (Twig compiled templates)
- `web/sites/default/files/styles/` (Image style cache)
- `web/sites/default/files/css/` and `web/sites/default/files/js/` (Aggregated assets)

These directories contain auto-generated files with very long filenames and nested structures that can cause filesystem reading issues, especially in Docker/DDEV environments.

**Solution** (RECOMMENDED - Automatic as of version 1.x):

The module now **automatically excludes** problematic cache directories that regenerate automatically:

1. Go to **Restic Backup > Files** to see the exclusions
2. Cache directories are greyed-out with explanations:
   - `web/sites/default/files/php` - Twig template cache (regenerated automatically)
   - `web/sites/default/files/styles` - Image style cache (regenerated on demand)
   - `web/sites/default/files/css` - CSS aggregation cache
   - `web/sites/default/files/js` - JavaScript aggregation cache
3. These exclusions are safe - Drupal regenerates these files automatically

**Verify the fix**:
```bash
# Run backup with auto-exclusions
ddev exec drush restic:backup

# Check snapshot now shows actual file sizes
ddev exec drush restic:stats
```

**Why these exclusions are safe**:
- Twig templates: Regenerated from source `.html.twig` files
- Image styles: Regenerated from original images in `2026-02/` etc.
- CSS/JS: Regenerated from source files when cache is cleared
- Your actual uploaded files (images, PDFs, etc.) are **still backed up**

**For Advanced Users** - Custom exclusions:

The File Selection form also allows adding custom exclusions via the "Additional Exclusions" textarea. Each exclusion should be on its own line, using paths relative to the project root.

## Development & Testing

### DDEV Local Development

This module works seamlessly in DDEV environments for both development and testing.

#### Prerequisites for DDEV

1. **Restic installed in DDEV container** (see Installation section above)
2. **Native Docker bind mounts** (default on Linux, no additional configuration needed)
3. **Repository location**: Use paths inside the container (e.g., `/var/www/html/private/restic-repo`)

#### DDEV vs Production

| Aspect | DDEV (Development) | Production |
|--------|-------------------|------------|
| **Restic Binary** | Install in web container | Install on host system |
| **Repository Path** | `/var/www/html/private/restic-repo` | `/var/backups/drupal-restic` |
| **File Mounting** | Native Docker bind mounts | Native filesystem |
| **Performance** | Slightly slower (container overhead) | Full speed |
| **Testing** | Perfect for testing module functionality | Real backups for production use |

#### Running Backups in DDEV

```bash
# Test configuration
ddev exec drush restic:validate

# Run file discovery scan
ddev exec drush restic:scan

# Initialize repository (first time only)
ddev exec drush restic:init --repo=/var/www/html/private/restic-repo --password=YOUR_PASSWORD

# Perform backup
ddev exec drush restic:backup

# Verify backup succeeded
ddev exec drush restic:snapshots
ddev exec drush restic:stats

# View backup via dashboard
# Visit: /admin/config/system/restic-backup
```

#### DDEV Testing Best Practices

1. **Test with small datasets first**: Start with a limited file set to verify functionality
2. **Use cache exclusions**: The auto-excluded cache directories prevent most filesystem issues
3. **Monitor logs**: Check `ddev exec drush watchdog:show --type=restic_backup` for any errors
4. **Verify snapshots**: Always run `drush restic:snapshots` after backups to confirm success
5. **Test restore**: Verify `drush restic:restore` works with your snapshots

#### Production Deployment

When moving from DDEV to production:

1. **Repository Path**: Update to production path in admin UI or via:
   ```bash
   drush config:set restic_backup.settings repo_path /var/backups/drupal-restic
   ```

2. **File Paths**: Include/exclude paths work identically in both environments (relative to project root)

3. **Cron Jobs**: Schedule backups via Drupal cron or system cron:
   ```bash
   # Drupal cron (recommended)
   drush cron

   # Or system cron (add to crontab)
   0 2 * * * cd /var/www/drupal && drush restic:backup
   ```

4. **Encryption**: Strongly recommended for production - use environment variables:
   ```bash
   # In .env or system environment
   export RESTIC_PASSWORD='your-strong-password'
   ```

5. **Monitoring**: Enable email notifications for failures (see Email Notifications section)

#### Alternative: Host-Based Backups (Production-Only)

For advanced scenarios where Docker/DDEV filesystem issues persist, you can run restic directly on the host:

**Setup**:
1. Install restic on host system: `apt-get install restic` (Ubuntu/Debian)
2. Create repository on host filesystem
3. Run backups from host with absolute paths:
   ```bash
   restic -r /var/backups/drupal-restic \
     backup /var/www/drupal/web/sites/default/files \
     /var/www/drupal/private \
     --exclude="/var/www/drupal/web/sites/default/files/php" \
     --exclude="/var/www/drupal/web/sites/default/files/styles"
   ```

**Note**: This approach bypasses the Drupal module entirely - the module would only be used for configuration management and monitoring. Generally not recommended unless container-based backups are problematic.

## Monitoring & Logs

### View Backup Logs

```bash
# Admin UI
Admin > Configuration > System > Restic Backup > Logs

# Drush
drush watchdog:show --type=restic_backup --limit=50
```

### Email Notifications

Configure email notifications for backup failures:

1. Go to **Restic Backup > Policy & Schedule**
2. Enable "Notify on Failure"
3. Enter notification email address
4. Save configuration

## Performance

### Large Backups

For sites with large file sets:

- Initial backup may take time (restic manages this efficiently)
- Incremental backups are fast (only changed files)
- Enable cron for background processing: **queue runner** will execute backup jobs

### Optimization Tips

- Exclude unnecessary files (regenerable ones are auto-excluded)
- Use local SSD for repository if possible
- For remote repos, ensure good network connectivity
- Set appropriate retention policies to manage storage

## Integration with Other Modules

### Backup & Migrate

This module complements Backup & Migrate for full backup coverage:

- **Backup & Migrate**: Database backups + private files
- **Restic Backup**: File backups (public uploads, config)

Together they provide complete backup coverage.

## Architecture

### Module Structure

```
restic_backup/
├── src/
│   ├── Service/
│   │   ├── ResticManager.php (Restic CLI wrapper)
│   │   ├── FileDiscoveryService.php (File scanning & categorization)
│   │   ├── EncryptionValidator.php (Security checks)
│   │   └── BackupLogger.php (Operation logging)
│   ├── Form/ (Admin configuration forms)
│   ├── Controller/ (Admin page logic)
│   ├── Drush/ (CLI commands)
│   ├── Plugin/QueueWorker/ (Async processing)
│   └── EventSubscriber/ (Cron integration)
├── config/ (Default configuration)
├── templates/ (Twig templates)
└── tests/ (PHPUnit tests)
```

### Design Principles

1. **Fail-Safe Security**: Sensitive files excluded unless encryption confirmed
2. **Intelligent Defaults**: Auto-exclude dependencies, regenerable, and ignored files
3. **User Friendly**: Three-step setup wizard, clear categorization
4. **Production Ready**: Async processing, error handling, comprehensive logging
5. **Extensible**: Services available for custom integrations

## Testing

### Run Tests

```bash
# Unit tests (fast)
ddev exec vendor/bin/phpunit --testsuite unit web/modules/custom/restic_backup/

# Functional tests (with database)
ddev exec vendor/bin/phpunit --testsuite functional web/modules/custom/restic_backup/

# All tests
ddev exec vendor/bin/phpunit web/modules/custom/restic_backup/
```

### Test Coverage

- File discovery algorithm
- Encryption validation
- Restic CLI operations
- Form submissions
- Admin page access

## Contributing

Contributions are welcome! See the spec document for architecture details and development guidelines.

## Support

- **Documentation**: This README + inline code comments
- **Issues**: Report bugs on GitHub
- **Discussion**: See AGENTS.md for AI development context

## License

GPL-2.0-or-later (Drupal standard)

## References

- [Restic Documentation](https://restic.readthedocs.io/)
- [Restic GitHub](https://github.com/restic/restic)
- [Drupal Coding Standards](https://www.drupal.org/docs/develop/standards)
- [Drupal 10+ Security](https://www.drupal.org/docs/develop/security)
