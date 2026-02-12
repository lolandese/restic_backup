<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Drush;

use Drupal\restic_backup\Service\ResticManager;
use Drupal\restic_backup\Service\FileDiscoveryService;
use Psr\Log\LoggerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drush commands for Restic Backup operations.
 *
 * @ingroup restic_backup
 */
class ResticCommands extends DrushCommands {

  /**
   * Restic manager service.
   */
  protected ResticManager $resticManager;

  /**
   * File discovery service.
   */
  protected FileDiscoveryService $fileDiscoveryService;

  /**
   * Constructs a ResticCommands object.
   *
   * @param \Drupal\restic_backup\Service\ResticManager $restic_manager
   *   The Restic manager service.
   * @param \Drupal\restic_backup\Service\FileDiscoveryService $file_discovery
   *   The file discovery service.
   */
  public function __construct(
    ResticManager $restic_manager,
    FileDiscoveryService $file_discovery
  ) {
    $this->resticManager = $restic_manager;
    $this->fileDiscoveryService = $file_discovery;
  }

  /**
   * Initialize a Restic repository.
   *
   * @command restic:init
   * @description Initialize a new Restic repository
   * @aliases ri, restic-init
   */
  #[CLI\Command(name: 'restic:init', aliases: ['ri', 'restic-init'])]
  #[CLI\Option(name: 'repo', description: 'Repository path')]
  #[CLI\Option(name: 'password', description: 'Repository password')]
  public function init($options = ['repo' => NULL, 'password' => NULL]): int {
    $config = \Drupal::config('restic_backup.settings');

    // Get repo path from option or config
    $repo_path = $options['repo'] ?? $config->get('repo_path');

    if (empty($repo_path)) {
      $this->logger()->error('Repository path not provided');
      $this->logger()->warning('Provide --repo option or configure at /admin/config/system/restic-backup/setup');
      return self::EXIT_FAILURE;
    }

    // Get password from option or state
    $password = $options['password'];
    if (empty($password)) {
      $password = \Drupal::state()->get('restic_backup.repository_password', '');
    }

    if (empty($password) && $config->get('encryption_enabled')) {
      $this->logger()->error('Repository password required for encrypted repository');
      $this->logger()->warning('Provide --password option or configure at /admin/config/system/restic-backup/setup');
      return self::EXIT_FAILURE;
    }

    $this->logger()->info('Initializing repository at: ' . $repo_path);

    $result = $this->resticManager->initRepository($repo_path, $password);

    if ($result) {
      $this->logger()->success('✓ Repository initialized successfully');
      $this->logger()->info('You can now run: drush restic:backup');
      return self::EXIT_SUCCESS;
    }
    else {
      $this->logger()->error('✗ Repository initialization failed');
      $this->logger()->warning('Check that:');
      $this->logger()->warning('  1. Directory exists and is writable');
      $this->logger()->warning('  2. Restic binary is installed: drush restic:validate');
      return self::EXIT_FAILURE;
    }
  }

  /**
   * Run a backup operation.
   *
   * @command restic:backup
   * @description Run a backup of configured files
   * @aliases rb, restic-backup
   */
  #[CLI\Command(name: 'restic:backup', aliases: ['rb', 'restic-backup'])]
  #[CLI\Option(name: 'manual', description: 'Mark as manual backup')]
  public function backup($options = ['manual' => FALSE]): int {
    $config = \Drupal::config('restic_backup.settings');
    $paths = $config->get('included_paths') ?? [];

    if (empty($paths)) {
      $this->logger()->error('No paths configured for backup');
      $this->logger()->warning('Configure backup paths at: /admin/config/system/restic-backup/file-selection');
      $this->logger()->warning('Or run: drush restic:scan');
      return self::EXIT_FAILURE;
    }

    $this->logger()->info('Starting backup of ' . count($paths) . ' path(s)...');

    // Get exclusion patterns
    $excluded_paths = $config->get('excluded_paths') ?? [];

    // Run backup with exclusions
    $result = $this->resticManager->backup($paths, $excluded_paths);

    if (!$result['success']) {
      $this->logger()->error('✗ Backup failed: ' . $result['message']);
      return self::EXIT_FAILURE;
    }

    // Display results
    $this->logger()->success('✓ Backup completed successfully');
    $this->logger()->info('Snapshot ID: ' . ($result['snapshot_id'] ?? 'unknown'));
    $this->logger()->info('Files backed up: ' . number_format($result['files_count'] ?? 0));
    $this->logger()->info('Backup size: ' . $this->formatBytes($result['backup_size'] ?? 0));

    // Auto-apply retention policy
    $this->logger()->info('');
    $this->logger()->info('Applying retention policy...');

    $retention_policy = $config->get('retention_policy') ?? [];
    if (!empty($retention_policy)) {
      $prune_result = $this->resticManager->prune($retention_policy);

      if ($prune_result) {
        $this->logger()->success('✓ Retention policy applied');
      }
      else {
        $this->logger()->warning('⚠ Retention policy application failed');
      }
    }

    // Show final state
    $snapshots = $this->resticManager->listSnapshots();
    $this->logger()->info('Total snapshots: ' . count($snapshots));

    return self::EXIT_SUCCESS;
  }

