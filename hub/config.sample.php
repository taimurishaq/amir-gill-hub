<?php
// Copy this file to config.php and edit. Everything is optional except cron_key.
return [
    'data_dir'      => '',                        // RECOMMENDED: folder OUTSIDE public_html for the database + secret.key, e.g. /home/USER/hub_data
    'cron_key'      => 'change-me-long-random',   // secret for web-cron (cron-job.org) URL
    'base_url'      => '',                        // e.g. https://yourdomain.com/hub  (auto-detected if empty; set it behind proxies)

    // Who may create an account on this hub: 'open' (anyone with the link), 'invite' (needs invite_code), 'closed'.
    // The very first account can always be created.
    'signup'        => 'invite',
    'invite_code'   => 'change-me',
    'max_brands'    => 5,                         // businesses per user
    'max_ai_per_user_day' => 12,                  // AI-written posts (autopilot + timeline + YouTube) per user per 24h

    // Shared free AI key (https://aistudio.google.com/apikey). Users can also add their own in Settings.
    'gemini_key'    => '',
    'gemini_model'  => 'gemini-2.5-flash',          // model names change; edit if Google retires this one
    // A key starting with sk-ant- (Settings, or put it in gemini_key above) uses Claude instead. Cheap + best writing:
    'claude_model'  => 'claude-haiku-5-5',        // or 'claude-sonnet-5-5' for higher quality

    // "Connect" buttons. Redirect URI to register in EVERY app below:  https://yourdomain.com/hub/oauth.php
    'meta_app_id'   => '', 'meta_app_secret'   => '',   // developers.facebook.com (Facebook + Instagram)
    'linkedin_client_id' => '', 'linkedin_client_secret' => '',   // linkedin.com/developers ("Share on LinkedIn" product)
    'linkedin_org'  => false,                     // true only if approved for LinkedIn Community Management API (company pages)
    'x_client_id'   => '', 'x_client_secret'   => '',   // developer.x.com (OAuth 2.0, "Web App" type)

    'graph_version' => 'v21.0',
    'max_attempts'  => 3,
    'retry_minutes' => 5,
];
