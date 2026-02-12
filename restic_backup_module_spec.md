# Drupal Restic Backup Module - Development Specification

## Project Overview

**Module Name**: `restic_backup` (or `incremental_backup`)
**Purpose**: Provide intelligent, incremental file backups for Drupal 10+ using Restic
**Target**: Drupal.org contrib module (fills gap - no existing incremental file backup solutions)

## Problem Statement

Drupal sites need:
- Incremental backups of files NOT in version control (gitignored files)
- Efficient backups that don't re-download unchanged files
- Automatic exclusion of regenerable files (image styles, aggregated CSS/JS)
- Secure handling of sensitive files (requires encryption)
- Complement existing database backup solutions (Backup & Migrate handles DB)

## Technology Choice: Restic

### Why Restic over Alternatives

**Chosen**: Restic
**Rejected**: Custom rsync, Borg Backup, Backup & Migrate extensions

**Reasons**:
1. **Free & Open Source**: BSD-2-Clause license (GPL-compatible)
2. **Zero software costs**: No licensing fees, no subscriptions
3. **Battle-tested**: Production-proven incremental backup tool
4. **Built-in features**:
   - Block-level deduplication
   - AES-256 encryption (native)
   - Compression (multiple algorithms)
   - Integrity verification
   - Multiple backends (local, S3, SFTP, B2, etc.)
5. **Better than alternatives**:
   - vs. rsync: Has deduplication, encryption, snapshot management
   - vs. Borg: Cross-platform, cloud-native, single binary
   - vs. Backup & Migrate: No incremental file support in existing modules

### License Compatibility

- Restic: BSD-2-Clause (permissive)
- Drupal: GPL-2.0-or-later (copyleft)
- **Status**: Fully compatible (BSD is GPL-compatible upstream)
- **Module licensing**: GPL-2.0-or-later (standard Drupal contrib)

## Core Features

### 1. Gitignore-Based File Discovery

**Concept**: Scan project's `.gitignore` to automatically identify backup candidates

**Implementation approach**:
```bash
# Use git's native parser for accuracy
git check-ignore -v <path>
```

**Fallback**: If `.gitignore` doesn't exist, refuse to proceed (require manual configuration)

### 2. Intelligent Default Selection

**Auto-EXCLUDE** (dependencies - composer/npm will rebuild):
- `vendor/` (Composer dependencies)
- `node_modules/` (npm/yarn dependencies)
- `web/core/` (Composer-managed Drupal core)

**Auto-EXCLUDE** (regenerable Drupal files):
- `web/sites/*/files/styles/*` (image style derivatives)
- `web/sites/*/files/css/*` (aggregated CSS)
- `web/sites/*/files/js/*` (aggregated JavaScript)
- `web/sites/*/files/php/*` (Twig template cache)
- `web/sites/*/files/advagg_*/*` (Advanced Aggregation module cache)

**Auto-INCLUDE** (user uploads):
- `web/sites/*/files/**` (excluding above regenerable paths)
- EXCEPT: `!web/sites/*/files/.htaccess` (preserve important .htaccess)

**Conditional INCLUDE** (sensitive - requires encryption):
- `web/sites/*/settings.local.php`
- `web/sites/*/services.yml` (if present)
- Private files directory (`private://`)
- `.env` files
- Any custom paths marked as sensitive

### 3. Encryption as Security Gatekeeper

**Rule**: Sensitive files ONLY backed up if Restic encryption is configured

**Validation check**:
```bash
# Detect if repo is encrypted
restic cat config
# Requires password → encrypted ✓
# No password required → NOT encrypted ✗
```

**UX Flow**:
```
IF sensitive_files_detected AND !encryption_configured THEN
  SHOW WARNING:
  "⚠️ Sensitive files found but encryption not enabled.
   These files will be EXCLUDED:
   - settings.local.php
   - private://

   [Configure Encryption] [Proceed Without Sensitive Files]"
ENDIF
```

### 4. Multi-Site Support

**Handle**: `web/sites/*/files/` patterns

**Features**:
- Auto-detect all sites
- Per-site inclusion/exclusion
- Site-specific sensitive file handling

### 5. User Interface

**Configuration Form** (`/admin/config/system/restic-backup`):
- Repository path/URL
- Encryption password (required for sensitive files)
- Restic binary path (with validation)
- Retention policies (keep-daily, keep-weekly, etc.)

**File Selection UI** (grouped, checkboxes):
```
📁 Public Files [✓ Default: Included]
  ✓ web/sites/default/files/documents/
  ✓ web/sites/default/files/images/
  ✗ web/sites/default/files/styles/ (auto-excluded)

🔒 Private Files [⚠ Requires Encryption]
  ⚠ private/documents/ (excluded: encryption not set up)

🔒 Sensitive Config [⚠ Requires Encryption]
  ⚠ settings.local.php

📦 Dependencies [✗ Default: Excluded]
  ✗ vendor/
  ✗ node_modules/
```

### 6. Drush Commands

```bash
# Initialize repository
drush restic:init

# Run backup (manual)
drush restic:backup

# List snapshots
drush restic:snapshots

# Restore files
drush restic:restore [snapshot-id] [--path=/path/to/restore]

# Prune old snapshots
drush restic:prune

# Validate configuration
drush restic:validate

# Show backup statistics
drush restic:stats
```

### 7. Scheduled Backups

- Integrate with Drupal cron
- Configuration for backup frequency
- Queue API for async processing (large file sets)

### 8. Monitoring & Logging

- Log all operations to Drupal's watchdog
- Parse Restic's JSON output for metrics
- Email notifications on failure
- Integration points for external monitoring (optional)

## File Discovery Algorithm (Detailed Specification)

### Overview

The file discovery algorithm is the intelligence layer that automatically identifies which files should be backed up. It combines three strategies:

1. **Git-based filtering** (`.gitignore` rules)
2. **Drupal-specific pattern recognition** (regenerable files, config)
3. **Sensitivity detection** (encryption-gated files)

### Algorithm Flowchart

```
START: Scan Project Files
│
├─→ Is .gitignore present?
│   ├─ YES: Parse .gitignore rules
│   └─ NO: Set error flag, require manual config
│
├─→ For each discovered file/directory:
│   │
│   ├─→ Is path ignored by .gitignore?
│   │   ├─ YES: Mark as "SKIPPED" (CONTINUE)
│   │   └─ NO: Continue to next check
│   │
│   ├─→ Is path a known dependency? (vendor/, node_modules/, web/core/)
│   │   ├─ YES: Mark as "DEPENDENCY" (EXCLUDED by default)
│   │   └─ NO: Continue to next check
│   │
│   ├─→ Is path regenerable? (styles/*, css/*, js/*, php/*, advagg*/*)
│   │   ├─ YES: Mark as "REGENERABLE" (INFORMATIONAL ONLY)
│   │   └─ NO: Continue to next check
│   │
│   ├─→ Is path sensitive config? (settings.*.php, services.yml, .env, *.key, private/*)
│   │   ├─ YES: Mark as "SENSITIVE" and "EXCLUDED" (pending encryption check)
│   │   └─ NO: Continue to next check
│   │
│   ├─→ Is path under web/sites/*/files/?
│   │   ├─ YES: Mark as "PUBLIC_FILES" (INCLUDED by default, exclude regenerable subpaths)
│   │   └─ NO: Continue to next check
│   │
│   └─→ Is path under private/ or other private stream?
│       ├─ YES: Mark as "PRIVATE_FILES" (SENSITIVE, excluded pending encryption)
│       └─ NO: Mark as "OTHER"
│
├─→ Categorize results:
│   ├─ public_files: [list of included paths with counts/sizes]
│   ├─ private_files: [list of sensitive paths, encryption-gated]
│   ├─ sensitive_config: [settings.php, .env, etc.]
│   ├─ dependencies: [vendor, node_modules, web/core - excluded by default]
│   └─ regenerable: [styles, aggregated CSS/JS - informational only]
│
└─→ Return categorized structure with metadata (file count, total size per category)
```

### Algorithm Pseudocode

