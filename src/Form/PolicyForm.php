<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Policy and Schedule Configuration Form.
 *
 * @ingroup restic_backup
 */
class PolicyForm extends ConfigFormBase {

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
    return 'restic_backup_policy_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('restic_backup.settings');

    // ========== RETENTION POLICY SECTION ==========
    $form['retention'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Retention Policy'),
      '#description' => $this->t('Define how many snapshots to keep. Set to 0 to disable that retention level.'),
      '#weight' => -10,
    ];

    $form['retention']['keep_daily'] = [
      '#type' => 'number',
      '#title' => $this->t('Keep Daily Snapshots'),
      '#description' => $this->t('Number of daily snapshots to retain (e.g., 7 for last week)'),
      '#default_value' => $config->get('retention_policy.keep_daily') ?? 7,
      '#min' => 0,
      '#max' => 365,
    ];

    $form['retention']['keep_weekly'] = [
      '#type' => 'number',
      '#title' => $this->t('Keep Weekly Snapshots'),
      '#description' => $this->t('Number of weekly snapshots to retain (e.g., 4 for last month)'),
      '#default_value' => $config->get('retention_policy.keep_weekly') ?? 4,
      '#min' => 0,
      '#max' => 52,
    ];

    $form['retention']['keep_monthly'] = [
      '#type' => 'number',
      '#title' => $this->t('Keep Monthly Snapshots'),
      '#description' => $this->t('Number of monthly snapshots to retain (e.g., 12 for last year)'),
      '#default_value' => $config->get('retention_policy.keep_monthly') ?? 12,
      '#min' => 0,
      '#max' => 120,
    ];

    $form['retention']['keep_yearly'] = [
      '#type' => 'number',
      '#title' => $this->t('Keep Yearly Snapshots'),
      '#description' => $this->t('Number of yearly snapshots to retain (e.g., 3 for last 3 years)'),
      '#default_value' => $config->get('retention_policy.keep_yearly') ?? 3,
      '#min' => 0,
      '#max' => 10,
    ];

    // ========== BACKUP SCHEDULE SECTION ==========
    $form['schedule'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Backup Schedule'),
      '#description' => $this->t('Configure when automated backups should run.'),
      '#weight' => 0,
    ];

    $form['schedule']['backup_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Backup Frequency'),
      '#description' => $this->t('How often backups should run automatically via cron'),
      '#options' => [
        'daily' => $this->t('Daily at midnight'),
        'daily_2am' => $this->t('Daily at 2 AM'),
        'weekly' => $this->t('Weekly (Sunday at 2 AM)'),
        'custom' => $this->t('Custom cron expression'),
      ],
      '#default_value' => $config->get('backup_schedule') ?? 'daily_2am',
    ];

    $form['schedule']['custom_cron_expression'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom Cron Expression'),
      '#description' => $this->t('Standard cron format: minute hour day month weekday (e.g., "0 2 * * *" for 2 AM daily)'),
      '#default_value' => $config->get('custom_cron_expression') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="backup_frequency"]' => ['value' => 'custom'],
        ],
        'required' => [
          ':input[name="backup_frequency"]' => ['value' => 'custom'],
        ],
      ],
    ];

    // ========== NOTIFICATIONS SECTION ==========
    $form['notifications'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Notifications'),
      '#description' => $this->t('Configure email notifications for backup operations.'),
      '#weight' => 10,
    ];

    $form['notifications']['notify_on_failure'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send email notification on backup failures'),
      '#description' => $this->t('Receive an email when a scheduled backup fails'),
      '#default_value' => $config->get('notify_on_failure') ?? TRUE,
    ];

    $form['notifications']['notification_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Notification Email Address'),
      '#description' => $this->t('Email address to receive failure notifications'),
      '#default_value' => $config->get('notification_email') ?? \Drupal::config('system.site')->get('mail'),
      '#states' => [
        'visible' => [
          ':input[name="notify_on_failure"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="notify_on_failure"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('restic_backup.settings');

    // Save retention policy settings.
    $config
      ->set('retention_policy.keep_daily', (int) $form_state->getValue('keep_daily'))
      ->set('retention_policy.keep_weekly', (int) $form_state->getValue('keep_weekly'))
      ->set('retention_policy.keep_monthly', (int) $form_state->getValue('keep_monthly'))
      ->set('retention_policy.keep_yearly', (int) $form_state->getValue('keep_yearly'));

    // Save backup schedule.
    $frequency = $form_state->getValue('backup_frequency');
    $config->set('backup_schedule', $frequency);

    if ($frequency === 'custom') {
      $customCron = $form_state->getValue('custom_cron_expression');
      $config->set('custom_cron_expression', $customCron);
    }

    // Save notification settings.
    $config
      ->set('notify_on_failure', (bool) $form_state->getValue('notify_on_failure'))
      ->set('notification_email', $form_state->getValue('notification_email'));

    $config->save();

    $this->messenger()->addStatus($this->t('Retention policy and schedule have been saved.'));

    parent::submitForm($form, $form_state);
  }

}