  /**
   * List snapshots in the repository.
   *
   * @command restic:snapshots
   * @description List all backup snapshots
   * @aliases rsn, restic-snapshots
   */
  #[CLI\Command(name: 'restic:snapshots', aliases: ['rsn', 'restic-snapshots'])]
  public function snapshots(): int {
    $this->logger()->info('Fetching snapshots...');

    $snapshots = $this->resticManager->listSnapshots();

    if (empty($snapshots)) {
      $this->logger()->warning('No snapshots found');
      $this->logger()->info('Create your first backup: drush restic:backup');
      return self::EXIT_SUCCESS;
    }

    $this->logger()->success('Found ' . count($snapshots) . ' snapshot(s):');
    $this->logger()->info('');

    // Display snapshots in table format
    foreach ($snapshots as $snapshot) {
      $id = substr($snapshot['id'], 0, 8);
      $time = date('Y-m-d H:i:s', strtotime($snapshot['time']));
      $hostname = $snapshot['hostname'] ?? 'N/A';
      $paths = isset($snapshot['paths']) ? implode(', ', array_slice($snapshot['paths'], 0, 2)) : 'N/A';

      $line = sprintf('  %s  %s  %s  %s',
        str_pad($id, 10),
        str_pad($time, 20),
        str_pad($hostname, 15),
        $paths
      );
      $this->io()->writeln($line);
    }

    $this->logger()->info('');
    $this->logger()->info('Use snapshot ID with: drush restic:restore <snapshot-id>');

    return self::EXIT_SUCCESS;
  }

  /**
   * Restore files from a snapshot.
   *
   * @command restic:restore
   * @description Restore files from a snapshot
   * @aliases rr, restic-restore
   */
  #[CLI\Command(name: 'restic:restore', aliases: ['rr', 'restic-restore'])]
  #[CLI\Argument(name: 'snapshot-id', description: 'ID of snapshot to restore')]
  #[CLI\Option(name: 'target', description: 'Target restore path')]
  public function restore($snapshot_id, $options = ['target' => NULL]): int {
    if (empty($snapshot_id)) {
      $this->logger()->error('Snapshot ID is required');
      $this->logger()->info('List available snapshots: drush restic:snapshots');
      return self::EXIT_FAILURE;
    }

    $target_path = $options['target'];
    if (empty($target_path)) {
      $this->logger()->error('Target path is required');
      $this->logger()->info('Example: drush restic:restore abc12345 --target=/tmp/restore');
      return self::EXIT_FAILURE;
    }

    // Ensure target directory exists
    if (!is_dir($target_path)) {
      if (!mkdir($target_path, 0755, TRUE)) {
        $this->logger()->error('Failed to create target directory: ' . $target_path);
        return self::EXIT_FAILURE;
      }
    }

    $this->logger()->info('Restoring snapshot ' . $snapshot_id . ' to ' . $target_path . '...');
    $this->logger()->warning('This may take several minutes for large backups');

    $result = $this->resticManager->restore($snapshot_id, $target_path);

    if ($result) {
      $this->logger()->success('✓ Restore completed successfully');
      $this->logger()->info('Files restored to: ' . $target_path);
      return self::EXIT_SUCCESS;
    }
    else {
      $this->logger()->error('✗ Restore failed');
      $this->logger()->warning('Check that:');
      $this->logger()->warning('  1. Snapshot ID is correct: drush restic:snapshots');
      $this->logger()->warning('  2. Target path is writable');
      $this->logger()->warning('  3. Repository is accessible');
      return self::EXIT_FAILURE;
    }
  }