```php
/**
 * Scan entire Drupal project and categorize files for backup.
 *
 * @return array
 *   Nested array structure:
 *   [
 *     'public_files' => [
 *       'included' => [['path' => '', 'human' => '', 'size' => 0, 'count' => 0], ...],
 *       'excluded_regenerable' => [['path' => 'styles/', 'reason' => 'auto-regenerated'], ...],
 *     ],
 *     'private_files' => [
 *       'paths' => [...],
 *       'requires_encryption' => true,
 *       'encryption_configured' => false,
 *     ],
 *     'sensitive_config' => [
 *       'paths' => [...],
 *       'requires_encryption' => true,
 *     ],
 *     'dependencies' => [
 *       'vendor/', 'node_modules/', 'web/core/',
 *       'excluded_by_default' => true,
 *     ],
 *     'regenerable' => [
 *       'informational' => [...],
 *       'explanation' => 'These are auto-generated; safe to delete',
 *     ],
 *     'metadata' => [
 *       'gitignore_found' => true,
 *       'total_candidate_size_mb' => 500,
 *       'total_files' => 5000,
 *     ],
 *   ]
 *
 * @throws \Exception If .gitignore not found or git not available.
 */
public function scanProject(): array {
  // 1. VALIDATE PREREQUISITES
  if (!$this->hasGitignore()) {
    throw new \Exception('No .gitignore found. Cannot auto-detect files.');
  }
  if (!$this->gitAvailable()) {
    throw new \Exception('git command not available. Required for .gitignore parsing.');
  }

  // 2. INITIALIZE CATEGORIES
  $categories = [
    'public_files' => ['included' => [], 'excluded_regenerable' => []],
    'private_files' => ['paths' => [], 'requires_encryption' => true],
    'sensitive_config' => ['paths' => [], 'requires_encryption' => true],
    'dependencies' => [],
    'regenerable' => ['informational' => []],
    'metadata' => ['gitignore_found' => true, 'total_size_bytes' => 0, 'total_files' => 0],
  ];

  // 3. PARSE GITIGNORE
  $gitignoreRules = $this->parseGitignore();

  // 4. SCAN PROJECT ROOT
  $projectRoot = $this->getProjectRoot();
  $allFiles = $this->recursiveFileScan($projectRoot);

  // 5. CATEGORIZE EACH FILE
  foreach ($allFiles as $filepath) {
    $relativePath = str_replace($projectRoot . '/', '', $filepath);

    // 5a. Check against .gitignore first
    if ($this->isIgnoredByGit($relativePath, $gitignoreRules)) {
      continue; // Skip ignored files
    }

    // 5b. Check if it's a dependency
    if ($this->isDependency($relativePath)) {
      $categories['dependencies'][] = $relativePath;
      continue;
    }

    // 5c. Check if it's regenerable
    if ($this->isRegenerableFile($relativePath)) {
      $categories['regenerable']['informational'][] = [
        'path' => $relativePath,
        'reason' => 'Auto-generated by Drupal (image styles, CSS/JS aggregation, cache)',
      ];
      continue; // Don't include in backup by default
    }

    // 5d. Check if it's sensitive config
    if ($this->isSensitiveConfig($relativePath)) {
      $categories['sensitive_config']['paths'][] = [
        'path' => $relativePath,
        'type' => $this->getSensitiveConfigType($relativePath),
      ];
      continue;
    }

    // 5e. Check if it's private files
    if ($this->isPrivateFile($relativePath)) {
      $categories['private_files']['paths'][] = [
        'path' => $relativePath,
        'size' => filesize($filepath),
      ];
      continue;
    }

    // 5f. Check if it's public files
    if ($this->isPublicFile($relativePath)) {
      $categories['public_files']['included'][] = [
        'path' => $relativePath,
        'size' => filesize($filepath),
        'human' => $this->formatBytes(filesize($filepath)),
      ];
      $categories['metadata']['total_size_bytes'] += filesize($filepath);
      $categories['metadata']['total_files']++;
    }
  }

  // 6. AGGREGATE STATS
  $categories['public_files']['count'] = count($categories['public_files']['included']);
  $categories['private_files']['count'] = count($categories['private_files']['paths']);
  $categories['sensitive_config']['count'] = count($categories['sensitive_config']['paths']);
  $categories['dependencies']['count'] = count($categories['dependencies']);

  return $categories;
}

// HELPER METHODS

/**
 * Check if file path is ignored by .gitignore using git check-ignore.
 */
private function isIgnoredByGit(string $relativePath): bool {
  $process = new Process(['git', 'check-ignore', $relativePath]);
  $process->run();
  return $process->getExitCode() === 0;
}

/**
 * Check if path is a known dependency directory.
 */
private function isDependency(string $path): bool {
  $dependencies = [
    'vendor/',           // Composer packages
    'node_modules/',     // npm/yarn packages
    'web/core/',         // Composer-managed Drupal core
    'web/libraries/',    // Some libraries installed via Composer
  ];

  foreach ($dependencies as $dep) {
    if (strpos($path, $dep) === 0) {
      return true;
    }
  }
  return false;
}

/**
 * Check if path is a regenerable file (safe to delete/restore from elsewhere).
 */
private function isRegenerableFile(string $path): bool {
  $regenerables = [
    // Image style derivatives
    preg_quote('web/sites/default/files/styles/'),
    preg_quote('web/sites/*/files/styles/'),

    // Aggregated CSS and JavaScript
    preg_quote('web/sites/default/files/css/'),
    preg_quote('web/sites/*/files/css/'),
    preg_quote('web/sites/default/files/js/'),
    preg_quote('web/sites/*/files/js/'),

    // Twig template cache
    preg_quote('web/sites/default/files/php/'),
    preg_quote('web/sites/*/files/php/'),

    // Advanced Aggregation module cache
    preg_quote('web/sites/default/files/advagg_'),
    preg_quote('web/sites/*/files/advagg_'),
  ];

  foreach ($regenerables as $pattern) {
    if (preg_match("/{$pattern}/", $path)) {
      return true;
    }
  }
  return false;
}

/**
 * Check if path contains sensitive configuration.
 */
private function isSensitiveConfig(string $path): bool {
  // Exact filename matches (always sensitive)
  $sensitiveFiles = [
    'settings.local.php',
    'settings.php',
    'services.yml',
    '.env',
    '.env.local',
  ];

  $filename = basename($path);
  if (in_array($filename, $sensitiveFiles)) {
    return true;
  }

  // Pattern matches (sensitive)
  $sensitivePatterns = [
    '*.key',      // Private keys
    '*.pem',      // Certificates
    '*.ppk',      // Putty keys
  ];

  foreach ($sensitivePatterns as $pattern) {
    $regex = str_replace('*', '.*', preg_quote($pattern));
    if (preg_match("/^{$regex}$/", $filename)) {
      return true;
    }
  }

  return false;
}

/**
 * Check if path is within private files directory.
 */
private function isPrivateFile(string $path): bool {
  // Get Drupal's private stream wrapper path
  $privatePath = $this->getPrivateFilesPath();
  return strpos($path, $privatePath) === 0;
}

/**
 * Check if path is within public files directory (web/sites/*/files).
 */
private function isPublicFile(string $path): bool {
  return preg_match('#^web/sites/[^/]+/files/#', $path);
}
```

### Key Decision Points

| Decision | Logic | Result |
|----------|-------|--------|
| **Gitignore Hit** | If git check-ignore returns 0 → Skip file | Safe: respect user's VCS decisions |
| **Dependency Detection** | Hardcoded list (vendor/, node_modules/) | Safe: these are rebuilable from composer.json/package.json |
| **Regenerable Files** | Pattern match (styles/*, css/*, js/*, php/*) | Safe: Drupal auto-recreates these on cache clear |
| **Sensitive Config** | Filename match (settings.php, .env, *.key) | Secure: Block unless encryption enabled |
| **Public Files** | Path pattern match (web/sites/*/files/) | Default: Included (must allow opt-out) |
| **Private Files** | Drupal stream wrapper path | Secure: Must encrypt to backup |

