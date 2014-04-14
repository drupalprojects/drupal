<?php

/**
 * @file
 * Contains Drupal\Core\Mail\Plugin\Mail\PhpMail.
 */

namespace Drupal\Core\Mail\Plugin\Mail;

use Drupal\Component\Utility\Settings;
use Drupal\Core\Mail\MailInterface;

/**
 * Defines the default Drupal mail backend, using PHP's native mail() function.
 *
 * @Mail(
 *   id = "php_mail",
 *   label = @Translation("Default PHP mailer"),
 *   description = @Translation("Sends the message as plain text, using PHP's native mail() function.")
 * )
 */
class PhpMail implements MailInterface {

  /**
   * Concatenates and wraps the e-mail body for plain-text mails.
   *
   * @param array $message
   *   A message array, as described in HOOK_mail_alter().
   *
   * @return array
   *   The formatted $message.
   */
  public function format(array $message) {
    // Join the body array into one string.
    $message['body'] = implode("\n\n", $message['body']);
    // Convert any HTML to plain-text.
    $message['body'] = drupal_html_to_text($message['body']);
    // Wrap the mail body for sending.
    $message['body'] = drupal_wrap_mail($message['body']);

    return $message;
  }

  /**
   * Sends an e-mail message.
   *
   * @param array $message
   *   A message array, as described in HOOK_mail_alter().
   *
   * @return bool
   *   TRUE if the mail was successfully accepted, otherwise FALSE.
   *
   * @see http://php.net/manual/en/function.mail.php
   * @see drupal_mail()
   */
  public function mail(array $message) {
    // If 'Return-Path' isn't already set in php.ini, we pass it separately
    // as an additional parameter instead of in the header.
    if (isset($message['headers']['Return-Path'])) {
      $return_path_set = strpos(ini_get('sendmail_path'), ' -f');
      if (!$return_path_set) {
        $message['Return-Path'] = $message['headers']['Return-Path'];
        unset($message['headers']['Return-Path']);
      }
    }
    $mimeheaders = array();
    foreach ($message['headers'] as $name => $value) {
      $mimeheaders[] = $name . ': ' . mime_header_encode($value);
    }
    $line_endings = Settings::get('mail_line_endings', PHP_EOL);
    // Prepare mail commands.
    $mail_subject = mime_header_encode($message['subject']);
    // Note: e-mail uses CRLF for line-endings. PHP's API requires LF
    // on Unix and CRLF on Windows. Drupal automatically guesses the
    // line-ending format appropriate for your system. If you need to
    // override this, adjust $settings['mail_line_endings'] in settings.php.
    $mail_body = preg_replace('@\r?\n@', $line_endings, $message['body']);
    // For headers, PHP's API suggests that we use CRLF normally,
    // but some MTAs incorrectly replace LF with CRLF. See #234403.
    $mail_headers = join("\n", $mimeheaders);

    $request = \Drupal::request();

    // We suppress warnings and notices from mail() because of issues on some
    // hosts. The return value of this method will still indicate whether mail
    // was sent successfully.
    if (!$request->server->has('WINDIR') && strpos($request->server->get('SERVER_SOFTWARE'), 'Win32') === FALSE) {
      // On most non-Windows systems, the "-f" option to the sendmail command
      // is used to set the Return-Path. There is no space between -f and
      // the value of the return path.
      $additional_headers = isset($message['Return-Path']) ? '-f' . $message['Return-Path'] : '';
      $mail_result = @mail(
        $message['to'],
        $mail_subject,
        $mail_body,
        $mail_headers,
        $additional_headers
      );
    }
    else {
      // On Windows, PHP will use the value of sendmail_from for the
      // Return-Path header.
      $old_from = ini_get('sendmail_from');
      ini_set('sendmail_from', $message['Return-Path']);
      $mail_result = @mail(
        $message['to'],
        $mail_subject,
        $mail_body,
        $mail_headers
      );
      ini_set('sendmail_from', $old_from);
    }

    return $mail_result;
  }
}
