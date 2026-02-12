<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Service wrapping the Restic CLI tool.
 *
 * Provides high-level Restic operations (init, backup, restore, prune, stats)
 * with proper error handling and logging.
 */
class ResticManager {

  /**
   * Logger instance.
   */
  protected LoggerInterface $logger;

  /**
   * Config factory service.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * File system service.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Restic binary path.
   */
  protected string $binaryPath;

  /**
   * State service for retrieving repository password.
   */
  protected $state;

  /**
   * Constructs a new ResticManager instance.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(
    LoggerInterface $logger,
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system
  ) {
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->state = \Drupal::state();

    // Get binary path from config
    $config = $this->configFactory->get('restic_backup.settings');
    $this->binaryPath = $config->get('binary_path') ?: 'restic';
  }

  /**
   * Initialize a new Restic repository.
   *
   * @param string $repo_path
   *   Path or URL to the repository.
   * @param string $password
   *   Repository password for encryption (optional).
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function initRepository(string $repo_path, string $password = ''): bool {
    try {
      $command = [$this->binaryPath, '-r', $repo_path, 'init'];

      $process = new Process($command);

      // Set password via environment variable (restic standard)
      if (!empty($password)) {
        $process->setEnv(['RESTIC_PASSWORD' => $password]);
      }

      $process->run();

      if ($process->getExitCode() !== 0) {
        $this->logger->error(
          'Failed to init repository at @path: @error',
          [
            '@path' => $repo_path,
            '@error' => $process->getErrorOutput(),
          ]
        );
        return FALSE;
      }

      $this->logger->info('Repository initialized at @path', ['@path' => $repo_path]);
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error(
        'Exception during repository init: @error',
        ['@error' => $e->getMessage()]
      );
      return FALSE;
    }
  }

  /**
   * Run a backup operation.
   *
   * @param array $paths
   *   Paths to back up.
   * @param array $exclude_patterns
   *   Patterns to exclude (optional).
   *
   * @return array
   *   Result array with keys:
   *     - success: Boolean
   *     - snapshot_id: ID of created snapshot (if successful)
   *     - files_count: Number of files processed
   *     - backup_size: Size of backup created
   *     - message: Status message
   */
  public function backup(array $paths, array $exclude_patterns = []): array {
    if (empty($paths)) {
      return [
        'success' => FALSE,
        'message' => 'No paths provided for backup',
      ];
    }

    try {
      $config = $this->configFactory->get('restic_backup.settings');
      $repo_path = $config->get('repo_path');

      if (empty($repo_path)) {
        return [
          'success' => FALSE,
          'message' => 'Repository not configured',
        ];
      }

      // Build command
      $command = [$this->binaryPath, '-r', $repo_path, 'backup'];

      // Add exclude patterns
      foreach ($exclude_patterns as $pattern) {
        $command[] = '--exclude';
        $command[] = $pattern;
      }

      // Add paths to backup
      $command = array_merge($command, $paths);

      // Add JSON output for parsing
      $command[] = '--json';

      $process = new Process($command);
      $process->setTimeout(3600); // 1 hour timeout

      // Set working directory to project root (parent of Drupal root)
      $project_root = dirname(\Drupal::root());
      $process->setWorkingDirectory($project_root);

      // Set repository password from state
      $password = $this->getRepositoryPassword();
      if (!empty($password)) {
        $process->setEnv(['RESTIC_PASSWORD' => $password]);
      }

      $this->logger->info('Starting backup of @count paths', ['@count' => count($paths)]);

      $process->run();

      $exit_code = $process->getExitCode();

      // Restic exit codes:
      // 0 = success
      // 1 = fatal error
      // 3 = some source files could not be read (partial success)
      if ($exit_code !== 0 && $exit_code !== 3) {
        $error = $process->getErrorOutput();
        $this->logger->error('Backup failed: @error', ['@error' => $error]);
        return [
          'success' => FALSE,
          'message' => 'Backup operation failed: ' . $error,
        ];
      }

      // Log warning for partial success
      if ($exit_code === 3) {
        $this->logger->warning('Backup completed with warnings: some files could not be read');
      }

      // Parse JSON output
      $output = $process->getOutput();
      $json_lines = explode("\n", trim($output));
      $summary = [];

      foreach ($json_lines as $line) {
        if (!empty($line)) {
          $data = json_decode($line, TRUE);
          if ($data && isset($data['message_type'])) {
            if ($data['message_type'] === 'summary') {
              $summary = $data;
            }
          }
        }
      }

      // Extract backup info
      $snapshot_id = $summary['snapshot_id'] ?? 'unknown';
      $file_count = $summary['files_new'] ?? 0;
      $total_size = $summary['total_file_size'] ?? 0;

      $this->logger->info(
        'Backup successful: @files files, @size bytes',
        ['@files' => $file_count, '@size' => $total_size]
      );

      return [
        'success' => TRUE,
        'snapshot_id' => $snapshot_id,
        'files_count' => $file_count,
        'backup_size' => $total_size,
        'message' => sprintf(
          'Backup complete: %d files, %s',
          $file_count,
          $this->formatBytes($total_size)
        ),
      ];
    } catch (\Exception $e) {
      $this->logger->error('Exception during backup: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'message' => 'Backup failed: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * List all snapshots in the repository.
   *
   * @return array
   *   Array of snapshot information.
   */
  public function listSnapshots(): array {
    try {
      $config = $this->configFactory->get('restic_backup.settings');
      $repo_path = $config->get('repo_path');

      if (empty($repo_path)) {
        $this->logger->error('Repository not configured');
        return [];
      }

      $command = [$this->binaryPath, '-r', $repo_path, 'snapshots', '--json'];

      $process = new Process($command);

      // Set repository password
      $password = $this->getRepositoryPassword();
      if (!empty($password)) {
        $process->setEnv(['RESTIC_PASSWORD' => $password]);
      }

      $process->run();

      if ($process->getExitCode() !== 0) {
        $this->logger->error('Failed to list snapshots: @error', [
          '@error' => $process->getErrorOutput(),
        ]);
        return [];
      }

      $output = $process->getOutput();
      $snapshots = json_decode($output, TRUE);

      return is_array($snapshots) ? $snapshots : [];
    } catch (\Exception $e) {
      $this->logger->error('Exception listing snapshots: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Restore files from a snapshot.
   *
   * @param string $snapshot_id
   *   ID of snapshot to restore from.
   * @param string $target_path
   *   Path to restore files to.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  /**
   * Restore files from a snapshot.
   *
   * @param string $snapshot_id
   *   Snapshot ID to restore from.
   * @param string $target_path
   *   Target path for restoration. Empty string restores to original locations.
   * @param array $include_paths
   *   Optional array of specific paths to restore. Empty array restores all.
   *
   * @return array
   *   Array with keys: success (bool), message (string), files_restored (int).
   */
  public function restore(string $snapshot_id, string $target_path = '', array $include_paths = []): array {
    try {
      $config = $this->configFactory->get('restic_backup.settings');
      $repo_path = $config->get('repo_path');

      if (empty($repo_path)) {
        $this->logger->error('Repository not configured');
        return [
          'success' => FALSE,
          'message' => 'Repository not configured',
          'files_restored' => 0,
        ];
      }

      $command = [
        $this->binaryPath,
        '-r',
        $repo_path,
        'restore',
        $snapshot_id,
      ];

      // Add target path if specified, otherwise restore to original locations
      if (!empty($target_path)) {
        $command[] = '--target';
        $command[] = $target_path;
      }
      else {
        // Restore to original locations (requires working directory)
        $command[] = '--target';
        $command[] = '/';
      }

      // Add include paths if specified (specific file restoration)
      foreach ($include_paths as $path) {
        $command[] = '--include';
        $command[] = $path;
      }

      $process = new Process($command);
      $process->setTimeout(3600); // 1 hour timeout

      // Set repository password
      $password = $this->getRepositoryPassword();
      if (!empty($password)) {
        $process->setEnv(['RESTIC_PASSWORD' => $password]);
      }

      $this->logger->info('Restoring snapshot @id to @path', [
        '@id' => $snapshot_id,
        '@path' => $target_path ?: 'original locations',
      ]);

      $process->run();

      $exit_code = $process->getExitCode();
      if ($exit_code !== 0) {
        $error_output = $process->getErrorOutput();
        $this->logger->error('Restore failed: @error', [
          '@error' => $error_output,
        ]);
        return [
          'success' => FALSE,
          'message' => 'Restore failed: ' . $error_output,
          'files_restored' => 0,
        ];
      }

      // Parse output to count restored files
      $output = $process->getOutput();
      $files_restored = 0;

      // Restic output typically shows "restoring <file>" for each file
      if (preg_match_all('/restoring|restored/', $output, $matches)) {
        $files_restored = count($matches[0]);
      }

      $this->logger->info('Restore successful: @count files restored', [
        '@count' => $files_restored,
      ]);

      return [
        'success' => TRUE,
        'message' => sprintf('Successfully restored %d file(s)', $files_restored),
        'files_restored' => $files_restored,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Exception during restore: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'message' => 'Exception: ' . $e->getMessage(),
        'files_restored' => 0,
      ];
    }
  }

  /**
   * Prune old snapshots based on retention policy.
   *
   * @param array $keep_policy
   *   Policy array with keep_daily, keep_weekly, keep_monthly, keep_yearly.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function prune(array $keep_policy = []): bool {
    try {
      $config = $this->configFactory->get('restic_backup.settings');
      $repo_path = $config->get('repo_path');

      if (empty($repo_path)) {
        $this->logger->error('Repository not configured');
        return FALSE;
      }

      // Use provided policy or get from config
      if (empty($keep_policy)) {
        $keep_policy = $config->get('retention_policy') ?: [
          'keep_daily' => 7,
          'keep_weekly' => 4,
          'keep_monthly' => 12,
          'keep_yearly' => 3,
        ];
      }

      $command = [
        $this->binaryPath,
        '-r',
        $repo_path,
        'forget',
        '--keep-daily=' . $keep_policy['keep_daily'],
        '--keep-weekly=' . $keep_policy['keep_weekly'],
        '--keep-monthly=' . $keep_policy['keep_monthly'],
        '--keep-yearly=' . $keep_policy['keep_yearly'],
        '--prune',
      ];

      $process = new Process($command);
      $process->setTimeout(3600);

      // Set repository password
      $password = $this->getRepositoryPassword();
      if (!empty($password)) {
        $process->setEnv(['RESTIC_PASSWORD' => $password]);
      }

      $this->logger->info('Running retention prune with policy: @policy', [
        '@policy' => json_encode($keep_policy),
      ]);

      $process->run();

      if ($process->getExitCode() !== 0) {
        $this->logger->error('Prune failed: @error', [
          '@error' => $process->getErrorOutput(),
        ]);
        return FALSE;
      }

      $this->logger->info('Prune successful');
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Exception during prune: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Get statistics for a snapshot.
   *
   * @param string $snapshot_id
   *   Snapshot ID (default: 'latest').
   *
   * @return array
   *   Statistics array or empty array on failure.
   */
  public function stats(string $snapshot_id = 'latest'): array {
    try {
      $config = $this->configFactory->get('restic_backup.settings');
      $repo_path = $config->get('repo_path');

      if (empty($repo_path)) {
        return [];
      }

      $command = [
        $this->binaryPath,
        '-r',
        $repo_path,
        'snapshots',
        $snapshot_id,
        '--json',
      ];

      $process = new Process($command);

      // Set repository password
      $password = $this->getRepositoryPassword();
      if (!empty($password)) {
        $process->setEnv(['RESTIC_PASSWORD' => $password]);
      }

      $process->run();

      if ($process->getExitCode() !== 0) {
        return [];
      }

      $output = $process->getOutput();
      $snapshots = json_decode($output, TRUE);

      return is_array($snapshots) && !empty($snapshots) ? $snapshots[0] : [];
    } catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Check if repository is encrypted.
   *
   * @param string $repo_path
   *   Repository path.
   *
   * @return bool
   *   TRUE if encrypted, FALSE otherwise.
   */
  public function isEncrypted(string $repo_path): bool {
    try {
      $process = new Process([$this->binaryPath, '-r', $repo_path, 'cat', 'config']);
      $process->run();

      if ($process->getExitCode() === 0) {
        return FALSE; // Config readable = NOT encrypted
      }

      $error = $process->getErrorOutput();
      return strpos($error, 'password') !== FALSE;
    } catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Validate Restic binary installation.
   *
   * @return bool
   *   TRUE if binary is available and working.
   */
  public function validateBinary(): bool {
    try {
      $process = new Process([$this->binaryPath, 'version']);
      $process->run();
      return $process->getExitCode() === 0;
    } catch (\Exception $e) {
      $this->logger->warning('Restic binary validation failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Check repository integrity.
   *
   * @return array
   *   Result array with 'success' boolean and 'message' string.
   */
  public function check(): array {
    try {
      $config = $this->configFactory->get('restic_backup.settings');
      $repo_path = $config->get('repo_path');

      if (empty($repo_path)) {
        return [
          'success' => FALSE,
          'message' => 'Repository not configured',
        ];
      }

      $command = [$this->binaryPath, '-r', $repo_path, 'check'];

      $process = new Process($command);
      $process->setTimeout(1800); // 30 minutes

      // Set repository password
      $password = $this->getRepositoryPassword();
      if (!empty($password)) {
        $process->setEnv(['RESTIC_PASSWORD' => $password]);
      }

      $this->logger->info('Checking repository integrity');
      $process->run();

      if ($process->getExitCode() !== 0) {
        $error = $process->getErrorOutput();
        $this->logger->error('Repository check failed: @error', ['@error' => $error]);
        return [
          'success' => FALSE,
          'message' => 'Repository check failed: ' . $error,
        ];
      }

      $this->logger->info('Repository check successful');
      return [
        'success' => TRUE,
        'message' => 'Repository integrity verified',
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Exception during check: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'message' => 'Repository check failed: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Get overall repository statistics.
   *
   * @return array
   *   Statistics with total_size, total_file_count, snapshot_count.
   */
  public function getRepositoryStats(): array {
    try {
      $config = $this->configFactory->get('restic_backup.settings');
      $repo_path = $config->get('repo_path');

      if (empty($repo_path)) {
        return [];
      }

      $command = [
        $this->binaryPath,
        '-r',
        $repo_path,
        'stats',
        '--json',
      ];

      $process = new Process($command);
      $process->setTimeout(300); // 5 minutes

      // Set repository password
      $password = $this->getRepositoryPassword();
      if (!empty($password)) {
        $process->setEnv(['RESTIC_PASSWORD' => $password]);
      }

      $process->run();

      if ($process->getExitCode() !== 0) {
        return [];
      }

      $output = $process->getOutput();
      $stats = json_decode($output, TRUE);

      if (!is_array($stats)) {
        return [];
      }

      // Get snapshot count
      $snapshots = $this->listSnapshots();

      return [
        'total_size' => $stats['total_size'] ?? 0,
        'total_file_count' => $stats['total_file_count'] ?? 0,
        'snapshot_count' => count($snapshots),
        'total_blob_count' => $stats['total_blob_count'] ?? 0,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Exception getting repository stats: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get repository password from state.
   *
   * @return string
   *   Password or empty string.
   */
  protected function getRepositoryPassword(): string {
    $config = $this->configFactory->get('restic_backup.settings');
    $encryption_enabled = $config->get('encryption_enabled');

    if (!$encryption_enabled) {
      return '';
    }

    // Prioritize environment variable for production security
    $envPassword = getenv('RESTIC_PASSWORD');
    if (!empty($envPassword)) {
      return $envPassword;
    }

    // Fall back to state (set during SetupForm submission)
    $password = $this->state->get('restic_backup.repository_password', '');

    return $password;
  }

  /**
   * Format bytes for display.
   */
  private function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, 2) . ' ' . $units[$pow];
  }

}
