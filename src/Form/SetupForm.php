<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\restic_backup\Service\ResticManager;
use Drupal\restic_backup\Service\EncryptionValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Restic Binary and Repository on Settings Form.
 *
 * @ingroup restic_backup
 */
class SetupForm extends ConfigFormBase {

  /**
   * Restic manager service.
   */
  protected ResticManager $resticManager;

  /**
   * Encryption validator service.
   */
  protected EncryptionValidator $encryptionValidator;

  /**
   * Constructs a SetupForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\restic_backup\Service\ResticManager $restic_manager
   *   The Restic manager service.
   * @param \Drupal\restic_backup\Service\EncryptionValidator $encryption_validator
   *   The encryption validator service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    ResticManager $restic_manager,
    EncryptionValidator $encryption_validator
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->resticManager = $restic_manager;
    $this->encryptionValidator = $encryption_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('restic_backup.manager'),
      $container->get('restic_backup.encryption_validator')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['restic_backup.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'restic_backup_setup_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('restic_backup.settings');

    // ========== BINARY & VALIDATION SECTION ==========
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
      '#required' => TRUE,
    ];

    $form['binary_section']['validate_binary'] = [
      '#type' => 'button',
      '#value' => $this->t('Validate Restic Installation'),
      '#ajax' => [
        'callback' => '::validateBinaryAjax',
        'wrapper' => 'binary-validation-result',
        'event' => 'click',
      ],
    ];

    $form['binary_section']['validation_result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'binary-validation-result'],
    ];

    // ========== REPOSITORY CONFIGURATION SECTION ==========
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
        'sftp' => $this->t('SFTP Remote Server'),
        's3' => $this->t('Amazon S3 or Compatible'),
      ],
      '#default_value' => $config->get('repo_type') ?: 'local',
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::repoTypeAjax',
        'wrapper' => 'repo-path-container',
        'event' => 'change',
      ],
    ];

    $repoType = $form_state->getValue('repo_type') ?: $config->get('repo_type') ?: 'local';

    $form['repo_section']['repo_path_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'repo-path-container'],
    ];

    // Dynamic repo path field based on type.
    $pathDescriptions = [
      'local' => $this->t('Absolute path to backup directory (e.g., /mnt/backups/restic-repo or /var/backups/restic)'),
      'sftp' => $this->t('Format: sftp://user@host:/path/to/repo (e.g., sftp://backup@example.com:/backups/drupal)'),
      's3' => $this->t('Format: s3:s3.amazonaws.com/bucket/prefix or s3:https://s3.amazonaws.com/bucket-name'),
    ];

    $form['repo_section']['repo_path_container']['repo_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository Path'),
      '#description' => $pathDescriptions[$repoType] ?? $pathDescriptions['local'],
      '#default_value' => $config->get('repo_path'),
      '#required' => TRUE,
    ];

    // ========== ENCRYPTION & SECURITY SECTION ==========
    $form['encryption_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Encryption & Security'),
      '#weight' => 0,
    ];

    // Show current encryption status
    $encryption_enabled = $config->get('encryption_enabled');
    $password_exists = !empty(\Drupal::state()->get('restic_backup.repository_password'));

    if ($encryption_enabled && $password_exists) {
      $form['encryption_section']['current_status'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['messages', 'messages--status']],
        '#value' => $this->t('✓ <strong>Encryption is currently enabled</strong> with a password set.'),
      ];
    }

    $form['encryption_section']['info'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      '#value' => $this->t(
        '<strong>⚠️ Encryption Required for Sensitive Files</strong><br>' .
        'Without encryption, sensitive files (settings.php, .env, private files) will be excluded from backups.'
      ),
    ];

    $form['encryption_section']['encryption_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Encryption (Recommended)'),
      '#description' => $this->t('Encrypt repository with AES-256. Requires a strong password.'),
      '#default_value' => $encryption_enabled ?? TRUE,
    ];

    $password_description = $password_exists
      ? $this->t('<strong>Current:</strong> Password is already set. Leave blank to keep current password, or enter a new password to change it.<br><strong>⚠️ Important:</strong> Store this password securely! You <strong>cannot recover your backups</strong> without it.')
      : $this->t('<strong>⚠️ Important:</strong> Store this password securely! You <strong>cannot recover your backups</strong> without it.');

    $form['encryption_section']['password'] = [
      '#type' => 'password_confirm',
      '#title' => $this->t('Repository Password'),
      '#description' => $password_description,
      '#states' => [
        'visible' => [
          ':input[name="encryption_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // ========== ADVANCED OPTIONS SECTION ==========
    $form['advanced_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Options'),
      '#weight' => 10,
      '#open' => FALSE,
    ];

    $form['advanced_section']['use_queue'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use Background Queue for Backups'),
      '#description' => $this->t('Process backups in the background using Drupal\'s queue system. This prevents timeouts for large backups but requires cron to be running. When disabled, backups run immediately (may timeout on large sites).'),
      '#default_value' => $config->get('use_queue') ?? FALSE,
    ];

    $form['advanced_section']['file_scan_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('File Discovery Scan Frequency'),
      '#description' => $this->t('How often to scan for new files in the background. Scanned files are cached for instant form loading. Use "Manual only" if you prefer to rescan manually via the "Rescan Files Now" button.'),
      '#options' => [
        'never' => $this->t('Never (Manual only)'),
        'every_cron' => $this->t('Every Cron Run'),
        'daily' => $this->t('Daily (Recommended)'),
        'weekly' => $this->t('Weekly'),
      ],
      '#default_value' => $config->get('file_scan_frequency') ?? 'daily',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate binary path doesn't look like a repository path
    $binary_path = $form_state->getValue('binary_path');

    // Check if binary path contains multiple path segments suggesting it's a repo path
    $segments = explode('/', trim($binary_path, '/'));
    $last_segment = end($segments);

    // If the last segment looks like a directory name (not an executable)
    // and doesn't end with common executable patterns, it's likely wrong
    if (strpos($last_segment, '.') === FALSE &&
        !in_array($last_segment, ['restic', 'restic.exe']) &&
        count($segments) > 3) {
      $form_state->setErrorByName('binary_path',
        $this->t('Binary path appears to be a repository path. Please enter only the path to the restic executable (e.g., /usr/bin/restic), not the repository path.')
      );
    }

    // Check if binary path ends with common executable names
    if (!preg_match('/restic(\.exe)?$/', $last_segment)) {
      $this->messenger()->addWarning(
        $this->t('Binary path should end with "restic" or "restic.exe". Current value: @path', [
          '@path' => $binary_path
        ])
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('restic_backup.settings');

    // Save binary path.
    $config->set('binary_path', $form_state->getValue('binary_path'));

    // Save repository configuration.
    $config->set('repo_type', $form_state->getValue('repo_type'));
    $config->set('repo_path', $form_state->getValue('repo_path'));

    // Save encryption settings.
    $encryptionEnabled = (bool) $form_state->getValue('encryption_enabled');
    $config->set('encryption_enabled', $encryptionEnabled);

    // Save advanced options.
    $config->set('use_queue', (bool) $form_state->getValue('use_queue'));
    $config->set('file_scan_frequency', $form_state->getValue('file_scan_frequency'));

    // Store password securely if encryption is enabled and a password is provided.
    $password = $form_state->getValue('password');
    if ($encryptionEnabled) {
      if (!empty($password)) {
        // New password provided - update it
        \Drupal::state()->set('restic_backup.repository_password', $password);
        $this->messenger()->addWarning($this->t(
          'Password updated. <strong>Important:</strong> For production use, store the password in an environment variable or secure key management system.'
        ));
      }
      else {
        // No password provided - check if one exists
        $existing_password = \Drupal::state()->get('restic_backup.repository_password');
        if (empty($existing_password)) {
          $this->messenger()->addWarning($this->t(
            'Encryption is enabled but no password is set. Please set a password or initialize the repository with: drush restic:init --password=YOUR_PASSWORD'
          ));
        }
      }
    }
    elseif (!$encryptionEnabled) {
      // Encryption disabled - warn about existing password
      $existing_password = \Drupal::state()->get('restic_backup.repository_password');
      if (!empty($existing_password)) {
        $this->messenger()->addWarning($this->t(
          'Encryption disabled but password is still stored. Repository will continue to require the password for existing backups.'
        ));
      }
    }

    $config->save();

    // Note: Repository initialization should be done manually via Drush or
    // a dedicated "Initialize Repository" button to avoid blocking form saves.
    // Users can run: drush restic:init
    //
    // We save the config here, and repository init happens separately.

    $this->messenger()->addStatus($this->t('Restic backup configuration has been saved.'));

    parent::submitForm($form, $form_state);
  }

  /**
   * AJAX callback for binary validation.
   */
  public function validateBinaryAjax(array &$form, FormStateInterface $form_state) {
    $binaryPath = $form_state->getValue('binary_path');

    $result = [
      '#type' => 'container',
      '#attributes' => ['id' => 'binary-validation-result'],
    ];

    try {
      // Validate that binary exists and is executable.
      if (!file_exists($binaryPath)) {
        $result['message'] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['messages', 'messages--error']],
          '#value' => $this->t('❌ Binary not found at @path', ['@path' => $binaryPath]),
        ];
        return $result;
      }

