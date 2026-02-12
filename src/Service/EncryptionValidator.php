<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Service for validating encryption configuration and securing sensitive files.
 *
 * Implements fail-safe encryption validation: sensitive files are blocked
 * unless encryption is confirmed to be enabled on the Restic repository.
 */
class EncryptionValidator {

  /**
   * Restic manager service.
   */
  protected ResticManager $resticManager;

  /**
   * Logger instance.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new EncryptionValidator instance.
   *
   * @param \Drupal\restic_backup\Service\ResticManager $restic_manager
   *   The Restic manager service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    ResticManager $restic_manager,
    LoggerInterface $logger
  ) {
    $this->resticManager = $restic_manager;
    $this->logger = $logger;
  }

  /**
   * Validate repository encryption status.
   *
   * Attempts to determine if the Restic repository is encrypted by checking
   * if accessing the repository config requires a password.
   *
   * @param string $repo_path
   *   Path or URL to the Restic repository.
   *
   * @return array
   *   Status array with keys:
   *     - status: 'encrypted', 'unencrypted', 'unknown', or 'not_found'
   *     - message: Human-readable status message
   *     - can_backup_sensitive: Boolean indicating if sensitive files are safe
   */
  public function validateRepository(string $repo_path): array {
    // Check if repository exists
    if (!$this->repositoryExists($repo_path)) {
      $this->logger->warning('Repository not found at @path', ['@path' => $repo_path]);
      return [
        'status' => 'not_found',
        'message' => 'Restic repository not found or not initialized',
        'can_backup_sensitive' => FALSE,
      ];
    }

    // Try to read config without password
    try {
      $process = new Process(['restic', '-r', $repo_path, 'cat', 'config']);
      $process->run();

      if ($process->getExitCode() === 0) {
        // Config readable without password = NOT encrypted
        $this->logger->info('Repository at @path is NOT encrypted', ['@path' => $repo_path]);
        return [
          'status' => 'unencrypted',
          'message' => 'Repository is NOT encrypted. Sensitive files will be excluded.',
          'can_backup_sensitive' => FALSE,
        ];
      }

      // Check if error is password-related
      $error = $process->getErrorOutput();
      if (strpos($error, 'password') !== FALSE ||
          strpos($error, 'key') !== FALSE) {
        $this->logger->info('Repository at @path is encrypted (password required)', ['@path' => $repo_path]);
        return [
          'status' => 'encrypted',
          'message' => 'Repository is encrypted. Sensitive files can be backed up.',
          'can_backup_sensitive' => TRUE,
        ];
      }
    } catch (\Exception $e) {
      $this->logger->error('Error checking repository encryption: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    // Default to conservative: assume NOT encrypted
    $this->logger->warning('Could not determine encryption status at @path, assuming NOT encrypted', [
      '@path' => $repo_path,
    ]);
    return [
      'status' => 'unknown',
      'message' => 'Could not determine encryption status. Assuming NOT encrypted (conservative).',
      'can_backup_sensitive' => FALSE,
    ];
  }

  /**
   * Validate file selections against encryption requirements.
   *
   * Implements FAIL-SAFE behavior: removes sensitive files from selection
   * if encryption is not enabled. Returns filtered list + warnings.
   *
   * @param array $selected_files
   *   User's chosen files from UI.
   * @param bool $encryption_enabled
   *   Whether repository is encrypted.
   *
   * @return array
   *   Validation result with keys:
   *     - safe_files: Files safe to backup
   *     - excluded_files: Files removed due to encryption requirement
   *     - warnings: Array of warning messages for UI display
   *     - can_backup_all: Boolean indicating if all selections are safe
   */
  public function validateSelection(
    array $selected_files,
    bool $encryption_enabled
  ): array {
    $safe_files = [];
    $excluded_files = [];
    $warnings = [];

    foreach ($selected_files as $filepath) {
      if ($this->isSensitiveFile($filepath)) {
        if (!$encryption_enabled) {
          // EXCLUDE sensitive file if encryption not enabled
          $excluded_files[] = [
            'path' => $filepath,
            'type' => $this->getSensitiveFileType($filepath),
            'reason' => 'encryption_required',
          ];
          continue;
        }
      }

      // File is safe to backup
      $safe_files[] = $filepath;
    }

    // Generate warnings for UI
    if (!empty($excluded_files)) {
      $excluded_paths = array_column($excluded_files, 'path');
      $warning_message = sprintf(
        'Encryption is NOT enabled. %d sensitive file(s) will be excluded: %s',
        count($excluded_files),
        implode(', ', $excluded_paths)
      );

      $warnings[] = [
        'severity' => 'warning',
        'message' => $warning_message,
        'action' => 'Enable encryption to include sensitive files',
        'action_link' => '/admin/config/system/restic-backup/setup',
      ];

      $this->logger->warning($warning_message);
    }

    return [
      'safe_files' => $safe_files,
      'excluded_files' => $excluded_files,
      'warnings' => $warnings,
      'can_backup_all' => $encryption_enabled,
    ];
  }

  /**
   * Check if a file path contains sensitive data.
   *
   * @param string $path
   *   File path to check.
   *
   * @return bool
   *   TRUE if file is sensitive.
   */
  private function isSensitiveFile(string $path): bool {
    $sensitive_patterns = [
      'settings.php',
      'settings.local.php',
      'services.yml',
      '.env',
      '*.key',
      '*.pem',
      '*.ppk',
      'private/', // Private files stream
    ];

    foreach ($sensitive_patterns as $pattern) {
      if ($this->matchesPattern($path, $pattern)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Get sensitive file category type for labeling.
   *
   * @param string $path
   *   File path.
   *
   * @return string
   *   Category type: 'drupal_config', 'drupal_services', 'environment_vars',
   *   'crypto_key', 'private_files', or 'other_sensitive'.
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

  /**
   * Check if path matches a pattern (supports wildcards).
   *
   * @param string $path
   *   Path to check.
   * @param string $pattern
   *   Pattern with * and ? wildcards.
   *
   * @return bool
   *   TRUE if path matches pattern.
   */
  private function matchesPattern(string $path, string $pattern): bool {
    // Exact match
    if ($path === $pattern) {
      return TRUE;
    }

    // Directory prefix match
    if (substr($pattern, -1) === '/') {
      return strpos($path, $pattern) === 0;
    }

    // Filename match (basename)
    $filename = basename($path);
    if ($pattern === $filename) {
      return TRUE;
    }

    // Wildcard match
    $regex = str_replace(
      ['*', '?'],
      ['.*', '.'],
      preg_quote($pattern, '/')
    );
    if (preg_match("/^{$regex}$/", $filename)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Check if repository exists at given path.
   *
   * @param string $repo_path
   *   Path or URL to check.
   *
   * @return bool
   *   TRUE if repository exists.
   */
  private function repositoryExists(string $repo_path): bool {
    // Local path
    if (strpos($repo_path, '/') === 0 || strpos($repo_path, 'C:') === 0) {
      return is_dir($repo_path);
    }

    // Remote URL (SFTP, S3, etc.) - we can't easily verify without credentials
    // Just check if the string looks like a valid repo specification
    return !empty($repo_path) && strlen($repo_path) > 3;
  }

}
