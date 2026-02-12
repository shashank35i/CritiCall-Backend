<?php
require_once __DIR__ . "/email_otp.php";

/**
 * SehatSethu - Email Verification OTP Template
 */
function email_verify_otp_template(string $name, string $otp) : string {
  return email_otp_template($name, $otp, 10, "Verify your email");
}