  /**
   * Prune old snapshots based on retention policy.
   *
   * @command restic:prune
   * @description Prune old snapshots based on retention policy
   * @aliases rp, restic-prune
   */
  #[CLI\Command(name: 'restic:prune', aliases: ['rp', 'restic-prune'])]
  public function prune(): int {
    $config = \Drupal::config('restic_backup.settings');
    $retention_policy = $config->get('retention_policy') ?? [];

    if (empty($retention_policy)) {
      $this->logger()->warning('No retention policy configured, using defaults');
      $retention_policy = [
        'keep_daily' => 7,
        'keep_weekly' => 4,
        'keep_monthly' => 12,
        'keep_yearly' => 3,
      ];
    }

    // Show before state
    $snapshots_before = $this->resticManager->listSnapshots();
    $count_before = count($snapshots_before);

    $this->logger()->info('Snapshots before prune: ' . $count_before);
    $this->logger()->info('Retention policy:');
    $this->logger()->info('  Daily: ' . $retention_policy['keep_daily']);
    $this->logger()->info('  Weekly: ' . $retention_policy['keep_weekly']);
    $this->logger()->info('  Monthly: ' . $retention_policy['keep_monthly']);
    $this->logger()->info('  Yearly: ' . $retention_policy['keep_yearly']);
    $this->logger()->info('');
    $this->logger()->info('Applying retention policy...');

    $result = $this->resticManager->prune($retention_policy);

    if (!$result) {
      $this->logger()->error('✗ Prune operation failed');
      return self::EXIT_FAILURE;
    }

    // Show after state
    $snapshots_after = $this->resticManager->listSnapshots();
    $count_after = count($snapshots_after);
    $deleted = $count_before - $count_after;

    $this->logger()->success('✓ Retention policy applied');
    $this->logger()->info('Deleted: ' . $deleted . ' snapshot(s)');
    $this->logger()->info('Remaining: ' . $count_after . ' snapshot(s)');

    return self::EXIT_SUCCESS;
  }

  /**
   * Validate Restic configuration.
   *
   * @command restic:validate
   * @description Validate Restic binary and repository configuration
   * @aliases rv, restic-validate
   */
  #[CLI\Command(name: 'restic:validate', aliases: ['rv', 'restic-validate'])]
  public function validate(): int {
    $config = \Drupal::config('restic_backup.settings');
    $all_valid = TRUE;

    $this->logger()->info('Validating Restic configuration...');
    $this->logger()->info('');

    // Check binary
    if ($this->resticManager->validateBinary()) {
      $this->logger()->success('✓ Restic binary found and working');
    }
    else {
      $this->logger()->error('✗ Restic binary not found or not working');
      $this->logger()->warning('See README.md section "Installing Restic in DDEV"');
      $all_valid = FALSE;
    }

    // Check repository path
    $repo_path = $config->get('repo_path');
    if (!empty($repo_path)) {
      $this->logger()->success('✓ Repository path configured: ' . $repo_path);
    }
    else {
      $this->logger()->error('✗ Repository path not configured');
      $this->logger()->warning('Configure at: /admin/config/system/restic-backup/setup');
      $all_valid = FALSE;
    }

    // Check encryption
    $encryption_enabled = $config->get('encryption_enabled');
    if ($encryption_enabled) {
      $password = \Drupal::state()->get('restic_backup.repository_password', '');
      if (!empty($password)) {
        $this->logger()->success('✓ Encryption enabled with password configured');
      }
      else {
        $this->logger()->warning('⚠ Encryption enabled but no password set');
        $this->logger()->warning('Configure at: /admin/config/system/restic-backup/setup');
      }
    }
    else {
      $this->logger()->info('ℹ Encryption disabled');
    }

    // Check file paths
    $paths = $config->get('included_paths') ?? [];
    if (!empty($paths)) {
      $this->logger()->success('✓ Backup paths configured: ' . count($paths) . ' path(s)');
    }
    else {
      $this->logger()->warning('⚠ No backup paths configured');
      $this->logger()->info('Configure at: /admin/config/system/restic-backup/file-selection');
    }

    // Check retention policy
    $retention = $config->get('retention_policy');
    if (!empty($retention)) {
      $this->logger()->success('✓ Retention policy configured');
    }
    else {
      $this->logger()->info('ℹ Retention policy not configured (will use defaults)');
    }

    $this->logger()->info('');
    if ($all_valid) {
      $this->logger()->success('Configuration is valid');
      return self::EXIT_SUCCESS;
    }
    else {
      $this->logger()->error('Configuration has errors - see above');
      return self::EXIT_FAILURE;
    }
  }