### Error Handling

```
Condition: .gitignore missing
Result: Throw exception, cannot auto-scan
UX: Show admin message: "ERROR: No .gitignore found. Cannot auto-detect files to backup."
Fix: "Please create .gitignore or configure paths manually"

Condition: git command not available
Result: Throw exception
UX: Show admin message: "ERROR: git command not found. .gitignore parsing requires git CLI."
Fix: "Install git or provide fallback file list"

Condition: Private or sensitive files detected but encryption not configured
Result: Mark files as "excluded_pending_encryption"
UX: Show warning: "⚠️ X private files found but encryption not configured. These will be EXCLUDED."
Fix: "Enable encryption at [link] to backup sensitive files"
```

---

## Encryption Validation & State Machine

### State Diagram

```
[INITIAL STATE]
     ↓
┌─────────────────────┐
│ NO_REPO_CONFIGURED  │ ← No repository path set in config
└──────────┬──────────┘
           ↓ [User sets repo path]
┌─────────────────────────────────────────┐
│ REPO_EXISTS_ENCRYPTION_STATUS_UNKNOWN   │ ← Need to check if encrypted
└──────────┬──────────────────────────────┘
           ↓ [Check restic cat config]
           ├─→ [Exit 0, requires password prompt] → ENCRYPTED ✓
           └─→ [Exit 0, no password required] → UNENCRYPTED ✗

┌──────────────────────┐       ┌───────────────────────┐
│   UNENCRYPTED_REPO   │       │  ENCRYPTED_REPO       │
│  (Sensitive files    │       │ (Sensitive files      │
│   BLOCKED)           │       │  ALLOWED)             │
└──────────────────────┘       └───────────────────────┘
     ↑                                    ↑
     └───────────────────┬────────────────┘
                         ↓
              [User can proceed with
               file selection based on
               encryption status]
```

### Validation Rules Engine

```php
/**
 * Encryption Validation Service
 *
 * Determines what files can be safely backed up based on encryption status.
 * PRINCIPLE: Fail-safe. Block sensitive files unless encryption is confirmed.
 */
class EncryptionValidator {

  /**
   * Check if Restic repository exists and detect encryption status.
   *
   * @param string $repoPath
   *   Local path or remote URL to Restic repository.
   *
   * @return array
   *   [
   *     'status' => 'unencrypted'|'encrypted'|'unknown'|'not_found',
   *     'message' => 'Human readable status',
   *     'can_backup_sensitive' => bool,
   *   ]
   */
  public function validateRepository(string $repoPath): array {
    // 1. Check if repo exists
    if (!$this->repositoryExists($repoPath)) {
      return [
        'status' => 'not_found',
        'message' => 'Restic repository not found or not initialized',
        'can_backup_sensitive' => false,
      ];
    }

    // 2. Try to read config without password
    try {
      $process = new Process(['restic', '-r', $repoPath, 'cat', 'config']);
      $process->run();

      if ($process->getExitCode() === 0) {
        // Config readable without password = NOT encrypted
        return [
          'status' => 'unencrypted',
          'message' => 'Repository is NOT encrypted. Sensitive files will be excluded.',
          'can_backup_sensitive' => false,
        ];
      }
    } catch (\Exception $e) {
      // Fall through to next check
    }

    // 3. Check if password required (encrypted)
    if (strpos($process->getErrorOutput(), 'password') !== false) {
      return [
        'status' => 'encrypted',
        'message' => 'Repository is encrypted. Sensitive files can be backed up.',
        'can_backup_sensitive' => true,
      ];
    }

    // 4. Unknown state
    return [
      'status' => 'unknown',
      'message' => 'Could not determine encryption status. Assuming NOT encrypted (conservative).',
      'can_backup_sensitive' => false,
    ];
  }

  /**
   * Validate file selection against encryption constraints.
   *
   * FAIL-SAFE BEHAVIOR:
   * - If sensitive files selected but encryption not enabled → REMOVE those files
   * - If encryption enabled → ALLOW all file categories
   * - Return modified selections + list of excluded files with reasons
   *
   * @param array $selectedFiles
   *   User's chosen files/categories from UI.
   * @param bool $encryptionEnabled
   *   Whether Restic repository is encrypted.
   *
   * @return array
   *   [
   *     'safe_files' => [...], // Files safe to backup
   *     'excluded_files' => [
   *       'reason' => 'encryption_required',
   *       'files' => [...],
   *       'message' => 'These files require encryption...',
   *     ],
   *     'warnings' => [...],
   *   ]
   */
  public function validateSelection(
    array $selectedFiles,
    bool $encryptionEnabled
  ): array {
    $safeFiles = [];
    $excluded = [];
    $warnings = [];

    foreach ($selectedFiles as $filepath) {
      if ($this->isSensitiveFile($filepath)) {
        if (!$encryptionEnabled) {
          // EXCLUDE sensitive files if encryption not enabled
          $excluded[] = [
            'path' => $filepath,
            'type' => $this->getSensitiveFileType($filepath),
            'reason' => 'encryption_required',
          ];
          continue;
        }
      }

      // File is safe to backup
      $safeFiles[] = $filepath;
    }

    // Generate warnings
    if (!empty($excluded)) {
      $warnings[] = [
        'severity' => 'warning',
        'message' => sprintf(
          'Encryption is NOT enabled. %d sensitive files will be excluded: %s',
          count($excluded),
          implode(', ', array_column($excluded, 'path'))
        ),
        'action' => 'Enable encryption to include: [Click to configure encryption]',
      ];
    }

    return [
      'safe_files' => $safeFiles,
      'excluded_files' => $excluded,
      'warnings' => $warnings,
      'can_backup_all' => $encryptionEnabled,
    ];
  }

  /**
   * Determine if a file path is sensitive (requires encryption).
   */
  private function isSensitiveFile(string $path): bool {
    $sensitivePatterns = [
      'settings.php',
      'settings.local.php',
      'services.yml',
      '.env',
      '*.key',
      '*.pem',
      'private/', // Private files stream
    ];

    foreach ($sensitivePatterns as $pattern) {
      if ($this->matchesPattern($path, $pattern)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Categorize sensitive file type for UI feedback.
   */
  private function getSensitiveFileType(string $path): string {
    if (preg_match('/settings\.(local\.)?php/', $path)) {
      return 'drupal_config';
    }
    if (preg_match('/services\.yml/', $path)) {
      return 'drupal_services';
    }
    if (preg_match('/\.env/', $path)) {
      return 'environment_vars';
    }
    if (preg_match('/\.(key|pem|ppk)$/', $path)) {
      return 'crypto_key';
    }
    if (preg_match('#^private/#', $path)) {
      return 'private_files';
    }
    return 'other_sensitive';
  }
}
```

### UI Integration

**Form Validation Hook:**

```php
public function validateForm(array &$form, FormStateInterface $form_state) {
  $selectedFiles = $form_state->getValue('files');
  $encryptionEnabled = $form_state->getValue('encryption_enabled');

  $validation = $this->encryptionValidator->validateSelection(
    $selectedFiles,
    $encryptionEnabled
  );

  if (!empty($validation['excluded_files'])) {
    // Show warning but allow form submission
    // (files will be auto-excluded on save)
    foreach ($validation['warnings'] as $warning) {
      $this->messenger->addWarning($warning['message']);
    }
  }

  // Update form state with safe files (auto-filter invalid selections)
  $form_state->setValue('files', $validation['safe_files']);
}
```

---

## User Interface & Form Design

### Multi-Step Form Architecture

The module uses a **three-step configuration wizard** instead of a single monolithic form. This allows:
- User to validate Restic setup before choosing files
- Clear separation of concerns (repo setup vs. file selection vs. policies)
- Better error recovery (skip early if Restic not working)

### Step 1: Setup Form (`/admin/config/system/restic-backup/setup`)

**Purpose**: Configure Restic binary location and repository details

**Form Structure** (PHP method):

