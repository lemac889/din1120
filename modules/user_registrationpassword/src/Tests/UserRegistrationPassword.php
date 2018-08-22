<?php

namespace Drupal\user_registrationpassword\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Functionality tests for User registration password module.
 *
 * @group UserRegistrationPassword.
 */
class UserRegistrationPassword extends WebTestBase {

  /**
   * Modules to install.
   *
   * @var array
   */
  public static $modules = array('user_registrationpassword');

  /**
   * Implements testRegistrationWithEmailVerificationAndPassword().
   */
  function testRegistrationWithEmailVerificationAndPassword() {
    // Register a new account.
    $edit = array();
    $edit['name'] = $name = $this->randomMachineName();
    $edit['mail'] = $mail = $edit['name'] . '@example.com';
    $edit['pass[pass1]'] = $new_pass = $this->randomMachineName();
    $edit['pass[pass2]'] = $new_pass;
    $pass = $new_pass;
    $this->drupalPostForm('user/register', $edit, 'Create new account');
    $this->assertText('In the meantime, a welcome message with further instructions has been sent to your email address.', 'User registered successfully.');

    // Load the new user.
    $accounts = \Drupal::entityQuery('user')
      ->condition('name', $name)
      ->condition('mail', $mail)
      ->condition('status', 0)
      ->execute();
    /** @var \Drupal\user\UserInterface $account */
    $account = \Drupal::entityTypeManager()->getStorage('user')->load(reset($accounts));

    // Configure some timestamps.
    // We up the timestamp a bit, else the check will fail.
    // The function that checks this uses the execution time
    // and that's always larger in real-life situations
    // (and it fails correctly when you remove the + 5000).
    $timestamp = REQUEST_TIME + 5000;
    $test_timestamp = REQUEST_TIME;
    $bogus_timestamp = REQUEST_TIME - 86500;

    // check if the account has not been activated.
    $this->assertFalse($account->isActive(), 'New account is blocked until approved via e-mail confirmation. status check.');
    $this->assertFalse($account->getLastLoginTime(), 'New account is blocked until approved via e-mail confirmation. login check.');
    $this->assertFalse($account->getLastAccessedTime(), 'New account is blocked until approved via e-mail confirmation. access check.');

    // Login before activation.
    $auth = array(
      'name' => $name,
      'pass' => $pass,
    );
    $this->drupalPostForm('user/login', $auth, 'Log in');
    $this->assertText('The username ' . $name . ' has not been activated or is blocked.', 'User cannot login yet.');

    // Timestamp can not be smaller then current. (== registration time).
    // If this is the case, something is really wrong.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$test_timestamp/" . user_pass_rehash($account, $test_timestamp));
    $this->assertText('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.');

    // Fake key combi.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$timestamp/" . user_pass_rehash($account, $bogus_timestamp));
    $this->assertText('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.');

    // Fake timestamp.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$bogus_timestamp/" . user_pass_rehash($account, $timestamp));
    $this->assertText('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.');

    // Wrong password.
    $account_cloned = clone $account;
    $account_cloned->setPassword('boguspass');
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$timestamp/" . user_pass_rehash($account_cloned, $timestamp));
    $this->assertText('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.');

    // Attempt to use the activation link.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$timestamp/" . user_pass_rehash($account, $timestamp));
    $this->assertText('You have just used your one-time login link. Your account is now active and you are authenticated.');

    // Attempt to use the activation link again.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$timestamp/" . user_pass_rehash($account, $timestamp));
    $this->assertText('You are currently authenticated as user ' . $account->getAccountName() . '.');

    // Logout the user
    $this->drupalLogout();

    // Then attempt to use the activation link yet again.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$timestamp/" . user_pass_rehash($account, $timestamp));
    $this->assertText('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.');

    // And then try to do normal login.
    $auth = array(
      'name' => $name,
      'pass' => $pass,
    );
    $this->drupalPostForm('user/login', $auth, 'Log in');
    $this->assertText('Member for', 'User logged in.');
  }

  function testPasswordResetFormResendActivation() {
    // Register a new account.
    $edit1 = array();
    $edit1['name'] = $name = $this->randomMachineName();
    $edit1['mail'] = $mail = $edit1['name'] . '@example.com';
    $edit1['pass[pass1]'] = $new_pass = $this->randomMachineName();
    $edit1['pass[pass2]'] = $new_pass;
    $this->drupalPostForm('user/register', $edit1, 'Create new account');
    $this->assertText('In the meantime, a welcome message with further instructions has been sent to your email address.', 'User registered successfully.');

    // Request a new activation e-mail.
    $edit2 = array();
    $edit2['name'] = $edit1['name'];
    $this->drupalPostForm('user/password', $edit2, 'Submit');
    $this->assertText('Further instructions have been sent to your e-mail address.', 'Password rest form submitted successfully.');

    // Request a new activation e-mail for a non-existing user name.
    $edit3 = array();
    $edit3['name'] = $this->randomMachineName();
    $this->drupalPostForm('user/password', $edit3, 'Submit');
    $this->assertText($edit3['name'] . ' is not recognized as a username or an email address.', 'Password rest form failed correctly.');

    // Request a new activation e-mail for a non-existing user e-mail.
    $edit4 = array();
    $edit4['name'] = $this->randomMachineName() . '@example.com';
    $this->drupalPostForm('user/password', $edit4, 'Submit');
    $this->assertText($edit4['name'] . ' is not recognized as a username or an email address.', 'Password rest form failed correctly.');
  }

  function testLoginWithUrpLinkWhileBlocked() {
    $timestamp = REQUEST_TIME + 5000;

    // Register a new account.
    $edit = array();
    $edit['name'] = $name = $this->randomMachineName();
    $edit['mail'] = $mail = $edit['name'] . '@example.com';
    $edit['pass[pass1]'] = $new_pass = $this->randomMachineName();
    $edit['pass[pass2]'] = $new_pass;
    $this->drupalPostForm('user/register', $edit, 'Create new account');

    // Load the new user.
    $accounts = \Drupal::entityQuery('user')
      ->condition('name', $name)
      ->condition('mail', $mail)
      ->condition('status', 0)
      ->execute();
    /** @var \Drupal\user\UserInterface $account */
    $account = \Drupal::entityTypeManager()->getStorage('user')->load(reset($accounts));

    // Attempt to use the activation link.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$timestamp/" . user_pass_rehash($account, $timestamp));
    $this->assertText(t('You have just used your one-time login link. Your account is now active and you are authenticated.'));

    $this->drupalLogout();

    // Block the user.
    $account
      ->setLastLoginTime(REQUEST_TIME)
      ->block()
      ->save();

    // Then try to use the link.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$timestamp/" . user_pass_rehash($account, $timestamp));
    $this->assertText('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.');

    // Try to request a new activation e-mail.
    $edit2['name'] = $edit['name'];
    $this->drupalPostForm('user/password', $edit2, 'Submit');
    $this->assertText($edit2['name'] . ' is blocked or has not been activated yet.', 'Password reset form failed correctly.');
  }
}