      if (!is_executable($binaryPath)) {
        $result['message'] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['messages', 'messages--error']],
          '#value' => $this->t('❌ File exists but is not executable: @path', ['@path' => $binaryPath]),
        ];
        return $result;
      }

      // Run restic version command to validate.
      $process = new \Symfony\Component\Process\Process([$binaryPath, 'version']);
      $process->run();

      if ($process->isSuccessful()) {
        $version = trim($process->getOutput());
        $result['message'] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['messages', 'messages--status']],
          '#value' => $this->t('✅ Valid Restic binary found<br><pre>@version</pre>', [
            '@version' => $version,
          ]),
        ];
      }
      else {
        $result['message'] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['messages', 'messages--error']],
          '#value' => $this->t('❌ Binary validation failed: @error', [
            '@error' => $process->getErrorOutput(),
          ]),
        ];
      }
    }
    catch (\Exception $e) {
      $result['message'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['messages', 'messages--error']],
        '#value' => $this->t('❌ Error during validation: @error', [
          '@error' => $e->getMessage(),
        ]),
      ];
    }

    return $result;
  }

  /**
   * AJAX callback for repository type change.
   */
  public function repoTypeAjax(array &$form, FormStateInterface $form_state) {
    return $form['repo_section']['repo_path_container'];
  }

}
