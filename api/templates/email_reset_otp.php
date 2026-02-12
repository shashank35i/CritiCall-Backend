<?php
require_once __DIR__ . "/email_otp.php";

/**
 * SehatSethu - Password Reset OTP Template
 */
function email_reset_otp_template(string $name, string $otp) : string {
  return email_otp_template($name, $otp, 10, "Reset your password");
}