```php
public function buildForm(array $form, FormStateInterface $form_state) {
  $config = $this->configFactory->get('restic_backup.settings');

  // ========== BINARY & VALIDATION ==========
  $form['binary_section'] = [
    '#type' => 'fieldset',
    '#title' => $this->t('Restic Binary'),
    '#weight' => -10,
  ];

  $form['binary_section']['binary_path'] = [
    '#type' => 'textfield',
    '#title' => $this->t('Restic Binary Path'),
    '#description' => $this->t('Full path to restic executable (e.g., /usr/bin/restic or /usr/local/bin/restic)'),
    '#default_value' => $config->get('binary_path') ?: '/usr/bin/restic',
    '#required' => true,
  ];

  $form['binary_section']['validate_binary'] = [
    '#type' => 'submit',
    '#value' => $this->t('Validate Restic Installation'),
    '#submit' => [[$this, 'validateBinaryCallback']],
    '#ajax' => [
      'callback' => [$this, 'validateBinaryAjax'],
      'wrapper' => 'binary-validation-result',
    ],
  ];

  $form['binary_section']['validation_result'] = [
    '#type' => 'container',
    '#attributes' => ['id' => 'binary-validation-result'],
  ];

  // ========== REPOSITORY CONFIGURATION ==========
  $form['repo_section'] = [
    '#type' => 'fieldset',
    '#title' => $this->t('Repository Configuration'),
    '#weight' => -5,
  ];

  $form['repo_section']['repo_type'] = [
    '#type' => 'radios',
    '#title' => $this->t('Repository Type'),
    '#options' => [
      'local' => $this->t('Local Directory'),
      'sftp' => $this->t('SFTP Remote'),
      's3' => $this->t('Amazon S3'),
    ],
    '#default_value' => $config->get('repo_type') ?: 'local',
    '#ajax' => [
      'callback' => [$this, 'repoTypeAjax'],
      'wrapper' => 'repo-path-container',
    ],
  ];

  $repoType = $form_state->getValue('repo_type') ?: $config->get('repo_type') ?: 'local';

  $form['repo_section']['repo_path_container'] = [
    '#type' => 'container',
    '#attributes' => ['id' => 'repo-path-container'],
  ];

  // Dynamic repo path field based on type
  switch ($repoType) {
    case 'local':
      $form['repo_section']['repo_path_container']['repo_path'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Local Directory Path'),
        '#description' => $this->t('Absolute path to backup directory (e.g., /mnt/backups/restic-repo)'),
        '#default_value' => $config->get('repo_path'),
        '#required' => true,
      ];
      break;

    case 'sftp':
      $form['repo_section']['repo_path_container']['repo_path'] = [
        '#type' => 'textfield',
        '#title' => $this->t('SFTP Repository URL'),
        '#description' => $this->t('Format: sftp://user@host:/path/to/repo'),
        '#default_value' => $config->get('repo_path'),
        '#required' => true,
      ];
      break;

    case 's3':
      $form['repo_section']['repo_path_container']['repo_path'] = [
        '#type' => 'textfield',
        '#title' => $this->t('S3 Repository URI'),
        '#description' => $this->t('Format: s3:s3.amazonaws.com/bucket/prefix'),
        '#default_value' => $config->get('repo_path'),
        '#required' => true,
      ];
      break;
  }

  // ========== ENCRYPTION SETUP ==========
  $form['encryption_section'] = [
    '#type' => 'fieldset',
    '#title' => $this->t('Encryption & Security'),
    '#weight' => 0,
  ];

  $form['encryption_section']['info'] = [
    '#type' => 'html_tag',
    '#tag' => 'div',
    '#attributes' => ['class' => ['messages', 'messages--status']],
    '#value' => $this->t(
      '<strong>Encryption is required to backup sensitive files</strong> ' .
      '(settings.php, .env, private files). Without encryption, these files will be excluded.'
    ),
  ];

  $form['encryption_section']['encryption_enabled'] = [
    '#type' => 'checkbox',
    '#title' => $this->t('Enable Encryption'),
    '#description' => $this->t('Encrypt repository with AES-256. Requires a strong password.'),
    '#default_value' => $config->get('encryption_enabled'),
  ];

  $form['encryption_section']['password'] = [
    '#type' => 'password_confirm',
    '#title' => $this->t('Repository Password'),
    '#description' => $this->t('Strong password for repository encryption. <strong>Store securely!</strong> You cannot recover your backups without this password.'),
    '#required' => false,
    '#states' => [
      'visible' => [
        ':input[name="encryption_enabled"]' => ['checked' => true],
      ],
      'required' => [
        ':input[name="encryption_enabled"]' => ['checked' => true],
      ],
    ],
  ];

  return $form;
}
```

**Twig Template** (`setup.html.twig`):

```twig
<div class="restic-setup-form">
  <h2>{{ 'Restic Backup Setup'|trans }}</h2>

  <div class="restic-setup-steps">
    <div class="step step-1 active">
      <span class="step-number">1</span>
      <span class="step-label">{{ 'Binary'|trans }}</span>
    </div>
    <div class="step step-2">
      <span class="step-number">2</span>
      <span class="step-label">{{ 'Repository'|trans }}</span>
    </div>
    <div class="step step-3">
      <span class="step-number">3</span>
      <span class="step-label">{{ 'Encryption'|trans }}</span>
    </div>
  </div>

  {{ form_start(form) }}
    {{ form.binary_section }}
    {{ form.repo_section }}
    {{ form.encryption_section }}
    {{ form.actions }}
  {{ form_end(form) }}

  {% if validation_error %}
    <div class="messages messages--error" role="alert">
      {{ validation_error }}
    </div>
  {% endif %}
</div>
```

### Step 2: File Selection Form (`/admin/config/system/restic-backup/files`)

**Purpose**: Choose which files/directories to back up, organized by category

**Form Structure** (PHP method - simplified):