  /**
   * Get statistics for a snapshot.
   *
   * @command restic:stats
   * @description Show backup statistics
   * @aliases rst, restic-stats
   */
  #[CLI\Command(name: 'restic:stats', aliases: ['rst', 'restic-stats'])]
  #[CLI\Option(name: 'snapshot', description: 'Snapshot ID (default: latest)')]
  public function stats($options = ['snapshot' => 'latest']): int {
    $this->logger()->info('Fetching repository statistics...');

    $stats = $this->resticManager->getRepositoryStats();

    if (empty($stats)) {
      $this->logger()->error('Unable to fetch repository statistics');
      $this->logger()->warning('Check that repository is configured and initialized');
      return self::EXIT_FAILURE;
    }

    $this->logger()->success('Repository Statistics:');
    $this->logger()->info('');
    $this->logger()->info('Total Snapshots: ' . number_format($stats['snapshot_count']));
    $this->logger()->info('Total Size: ' . $this->formatBytes($stats['total_size']));
    $this->logger()->info('Total Files: ' . number_format($stats['total_file_count']));
    $this->logger()->info('Total Blobs: ' . number_format($stats['total_blob_count']));

    // Show latest snapshot info
    $snapshots = $this->resticManager->listSnapshots();
    if (!empty($snapshots)) {
      $latest = end($snapshots);
      $this->logger()->info('');
      $this->logger()->info('Latest Snapshot:');
      $this->logger()->info('  ID: ' . substr($latest['id'], 0, 8));
      $this->logger()->info('  Time: ' . date('Y-m-d H:i:s', strtotime($latest['time'])));
      $this->logger()->info('  Hostname: ' . ($latest['hostname'] ?? 'N/A'));
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Scan and list files that would be backed up.
   *
   * @command restic:scan
   * @description Scan project and list files to be backed up (results are cached for fast form loading)
   * @aliases rsc, restic-scan
   */
  #[CLI\Command(name: 'restic:scan', aliases: ['rsc', 'restic-scan'])]
  public function scan(): int {
    $start_time = microtime(TRUE);

    // Create spinning progress indicator (250ms interval for visible animation)
    $output = $this->output();
    $progress = new ProgressIndicator($output, null, 250, ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏']);
    $progress->start('Scanning project files (analyzing .gitignore patterns)');

    // Scan with progress callback and cache results
    $categories = $this->fileDiscoveryService->scanAndCache(function($processed, $total) use ($progress) {
      $progress->advance();
    });

    $config = \Drupal::config('restic_backup.settings');
    $encryption_enabled = $config->get('encryption_enabled');

    $elapsed = round(microtime(TRUE) - $start_time, 2);
    $progress->finish('Scan complete in ' . $elapsed . 's!');
    $this->io()->newLine();
    $this->logger()->info('');

    // Public files
    if (!empty($categories['public_files']['included'])) {
      $count = count($categories['public_files']['included']);
      $size = array_sum(array_column($categories['public_files']['included'], 'size'));
      $this->logger()->success('Public Files: ' . $count . ' files (' . $this->formatBytes($size) . ')');
    }

    // Private files
    if (!empty($categories['private_files']['paths'])) {
      $count = count($categories['private_files']['paths']);
      $size = array_sum(array_column($categories['private_files']['paths'], 'size'));
      $this->logger()->success('Private Files: ' . $count . ' files (' . $this->formatBytes($size) . ')');
    }

    // Sensitive config
    if (!empty($categories['sensitive_config']['paths'])) {
      $count = count($categories['sensitive_config']['paths']);
      $size = array_sum(array_column($categories['sensitive_config']['paths'], 'size'));
      $this->logger()->warning('Sensitive Config: ' . $count . ' files (' . $this->formatBytes($size) . ')');

      // Only show encryption warning if encryption is NOT enabled
      if (!$encryption_enabled) {
        $this->logger()->warning('  ⚠ Requires encryption enabled');
      } else {
        $this->logger()->success('  ✓ Encryption enabled - sensitive files protected');
      }
    }

    // Regenerable
    if (!empty($categories['regenerable'])) {
      $count = count($categories['regenerable']);
      $this->logger()->info('Regenerable: ' . $count . ' files (auto-excluded)');
    }

    // Dependencies
    if (!empty($categories['dependencies'])) {
      $count = count($categories['dependencies']);
      $this->logger()->info('Dependencies: ' . $count . ' files (auto-excluded)');
    }

    $this->logger()->info('');
    $this->logger()->info('Configure file selection at: /admin/config/system/restic-backup/file-selection');

    return self::EXIT_SUCCESS;
  }

  /**
   * Format bytes for human-readable display.
   *
   * @param int $bytes
   *   Number of bytes.
   *
   * @return string
   *   Formatted string.
   */
  protected function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, 2) . ' ' . $units[$pow];
  }

}
