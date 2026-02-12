<?php
/**
 * SehatSethu - Shared OTP Email Template (Responsive)
 *
 * Keep email HTML table-based for maximum compatibility across Gmail/Outlook/mobile.
 *
 * Usage:
 *   $html = email_otp_template($name, $otp, 10, "Verify your email");
 */
function email_otp_template(string $name, string $otp, int $minutes, string $title) : string {
  $safeName   = htmlspecialchars($name ?: "there", ENT_QUOTES, "UTF-8");
  $safeOtp    = htmlspecialchars($otp, ENT_QUOTES, "UTF-8");
  $safeTitle  = htmlspecialchars($title, ENT_QUOTES, "UTF-8");
  $safeMins   = max(1, (int)$minutes);
  $year       = date("Y");

  // Preheader text (shown in email previews in many clients)
  $preheader  = htmlspecialchars("Your one-time code is {$otp}. Expires in {$safeMins} minutes.", ENT_QUOTES, "UTF-8");

  return <<<HTML
<!doctype html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="x-apple-disable-message-reformatting" />
    <title>{$safeTitle} • SehatSethu</title>

    <style>
      /* Many clients ignore <style>; critical styles are inline. This is best-effort polish. */
      @media (max-width: 600px) {
        .container { width: 100% !important; }
        .px { padding-left: 16px !important; padding-right: 16px !important; }
        .py { padding-top: 18px !important; padding-bottom: 18px !important; }
        .h1 { font-size: 22px !important; line-height: 28px !important; }
        .otp { font-size: 30px !important; letter-spacing: 6px !important; }
        .muted { font-size: 13px !important; }
      }

      /* Dark mode (supported in some clients) */
      @media (prefers-color-scheme: dark) {
        body, .bg { background: #0b1220 !important; }
        .card { background: #0f172a !important; }
        .text { color: #e5e7eb !important; }
        .muted { color: #94a3b8 !important; }
        .divider { border-color: rgba(148,163,184,.25) !important; }
        .otpbox { background: rgba(148,163,184,.10) !important; border-color: rgba(148,163,184,.25) !important; }
      }
    </style>
  </head>

  <body class="bg" style="margin:0; padding:0; background:#f6f8fb; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
    <!-- Preheader (hidden) -->
    <div style="display:none; font-size:1px; color:#f6f8fb; line-height:1px; max-height:0px; max-width:0px; opacity:0; overflow:hidden;">
      {$preheader}
    </div>

    <!-- Full width wrapper -->
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; background:#f6f8fb; margin:0; padding:0;">
      <tr>
        <td align="center" style="padding:24px 12px;">

          <!-- Container -->
          <table role="presentation" class="container" width="600" cellspacing="0" cellpadding="0" border="0" style="width:600px; max-width:600px;">
            <!-- Header bar -->
            <tr>
              <td style="padding:0;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; border-radius:16px 16px 0 0; overflow:hidden;">
                  <tr>
                    <td style="background:#059669; padding:18px 22px;">
                      <div style="font-family:Arial, sans-serif; font-size:18px; font-weight:700; color:#ffffff;">
                        SehatSethu
                      </div>
                      <div style="font-family:Arial, sans-serif; font-size:13px; color:rgba(255,255,255,.92); margin-top:2px;">
                        {$safeTitle}
                      </div>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>

            <!-- Card -->
            <tr>
              <td class="card px py" style="background:#ffffff; border-radius:0 0 16px 16px; padding:22px;">
                <div class="text" style="font-family:Arial, sans-serif; color:#0f172a;">
                  <div class="h1" style="font-size:20px; line-height:26px; font-weight:700; margin:0 0 8px 0;">
                    Hi {$safeName},
                  </div>

                  <p class="muted" style="margin:0 0 16px 0; font-size:14px; line-height:20px; color:#334155;">
                    Use the one-time password (OTP) below to continue. For your security, please don’t share this code with anyone.
                  </p>

                  <!-- OTP box -->
                  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="otpbox" style="width:100%; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:14px;">
                    <tr>
                      <td align="center" style="padding:18px 16px;">
                        <div class="otp" style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:34px; line-height:40px; letter-spacing:8px; font-weight:800; color:#0f172a;">
                          {$safeOtp}
                        </div>
                        <div class="muted" style="font-family:Arial, sans-serif; font-size:13px; line-height:18px; color:#64748b; margin-top:6px;">
                          Expires in {$safeMins} minutes
                        </div>
                      </td>
                    </tr>
                  </table>

                  <hr class="divider" style="border:none; border-top:1px solid #e2e8f0; margin:18px 0;" />

                  <p class="muted" style="margin:0; font-size:13px; line-height:19px; color:#64748b;">
                    If you didn’t request this code, you can safely ignore this email. Someone may have typed your email by mistake.
                  </p>

                  <p class="muted" style="margin:12px 0 0 0; font-size:12px; line-height:18px; color:#94a3b8;">
                    © {$year} SehatSethu. All rights reserved.
                  </p>
                </div>
              </td>
            </tr>

            <!-- Footer spacing -->
            <tr>
              <td style="height:10px; line-height:10px; font-size:10px;">&nbsp;</td>
            </tr>
          </table>

        </td>
      </tr>
    </table>
  </body>
</html>
HTML;
}