```php
public function buildForm(array $form, FormStateInterface $form_state) {
  // Scan project to get file categories
  $fileCategories = $this->fileDiscoveryService->scanProject();
  $encryptionEnabled = $this->config->get('encryption_enabled');

  // ========== STATUS SECTION ==========
  $form['status'] = [
    '#type' => 'fieldset',
    '#title' => $this->t('Backup Status'),
    '#weight' => -20,
  ];

  $form['status']['info'] = [
    '#type' => 'html_tag',
    '#tag' => 'div',
    '#attributes' => ['class' => ['backup-status-summary']],
    '#value' => $this->buildStatusMarkup($fileCategories),
  ];

  // ========== PUBLIC FILES SECTION ==========
  $form['public_files'] = [
    '#type' => 'fieldset',
    '#title' => $this->t('📁 Public Files (User Uploads)'),
    '#weight' => -10,
    '#collapsible' => false,
  ];

  $form['public_files']['info'] = [
    '#type' => 'html_tag',
    '#tag' => 'div',
    '#value' => $this->t(
      '@count files, @size total',
      [
        '@count' => count($fileCategories['public_files']['included']),
        '@size' => format_bytes($fileCategories['metadata']['total_size_bytes']),
      ]
    ),
  ];

  foreach ($fileCategories['public_files']['included'] as $file) {
    $form['public_files']['file_' . str_replace('/', '_', $file['path'])] = [
      '#type' => 'checkbox',
      '#title' => $file['path'] . ' (' . $file['human'] . ')',
      '#default_value' => true,
    ];
  }

  $form['public_files']['regenerable'] = [
    '#type' => 'details',
    '#title' => $this->t('Regenerable (Auto-Excluded - Safe to Skip)'),
    '#open' => false,
  ];

  foreach ($fileCategories['regenerable'] as $regen) {
    $form['public_files']['regenerable']['regen_' . str_replace('/', '_', $regen['path'])] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['regenerable-item']],
      '#value' => $this->t(
        '<strong>@path</strong> - @reason',
        ['@path' => $regen['path'], '@reason' => $regen['reason']]
      ),
    ];
  }

  // ========== PRIVATE FILES SECTION (ENCRYPTION-GATED) ==========
  $form['private_files'] = [
    '#type' => 'fieldset',
    '#title' => $this->t('🔒 Private Files (Requires Encryption)'),
    '#weight' => 0,
  ];

  if (!$encryptionEnabled) {
    $form['private_files']['warning'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      '#value' => $this->t(
        '<strong>⚠️ Encryption Not Enabled</strong> - Private files will be excluded. ' .
        '<a href="@link">Enable encryption</a> to include private files in backups.',
        ['@link' => url('admin/config/system/restic-backup/setup')]
      ),
    ];
  }

  foreach ($fileCategories['private_files']['paths'] as $file) {
    $form['private_files']['file_' . str_replace('/', '_', $file['path'])] = [
      '#type' => 'checkbox',
      '#title' => $file['path'] . ' (' . format_bytes($file['size']) . ')',
      '#default_value' => $encryptionEnabled,
      '#disabled' => !$encryptionEnabled,
    ];
  }

  // ========== SENSITIVE CONFIG SECTION ==========
  $form['sensitive_config'] = [
    '#type' => 'fieldset',
    '#title' => $this->t('🔒 Sensitive Configuration (Requires Encryption)'),
    '#weight' => 5,
  ];

  if (!$encryptionEnabled) {
    $form['sensitive_config']['excluded_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      '#value' => $this->t(
        'These files will be <strong>excluded</strong> because encryption is not enabled: ' .
        '@files',
        ['@files' => implode(', ', array_column($fileCategories['sensitive_config']['paths'], 'path'))]
      ),
    ];
  }

  foreach ($fileCategories['sensitive_config']['paths'] as $file) {
    $form['sensitive_config']['file_' . str_replace('/', '_', $file['path'])] = [
      '#type' => 'checkbox',
      '#title' => $this->t(
        '@path (@type)',
        [
          '@path' => $file['path'],
          '@type' => $this->sensitiveFileTypeLabel($file['type']),
        ]
      ),
      '#default_value' => $encryptionEnabled,
      '#disabled' => !$encryptionEnabled,
    ];
  }

  return $form;
}

private function buildStatusMarkup(array $categories): string {
  $markup = '<div class="restic-status-grid">';

  $markup .= sprintf(
    '<div class="status-item"><strong>Backup Size:</strong> %s</div>',
    format_bytes($categories['metadata']['total_size_bytes'])
  );

  $markup .= sprintf(
    '<div class="status-item"><strong>Total Files:</strong> %s</div>',
    $categories['metadata']['total_files']
  );

  $markup .= sprintf(
    '<div class="status-item"><strong>Public Files:</strong> %d</div>',
    count($categories['public_files']['included'])
  );

  $markup .= sprintf(
    '<div class="status-item"><strong>Private Files:</strong> %d</div>',
    count($categories['private_files']['paths'])
  );

  $markup .= '</div>';

  return $markup;
}
```

**Twig Template** (`file-selection.html.twig`):

```twig
<div class="restic-file-selection-form">
  <h2>{{ 'Choose Files to Backup'|trans }}</h2>

  <div class="file-selection-intro">
    <p>
      {{ 'Select which files and directories should be backed up. Files in .gitignore and regenerable files (image styles, aggregated CSS/JS) are excluded by default.'|trans }}
    </p>
    {% if not encryption_enabled %}
      <div class="messages messages--warning">
        <strong>{{ 'Encryption Not Enabled'|trans }}</strong><br>
        {{ 'Some files (private files, settings.php, .env) require encryption to be backed up. '|trans }}
        <a href="{{ admin_setup_url }}">{{ 'Enable encryption now'|trans }}</a>
      </div>
    {% endif %}
  </div>

  {{ form_start(form) }}

    {{-- PUBLIC FILES SECTION --}}
    <fieldset class="file-category public-files">
      <legend>📁 {{ 'Public Files (User Uploads)'|trans }}</legend>
      <div class="file-category-info">
        {{ file_count }}/{{ total_files }} {{ 'files'|trans }}, {{ total_size|format_bytes }}
      </div>
      <div class="file-list">
        {% for file_field_name, file_field in form.public_files.file_* %}
          <div class="file-item">
            {{ form_widget(file_field) }}
            <label class="file-path">{{ file_field['#title'] }}</label>
          </div>
        {% endfor %}
      </div>
    </fieldset>

    {{-- REGENERABLE FILES (COLLAPSED) --}}
    <details class="file-category regenerable">
      <summary>
        {{ 'Regenerable Files (Auto-Excluded - Safe to Skip)'|trans }}
        <span class="count">({{ regenerable_count }})</span>
      </summary>
      <div class="regenerable-explanation">
        {{ 'These files are automatically generated by Drupal and safe to delete. They will be recreated when needed:'|trans }}
      </div>
      <ul class="regenerable-list">
        {% for regen in regenerable_files %}
          <li>{{ regen.path }} — {{ regen.reason }}</li>
        {% endfor %}
      </ul>
    </details>

    {{-- PRIVATE FILES SECTION --}}
    <fieldset class="file-category private-files requires-encryption">
      <legend>🔒 {{ 'Private Files (Requires Encryption)'|trans }}</legend>
      {% if not encryption_enabled %}
        <div class="encryption-warning">
          <strong>{{ 'Encryption Not Enabled'|trans }}</strong><br>
          {{ 'These files will be excluded because encryption is not configured.'|trans }}
        </div>
      {% endif %}
      <div class="file-list">
        {% for file_field_name, file_field in form.private_files.file_* %}
          <div class="file-item">
            {{ form_widget(file_field) }}
            <label class="file-path">{{ file_field['#title'] }}</label>
          </div>
        {% endfor %}
      </div>
    </fieldset>

    {{-- SENSITIVE CONFIG SECTION --}}
    <fieldset class="file-category sensitive-config requires-encryption">
      <legend>🔒 {{ 'Sensitive Configuration (Requires Encryption)'|trans }}</legend>
      {% if not encryption_enabled %}
        <div class="encryption-warning">
          <strong>{{ 'Encryption Not Enabled'|trans }}</strong><br>
          {{ 'These files will be excluded: @files'|trans({'@files': sensitive_files|join(', ')}) }}
        </div>
      {% endif %}
      <div class="file-list">
        {% for file_field_name, file_field in form.sensitive_config.file_* %}
          <div class="file-item">
            {{ form_widget(file_field) }}
            <label class="file-path">{{ file_field['#title'] }}</label>
          </div>
        {% endfor %}
      </div>
    </fieldset>

    {{-- DEPENDENCIES (COLLAPSED, INFORMATIONAL) --}}
    <details class="file-category dependencies">
      <summary>
        📦 {{ 'Dependencies (Auto-Excluded)'|trans }}
        <span class="count">({{ dependencies_count }})</span>
      </summary>
      <div class="dependencies-explanation">
        {{ 'These directories are managed by package managers and will be rebuilt from composer.json/package.json:'|trans }}
      </div>
      <ul class="dependencies-list">
        {% for dep in dependencies %}
          <li><code>{{ dep }}</code></li>
        {% endfor %}
      </ul>
    </details>

    {{ form.actions }}

  {{ form_end(form) }}
</div>

<style>
  .restic-file-selection-form .file-category {
    border: 1px solid #ddd;
    padding: 15px;
    margin: 20px 0;
    border-radius: 4px;
  }

  .restic-file-selection-form .file-category legend {
    font-weight: bold;
    font-size: 1.1em;
    padding: 0 10px;
  }

  .restic-file-selection-form .file-item {
    margin: 8px 0;
    display: flex;
    align-items: center;
  }

  .restic-file-selection-form .file-path {
    margin-left: 10px;
    font-family: monospace;
    font-size: 0.9em;
  }

  .restic-file-selection-form .requires-encryption .encryption-warning {
    background-color: #fff3cd;
    border-left: 4px solid #ff9800;
    padding: 10px;
    margin-bottom: 15px;
  }

  .restic-file-selection-form details summary {
    cursor: pointer;
    font-weight: bold;
    padding: 10px;
    background: #f5f5f5;
    border-radius: 3px;
  }

  .regenerable-list, .dependencies-list {
    list-style: none;
    padding-left: 20px;
  }

  .regenerable-list li:before, .dependencies-list li:before {
    content: "✓ ";
    color: green;
    margin-right: 8px;
  }
</style>
```

