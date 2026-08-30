{{--
    The email body.

    Plain, inlined and deliberately dull. Mail clients strip stylesheets, corporate gateways rewrite
    markup, and a site office reads this on a phone over a satellite link — so there is no external
    CSS, no images and no web fonts.

    The link is a link, never a button that turns out to be an image. And it always lands on a page
    that asks the policy again: an email forwarded to somebody outside the company gets them a login
    screen, not a record (docs/10 §1).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f5f7; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="max-width:560px; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:8px;">
                    <tr>
                        <td style="padding:20px 24px; border-bottom:1px solid #e5e7eb; font-size:13px; font-weight:600; color:#6b7280;">
                            {{ $organizationName }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 12px; font-size:17px; font-weight:600; line-height:1.4; color:#111827;">
                                {{ $heading }}
                            </p>

                            @if ($bodyText)
                                <p style="margin:0 0 20px; font-size:14px; line-height:1.6; color:#374151;">
                                    {{ $bodyText }}
                                </p>
                            @endif

                            @if ($actionUrl)
                                <p style="margin:0 0 20px;">
                                    <a href="{{ $actionUrl }}"
                                       style="display:inline-block; padding:10px 18px; background-color:#1d4ed8; color:#ffffff; text-decoration:none; border-radius:6px; font-size:14px; font-weight:600;">
                                        {{ $actionLabel ?? 'Open it' }}
                                    </a>
                                </p>
                                <p style="margin:0; font-size:12px; line-height:1.6; color:#6b7280;">
                                    If the button does not work, paste this into your browser:<br>
                                    <span style="word-break:break-all;">{{ $actionUrl }}</span>
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px; border-top:1px solid #e5e7eb; font-size:12px; line-height:1.6; color:#6b7280;">
                            You are receiving this because of your role or your part in the work it refers to.
                            You can change which of these arrive by email from Notification settings after signing in.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