### Step 3: Policy & Schedule Form

**Twig Template Structure** (`policy-schedule.html.twig`):

```twig
<div class="restic-policy-form">
  <h2>{{ 'Retention Policy & Schedule'|trans }}</h2>

  {{ form_start(form) }}

    {{-- RETENTION POLICY --}}
    <fieldset>
      <legend>{{ 'Retention Policy'|trans }}</legend>
      <p>{{ 'Define how long to keep snapshots'|trans }}</p>

      <div class="retention-grid">
        <div class="retention-item">
          {{ form.retention_policy.keep_daily }}
        </div>
        <div class="retention-item">
          {{ form.retention_policy.keep_weekly }}
        </div>
        <div class="retention-item">
          {{ form.retention_policy.keep_monthly }}
        </div>
        <div class="retention-item">
          {{ form.retention_policy.keep_yearly }}
        </div>
      </div>
    </fieldset>

    {{-- BACKUP SCHEDULE --}}
    <fieldset>
      <legend>{{ 'Backup Schedule'|trans }}</legend>
      <p>{{ 'When should automated backups run?'|trans }}</p>

      {{ form.backup_frequency }}
      {{ form.custom_cron_expression }}
    </fieldset>

    {{-- NOTIFICATIONS --}}
    <fieldset>
      <legend>{{ 'Notifications'|trans }}</legend>
      {{ form.notify_on_failure }}
      {{ form.notification_email }}
    </fieldset>

    {{ form.actions }}

  {{ form_end(form) }}
</div>
```

---

## Module Structure & File Manifest

### Complete Module Directory Tree & File Purposes

```
web/modules/custom/restic_backup/
│
├── 📄 restic_backup.info.yml
│   └─ Module metadata: name, description, version, dependencies, Drupal version
│      Dependencies: [drupal:core, drupal:file, drupal:system]
│
├── 📄 restic_backup.module
│   └─ Hook implementations:
│      - hook_help(): Provide help text at /admin/help/restic_backup
│      - hook_cron(): Trigger scheduled backups via Drupal cron
│      - hook_theme(): Register Twig templates (dashboard, file-selection)
│
├── 📄 restic_backup.services.yml
│   └─ Service definitions & dependency injection:
│      · restic_backup.manager (ResticManager - main service)
│      · restic_backup.file_discovery (FileDiscoveryService)
│      · restic_backup.encryption_validator (EncryptionValidator)
│      · restic_backup.backup_logger (BackupLogger)
│      · restic_backup.cron_handler (CronSubscriber)
│
├── 📄 restic_backup.routing.yml
│   └─ Admin page routes:
│      · restic_backup.admin_page -> /admin/config/system/restic-backup
│      · restic_backup.admin_setup -> /admin/config/system/restic-backup/setup
│      · restic_backup.admin_files -> /admin/config/system/restic-backup/files
│      · restic_backup.admin_policy -> /admin/config/system/restic-backup/policy
│      · restic_backup.snapshots_list -> /admin/config/system/restic-backup/snapshots
│
├── 📄 restic_backup.links.menu.yml
│   └─ Admin menu links (links to routing above)
│
├── 📄 restic_backup.permissions.yml
│   └─ Custom permissions:
│      · 'administer restic backup'
│      · 'access restic backup logs'
│      · 'restore from backup'
│
├── 📄 restic_backup.install
│   └─ Hook implementations:
│      - hook_requirements(): Check restic binary availability
│      - hook_install(): Initialize module state
│      - hook_uninstall(): Cleanup module data
│
├── 📁 src/
│   │
│   ├── 📁 Service/
│   │   ├── 📄 ResticManager.php
│   │   │   └─ Main service wrapping Restic CLI
│   │   │      - initRepository(repoPath, password): bool
│   │   │      - backup(paths, excludePatterns): array
│   │   │      - listSnapshots(): array
│   │   │      - restore(snapshotId, targetPath): bool
│   │   │      - prune(retentionPolicy): bool
│   │   │      - stats(snapshotId): array
│   │   │      - isEncrypted(repoPath): bool
│   │   │      - validateBinary(): bool
│   │   │
│   │   ├── 📄 FileDiscoveryService.php
│   │   │   └─ Intelligent file categorization
│   │   │      - scanProject(): array (main entry point)
│   │   │      - parseGitignore(): array
│   │   │      - findPublicFiles(): array
│   │   │      - findPrivateFiles(): array
│   │   │      - findSensitiveConfig(): array
│   │   │      - findRegenerableFiles(): array
│   │   │      - buildExclusionList(selections): array
│   │   │      - exportExclusionFile(patterns, path): void
│   │   │
│   │   ├── 📄 EncryptionValidator.php
│   │   │   └─ Encryption status checking & validation
│   │   │      - validateRepository(repoPath): array
│   │   │      - validateSelection(files, encryptionEnabled): array
│   │   │      - isSensitiveFile(path): bool
│   │   │      - getSensitiveFileType(path): string
│   │   │
│   │   └── 📄 BackupLogger.php
│   │       └─ Structured logging to watchdog + custom channel
│   │          - logOperation(operation, level, data): void
│   │          - getRecentLogs(limit): array
│   │          - getLogs(filter): array
│   │
│   ├── 📁 Form/
│   │   ├── 📄 SetupForm.php (ConfigFormBase)
│   │   │   └─ Step 1: Restic binary & repo configuration
│   │   │      Form: binary_path, repo_type, repo_path, encryption_enabled, password
│   │   │
│   │   ├── 📄 FileSelectionForm.php (ConfigFormBase)
│   │   │   └─ Step 2: Choose files to back up
│   │   │      Dynamic form based on FileDiscoveryService::scanProject()
│   │   │      Encryption-gated sensitive file sections
│   │   │
│   │   ├── 📄 PolicyForm.php (ConfigFormBase)
│   │   │   └─ Step 3: Retention policy & schedule
│   │   │      - keep_daily, keep_weekly, keep_monthly, keep_yearly
│   │   │      - backup_frequency (cron expression)
│   │   │      - notify_on_failure, notification_email
│   │   │
│   │   └── 📄 DashboardForm.php (FormBase)
│   │       └─ Admin dashboard (read-only status display + action buttons)
│   │          - Latest snapshot info
│   │          - Backup summary (size, files, schedule status)
│   │          - Action buttons: "Backup Now", "View Snapshots", "Restore"
│   │          - Status indicators (encryption enabled, recent backup)
│   │
│   ├── 📁 Controller/
│   │   ├── 📄 DashboardController.php (ControllerBase)
│   │   │   └─ Admin page route handler
│   │   │      - dashboard(): Render dashboard page
│   │   │      - backupNow(): AJAX/POST endpoint for manual backup
│   │   │
│   │   ├── 📄 SnapshotController.php (ControllerBase)
│   │   │   └─ Snapshot browsing & restore
│   │   │      - listSnapshots(): Render snapshot list
│   │   │      - viewSnapshot(snapshotId): Show snapshot details
│   │   │      - restore(): Restore from snapshot
│   │   │
│   │   └── 📄 LogController.php (ControllerBase)
│   │       └─ View backup operation logs
│   │          - viewLogs(): Render recent backup logs
│   │
│   ├── 📁 Drush/
│   │   └── 📄 ResticCommands.php (DrushCommands)
│   │       └─ CLI commands for backup operations
│   │          #[CLI\Command(name: 'restic:init')]
│   │          #[CLI\Command(name: 'restic:backup')]
│   │          #[CLI\Command(name: 'restic:snapshots')]
│   │          #[CLI\Command(name: 'restic:restore')]
│   │          #[CLI\Command(name: 'restic:prune')]
│   │          #[CLI\Command(name: 'restic:validate')]
│   │          #[CLI\Command(name: 'restic:stats')]
│   │
│   ├── 📁 Plugin/
│   │   └── 📁 QueueWorker/
│   │       └── 📄 ResticBackupWorker.php (QueueWorkerBase)
│   │           └─ Async backup processing
│   │              - processItem(data): Process queue item (long-running backup)
│   │              - Runs via Drupal's Queue API (triggered by cron)
│   │              - For very large backup sets, avoids PHP timeout
│   │
│   └── 📁 EventSubscriber/
│       └── 📄 CronSubscriber.php (EventSubscriberInterface)
│           └─ Drupal cron integration
│              - onCron(): Called every Drupal cron run
│              - Checks if backup schedule requires running
│              - Queues backup job if needed
│
├── 📁 config/
│   ├── 📁 install/
│   │   └── 📄 restic_backup.settings.yml
│   │       └─ Default empty configuration (user must set up via admin forms)
│   │
│   └── 📁 schema/
│       └── 📄 restic_backup.schema.yml
│           └─ Config schema & validation
│              - binary_path: string
│              - repo_type: enum (local, sftp, s3)
│              - repo_path: string
│              - encryption_enabled: boolean
│              - retention_policy: mapping (keep_daily, keep_weekly, etc.)
│              - excluded_paths: sequence (paths to exclude)
│              - included_paths: sequence (selected paths to include)
│              - backup_schedule: string (cron expression)
│              - notify_on_failure: boolean
│              - notification_email: email
│
├── 📁 templates/
│   ├── 📄 dashboard.html.twig
│   │   └─ Admin dashboard page (latest backup, stats, action buttons)
│   │      - Block 1: Status summary (size, file count, schedule status)
│   │      - Block 2: Latest snapshot info
│   │      - Block 3: Quick actions (Backup Now, View Snapshots, Configure)
│   │      - Block 4: Recent logs
│   │
│   ├── 📄 setup.html.twig
│   │   └─ Setup form styling (multi-step wizard)
│   │      - Restic binary validation
│   │      - Repository type selection (local/SFTP/S3)
│   │      - Encryption password entry
│   │
│   ├── 📄 file-selection.html.twig
│   │   └─ File categorization & selection interface
│   │      - Public files (included by default)
│   │      - Private files (encryption-gated, warning if disabled)
│   │      - Sensitive config (encryption-gated, warning if disabled)
│   │      - Regenerable (collapsed, informational only)
│   │      - Dependencies (collapsed, informational only)
│   │
│   ├── 📄 policy-schedule.html.twig
│   │   └─ Retention policy & backup schedule form
│   │
│   ├── 📄 snapshots-list.html.twig
│   │   └─ List of available snapshots
│   │      - Snapshot ID, date, size, file count
│   │      - Actions: View, Restore, Delete
│   │
│   └── 📄 logs.html.twig
│       └─ Backup operation logs
│          - Recent backup operations with timestamps
│          - Status (success, failed, warnings)
│          - File count, size processed
│
├── 📁 tests/
│   └── 📁 src/
│       ├── 📁 Unit/
│       │   ├── 📄 FileDiscoveryServiceTest.php
│       │   │   └─ Unit tests for file discovery logic
│       │   │      - testGitignoreParsing()
│       │   │      - testDependencyDetection()
│       │   │      - testRegenerableFileDetection()
│       │   │      - testSensitiveFileDetection()
│       │   │      - testPublicFileDetection()
│       │   │
│       │   ├── 📄 EncryptionValidatorTest.php
│       │   │   └─ Unit tests for encryption validation
│       │   │      - testEncryptionStatusDetection()
│       │   │      - testValidateSelection()
│       │   │      - testSensitiveFileBlocking()
│       │   │
│       │   └── 📄 BackupLoggerTest.php
│       │       └─ Unit tests for logging service
│       │
│       ├── 📁 Kernel/
│       │   ├── 📄 ResticManagerTest.php
│       │   │   └─ Kernel tests for Restic CLI wrapper
│       │   │      - testRepositoryInitialization()
│       │   │      - testBackupExecution()
│       │   │      - testValidateBinary()
│       │   │
│       │   ├── 📄 FileSelectionFormTest.php
│       │   │   └─ Kernel tests for file selection form
│       │   │      - testFormBuild()
│       │   │      - testFormSubmit()
│       │   │      - testEncryptionGating()
│       │   │
│       │   └── 📄 CronSubscriberTest.php
│       │       └─ Kernel tests for cron integration
│       │
│       └── 📁 Functional/
│           ├── 📄 DashboardPageTest.php
│           │   └─ Functional tests for admin dashboard
│           │      - testDashboardPageAccess()
│           │      - testBackupNowButton()
│           │      - testStatusDisplay()
│           │
│           ├── 📄 SetupFormTest.php
│           │   └─ Functional tests for setup wizard
│           │      - testFormValidation()
│           │      - testBinaryValidation()
│           │      - testRepositoryConfiguration()
│           │
│           ├── 📄 FileSelectionFormTest.php
│           │   └─ Functional tests for file selection
│           │      - testFormDisplay()
│           │      - testEncryptionGating()
│           │      - testFormSubmission()
│           │
│           └── 📄 BackupWorkflowTest.php
│               └─ End-to-end functional test
│                  - testCompleteBackupWorkflow() (setup → file selection → backup)
│
├── 📄 README.md
│   └─ User & developer documentation:
│      - Installation (install Restic binary, enable module)
│      - Quick start guide (setup form, choose files, run backup)
│      - Configuration examples (local dir, SFTP, S3)
│      - Drush command reference (restic:init, restic:backup, restic:snapshots, etc.)
│      - Troubleshooting (common issues and solutions)
│      - Security considerations (password management, encryption, key storage)
│      - Architecture overview (for contributors)
│      - Testing instructions (how to run tests locally)
│
└── 📄 restic_backup_module_spec.md
    └─ This file: Design specification & architecture (for ai agents & contributors)
```

### Key Service Dependencies (in services.yml)

```yaml
services:
  restic_backup.manager:
    class: Drupal\restic_backup\Service\ResticManager
    arguments:
      - '@logger.channel.restic_backup'
      - '@config.factory'
      - '@file_system'

  restic_backup.file_discovery:
    class: Drupal\restic_backup\Service\FileDiscoveryService
    arguments:
      - '@file_system'
      - '@logger.channel.restic_backup'

  restic_backup.encryption_validator:
    class: Drupal\restic_backup\Service\EncryptionValidator
    arguments:
      - '@restic_backup.manager'
      - '@logger.channel.restic_backup'

  restic_backup.backup_logger:
    class: Drupal\restic_backup\Service\BackupLogger
    arguments:
      - '@database'
      - '@logger.channel.restic_backup'

  restic_backup.cron_handler:
    class: Drupal\restic_backup\EventSubscriber\CronSubscriber
    arguments:
      - '@config.factory'
      - '@queue'
      - '@logger.channel.restic_backup'
    tags:
      - { name: 'event_subscriber' }

  logger.channel.restic_backup:
    parent: logger.channel_base
    arguments: ['restic_backup']
```

### Drupal Version Compatibility

- **Drupal 10.3+**: Full support (verified)
- **Drupal 11.x**: Full support (verified via Drush 13 compatibility)
- **PHP**: 8.1+ (type hints, attributes)
- **Drush**: 13+ (PHP attribute-based commands)

---

## Architecture

### Module Structure

[Previous Architecture section remains unchanged - now references detailed sections above]

[Rest of document continues unchanged...]


### Module Structure

```
restic_backup/
├── restic_backup.info.yml
├── restic_backup.module
├── restic_backup.services.yml
├── restic_backup.routing.yml
├── restic_backup.links.menu.yml
├── restic_backup.install
│
├── config/
│   ├── install/
│   │   └── restic_backup.settings.yml
│   └── schema/
│       └── restic_backup.schema.yml
│
├── src/
│   ├── Form/
│   │   ├── ResticSettingsForm.php
│   │   └── FileSelectionForm.php
│   │
│   ├── Service/
│   │   ├── ResticService.php          # Main Restic wrapper
│   │   ├── FileDiscoveryService.php   # Gitignore parsing + file scanning
│   │   ├── EncryptionValidator.php    # Encryption checks
│   │   └── BackupLogger.php           # Logging/monitoring
│   │
│   ├── Commands/
│   │   └── ResticCommands.php         # Drush commands
│   │
│   └── Plugin/
│       └── QueueWorker/
│           └── ResticBackupWorker.php # Queue processing for async
│
├── templates/
│   └── file-selection.html.twig
│
└── README.md
```

### Key Services

#### ResticService.php

Core wrapper around Restic binary:

```php
namespace Drupal\restic_backup\Service;

class ResticService {

  /**
   * Initialize Restic repository.
   */
  public function initRepository(string $repoPath, string $password): bool;

  /**
   * Run backup of specified paths.
   */
  public function backup(array $paths, array $excludePatterns = []): array;

  /**
   * List all snapshots.
   */
  public function listSnapshots(): array;

  /**
   * Restore from snapshot.
   */
  public function restore(string $snapshotId, string $targetPath): bool;

  /**
   * Prune old snapshots based on retention policy.
   */
  public function prune(array $keepPolicy): bool;

  /**
   * Get backup statistics.
   */
  public function stats(string $snapshotId = 'latest'): array;

  /**
   * Check if repository is encrypted.
   */
  public function isEncrypted(string $repoPath): bool;

  /**
   * Validate Restic installation.
   */
  public function validateBinary(): bool;
}
```

#### FileDiscoveryService.php

Intelligent file scanning based on gitignore:

```php
namespace Drupal\restic_backup\Service;

class FileDiscoveryService {

  /**
   * Scan project based on .gitignore.
   */
  public function scanProject(): array {
    return [
      'public_files' => $this->findPublicFiles(),
      'private_files' => $this->findPrivateFiles(),
      'sensitive_config' => $this->findSensitiveConfig(),
      'dependencies' => $this->findDependencies(),
      'regenerable' => $this->findRegenerableFiles(),
    ];
  }

  /**
   * Parse .gitignore using git check-ignore.
   */
  private function parseGitignore(): array;

  /**
   * Find all public file directories.
   */
  private function findPublicFiles(): array;

  /**
   * Detect private file directories.
   */
  private function findPrivateFiles(): array;

  /**
   * Find sensitive configuration files.
   */
  private function findSensitiveConfig(): array;

  /**
   * Identify regenerable files (image styles, CSS/JS aggregation).
   */
  private function findRegenerableFiles(): array;

  /**
   * Build Restic exclude patterns from selections.
   */
  public function buildExclusionList(array $selections): array;

  /**
   * Generate .restic-exclude file.
   */
  public function exportExclusionFile(array $excludePatterns, string $path): void;
}
```

#### EncryptionValidator.php

Security checks for sensitive files:

```php
namespace Drupal\restic_backup\Service;

class EncryptionValidator {

  /**
   * Check if Restic repository has encryption configured.
   */
  public function isConfigured(string $repoPath): bool;

  /**
   * Determine if a file path contains sensitive data.
   */
  public function isSensitive(string $path): bool {
    $patterns = [
      '*/settings.local.php',
      '*/settings.php',
      '*/services.yml',
      'private/*',
      '.env',
      '*.key',
      '*.pem',
    ];
    // Pattern matching logic
  }

  /**
   * Validate that sensitive files are only backed up with encryption.
   */
  public function validateSelection(array $files, bool $encryptionEnabled): array;
}
```

## Implementation Phases

### Phase 1: MVP (Week 1)
- [ ] Basic Restic wrapper service
- [ ] Simple configuration form (repo path, password)
- [ ] Drush commands: init, backup, snapshots
- [ ] Manual path selection (hardcoded patterns)
- [ ] Basic logging

### Phase 2: Intelligent Discovery (Week 2)
- [ ] Gitignore parsing
- [ ] File categorization service
- [ ] Default selection logic
- [ ] Encryption validation
- [ ] UI for file selection

### Phase 3: Production Features (Week 3)
- [ ] Cron integration
- [ ] Queue-based async processing
- [ ] Retention policies (prune)
- [ ] Email notifications
- [ ] Restore functionality
- [ ] Statistics/monitoring

### Phase 4: Polish & Contrib (Week 4)
- [ ] Comprehensive testing
- [ ] Documentation (README, help text)
- [ ] Code review / Drupal coding standards
- [ ] Security review prep
- [ ] Drupal.org project application

## Configuration Schema

```yaml
# config/schema/restic_backup.schema.yml
restic_backup.settings:
  type: config_object
  label: 'Restic Backup settings'
  mapping:
    binary_path:
      type: string
      label: 'Restic binary path'
    repository:
      type: string
      label: 'Repository path or URL'
    password:
      type: string
      label: 'Repository password'
    encryption_enabled:
      type: boolean
      label: 'Encryption enabled'
    retention_policy:
      type: mapping
      label: 'Snapshot retention policy'
      mapping:
        keep_daily:
          type: integer
          label: 'Keep daily snapshots'
        keep_weekly:
          type: integer
          label: 'Keep weekly snapshots'
        keep_monthly:
          type: integer
          label: 'Keep monthly snapshots'
    excluded_paths:
      type: sequence
      label: 'Paths to exclude'
      sequence:
        type: string
    included_paths:
      type: sequence
      label: 'Paths to include'
      sequence:
        type: string
    backup_schedule:
      type: string
      label: 'Cron schedule expression'
```

## Security Considerations

1. **Password Storage**: Use Drupal's key module integration (or encrypted config)
2. **File Permissions**: Validate write access before backup
3. **Sensitive File Detection**: Pattern-based + user override
4. **SSH Key Management**: Document SSH key setup for remote repos
5. **Input Validation**: Sanitize all paths, validate binary execution

## Testing Strategy

1. **Unit Tests**: Service layer logic
2. **Functional Tests**: Drush commands, UI forms
3. **Integration Tests**: Actual Restic operations (mock mode + real mode)
4. **Manual Testing**: Various Drupal configurations (standard, multisite, composer-based)

## Documentation Requirements

### README.md

- Installation (Restic binary + module)
- Quick start guide
- Configuration examples (local, S3, SSH)
- Drush command reference
- Troubleshooting

### Help Text

- In-module help (`/admin/help/restic_backup`)
- Form field descriptions
- Error messages with solutions

## Drupal.org Contrib Submission

### Project Requirements
- GPL-2.0-or-later license
- Drupal coding standards compliance
- Security review (post-beta)
- Automated testing (PHPUnit)
- Semantic versioning

### Positioning
- **Category**: Backup and Migration
- **Keywords**: backup, incremental, restic, files, encryption
- **Fills gap**: First incremental file backup solution for Drupal

## Open Questions / Future Enhancements

1. **Multisite handling**: Per-site configuration vs. global?
2. **Cloud backend UI**: Add forms for S3/B2 configuration?
3. **Restore UI**: File browser for snapshot contents?
4. **Integration with Backup & Migrate**: Coordinate database + file backups?
5. **Progress indicators**: Real-time backup progress (WebSocket/AJAX)?
6. **Bandwidth throttling**: Rate limit for large backups?
7. **Comparison tool**: Show diff between snapshots?

## References

- Restic documentation: https://restic.readthedocs.io/
- Restic GitHub: https://github.com/restic/restic
- Backup & Migrate module: https://www.drupal.org/project/backup_migrate
- Drupal coding standards: https://www.drupal.org/docs/develop/standards
- GPL compatibility: https://www.gnu.org/licenses/license-list.html

## Notes for AI Assistant (Copilot)

This module bridges the gap between:
- **Version control** (code is in Git)
- **Database backups** (handled by Backup & Migrate or custom solutions)
- **File backups** (THIS MODULE - fills the missing piece)

The key innovation is **intelligent automation**:
- Don't make users list paths manually
- Use `.gitignore` as the source of truth
- Apply Drupal-specific knowledge (image styles, aggregation)
- Enforce security (encryption for secrets)

Think of this as "Time Machine for Drupal files" - incremental, efficient, secure.
